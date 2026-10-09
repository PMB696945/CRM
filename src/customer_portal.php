<?php
declare(strict_types=1);

/*
 * Customer portal: a separate, read-only site (its own subdomain, or account.php) where people at a customer
 * sign in to see their own account: details, services with their logins and IP addresses, orders, agreements
 * and support tickets. They can change only their own portal password. Each person sees only their own
 * company's account. The wholesale supplier isn't named anywhere on it.
 */

/** The customer portal's own address (e.g. myaccount.example.co.uk), set under Settings. */
function customer_portal_host(): string
{
    return strtolower(trim((string)setting('customer_portal_host'), " /"));
}

/** Is this request for the customer portal? */
function customer_portal_requested(): bool
{
    if (defined('CRM_CUSTOMER_PORTAL')) {
        return true;
    }
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    return customer_portal_host() !== '' && $host === preg_replace('#^https?://#', '', customer_portal_host());
}

/** The customer portal's full address, for emails. */
function customer_portal_public_url(string $go = 'home', array $params = []): string
{
    $host = customer_portal_host();
    $base = $host !== '' ? (str_starts_with($host, 'http') ? $host : 'https://' . $host) . '/index.php' : app_url() . '/account.php';
    return $base . '?' . http_build_query(['go' => $go] + $params);
}

function customer_portal_name(): string
{
    return company('name', config('app_name')) . ' customer portal';
}

/* ---------------------------------------------------------------- Users --- */

function customer_user(): ?array
{
    $id = (int)($_SESSION['customer_user_id'] ?? 0);
    if (!$id) {
        return null;
    }
    $idle = max(5, (int)(setting('session_idle_minutes') ?: 60)) * 60;
    $now = time();
    if ($now - (int)($_SESSION['customer_last'] ?? $now) > $idle || $now - (int)($_SESSION['customer_since'] ?? $now) > SESSION_MAX_HOURS * 3600) {
        unset($_SESSION['customer_user_id'], $_SESSION['customer_last'], $_SESSION['customer_since']);
        $_SESSION['flash'] = ['message' => 'You were signed out after a period of inactivity. Please sign in again.', 'type' => 'error'];
        return null;
    }
    $_SESSION['customer_last'] = $now;
    $u = db_one("SELECT u.*, a.name AS account_name, a.account_number FROM customer_users u JOIN accounts a ON a.id = u.account_id
        WHERE u.id = ? AND u.active = 1 AND a.status <> 'churned'", [$id]);
    if (!$u) {
        unset($_SESSION['customer_user_id']);
    }
    return $u;
}

/** Sign in. Returns the user, or throws with a reason the person can act on. */
function customer_login(string $email, string $password): array
{
    $u = db_one('SELECT * FROM customer_users WHERE email = ?', [strtolower(trim($email))]);
    $fail = 'That email and password don\'t match. Check them and try again.';
    if (!$u || !$u['active']) {
        password_verify($password, '$2y$10$abcdefghijklmnopqrstuuJ8P1sQGv4H5nN0QeH7l3JqKXr7o2y6e'); // same time either way
        throw new RuntimeException($fail);
    }
    if ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
        throw new RuntimeException('Too many attempts. Please wait ' . PORTAL_LOCK_MINUTES . ' minutes and try again, or reset your password.');
    }
    if (!$u['password_hash'] || !password_verify($password, $u['password_hash'])) {
        $failed = (int)$u['failed_logins'] + 1;
        db_exec('UPDATE customer_users SET failed_logins = ?, locked_until = ? WHERE id = ?',
            [$failed >= PORTAL_LOCK_AFTER ? 0 : $failed, $failed >= PORTAL_LOCK_AFTER ? date('Y-m-d H:i:s', time() + PORTAL_LOCK_MINUTES * 60) : null, $u['id']]);
        throw new RuntimeException($fail);
    }
    if ($u['must_change_password'] && $u['temp_password_expires_at'] && strtotime($u['temp_password_expires_at']) < time()) {
        throw new RuntimeException('That temporary password has expired. Use "Forgotten your password?" to get a new one.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['customer_user_id'] = (int)$u['id'];
    $_SESSION['customer_since'] = $_SESSION['customer_last'] = time();
    db_exec('UPDATE customer_users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?', [$u['id']]);
    return $u;
}

/** Give a customer user a temporary password (changed at first sign-in) and email it. */
function customer_send_password(int $userId, bool $welcome): array
{
    $u = db_one('SELECT u.*, a.name AS account_name FROM customer_users u JOIN accounts a ON a.id = u.account_id WHERE u.id = ?', [$userId]);
    $pw = temporary_password();
    db_exec('UPDATE customer_users SET password_hash = ?, must_change_password = 1, temp_password_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY), failed_logins = 0, locked_until = NULL WHERE id = ?',
        [password_hash($pw, PASSWORD_DEFAULT), PORTAL_TEMP_PASSWORD_DAYS, $userId]);
    $company = company('name', config('app_name'));
    $first = explode(' ', trim($u['name']))[0] ?: $u['name'];
    $body = '<p>Hi ' . h($first) . ',</p>'
        . ($welcome ? '<p>You can now see <b>' . h($u['account_name']) . '</b>\'s account with ' . h($company) . ' online: your services and their setup details, orders, agreements and support tickets.</p>'
            : '<p>Here\'s a new password for your ' . h($company) . ' account.</p>')
        . '<p style="font-size:15px;line-height:1.7">Email: <b>' . h($u['email']) . '</b><br>Temporary password: <b style="font-family:monospace;font-size:16px">' . h($pw) . '</b></p>'
        . email_button(customer_portal_public_url('login'), 'Sign in')
        . '<p>You\'ll choose your own password when you sign in. The temporary password stops working after ' . PORTAL_TEMP_PASSWORD_DAYS . ' days.</p>'
        . '<p style="color:#667085">If you weren\'t expecting this, you can ignore it.</p>';
    try {
        send_mail($u['email'], $u['name'], $welcome ? "Your online account with $company" : "Your $company account password",
            email_layout($welcome ? 'Welcome' : 'New password', $body));
        return ['emailed' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['emailed' => false, 'error' => $e->getMessage()];
    }
}

/* ----------------------------------------------------------------- Data --- */

/** The customer's services that are worth showing (not ceased), with product and site. */
function customer_services(int $accountId): array
{
    return db_all("SELECT s.*, p.name AS product_name, si.name AS site_name FROM services s LEFT JOIN products p ON p.id = s.product_id
        LEFT JOIN sites si ON si.id = s.site_id WHERE s.account_id = ? AND s.status <> 'ceased' ORDER BY FIELD(s.status, 'active', 'pending', 'suspended'), s.identifier", [$accountId]);
}

/** Broadband orders as the customer sees them: [product, address, label, detail]. */
function customer_broadband_orders(int $accountId): array
{
    $out = [];
    foreach (db_all('SELECT g.*, p.name AS our_product FROM giacom_orders g LEFT JOIN services s ON s.id = g.service_id LEFT JOIN products p ON p.id = s.product_id
            WHERE g.account_id = ? ORDER BY g.id DESC LIMIT 50', [$accountId]) as $g) {
        $appt = (json_decode((string)$g['details'], true) ?: [])['appointment'] ?? null;
        $label = match (true) {
            giacom_is_complete((string)$g['status']) => 'Live',
            giacom_is_cancelled((string)$g['status']) => 'Cancelled',
            default => 'In progress',
        };
        $out[] = [
            'product' => $g['our_product'] ?: 'Broadband',
            'address' => (string)$g['address_label'],
            'label' => $label,
            'detail' => $label === 'In progress'
                ? ($appt ? 'Install ' . fmt_date($appt['date']) . (!empty($appt['slot']) ? ' ' . $appt['slot'] : '') : ($g['crd'] ? 'Expected by ' . fmt_date($g['crd']) : ''))
                : ($g['completed_at'] && $label === 'Live' ? 'Live since ' . fmt_date($g['completed_at']) : ''),
            'placed' => $g['created_at'],
        ];
    }
    return $out;
}

/** Agreements the customer can see (not drafts or failed ones). */
function customer_contracts(int $accountId): array
{
    return db_all("SELECT * FROM contracts WHERE account_id = ? AND status IN ('sent','signed','expired') ORDER BY id DESC", [$accountId]);
}

/* ----------------------------------------------------------- The portal --- */

function customer_page(string $template, array $vars, string $title): never
{
    ob_start();
    try {
        render('customer/' . $template, $vars);
        $content = (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    ob_start();
    render('customer/layout', ['content' => $content, 'title' => $title, 'user' => array_key_exists('user', $vars) ? $vars['user'] : customer_user()]);
    portal_exit((string)ob_get_clean());
}

function customer_redirect(string $go, array $params = []): never
{
    portal_exit('', portal_url($go, $params));
}

/** The customer portal (its subdomain, or account.php). */
function customer_portal_dispatch(): never
{
    $go = (string)($_GET['go'] ?? 'home');
    if ($go === 'asset') {
        portal_send_asset((string)($_GET['f'] ?? ''));
    }
    if (!defined('PORTAL_TESTING')) {
        start_session();
    }
    $user = customer_user();
    $error = null;

    if ($go === 'logout') {
        unset($_SESSION['customer_user_id'], $_SESSION['customer_last'], $_SESSION['customer_since']);
        customer_redirect('login');
    }
    if ($go === 'forgot') {
        $sent = false;
        if (is_post()) {
            verify_csrf();
            $u = db_one('SELECT * FROM customer_users WHERE email = ? AND active = 1', [strtolower(trim((string)($_POST['email'] ?? '')))]);
            if ($u && (!$u['reset_sent_at'] || strtotime($u['reset_sent_at']) < time() - 600)) {
                db_exec('UPDATE customer_users SET reset_sent_at = NOW() WHERE id = ?', [$u['id']]);
                customer_send_password((int)$u['id'], false);
            }
            $sent = true;
        }
        customer_page('forgot', ['sent' => $sent, 'user' => null], 'Forgotten password');
    }
    if (!$user || $go === 'login') {
        if (is_post() && $go === 'login') {
            verify_csrf();
            try {
                customer_login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
            if ($error === null) {
                customer_redirect('home');
            }
        }
        if ($user && !is_post()) {
            customer_redirect('home');
        }
        customer_page('login', ['error' => $error, 'email' => (string)($_POST['email'] ?? ''), 'user' => null], 'Sign in');
    }

    if ($user['must_change_password'] || $go === 'password') {
        if (is_post()) {
            verify_csrf();
            $new = (string)($_POST['new_password'] ?? '');
            if (!$user['must_change_password'] && !password_verify((string)($_POST['current_password'] ?? ''), (string)$user['password_hash'])) {
                $error = 'Your current password isn\'t right.';
            } elseif ($new !== (string)($_POST['confirm_password'] ?? '')) {
                $error = 'The two new passwords don\'t match.';
            } elseif ($problem = password_problem($new, $user['email'])) {
                $error = $problem;
            } else {
                db_exec('UPDATE customer_users SET password_hash = ?, must_change_password = 0, temp_password_expires_at = NULL WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
                flash('Your password has been changed.');
                customer_redirect('home');
            }
        }
        customer_page('password', ['user' => $user, 'error' => $error, 'forced' => (bool)$user['must_change_password']], 'Your password');
    }

    $accountId = (int)$user['account_id'];
    switch ($go) {
        case 'services':
            customer_page('services', ['user' => $user, 'services' => customer_services($accountId)], 'Services');

        case 'orders':
            $orders = db_all('SELECT * FROM customer_orders WHERE account_id = ? ORDER BY id DESC LIMIT 50', [$accountId]);
            customer_page('orders', ['user' => $user, 'orders' => $orders, 'broadband' => customer_broadband_orders($accountId)], 'Orders');

        case 'agreements':
            customer_page('agreements', ['user' => $user, 'contracts' => customer_contracts($accountId)], 'Agreements');

        case 'document':
            // A document from one of the customer's own agreements.
            $c = db_one("SELECT * FROM contracts WHERE id = ? AND account_id = ? AND status IN ('sent','signed','expired')", [(int)($_GET['id'] ?? 0), $accountId]);
            $file = (string)($_GET['file'] ?? '');
            $names = $c ? array_column(contract_documents($c), 'file') : [];
            if ($c && $c['signed_file']) {
                $names[] = $c['signed_file'];
            }
            if (!$c || !in_array($file, $names, true) || !is_file($path = storage_path('contracts') . '/' . basename($file))) {
                customer_not_found('That document wasn\'t found.');
            }
            audit('download', "Customer portal: {$user['name']} downloaded a document from {$c['reference']}", 'contracts', (int)$c['id'], null, null, $accountId);
            $title = array_column(contract_documents($c), 'title', 'file')[$file] ?? 'signed';
            if (defined('PORTAL_TESTING')) {
                throw new PortalExit('FILE:' . basename($path));
            }
            send_download($path, $c['reference'] . ' ' . preg_replace('/[^A-Za-z0-9 _-]/', '', $title) . (str_ends_with($file, '.pdf') ? '.pdf' : '.docx'));
            exit;

        case 'tickets':
            $tickets = db_all('SELECT reference, subject, category, status, created_at, resolved_at FROM tickets WHERE account_id = ? ORDER BY id DESC LIMIT 100', [$accountId]);
            customer_page('tickets', ['user' => $user, 'tickets' => $tickets], 'Support tickets');

        case 'home':
        default:
            $account = db_one('SELECT * FROM accounts WHERE id = ?', [$accountId]);
            $contacts = db_all('SELECT name, job_title, email, phone, mobile FROM contacts WHERE account_id = ? ORDER BY is_primary DESC, name', [$accountId]);
            $sites = db_all('SELECT name, address, city, postcode FROM sites WHERE account_id = ? ORDER BY name', [$accountId]);
            $services = customer_services($accountId);
            $counts = [
                'live' => count(array_filter($services, fn($s) => $s['status'] === 'active')),
                'orders' => (int)db_value("SELECT COUNT(*) FROM customer_orders WHERE account_id = ? AND status NOT IN ('completed','cancelled')", [$accountId])
                    + count(array_filter(customer_broadband_orders($accountId), fn($b) => $b['label'] === 'In progress')),
                'tickets' => (int)db_value("SELECT COUNT(*) FROM tickets WHERE account_id = ? AND status NOT IN ('resolved','closed')", [$accountId]),
                'to_sign' => (int)db_value("SELECT COUNT(*) FROM contracts WHERE account_id = ? AND status = 'sent'", [$accountId]),
            ];
            customer_page('home', compact('user', 'account', 'contacts', 'sites', 'counts'), 'Your account');
    }
}

function customer_not_found(string $message): never
{
    if (!headers_sent()) {
        http_response_code(404);
    }
    customer_page('message', ['title' => 'Not found', 'message' => $message], 'Not found');
}

/* ---------------------------------------------------------- Staff side --- */

/** Staff: give people at a customer portal access, send a new password, or remove access (from the customer's page). */
function customer_portal_users_controller(): void
{
    require_permission('customers.edit');
    if (!is_post()) {
        redirect(url('accounts'));
    }
    verify_csrf();
    $account = db_one('SELECT * FROM accounts WHERE id = ?', [query_int('account_id') ?? 0]) ?? not_found('Customer not found.');
    $back = url('accounts', ['action' => 'view', 'id' => $account['id'], 'tab' => 'customer']) . '#customer-portal';
    $do = query('do');
    if ($do === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Enter a name and a valid email address.', 'error');
            redirect($back);
        }
        if ($other = db_one('SELECT u.id, a.name FROM customer_users u JOIN accounts a ON a.id = u.account_id WHERE u.email = ?', [$email])) {
            flash("$email already has customer portal access (for {$other['name']}).", 'error');
            redirect($back);
        }
        db_exec('INSERT INTO customer_users (account_id, name, email) VALUES (?, ?, ?)', [$account['id'], mb_substr($name, 0, 150), $email]);
        $uid = (int)db()->lastInsertId();
        audit('create', "Customer portal access given to $name <$email>", 'accounts', (int)$account['id'], null, null, (int)$account['id']);
        $r = customer_send_password($uid, true);
        flash($r['emailed'] ? "Portal access added. $name has been emailed a temporary password." : "Portal access added, but the welcome email couldn't be sent: {$r['error']}", $r['emailed'] ? 'success' : 'error');
        redirect($back);
    }
    $u = db_one('SELECT * FROM customer_users WHERE id = ? AND account_id = ?', [query_int('user_id') ?? 0, $account['id']]) ?? not_found('Portal user not found.');
    if ($do === 'reset') {
        $r = customer_send_password((int)$u['id'], $u['last_login_at'] === null);
        audit('update', "Customer portal password reset for {$u['name']} <{$u['email']}>", 'accounts', (int)$account['id'], null, null, (int)$account['id']);
        flash($r['emailed'] ? "A new temporary password has been emailed to {$u['email']}." : "The email couldn't be sent: {$r['error']}", $r['emailed'] ? 'success' : 'error');
    } elseif ($do === 'toggle') {
        db_exec('UPDATE customer_users SET active = ? WHERE id = ?', [$u['active'] ? 0 : 1, $u['id']]);
        audit('update', 'Customer portal access ' . ($u['active'] ? 'removed from' : 'restored for') . " {$u['name']} <{$u['email']}>", 'accounts', (int)$account['id'], null, null, (int)$account['id']);
        flash($u['active'] ? "{$u['name']} can no longer sign in." : "{$u['name']} can sign in again.");
    }
    redirect($back);
}
