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
            $tickets = db_all('SELECT id, reference, subject, category, status, created_at, updated_at, resolved_at FROM tickets WHERE account_id = ? ORDER BY id DESC LIMIT 100', [$accountId]);
            customer_page('tickets', ['user' => $user, 'tickets' => $tickets], 'Support tickets');

        case 'ticket_new':
            $v = ['category' => 'fault', 'service_id' => (string)($_GET['service'] ?? ''), 'urgency' => 'normal', 'subject' => '', 'description' => ''];
            $errors = [];
            if (is_post()) {
                verify_csrf();
                foreach ($v as $k => $_) {
                    $v[$k] = trim((string)($_POST[$k] ?? ''));
                }
                if (!isset(CUSTOMER_TICKET_CATEGORIES[$v['category']])) {
                    $errors['category'] = 'Choose what it\'s about.';
                }
                if ($v['subject'] === '') {
                    $errors['subject'] = 'Give it a short summary.';
                }
                if (mb_strlen($v['description']) < 10) {
                    $errors['description'] = 'Please tell us a bit more.';
                }
                if (!$errors) {
                    $t = customer_raise_ticket($user, $v);
                    flash("Thank you. Your request is logged as {$t['reference']} and we'll be in touch.");
                    customer_redirect('ticket', ['id' => $t['id']]);
                }
            }
            customer_page('ticket_form', ['user' => $user, 'v' => $v, 'errors' => $errors, 'services' => customer_services($accountId)], 'Raise a support ticket');

        case 'ticket':
            $t = db_one('SELECT t.*, s.identifier AS service_identifier FROM tickets t LEFT JOIN services s ON s.id = t.service_id WHERE t.id = ? AND t.account_id = ?',
                [(int)($_GET['id'] ?? 0), $accountId]) ?? customer_not_found('That ticket wasn\'t found.');
            if (is_post()) {
                verify_csrf();
                $body = trim((string)($_POST['body'] ?? ''));
                if ($body === '') {
                    flash('Type your update first.', 'error');
                } else {
                    customer_ticket_reply($user, $t, $body);
                    flash('Thanks, your update has been added.');
                }
                customer_redirect('ticket', ['id' => $t['id']]);
            }
            $updates = db_all('SELECT c.body, c.created_at, c.customer_user_id, u.name AS staff_name, cu.name AS customer_name FROM ticket_comments c
                LEFT JOIN users u ON u.id = c.user_id LEFT JOIN customer_users cu ON cu.id = c.customer_user_id
                WHERE c.ticket_id = ? AND c.is_internal = 0 ORDER BY c.created_at, c.id', [$t['id']]);
            customer_page('ticket', ['user' => $user, 't' => $t, 'updates' => $updates], $t['reference']);

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

/* -------------------------------------------------------------- Tickets --- */

const CUSTOMER_TICKET_CATEGORIES = ['fault' => 'Fault or problem', 'billing' => 'Billing', 'order' => 'An order', 'porting' => 'Moving a number to us', 'general' => 'Something else'];
const CUSTOMER_TICKET_STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'awaiting_customer' => 'Waiting for you', 'awaiting_carrier' => 'With the network', 'resolved' => 'Resolved', 'closed' => 'Closed'];

/** A customer raises a ticket: routed like any other (by category to its group), the team told, the customer emailed the reference. */
function customer_raise_ticket(array $user, array $v): array
{
    $service = $v['service_id'] !== '' ? db_one("SELECT id FROM services WHERE id = ? AND account_id = ?", [(int)$v['service_id'], $user['account_id']]) : null;
    $contact = db_one('SELECT id FROM contacts WHERE account_id = ? AND email = ? LIMIT 1', [$user['account_id'], $user['email']]);
    $id = insert_row('tickets', [
        'account_id' => $user['account_id'], 'service_id' => $service['id'] ?? null, 'contact_id' => $contact['id'] ?? null,
        'subject' => mb_substr($v['subject'], 0, 200), 'category' => $v['category'], 'priority' => $v['urgency'] === 'down' ? 'P2' : 'P3',
        'status' => 'open', 'group_id' => null, 'assigned_to' => null, 'carrier_ref' => null,
        'description' => $v['description'] . "\n\n(Raised on the customer portal by {$user['name']} <{$user['email']}>)",
    ]);
    db_exec('UPDATE tickets SET raised_by_customer_user_id = ? WHERE id = ?', [$user['id'], $id]);
    $t = db_one('SELECT * FROM tickets WHERE id = ?', [$id]);
    audit('create', "Ticket {$t['reference']} raised on the customer portal by {$user['name']}: {$t['subject']}", 'tickets', $id, null, null, (int)$user['account_id']);
    log_activity((int)$user['account_id'], 'task', "Ticket {$t['reference']} raised on the customer portal: {$t['subject']}");
    if ($t['group_id']) {
        ticket_notify_group($id);
    } else {
        customer_ticket_notify_staff($t, 'New ticket from the customer portal', 'has been raised on the customer portal');
    }
    customer_ticket_email_customer($user, $t, 'We\'ve received your support request', '<p>Thank you, we\'ve logged your request as <b>' . h($t['reference']) . '</b> and will be in touch.</p>');
    return $t;
}

/** Email whoever has a ticket (or, if nobody, its group or the company inbox) about something the customer did. */
function customer_ticket_notify_staff(array $t, string $title, string $what): void
{
    if (!mail_configured()) {
        return;
    }
    $to = [];
    if ($t['assigned_to'] && ($u = db_one('SELECT name, email FROM users WHERE id = ? AND active = 1', [$t['assigned_to']]))) {
        $to[] = $u;
    } elseif ($t['group_id'] && ($g = db_one('SELECT name, email FROM ticket_groups WHERE id = ?', [$t['group_id']]))) {
        $to = $g['email'] ? [$g] : db_all('SELECT u.name, u.email FROM ticket_group_members m JOIN users u ON u.id = m.user_id WHERE m.group_id = ? AND u.active = 1', [$t['group_id']]);
    }
    if (!$to && ($ours = setting('company_email') ?: setting('mail_from_email'))) {
        $to = [['email' => $ours, 'name' => company('name', config('app_name'))]];
    }
    $account = (string)db_value('SELECT name FROM accounts WHERE id = ?', [$t['account_id']]);
    $body = '<p><b>' . h($t['reference']) . '</b> for ' . h($account) . ' ' . h($what) . ': ' . h($t['subject']) . '.</p>'
        . email_button(app_url() . '/' . url('tickets', ['action' => 'view', 'id' => $t['id']]), 'Open the ticket');
    foreach ($to as $u) {
        try {
            send_mail($u['email'], $u['name'], "{$t['reference']}: $title", email_layout($title, $body));
        } catch (IntegrationException $e) {
            error_log('Ticket email failed: ' . $e->getMessage());
        }
    }
}

/** Email the customer user about their ticket, with a link to it on the portal. */
function customer_ticket_email_customer(array $user, array $t, string $title, string $html): void
{
    if (!mail_configured()) {
        return;
    }
    try {
        send_mail($user['email'], $user['name'], "{$t['reference']}: " . $t['subject'], email_layout($title, $html
            . email_button(customer_portal_public_url('ticket', ['id' => $t['id']]), 'View your ticket')));
    } catch (IntegrationException $e) {
        error_log('Ticket email failed: ' . $e->getMessage());
    }
}

/** Staff added a customer-facing update: if the ticket was raised on the portal, email the person who raised it. */
function customer_ticket_staff_update(int $ticketId, string $body): void
{
    $t = db_one('SELECT * FROM tickets WHERE id = ?', [$ticketId]);
    if (!$t || empty($t['raised_by_customer_user_id']) || !($cu = db_one('SELECT * FROM customer_users WHERE id = ? AND active = 1', [$t['raised_by_customer_user_id']]))) {
        return;
    }
    customer_ticket_email_customer($cu, $t, 'An update on your support request', '<p>There\'s an update on <b>' . h($t['reference']) . '</b> (' . h($t['subject']) . '):</p>'
        . '<blockquote style="margin:12px 0;padding:8px 12px;border-left:3px solid #465fff;background:#f9fafb">' . nl2br(h($body)) . '</blockquote>'
        . '<p>Status: <b>' . h(CUSTOMER_TICKET_STATUSES[$t['status']] ?? humanize($t['status'])) . '</b></p>');
}

/** The customer adds an update to their ticket. A ticket waiting for them, or closed, opens again. */
function customer_ticket_reply(array $user, array $t, string $body): void
{
    db_exec('INSERT INTO ticket_comments (ticket_id, user_id, customer_user_id, body, is_internal) VALUES (?, NULL, ?, ?, 0)', [$t['id'], $user['id'], mb_substr($body, 0, 5000)]);
    if (in_array($t['status'], ['awaiting_customer', 'resolved', 'closed'], true)) {
        $data = array_intersect_key($t, array_flip(['account_id', 'service_id', 'contact_id', 'subject', 'category', 'priority', 'group_id', 'assigned_to', 'carrier_ref', 'description']));
        update_row('tickets', (int)$t['id'], ['status' => 'open'] + $data);
        db_exec('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal) VALUES (?, NULL, ?, 1)',
            [$t['id'], 'Reopened by the customer\'s update (was ' . humanize($t['status']) . ').']);
    } else {
        db_exec('UPDATE tickets SET updated_at = NOW() WHERE id = ?', [$t['id']]);
    }
    audit('ticket_update', "Ticket {$t['reference']}: customer update from {$user['name']} on the customer portal", 'tickets', (int)$t['id'], null, null, (int)$t['account_id']);
    customer_ticket_notify_staff(db_one('SELECT * FROM tickets WHERE id = ?', [$t['id']]), 'Customer update', 'has a new update from ' . $user['name']);
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
