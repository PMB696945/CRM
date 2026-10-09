<?php
declare(strict_types=1);

require_once __DIR__ . '/controllers_sales.php';

/** Only allow redirects back into the app. */
function safe_return(?string $to, string $fallback): string
{
    return (is_string($to) && preg_match('/^index\.php\?[^\s]*$/', $to)) ? $to : $fallback;
}

function login_controller(): void
{
    if (current_user()) {
        redirect(url('dashboard'));
    }
    $error = null;
    $email = '';
    $step = isset($_SESSION['pending_2fa']) ? '2fa' : 'password';
    if (query('restart') === '1') {
        unset($_SESSION['pending_2fa']);
        $step = 'password';
    }
    if (is_post()) {
        verify_csrf();
        if (($_POST['step'] ?? '') === '2fa') {
            $result = verify_second_factor((string)($_POST['code'] ?? ''));
        } else {
            $email = (string)($_POST['email'] ?? '');
            $result = attempt_login($email, (string)($_POST['password'] ?? ''));
        }
        switch ($result['status']) {
            case 'ok':
                redirect(current_user(true)['must_change_password'] ? url('password') : (must_set_up_2fa() ? url('profile') : url('dashboard')));
            case 'ip':
                $error = 'The CRM can only be used from approved locations, and this isn\'t one of them. Ask your administrator if you need access from here.';
                break;
            case 'temp_expired':
                $error = 'Your temporary password has expired. Ask your administrator to send you a new one.';
                break;
            case '2fa':
                $step = '2fa';
                break;
            case 'locked':
                $error = "Too many unsuccessful attempts. For your security, sign-in is paused. Try again in {$result['minutes']} minute" . ($result['minutes'] === 1 ? '' : 's') . '.';
                break;
            case 'expired':
                $step = 'password';
                $error = 'That took too long. Please sign in again.';
                break;
            default:
                $error = $step === '2fa' ? 'That code didn\'t work. Check your authenticator app and try again.' : 'Incorrect email or password.';
        }
    }
    render('login', ['error' => $error, 'email' => $email, 'step' => $step]);
}

/** Choose a new password: required after signing in with a temporary one, and available any time. */
function password_controller(): void
{
    $user = current_user();
    $forced = !empty($user['must_change_password']);
    $error = null;
    if (is_post()) {
        verify_csrf();
        $new = (string)($_POST['new_password'] ?? '');
        $row = db_one('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        if (!$forced && !password_verify((string)($_POST['current_password'] ?? ''), (string)$row['password_hash'])) {
            $error = 'Your current password isn\'t right.';
        } elseif ($new !== (string)($_POST['confirm_password'] ?? '')) {
            $error = 'The two new passwords don\'t match.';
        } elseif (password_verify($new, (string)$row['password_hash'])) {
            $error = 'Choose a different password from the temporary one.';
        } elseif ($problem = password_problem($new, $user['email'])) {
            $error = $problem;
        } else {
            db_exec('UPDATE users SET password_hash = ?, must_change_password = 0, temp_password_expires_at = NULL WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            audit('password_change', $forced ? 'Chose a password after signing in with a temporary one' : 'Changed password', 'users', (int)$user['id']);
            flash('Your password has been set.');
            redirect(must_set_up_2fa() ? url('profile') : url('dashboard'));
        }
    }
    page('password', ['forced' => $forced, 'error' => $error], 'Choose a password');
}

function dashboard_controller(): void
{
    $window = (int)config('renewal_window_days');
    $stats = [
        'customers' => (int)db_value("SELECT COUNT(*) FROM accounts WHERE status = 'active' AND is_customer = 1"),
        'prospects' => (int)db_value("SELECT COUNT(*) FROM accounts WHERE status = 'prospect'"),
        'mrr'       => can('revenue.view') ? (float)db_value("SELECT COALESCE(SUM(monthly_price),0) FROM services WHERE status = 'active'") : null,
        'lines'     => (int)db_value("SELECT COUNT(*) FROM services WHERE status = 'active'"),
        'open_tickets' => (int)db_value("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('resolved','closed')"),
        'breached'  => (int)db_value("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('resolved','closed') AND sla_due_at < NOW()"),
        'pipeline'  => (float)db_value("SELECT COALESCE(SUM(monthly_value),0) FROM opportunities WHERE stage NOT IN ('won','lost')"),
        'weighted'  => (float)db_value("SELECT COALESCE(SUM(monthly_value * probability / 100),0) FROM opportunities WHERE stage NOT IN ('won','lost')"),
        'expiring'  => (int)db_value("SELECT COUNT(*) FROM services WHERE status = 'active' AND contract_end_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)", [$window]),
        'won_mrr_month' => (float)db_value("SELECT COALESCE(SUM(monthly_value),0) FROM opportunities WHERE stage = 'won' AND updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
    ];

    $mrrByType = db_all("SELECT service_type, COUNT(*) AS n, SUM(monthly_price) AS mrr FROM services
        WHERE status = 'active' GROUP BY service_type ORDER BY mrr DESC");

    $renewals = db_all("SELECT a.id AS account_id, a.name, COUNT(*) AS services, SUM(s.monthly_price) AS mrr,
            MIN(s.contract_end_date) AS first_end,
            (SELECT COUNT(*) FROM opportunities o WHERE o.account_id = a.id AND o.opp_type = 'renewal' AND o.stage NOT IN ('won','lost')) AS open_renewals
        FROM services s JOIN accounts a ON a.id = s.account_id
        WHERE s.status = 'active' AND s.contract_end_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
        GROUP BY a.id, a.name ORDER BY first_end LIMIT 10", [$window]);

    $tickets = list_rows('tickets', ['preset' => 'open', 'per_page' => 8])['rows'];

    $tasks = db_all("SELECT t.*, a.name AS account_name FROM activities t JOIN accounts a ON a.id = t.account_id
        WHERE t.type = 'task' AND t.done = 0 AND (t.user_id = ? OR t.user_id IS NULL)
        ORDER BY (t.due_date IS NULL), t.due_date LIMIT 8", [current_user()['id']]);

    $recent = db_all("SELECT t.*, a.name AS account_name, u.name AS user_name FROM activities t
        JOIN accounts a ON a.id = t.account_id LEFT JOIN users u ON u.id = t.user_id
        ORDER BY t.created_at DESC LIMIT 8");

    $debtors = [];
    if (xero_connected() && can('finance.view')) {
        $stats['xero_overdue'] = (float)db_value('SELECT COALESCE(SUM(overdue),0) FROM xero_contacts');
        $stats['xero_outstanding'] = (float)db_value('SELECT COALESCE(SUM(GREATEST(outstanding,0)),0) FROM xero_contacts');
        $stats['xero_debtors'] = (int)db_value('SELECT COUNT(*) FROM xero_contacts WHERE overdue > 0');
        $debtors = db_all('SELECT a.id, a.name, a.status, a.credit_limit, x.outstanding, x.overdue, x.oldest_due_date
            FROM accounts a JOIN xero_contacts x ON x.id = a.xero_contact_id
            WHERE x.overdue > 0 ORDER BY x.overdue DESC LIMIT 8');
    }

    if (gc_configured() && can('finance.view')) {
        $stats['no_dd'] = list_rows('accounts', ['preset' => 'no_dd', 'per_page' => 1])['total'];
        $stats['dd_pending'] = (int)db_value("SELECT COUNT(*) FROM accounts a JOIN gocardless_customers g ON g.id = a.gocardless_customer_id
            WHERE a.status = 'active' AND g.mandate_status IN ('submitted','pending_submission','pending_customer_approval')");
    }

    page('dashboard', compact('stats', 'mrrByType', 'renewals', 'tickets', 'tasks', 'recent', 'window', 'debtors'), 'Dashboard');
}

function pipeline_controller(): void
{
    $rows = list_rows('opportunities', ['per_page' => 0, 'sort' => 'expected_close'])['rows'];
    $owner = query_int('owner_id');
    $columns = array_fill_keys(OPP_STAGES, []);
    foreach ($rows as $row) {
        if ($owner && (int)$row['owner_id'] !== $owner) {
            continue;
        }
        // Only show won/lost from the last 90 days to keep the board focused.
        if (in_array($row['stage'], ['won', 'lost'], true) && strtotime($row['updated_at']) < strtotime('-90 days')) {
            continue;
        }
        $columns[$row['stage']][] = $row;
    }
    page('pipeline', ['columns' => $columns, 'owner' => $owner, 'users' => ref_options('users')], 'Sales pipeline');
}

function search_controller(): void
{
    $q = query('q');
    $results = [];
    if ($q !== '') {
        foreach (array_merge(['accounts', 'contacts', 'sites', 'services', 'tickets', 'opportunities'], can('suppliers.view') ? ['suppliers', 'purchase_orders'] : []) as $name) {
            $found = list_rows($name, ['q' => $q, 'per_page' => 10]);
            if ($found['total'] > 0) {
                $results[$name] = $found;
            }
        }
    }
    page('search', ['q' => $q, 'results' => $results], 'Search');
}

/** JSON endpoint: services/contacts for a customer (dependent dropdowns). */
function refs_controller(): void
{
    $ref = query('ref');
    $accountId = query_int('account_id');
    header('Content-Type: application/json');
    if (!in_array($ref, ['services', 'contacts', 'opportunities', 'sites'], true) || !$accountId) {
        echo json_encode([]);
        return;
    }
    $out = [];
    foreach (ref_options($ref, $accountId) as $id => $label) {
        $out[] = ['id' => $id, 'label' => $label];
    }
    echo json_encode($out);
}

/** Address lookup for customer and site forms: the addresses Giacom knows at a postcode, as JSON. */
function address_lookup_controller(): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    if (!can('customers.edit')) {
        http_response_code(403);
        echo json_encode(['error' => 'You can\'t add or edit customers.']);
        return;
    }
    if (!giacom_configured()) {
        echo json_encode(['error' => 'Address lookup uses Giacom: add your Giacom details under Admin → Giacom.']);
        return;
    }
    try {
        $out = [];
        foreach (giacom_address_search((string)query('postcode'), trim((string)query('building'))) as $a) {
            $out[] = ['label' => $a['label'], 'fields' => giacom_address_fields($a)];
        }
        echo json_encode($out ? ['addresses' => $out] : ['error' => 'No addresses found at that postcode.']);
    } catch (IntegrationException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function users_controller(): void
{
    require_permission('users.manage');
    $action = query('action', 'list');
    $id = query_int('id');
    $errors = [];
    $values = ['name' => '', 'email' => '', 'role' => 'staff', 'active' => 1, 'ip_anywhere' => 0];

    if ($id) {
        $values = db_one('SELECT id, name, email, role, active, ip_anywhere, must_change_password FROM users WHERE id = ?', [$id]) ?? not_found();
        // Only super admins can change super admins.
        if ($values['role'] === 'super_admin' && !is_super_admin() && $action !== 'list') {
            forbidden();
        }
    }

    if (in_array($action, ['new', 'edit'], true)) {
        if (is_post()) {
            verify_csrf();
            $values = [
                'name'   => trim((string)($_POST['name'] ?? '')),
                'email'  => strtolower(trim((string)($_POST['email'] ?? ''))),
                'role'   => isset(roles()[$_POST['role'] ?? '']) ? $_POST['role'] : 'staff',
                'active' => empty($_POST['active']) ? 0 : 1,
                'ip_anywhere' => empty($_POST['ip_anywhere']) ? 0 : 1,
            ];
            $password = (string)($_POST['password'] ?? '');
            // New users get a temporary password by email unless one is typed in.
            $welcome = !$id && !empty($_POST['send_welcome']);
            if ($welcome) {
                $password = '';
            }
            if ($values['name'] === '') {
                $errors['name'] = 'Name is required.';
            }
            if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'A valid email is required.';
            } elseif (db_value('SELECT id FROM users WHERE email = ? AND id <> ?', [$values['email'], $id ?? 0])) {
                $errors['email'] = 'That email is already in use.';
            }
            if ((!$id && !$welcome || $password !== '') && ($problem = password_problem($password, $values['email']))) {
                $errors['password'] = $problem;
            }
            if ($values['role'] === 'super_admin' && !is_super_admin()) {
                $errors['role'] = 'Only a super admin can create another super admin.';
            }
            if ($id === (int)current_user()['id'] && ($values['role'] !== current_user()['role'] || !$values['active'])) {
                $errors['role'] = 'You cannot change your own role or disable yourself. Ask another super admin.';
            }
            $before = $id ? db_one('SELECT name, email, role, active FROM users WHERE id = ?', [$id]) : null;
            if (!$errors) {
                if ($id) {
                    db_exec('UPDATE users SET name = ?, email = ?, role = ?, active = ?, ip_anywhere = ? WHERE id = ?',
                        [$values['name'], $values['email'], $values['role'], $values['active'], $values['ip_anywhere'], $id]);
                    if ($password !== '') {
                        // A password set for someone else is temporary unless they're told otherwise.
                        $mustChange = $id !== (int)current_user()['id'] && !empty($_POST['must_change']);
                        db_exec('UPDATE users SET password_hash = ?, must_change_password = ?, temp_password_expires_at = ' . ($mustChange ? 'DATE_ADD(NOW(), INTERVAL ' . TEMP_PASSWORD_DAYS . ' DAY)' : 'NULL') . ' WHERE id = ?',
                            [password_hash($password, PASSWORD_DEFAULT), $mustChange ? 1 : 0, $id]);
                    }
                } else {
                    $mustChange = !$welcome && !empty($_POST['must_change']);
                    db_exec('INSERT INTO users (name, email, role, active, ip_anywhere, password_hash, must_change_password, temp_password_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ' . ($mustChange ? 'DATE_ADD(NOW(), INTERVAL ' . TEMP_PASSWORD_DAYS . ' DAY)' : 'NULL') . ')',
                        [$values['name'], $values['email'], $values['role'], $values['active'], $values['ip_anywhere'],
                            password_hash($welcome ? bin2hex(random_bytes(16)) : $password, PASSWORD_DEFAULT), $mustChange ? 1 : 0]);
                    $id = null;
                }
                $savedUserId = $id ?: (int)db()->lastInsertId();
                // Ticket groups this person works in.
                $beforeGroups = user_group_ids($savedUserId, true);
                $newGroups = array_values(array_intersect(array_map('intval', array_column(ticket_groups(), 'id')), array_map('intval', is_array($_POST['groups'] ?? null) ? $_POST['groups'] : [])));
                db_exec('DELETE FROM ticket_group_members WHERE user_id = ?', [$savedUserId]);
                foreach ($newGroups as $gid) {
                    db_exec('INSERT INTO ticket_group_members (group_id, user_id) VALUES (?, ?)', [$gid, $savedUserId]);
                }
                $userChanges = [];
                foreach (['name', 'email', 'role', 'active', 'ip_anywhere'] as $f) {
                    if ((string)($before[$f] ?? '') !== (string)$values[$f]) {
                        $userChanges[$f] = ['from' => (string)($before[$f] ?? ''), 'to' => (string)$values[$f]];
                    }
                }
                if ($password !== '') {
                    $userChanges['password'] = ['from' => '', 'to' => '(set)'];
                }
                sort($beforeGroups);
                sort($newGroups);
                if ($beforeGroups !== $newGroups) {
                    $gn = array_column(ticket_groups(), 'name', 'id');
                    $userChanges['ticket groups'] = ['from' => implode(', ', array_map(fn($g) => $gn[$g] ?? $g, $beforeGroups)), 'to' => implode(', ', array_map(fn($g) => $gn[$g] ?? $g, $newGroups))];
                }
                audit($id ? 'user_update' : 'user_create', 'User ' . $values['email'] . ($id ? ' updated' : ' created') . ' (' . role_label($values['role']) . ', ' . ($values['active'] ? 'active' : 'disabled') . ')' . ($password !== '' ? ', password set' : ''),
                    'users', $savedUserId, null, $userChanges ?: null);
                if ($welcome) {
                    $sent = send_temporary_password($savedUserId, true);
                    audit('user_welcome', 'Welcome email with a temporary password ' . ($sent['emailed'] ? 'sent to ' : 'could not be sent to ') . $values['email'], 'users', $savedUserId);
                    flash($sent['emailed']
                        ? "User added. A welcome email with a temporary password has been sent to {$values['email']}."
                        : "User added, but the welcome email couldn't be sent ({$sent['error']}). Their temporary password is {$sent['password']}. Pass it on securely: it isn't shown again.",
                        $sent['emailed'] ? 'success' : 'warning');
                } else {
                    flash('User saved.');
                }
                redirect(url('users'));
            }
        }
        $groupIds = is_post() ? array_map('intval', is_array($_POST['groups'] ?? null) ? $_POST['groups'] : []) : ($id ? user_group_ids($id, true) : []);
        page('user_form', ['values' => $values, 'errors' => $errors, 'id' => $id, 'groups' => ticket_groups(), 'groupIds' => $groupIds], $id ? 'Edit user' : 'New user');
        return;
    }

    if (in_array($action, ['disable', 'enable', 'delete'], true) && is_post() && $id) {
        verify_csrf();
        if ($id === (int)current_user()['id']) {
            flash('You can\'t ' . $action . ' your own account. Ask another admin.', 'error');
            redirect(url('users'));
        }
        if ($action === 'enable') {
            db_exec('UPDATE users SET active = 1 WHERE id = ?', [$id]);
            audit('user_update', 'User ' . $values['email'] . ' enabled', 'users', $id, null, ['active' => ['from' => '0', 'to' => '1']]);
            flash($values['name'] . ' can sign in again.');
            redirect(url('users'));
        }
        if ($action === 'delete') {
            // Only for accounts never used: anyone who has signed in keeps their name on their history (disable them instead).
            if (db_value('SELECT last_login_at FROM users WHERE id = ?', [$id])) {
                flash($values['name'] . ' has used the CRM, so they can be disabled but not deleted. That keeps their name on the work they did.', 'error');
                redirect(url('users'));
            }
            db_exec('DELETE FROM users WHERE id = ?', [$id]);
            audit('user_delete', 'User ' . $values['email'] . ' (' . $values['name'] . ') deleted', 'users', $id);
            flash($values['name'] . ' has been deleted.');
            redirect(url('users'));
        }
        // Disable: signed out at once (sessions check the account on every page), and optionally hand their work over.
        db_exec('UPDATE users SET active = 0 WHERE id = ?', [$id]);
        $to = (int)($_POST['reassign_to'] ?? 0);
        $moved = [];
        if ($to && $to !== $id && db_value('SELECT 1 FROM users WHERE id = ? AND active = 1', [$to])) {
            foreach ([
                'open tickets' => "UPDATE tickets SET assigned_to = ? WHERE assigned_to = ? AND status NOT IN ('resolved','closed')",
                'customers' => 'UPDATE accounts SET owner_id = ? WHERE owner_id = ?',
                'open opportunities' => "UPDATE opportunities SET owner_id = ? WHERE owner_id = ? AND stage NOT IN ('won','lost')",
                'open orders' => "UPDATE customer_orders SET assigned_to = ? WHERE assigned_to = ? AND status NOT IN ('completed','cancelled')",
            ] as $what => $sql) {
                $n = db()->prepare($sql);
                $n->execute([$to, $id]);
                if ($n->rowCount()) {
                    $moved[] = $n->rowCount() . ' ' . $what;
                }
            }
        }
        $toName = $to ? (string)db_value('SELECT name FROM users WHERE id = ?', [$to]) : '';
        audit('user_update', 'User ' . $values['email'] . ' disabled' . ($moved ? '; ' . implode(', ', $moved) . " passed to $toName" : ''), 'users', $id, null, ['active' => ['from' => '1', 'to' => '0']]);
        flash($values['name'] . ' has been disabled and can no longer sign in.' . ($moved ? ' ' . ucfirst(implode(', ', $moved)) . " passed to $toName." : ''));
        redirect(url('users'));
    }

    if ($action === 'send_password' && is_post() && $id) {
        verify_csrf();
        if ($id === (int)current_user()['id']) {
            flash('Change your own password from your profile.', 'error');
            redirect(url('users'));
        }
        $sent = send_temporary_password($id, !db_value('SELECT last_login_at FROM users WHERE id = ?', [$id]));
        audit('user_password_reset', 'New temporary password ' . ($sent['emailed'] ? 'emailed to ' : 'set (email failed) for ') . $values['email'], 'users', $id);
        flash($sent['emailed']
            ? "A new temporary password has been emailed to {$values['email']}. They'll choose their own when they sign in."
            : "The email couldn't be sent ({$sent['error']}). Their new temporary password is {$sent['password']}. Pass it on securely: it isn't shown again.",
            $sent['emailed'] ? 'success' : 'warning');
        redirect(url('users'));
    }

    if ($action === 'reset_2fa' && is_post() && $id) {
        verify_csrf();
        if ($values['role'] === 'super_admin' && !is_super_admin()) {
            forbidden();
        }
        db_exec('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0, recovery_codes = NULL WHERE id = ?', [$id]);
        audit('2fa_reset', 'Two-factor sign-in reset for ' . $values['email'], 'users', $id);
        flash('Two-factor sign-in reset for ' . $values['name'] . '. They can set it up again from their profile.');
        redirect(url('users'));
    }
    // Disabled users are kept (their names stay on their history) but listed separately.
    $showDisabled = query('show') === 'disabled';
    $users = db_all('SELECT id, name, email, role, active, created_at, totp_enabled, last_login_at, must_change_password, temp_password_expires_at, ip_anywhere FROM users WHERE active = ? ORDER BY name', [$showDisabled ? 0 : 1]);
    $disabledCount = (int)db_value('SELECT COUNT(*) FROM users WHERE active = 0');
    page('users', ['users' => $users, 'showDisabled' => $showDisabled, 'disabledCount' => $disabledCount], 'Users');
}

function profile_controller(): void
{
    $user = current_user();
    $row = db_one('SELECT password_hash, totp_enabled, recovery_codes, last_login_at FROM users WHERE id = ?', [$user['id']]);
    $errors = [];
    $action = query('action');
    $passwordOk = fn() => password_verify((string)($_POST['current_password'] ?? ''), (string)$row['password_hash']);

    if (is_post()) {
        verify_csrf();
        switch ($action) {
            case '2fa_start':
                $_SESSION['pending_totp_secret'] = base32_encode(random_bytes(20));
                redirect(url('profile'));
            case '2fa_cancel':
                unset($_SESSION['pending_totp_secret']);
                redirect(url('profile'));
            case '2fa_confirm':
                $secret = $_SESSION['pending_totp_secret'] ?? null;
                $step = $secret ? totp_verify($secret, (string)($_POST['code'] ?? '')) : null;
                if ($step === null) {
                    $errors['code'] = 'That code didn\'t match. Check the time on your phone is correct and try the newest code.';
                    break;
                }
                [$codes, $hashes] = make_recovery_codes();
                db_exec('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ?, recovery_codes = ? WHERE id = ?',
                    [encrypt_secret($secret), $step, $hashes, $user['id']]);
                unset($_SESSION['pending_totp_secret']);
                $_SESSION['show_recovery_codes'] = $codes;
                audit('2fa_enabled', 'Turned on two-factor sign-in', 'users', (int)$user['id']);
                flash('Two-factor sign-in is on. Save your recovery codes now.');
                redirect(url('profile'));
            case '2fa_codes':
                if (!$passwordOk()) {
                    $errors['current_password_codes'] = 'Current password is incorrect.';
                    break;
                }
                [$codes, $hashes] = make_recovery_codes();
                db_exec('UPDATE users SET recovery_codes = ? WHERE id = ?', [$hashes, $user['id']]);
                $_SESSION['show_recovery_codes'] = $codes;
                audit('2fa_codes', 'Generated new recovery codes', 'users', (int)$user['id']);
                flash('New recovery codes created. The old ones no longer work.');
                redirect(url('profile'));
            case '2fa_disable':
                if (setting('require_2fa') === '1') {
                    $errors['current_password_disable'] = 'Your administrator requires two-factor sign-in, so it can\'t be turned off.';
                    break;
                }
                if (!$passwordOk()) {
                    $errors['current_password_disable'] = 'Current password is incorrect.';
                    break;
                }
                db_exec('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_last_step = 0, recovery_codes = NULL WHERE id = ?', [$user['id']]);
                audit('2fa_disabled', 'Turned off two-factor sign-in', 'users', (int)$user['id']);
                flash('Two-factor sign-in is off.');
                redirect(url('profile'));
            default: // change password
                $new = (string)($_POST['new_password'] ?? '');
                if (!$passwordOk()) {
                    $errors['current_password'] = 'Current password is incorrect.';
                }
                if ($problem = password_problem($new, $user['email'])) {
                    $errors['new_password'] = $problem;
                } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
                    $errors['confirm_password'] = 'Passwords do not match.';
                }
                if (!$errors) {
                    db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
                    session_regenerate_id(true);
                    audit('password_change', 'Changed their password', 'users', (int)$user['id']);
                    flash('Password changed.');
                    redirect(url('profile'));
                }
        }
    }
    $recoveryCodes = $_SESSION['show_recovery_codes'] ?? null;
    unset($_SESSION['show_recovery_codes']);
    $pendingSecret = $_SESSION['pending_totp_secret'] ?? null;
    page('profile', [
        'user' => $user, 'errors' => $errors, 'row' => $row, 'recoveryCodes' => $recoveryCodes, 'pendingSecret' => $pendingSecret,
        'otpUri' => $pendingSecret ? totp_uri($pendingSecret, $user['email']) : null,
        'codesLeft' => count(json_decode((string)$row['recovery_codes'], true) ?: []),
    ], 'My profile');
}

/** Why a new password isn't acceptable, or null. */
function password_problem(string $password, string $email = ''): ?string
{
    if (strlen($password) < 10) {
        return 'Use at least 10 characters. A few random words works well.';
    }
    $lower = strtolower($password);
    $common = ['password', 'qwerty', '123456', 'letmein', 'welcome', 'admin', 'changeme', 'iloveyou', 'monkey', 'dragon', 'football', 'passw0rd'];
    foreach ($common as $c) {
        if (str_contains($lower, $c) && strlen($password) < 16) {
            return 'That password is too easy to guess. Avoid common words like "' . $c . '".';
        }
    }
    if ($email !== '' && str_contains($lower, strtolower(strtok($email, '@')))) {
        return 'Don\'t include your email name in your password.';
    }
    if (count(array_unique(str_split($password))) < 5) {
        return 'That password has too many repeated characters.';
    }
    return null;
}

/** Generic CRUD for everything in entities(). */
function entity_controller(string $name): void
{
    $entity = entity($name);
    $action = query('action', 'list');
    $id = query_int('id');
    $canWrite = empty($entity['perm']) || (bool)array_filter((array)$entity['perm'], 'can');
    if (!empty($entity['view_perm']) && !can($entity['view_perm']) && !$canWrite) {
        forbidden();
    }

    switch ($action) {
        case 'list':
        case 'export':
            $opts = [
                'q'       => query('q'),
                'preset'  => query('preset'),
                'sort'    => query('sort'),
                'dir'     => query('dir'),
                'page'    => query_int('p') ?? 1,
                'filters' => [],
            ];
            foreach ($entity['filters'] ?? [] as $f) {
                $opts['filters'][$f] = query($f);
            }
            if ($action === 'export') {
                require_permission('export');
                export_csv($name, $entity, $opts);
                return;
            }
            $result = list_rows($name, $opts);
            page('list', compact('name', 'entity', 'opts', 'result', 'canWrite'), $entity['plural']);
            return;

        case 'view':
            $row = $id ? find($name, $id) : null;
            if (!$row) {
                not_found($entity['label'] . ' not found.');
            }
            if ($name === 'tickets' && !can_see_ticket($row)) {
                forbidden();
            }
            if ($name === 'accounts') {
                account_view($entity, $row);
            } elseif ($name === 'tickets') {
                ticket_view($entity, $row);
            } elseif ($name === 'suppliers') {
                if ($row['account_id'] && !query('standalone')) {
                    // Also a customer or dealer: shown as the Supplier tab of the one company record.
                    redirect(url('accounts', ['action' => 'view', 'id' => $row['account_id'], 'tab' => 'supplier']));
                }
                supplier_view($entity, $row);
            } else {
                page('view', compact('name', 'entity', 'row', 'canWrite'), $entity['label']);
            }
            return;

        case 'new':
        case 'edit':
            if (!$canWrite || !empty($entity['no_new']) || ($name === 'products' && $action === 'new' && !can('products.edit'))) {
                forbidden();
            }
            $existing = null;
            if ($action === 'edit') {
                $existing = $id ? find($name, $id) : null;
                if (!$existing) {
                    not_found();
                }
                if ($name === 'tickets' && !can_see_ticket($existing)) {
                    forbidden();
                }
            }
            $values = $existing ?? defaults_for($entity);
            if ($name === 'accounts' && $existing) {
                $values += account_contact_values($existing);
            }
            $errors = [];
            if (is_post()) {
                verify_csrf();
                [$data, $errors] = validate($entity, $_POST);
                $errors += validate_scoped_refs($entity, $data);
                $errors += validate_rules($name, $data, $existing ? (int)$existing['id'] : null);
                $values = $data + $values;
                if (!$errors) {
                    try {
                        $before = $existing ? record_snapshot($name, $entity, $id) : [];
                        if ($existing) {
                            update_row($name, $id, $data);
                            $savedId = $id;
                        } else {
                            $savedId = insert_row($name, $data);
                            if ($name === 'tickets') {
                                ticket_notify_group($savedId);
                            }
                        }
                        $after = record_snapshot($name, $entity, $savedId);
                        $changes = [];
                        foreach ($after as $field => $value) {
                            if ((string)($before[$field] ?? '') !== (string)$value) {
                                $changes[$field] = ['from' => $before[$field] ?? '', 'to' => $value];
                            }
                        }
                        $label = $entity['label'] . ' ' . record_label($entity, find($name, $savedId) ?? $data);
                        audit($existing ? 'update' : 'create', $label . ($existing ? ' updated' . ($changes ? ': ' . implode(', ', array_keys($changes)) : ' (no changes)') : ' created'),
                            $name, $savedId, null, $changes ?: null);
                        $message = $entity['label'] . ' saved.';
                        if ($name === 'accounts' && xero_push_enabled() && (isset($changes['Accounts contact']) || isset($changes['Accounts contact email']))) {
                            try {
                                $message .= ' Invoice email in Xero updated to ' . xero_push_billing_contact($savedId) . '.';
                            } catch (IntegrationException $e) {
                                $message .= ' Couldn\'t update Xero: ' . $e->getMessage();
                            }
                        }
                        $type = 'success';
                        if ($name === 'products' && setting('xero_push_products') === '1' && xero_connected() && xero_can_write_items()) {
                            try {
                                $r = xero_push_products([$savedId]);
                                $message .= $r['failed'] ? ' But it couldn\'t be sent to Xero: ' . reset($r['failed']) : ' Sent to Xero.';
                                $type = $r['failed'] ? 'error' : 'success';
                            } catch (IntegrationException $e) {
                                $message .= ' But it couldn\'t be sent to Xero: ' . $e->getMessage();
                                $type = 'error';
                            }
                        }
                        if ($name === 'services') {
                            service_diary_record($savedId, $existing ?: null); // the billing diary
                        }
                        [$extra, $ok] = abillity_after_save($name, $savedId, $changes, !$existing);
                        $message .= $extra;
                        $type = $ok ? $type : 'error';
                        flash($message, $type);
                        redirect(safe_return($_POST['_return'] ?? null, url($name, ['action' => 'view', 'id' => $savedId])));
                    } catch (PDOException $e) {
                        if ($e->errorInfo[1] ?? null) {
                            $errors['_'] = (int)$e->errorInfo[1] === 1062
                                ? 'A record with that value already exists (duplicate).'
                                : 'Could not save: database error.';
                        } else {
                            throw $e;
                        }
                    }
                }
            }
            $return = safe_return($_POST['_return'] ?? query('return'), '');
            $title = ($existing ? 'Edit ' : 'New ') . strtolower($entity['label']);
            page('form', compact('name', 'entity', 'values', 'errors', 'existing', 'return'), $title);
            return;

        case 'delete':
            if (!is_post()) {
                redirect(url($name));
            }
            verify_csrf();
            // Customers are closed/deleted through approvals.php (with approval where needed).
            if (!$canWrite || $name === 'accounts' || !can('records.delete') || ($name === 'products' && !can('products.edit'))) {
                forbidden();
            }
            if ($id && ($row = find($name, $id))) {
                $snapshot = record_snapshot($name, $entity, $id);
                $accountId = isset($row['account_id']) ? (int)$row['account_id'] : null;
                try {
                    if ($name === 'services') {
                        service_diary_removed($row);
                    }
                    delete_row($name, $id);
                } catch (PDOException $e) {
                    if ((int)($e->errorInfo[1] ?? 0) !== 1451) {
                        throw $e;
                    }
                    flash('This ' . strtolower($entity['label']) . ' is still in use (for example on purchase orders), so it can\'t be deleted.'
                        . (isset($entity['fields']['active']) ? ' Untick "' . $entity['fields']['active']['label'] . '" instead.' : ''), 'error');
                    redirect(url($name, ['action' => 'view', 'id' => $id]));
                }
                audit('delete', $entity['label'] . ' ' . record_label($entity, $row) . ' deleted', $name, $id, null,
                    array_map(fn($v) => ['from' => $v, 'to' => ''], array_filter($snapshot, fn($v) => $v !== '')), $accountId);
            }
            flash($entity['label'] . ' deleted.');
            redirect(safe_return($_POST['_return'] ?? null, url($name)));

        case 'xero_push':
            // Send one product (from its page) or several (ticked on the list) to Xero as items.
            if ($name !== 'products' || !is_post()) {
                not_found();
            }
            verify_csrf();
            require_permission('products.edit');
            $ids = $id ? [$id] : array_filter(array_map('intval', is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []));
            if (($_POST['all'] ?? '') === '1') {
                $ids = array_map('intval', array_column(db_all('SELECT id FROM products WHERE active = 1'), 'id'));
            }
            try {
                $r = xero_push_products($ids);
                flash(xero_push_products_message($r), $r['failed'] ? 'error' : 'success');
            } catch (IntegrationException $e) {
                flash($e->getMessage(), 'error');
            }
            redirect(safe_return($_POST['_return'] ?? null, $id ? url('products', ['action' => 'view', 'id' => $id]) : url('products')));

        case 'make_account':
            // A supplier that is also a customer or dealer: give it a customer record (one company, with tabs).
            if ($name !== 'suppliers' || !is_post() || !$id) {
                not_found();
            }
            verify_csrf();
            require_permission('suppliers.edit');
            require_permission('customers.edit');
            $supplier = find('suppliers', $id) ?? not_found();
            if ($supplier['account_id']) {
                redirect(url('accounts', ['action' => 'view', 'id' => $supplier['account_id']]));
            }
            $accountId = supplier_make_account($supplier, ($_POST['role'] ?? '') === 'dealer');
            flash('Customer record added. ' . $supplier['name'] . ' is now one company with Customer and Supplier tabs.');
            redirect(url('accounts', ['action' => 'view', 'id' => $accountId, 'tab' => 'overview']));

        case 'xero_import':
            // Bring in (and link) suppliers from Xero contacts marked as suppliers.
            if ($name !== 'suppliers' || !is_post()) {
                not_found();
            }
            verify_csrf();
            require_permission('suppliers.edit');
            if (!xero_connected()) {
                flash('Connect Xero first (Admin → Xero).', 'error');
            } else {
                try {
                    xero_sync();
                    $r = xero_import_suppliers();
                    audit('xero_suppliers', "Suppliers brought in from Xero: {$r['created']} added, {$r['linked']} linked, {$r['updated']} updated");
                    flash($r['created'] || $r['linked'] || $r['updated']
                        ? "From Xero: {$r['created']} supplier(s) added, {$r['linked']} linked to existing suppliers, {$r['updated']} with details filled in."
                        : 'No new suppliers in Xero. (Xero marks a contact as a supplier once you enter a bill for them.)');
                } catch (IntegrationException $e) {
                    flash('Xero: ' . $e->getMessage(), 'error');
                }
            }
            redirect(safe_return($_POST['_return'] ?? null, url('suppliers')));

        case 'comment':
            if ($name !== 'tickets' || !is_post() || !$id) {
                not_found();
            }
            verify_csrf();
            require_permission('tickets.edit');
            if (!can_see_ticket(db_one('SELECT * FROM tickets WHERE id = ?', [$id]) ?? not_found())) {
                forbidden();
            }
            ticket_comment($id);
            return;

        default:
            not_found();
    }
}

/** Initial form values: field defaults plus any prefill in the query string. */
function defaults_for(array $entity): array
{
    $values = [];
    foreach ($entity['fields'] as $field => $def) {
        $values[$field] = $def['default'] ?? null;
        $prefill = query($field);
        if ($prefill !== '' && empty($def['readonly'])) {
            $values[$field] = $prefill;
        }
    }
    return $values;
}

function account_view(array $entity, array $account): void
{
    $id = (int)$account['id'];
    $contacts = list_rows('contacts', ['filters' => ['account_id' => $id], 'per_page' => 0])['rows'];
    $services = list_rows('services', ['filters' => ['account_id' => $id], 'per_page' => 0, 'sort' => 'status'])['rows'];
    $tickets = list_rows('tickets', ['filters' => ['account_id' => $id], 'per_page' => 20, 'sort' => 'created_at', 'dir' => 'desc'])['rows'];
    $opps = list_rows('opportunities', ['filters' => ['account_id' => $id], 'per_page' => 0])['rows'];
    $activities = list_rows('activities', ['filters' => ['account_id' => $id], 'per_page' => 30])['rows'];
    $children = $account['is_dealer'] ? list_rows('accounts', ['filters' => ['parent_id' => $id], 'per_page' => 0, 'sort' => 'name'])['rows'] : [];
    $quotes = list_rows('quotes', ['filters' => ['account_id' => $id], 'per_page' => 10, 'sort' => 'created_at', 'dir' => 'desc'])['rows'];
    $contracts = list_rows('contracts', ['filters' => ['account_id' => $id], 'per_page' => 10, 'sort' => 'created_at', 'dir' => 'desc'])['rows'];
    $sites = list_rows('sites', ['filters' => ['account_id' => $id], 'per_page' => 0, 'sort' => 'name'])['rows'];
    $mainContact = $account['main_contact_id'] ? db_one('SELECT * FROM contacts WHERE id = ?', [$account['main_contact_id']]) : null;
    $billingContact = $account['billing_contact_id'] ? db_one('SELECT * FROM contacts WHERE id = ?', [$account['billing_contact_id']]) : null;
    $pendingRequest = pending_request_for($id);
    $giacomOrders = can('orders.check') ? db_all('SELECT * FROM giacom_orders WHERE account_id = ? ORDER BY id DESC LIMIT 10', [$id]) : [];
    $giacomChecks = can('orders.check') ? db_all('SELECT id, address_label, result, created_at FROM giacom_checks WHERE account_id = ? ORDER BY id DESC LIMIT 5', [$id]) : [];
    $history = can('audit.view') ? db_all('SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
        WHERE a.account_id = ? ORDER BY a.id DESC LIMIT 15', [$id]) : [];

    $mrr = 0.0;
    $activeCount = 0;
    foreach ($services as $s) {
        if ($s['status'] === 'active') {
            $mrr += (float)$s['monthly_price'];
            $activeCount++;
        }
    }
    $openTickets = count(array_filter($tickets, fn($t) => !in_array($t['status'], ['resolved', 'closed'], true)));

    $xero = (xero_connected() && can('finance.view') && $account['xero_contact_id'])
        ? db_one('SELECT * FROM xero_contacts WHERE id = ?', [$account['xero_contact_id']])
        : null;

    $dd = null;
    if (gc_configured() && can('finance.view')) {
        $dd = [
            'customer' => $account['gocardless_customer_id'] ? db_one('SELECT * FROM gocardless_customers WHERE id = ?', [$account['gocardless_customer_id']]) : null,
            'link'     => gc_open_setup_link($id),
        ];
    }

    $files = account_documents($id);
    $supplier = account_supplier($id);
    $tabs = ['overview' => 'Overview', 'customer' => 'Customer'];
    if ($supplier && (can('suppliers.view') || can('suppliers.edit'))) {
        $tabs['supplier'] = 'Supplier';
    }
    if ($account['is_dealer']) {
        $tabs['dealer'] = 'Dealer';
    }
    $tab = isset($tabs[query('tab')]) ? query('tab') : 'overview';
    $supplierData = null;
    if ($tab === 'supplier') {
        $supplierData = [
            'supplier' => $supplier,
            'products' => list_rows('supplier_products', ['filters' => ['supplier_id' => $supplier['id']], 'per_page' => 0, 'sort' => 'description'])['rows'],
            'orders' => list_rows('purchase_orders', ['filters' => ['supplier_id' => $supplier['id']], 'per_page' => 20, 'sort' => 'created_at', 'dir' => 'desc'])['rows'],
            'files' => supplier_documents((int)$supplier['id']),
            'invoices' => db_all('SELECT * FROM supplier_invoices WHERE supplier_id = ? ORDER BY id DESC LIMIT 10', [$supplier['id']]),
            'canWrite' => can('suppliers.edit'),
        ];
    }
    $orders = list_rows('customer_orders', ['filters' => ['account_id' => $id], 'per_page' => 10, 'sort' => 'created_at', 'dir' => 'desc'])['rows'];
    page('account', compact('tabs', 'tab', 'supplier', 'supplierData', 'orders', 'files', 'entity', 'account', 'contacts', 'services', 'tickets', 'opps', 'activities', 'mrr', 'activeCount', 'openTickets', 'xero', 'dd', 'children', 'quotes', 'contracts',
        'sites', 'mainContact', 'billingContact', 'pendingRequest', 'history', 'giacomOrders', 'giacomChecks'), $account['name']);
}

function ticket_view(array $entity, array $ticket): void
{
    $comments = db_all('SELECT c.*, COALESCE(u.name, CONCAT(cu.name, \' (customer)\')) AS user_name FROM ticket_comments c LEFT JOIN users u ON u.id = c.user_id
        LEFT JOIN customer_users cu ON cu.id = c.customer_user_id WHERE c.ticket_id = ? ORDER BY c.created_at, c.id', [$ticket['id']]);
    page('ticket', compact('entity', 'ticket', 'comments'), $ticket['reference'] . ' ' . $ticket['subject']);
}

function ticket_comment(int $id): void
{
    $ticket = db_one('SELECT * FROM tickets WHERE id = ?', [$id]) ?? not_found();
    $body = trim((string)($_POST['body'] ?? ''));
    $status = (string)($_POST['status'] ?? '');
    $statuses = entity('tickets')['fields']['status']['options'];

    if ($body !== '') {
        db_exec('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal) VALUES (?, ?, ?, ?)',
            [$id, current_user()['id'], $body, empty($_POST['is_internal']) ? 0 : 1]);
    }
    if ($status !== '' && isset($statuses[$status]) && $status !== $ticket['status']) {
        $data = array_intersect_key($ticket, array_flip(['account_id', 'service_id', 'contact_id', 'subject', 'category', 'priority', 'assigned_to', 'carrier_ref', 'description']));
        update_row('tickets', $id, ['status' => $status] + $data);
        db_exec('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal) VALUES (?, ?, ?, 1)',
            [$id, current_user()['id'], 'Status changed from ' . humanize($ticket['status']) . ' to ' . humanize($status) . '.']);
    } else {
        db_exec('UPDATE tickets SET updated_at = NOW() WHERE id = ?', [$id]);
    }
    if ($body !== '' && empty($_POST['is_internal'])) {
        customer_ticket_staff_update($id, $body);
    }
    audit('ticket_update', "Ticket {$ticket['reference']}: " . ($body !== '' ? (empty($_POST['is_internal']) ? 'customer-visible' : 'internal') . ' note added' : 'updated')
        . ($status !== '' && $status !== $ticket['status'] && isset($statuses[$status]) ? ', status ' . humanize($ticket['status']) . ' → ' . humanize($status) : ''), 'tickets', $id);
    flash($body !== '' ? 'Update added.' : 'Ticket updated.');
    redirect(url('tickets', ['action' => 'view', 'id' => $id]));
}

function export_csv(string $name, array $entity, array $opts): void
{
    audit('export', $entity['plural'] . ' exported to CSV', $name);
    $opts['per_page'] = 0;
    $rows = list_rows($name, $opts)['rows'];
    $columns = array_keys(array_filter($entity['fields'] + ($entity['computed'] ?? []), 'field_enabled'));

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 correctly
    fputcsv($out, array_map(fn($c) => column_def($entity, $c)['label'], $columns), escape: '');
    foreach ($rows as $row) {
        $line = array_map(fn($c) => csv_safe(export_value($entity, $c, $row)), $columns);
        fputcsv($out, $line, escape: '');
    }
    fclose($out);
}

/** Neutralise spreadsheet formula injection. */
function csv_safe(string $value): string
{
    return ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($value))
        ? "'" . $value
        : $value;
}

/** Pick Xero contacts and create them as CRM customers. */
function xero_customers_controller(): void
{
    require_permission('customers.edit');
    if (!xero_connected()) {
        flash('Connect Xero first (Admin → Xero).', 'error');
        redirect(url('accounts'));
    }
    if (is_post()) {
        verify_csrf();
        if (($_POST['action'] ?? '') === 'sync') {
            try {
                xero_sync();
                flash('Contacts refreshed from Xero.');
            } catch (IntegrationException $e) {
                flash('Xero: ' . $e->getMessage(), 'error');
            }
            redirect(url('xero_customers'));
        }
        $r = xero_create_customers(array_map('intval', (array)($_POST['ids'] ?? [])));
        $n = count($r['created']);
        audit('xero_customers', "$n customer(s) created from Xero");
        flash($n ? "$n customer" . ($n === 1 ? '' : 's') . ' added from Xero and linked to it.' . ($r['skipped'] ? ' Skipped: ' . implode(', ', $r['skipped']) . '.' : '')
            . ($r['no_number'] ? ' Given a CRM account number because Xero has none for them: ' . implode(', ', $r['no_number']) . '.' : '')
            : 'Nothing was added. Tick the contacts to add first.', $n ? ($r['no_number'] ? 'warning' : 'success') : 'error');
        redirect($n === 1 ? url('accounts', ['action' => 'view', 'id' => $r['created'][0]]) : url('xero_customers'));
    }
    $all = query('show') === 'all';
    $q = trim((string)query('q'));
    $contacts = xero_importable_contacts(!$all, $q);
    page('xero_customers', compact('contacts', 'all', 'q'), 'Add customers from Xero');
}

function xero_controller(): void
{
    $action = query('action');

    // Connect is a plain link rather than a form: browsers apply the page's form-action
    // rule to every redirect after a form is sent, which can stop the hop to Xero's login.
    if ($action === 'connect' && !is_post()) {
        require_permission('settings.manage');
        if (!hash_equals(csrf_token(), (string)query('token'))) {
            flash('That link has expired. Press Connect to Xero again.', 'error');
            redirect(url('xero'));
        }
        if (!xero_configured()) {
            flash('Enter your Xero app\'s Client ID and Client Secret first.', 'error');
            redirect(url('xero'));
        }
        $_SESSION['xero_oauth_state'] = bin2hex(random_bytes(16));
        $_SESSION['xero_redirect_uri'] = xero_redirect_uri();
        redirect(xero_authorize_url($_SESSION['xero_oauth_state'], $_SESSION['xero_redirect_uri']));
    }

    if ($action === 'sync') {
        if (!is_post()) {
            redirect(url('xero'));
        }
        verify_csrf();
        require_permission('finance.view');
        try {
            $s = xero_sync();
            audit('sync', 'Xero sync run');
            flash(sprintf('Xero sync complete: %d contacts, %d unpaid invoices, %d customers newly linked', $s['contacts'], $s['invoices'], $s['linked'])
                . (setting('xero_update_customers') === '1' ? sprintf(', %d updated with changes made in Xero.', $s['updated'] ?? 0) : '.'));
        } catch (XeroException | PDOException $e) {
            flash('Xero sync failed: ' . $e->getMessage(), 'error');
        }
        redirect(safe_return($_POST['_return'] ?? null, url('xero')));
    }

    if ($action === 'push') {
        if (!is_post()) {
            redirect(url('xero'));
        }
        verify_csrf();
        require_permission('customers.edit');
        $accountId = query_int('id') ?? 0;
        try {
            flash('Xero will now send invoices and statements to ' . xero_push_billing_contact($accountId) . '.');
        } catch (IntegrationException $e) {
            flash($e->getMessage(), 'error');
        }
        redirect(url('accounts', ['action' => 'view', 'id' => $accountId]));
    }

    require_permission('settings.manage');

    if (is_post()) {
        verify_csrf();
        switch ($action) {
            case 'customer_details':
                set_setting('xero_update_customers', !empty($_POST['keep_updated']) ? '1' : null);
                $n = !empty($_POST['apply']) ? xero_update_customers_from_xero(array_map('intval', (array)($_POST['ids'] ?? []))) : 0;
                audit('settings', "Xero customer details: $n customer(s) updated" . (setting('xero_update_customers') ? ', updated on each sync' : ''));
                flash(!empty($_POST['apply']) ? ($n ? "$n customer" . ($n === 1 ? '' : 's') . ' updated from Xero.' : 'Tick the customers to update first.') : 'Saved.', !empty($_POST['apply']) && !$n ? 'error' : 'success');
                break;

            case 'account_numbers':
                set_setting('xero_use_account_numbers', !empty($_POST['keep_in_step']) ? '1' : null);
                $n = !empty($_POST['apply']) ? xero_adopt_account_numbers(true) : 0;
                audit('settings', "Xero account numbers: $n customer(s) updated" . (setting('xero_use_account_numbers') ? ', kept in step on each sync' : ''));
                flash($n ? "$n customer account number" . ($n === 1 ? '' : 's') . ' changed to match Xero.' : (!empty($_POST['apply']) ? 'No account numbers could be changed.' : 'Saved.'));
                break;

            case 'accounts':
                try {
                    $n = xero_fetch_accounts();
                    flash("Loaded $n account codes" . (($t = count(xero_tax_rates())) ? " and $t VAT rates" : '') . " from Xero. They're offered in lists wherever you set nominal codes.");
                } catch (IntegrationException $e) {
                    flash('Couldn\'t load account codes: ' . $e->getMessage(), 'error');
                }
                break;

            case 'items_setting':
                $on = !empty($_POST['push_products']);
                $scopes = setting('xero_scopes') ?: XERO_DEFAULT_SCOPES;
                $newScopes = $on ? xero_item_scopes($scopes) : $scopes;
                foreach (['xero_item_sales_account' => 'sales_account', 'xero_item_purchase_account' => 'purchase_account', 'xero_item_tax_type' => 'tax_type'] as $key => $field) {
                    $value = trim((string)($_POST[$field] ?? ''));
                    if ($problem = xero_code_problem($value, $field === 'tax_type' ? 'tax' : 'account')) {
                        flash($problem . ' Nothing was saved.', 'error');
                        redirect(url('xero'));
                    }
                    set_setting($key, $value === '' ? null : $value);
                }
                set_setting('xero_push_items', $on ? '1' : null);
                set_setting('xero_push_products', $on && !empty($_POST['push_on_save']) ? '1' : null);
                set_setting('xero_scopes', $newScopes === XERO_DEFAULT_SCOPES ? null : $newScopes);
                audit('settings', 'Xero: send products to Xero ' . ($on ? 'on' . (setting('xero_push_products') ? ', automatically on save' : ', manually') : 'off'));
                flash($on && $newScopes !== $scopes
                    ? 'Saved. Press Reconnect so Xero can grant the CRM permission to create items.'
                    : 'Saved.');
                break;

            case 'bills_setting':
                $on = !empty($_POST['push_bills']);
                $scopes = setting('xero_scopes') ?: XERO_DEFAULT_SCOPES;
                $newScopes = $on ? xero_bill_scopes($scopes) : $scopes;
                foreach (['bill_account' => 'xero_bill_account', 'bill_tax_type' => 'xero_bill_tax_type'] as $field => $key) {
                    $value = trim((string)($_POST[$field] ?? ''));
                    if ($problem = xero_code_problem($value, $field === 'bill_tax_type' ? 'tax' : 'account')) {
                        flash($problem . ' Nothing was saved.', 'error');
                        redirect(url('xero'));
                    }
                    set_setting($key, $value === '' ? null : $value);
                }
                $map = [];
                foreach (['5' => 'rate_5', '0' => 'rate_0', 'exempt' => 'rate_exempt', 'RC' => 'rate_rc'] as $rate => $field) {
                    $value = strtoupper(trim((string)($_POST[$field] ?? '')));
                    if ($problem = xero_code_problem($value, 'tax')) {
                        flash($problem . ' Nothing was saved.', 'error');
                        redirect(url('xero'));
                    }
                    $map[$rate] = $value;
                }
                set_setting('xero_bill_rate_map', json_encode($map));
                set_setting('xero_push_bills', $on ? '1' : null);
                set_setting('xero_bill_attach', !empty($_POST['bill_attach']) ? '1' : '0');
                set_setting('xero_bill_status', in_array($_POST['bill_status'] ?? '', ['DRAFT', 'SUBMITTED', 'AUTHORISED'], true) ? $_POST['bill_status'] : 'DRAFT');
                set_setting('xero_scopes', $newScopes === XERO_DEFAULT_SCOPES ? null : $newScopes);
                audit('settings', 'Xero: send approved supplier invoices as bills ' . ($on ? 'on' : 'off'));
                flash($on && $newScopes !== $scopes ? 'Saved. Press Reconnect so Xero can grant the CRM permission to create bills and attach files.' : 'Saved.');
                break;

            case 'suppliers_setting':
                set_setting('xero_import_suppliers', !empty($_POST['import_suppliers']) ? '1' : null);
                audit('settings', 'Xero: bring in suppliers on each sync ' . (setting('xero_import_suppliers') ? 'on' : 'off'));
                flash('Saved.');
                break;

            case 'push_setting':
                $on = !empty($_POST['push_contacts']);
                $scopes = setting('xero_scopes') ?: XERO_DEFAULT_SCOPES;
                $newScopes = $on ? xero_write_scopes($scopes) : $scopes;
                set_setting('xero_push_contacts', $on ? '1' : null);
                set_setting('xero_scopes', $newScopes === XERO_DEFAULT_SCOPES ? null : $newScopes);
                audit('settings', 'Xero: update invoice email from the CRM ' . ($on ? 'on' : 'off'));
                flash($on && $newScopes !== $scopes
                    ? 'Saved. Press Reconnect so Xero can grant the CRM permission to update contacts.'
                    : 'Saved.');
                break;

            case 'credentials':
                $clientId = trim((string)($_POST['client_id'] ?? ''));
                $secret = trim((string)($_POST['client_secret'] ?? ''));
                $scopes = trim(preg_replace('/\s+/', ' ', (string)($_POST['scopes'] ?? '')));
                $redirectUri = trim((string)($_POST['redirect_uri'] ?? ''));
                if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $clientId)) {
                    flash('That Client ID doesn\'t look right. Copy it from your app in the Xero developer portal.', 'error');
                    redirect(url('xero'));
                }
                if ($redirectUri !== '' && !preg_match('#^https?://[^\s]+/xero-callback\.php$#', $redirectUri)) {
                    flash('The redirect URI must be a full URL ending in /xero-callback.php.', 'error');
                    redirect(url('xero'));
                }
                if ($clientId !== setting('xero_client_id') && xero_connected()) {
                    xero_disconnect(); // tokens belong to the old app
                }
                set_setting('xero_client_id', $clientId);
                if ($secret !== '') {
                    set_setting('xero_client_secret', $secret);
                }
                set_setting('xero_scopes', $scopes === '' || $scopes === XERO_DEFAULT_SCOPES ? null : $scopes);
                set_setting('xero_redirect_uri', $redirectUri === '' ? null : $redirectUri);
                audit('settings', 'Xero app details saved');
                flash('Xero app details saved.');
                break;

            case 'connect':
                if (!xero_configured()) {
                    flash('Enter your Xero app\'s Client ID and Client Secret first.', 'error');
                    break;
                }
                $_SESSION['xero_oauth_state'] = bin2hex(random_bytes(16));
                $_SESSION['xero_redirect_uri'] = xero_redirect_uri();
                redirect(xero_authorize_url($_SESSION['xero_oauth_state'], $_SESSION['xero_redirect_uri']));

            case 'tenant':
                $tenants = json_decode((string)setting('xero_tenants', '[]'), true) ?: [];
                foreach ($tenants as $t) {
                    if ($t['id'] === ($_POST['tenant_id'] ?? '')) {
                        set_setting('xero_tenant_id', $t['id']);
                        set_setting('xero_tenant_name', $t['name']);
                        db_exec('UPDATE accounts SET xero_contact_id = NULL');
                        db_exec('DELETE FROM xero_contacts');
                        flash('Switched to ' . $t['name'] . '. Run a sync to load its balances.');
                    }
                }
                break;

            case 'disconnect':
                xero_disconnect();
                audit('settings', 'Xero disconnected');
                flash('Disconnected from Xero. Customer links and the last synced balances are kept but hidden until you reconnect.');
                break;
        }
        redirect(url('xero'));
    }

    $stats = [
        'contacts'    => (int)db_value('SELECT COUNT(*) FROM xero_contacts'),
        'linked'      => (int)db_value('SELECT COUNT(*) FROM accounts WHERE xero_contact_id IS NOT NULL'),
        'unlinked'    => db_all("SELECT id, name, account_number FROM accounts WHERE xero_contact_id IS NULL AND status IN ('active','suspended') ORDER BY name LIMIT 50"),
        'unlinked_total' => (int)db_value("SELECT COUNT(*) FROM accounts WHERE xero_contact_id IS NULL AND status IN ('active','suspended')"),
    ];
    page('xero', [
        'stats'       => $stats,
        'redirectUri' => xero_redirect_uri(),
        'tenants'     => json_decode((string)setting('xero_tenants', '[]'), true) ?: [],
        'summary'     => json_decode((string)setting('xero_last_sync_summary', 'null'), true),
    ], 'Xero');
}

function gocardless_controller(): void
{
    $action = query('action');
    $accountId = query_int('id');

    // Actions any signed-in user can take from a customer's page.
    if (in_array($action, ['link', 'check', 'sync', 'attach'], true)) {
        if (!is_post()) {
            redirect(url('gocardless'));
        }
        verify_csrf();
        require_permission('finance.view');
        $back = $accountId ? url('accounts', ['action' => 'view', 'id' => $accountId]) : url('gocardless');
        try {
            if ($action === 'sync') {
                $s = gc_sync();
                audit('sync', 'GoCardless sync run');
                flash(sprintf('GoCardless sync complete: %d customers, %d with an active mandate, %d newly linked.', $s['customers'], $s['active'], $s['linked']));
            } else {
                $account = $accountId ? db_one('SELECT * FROM accounts WHERE id = ?', [$accountId]) : null;
                if (!$account) {
                    not_found();
                }
                if ($action === 'attach') {
                    require_permission('customers.edit');
                    $gc = gc_link_account($accountId, (int)($_POST['gocardless_customer_id'] ?? 0));
                    flash("Linked to {$gc['name']} in GoCardless" . ($gc['mandate_status'] ? ' (' . strtolower(gc_mandate_label($gc['mandate_status'])) . ')' : '') . '.');
                } elseif ($action === 'link') {
                    gc_create_setup_link($account);
                    audit('dd_link', 'Direct Debit setup link created', 'accounts', $accountId);
                    flash('Direct Debit setup link created. Copy it or email it to the customer; it expires in 7 days.');
                } elseif ($account['gocardless_customer_id']) {
                    gc_refresh_account($accountId);
                    flash('Direct Debit status refreshed from GoCardless.');
                } else {
                    // Not linked yet: fetch GoCardless's latest customers and try to match them.
                    gc_sync();
                    $linked = db_one('SELECT g.* FROM accounts a JOIN gocardless_customers g ON g.id = a.gocardless_customer_id WHERE a.id = ?', [$accountId]);
                    flash($linked ? "Found and linked {$linked['name']} in GoCardless."
                        : 'Checked GoCardless, but couldn\'t match this customer automatically. If they have a mandate, choose them from the list on the Direct Debit card.', $linked ? 'success' : 'error');
                }
            }
        } catch (IntegrationException | PDOException $e) {
            flash($e->getMessage(), 'error');
        }
        redirect(safe_return($_POST['_return'] ?? null, $back));
    }

    require_permission('settings.manage');

    if (is_post()) {
        verify_csrf();
        if ($action === 'settings') {
            $token = trim((string)($_POST['access_token'] ?? ''));
            $environment = ($_POST['environment'] ?? '') === 'sandbox' ? 'sandbox' : 'live';
            $returnUrl = trim((string)($_POST['return_url'] ?? ''));
            $scheme = preg_replace('/[^a-z_]/', '', (string)($_POST['scheme'] ?? 'bacs')) ?: 'bacs';
            if ($returnUrl !== '' && !preg_match('#^https://\S+$#', $returnUrl)) {
                flash('The return page must be a full https:// address.', 'error');
                redirect(url('gocardless'));
            }
            $previous = [setting('gocardless_access_token'), setting('gocardless_environment')];
            if ($token !== '') {
                set_setting('gocardless_access_token', $token);
            }
            set_setting('gocardless_environment', $environment);
            set_setting('gocardless_return_url', $returnUrl ?: null);
            set_setting('gocardless_scheme', $scheme === 'bacs' ? null : $scheme);
            try {
                set_setting('gocardless_creditor', gc_creditor_name());
                audit('settings', 'GoCardless access token saved (' . $environment . ')');
                flash('GoCardless connected to ' . setting('gocardless_creditor') . '. Run a sync to load mandates.');
            } catch (GoCardlessException $e) {
                // Keep the old, working credentials if the new ones fail.
                set_setting('gocardless_access_token', $previous[0]);
                set_setting('gocardless_environment', $previous[1]);
                flash($e->getMessage(), 'error');
            }
        } elseif ($action === 'remove') {
            foreach (['gocardless_access_token', 'gocardless_creditor'] as $key) {
                set_setting($key, null);
            }
            audit('settings', 'GoCardless access token removed');
            flash('GoCardless access token removed. Customer links are kept but hidden until you add a token again.');
        }
        redirect(url('gocardless'));
    }

    page('gocardless', [
        'unlinkedMandates' => array_values(array_filter(gc_configured() ? gc_unlinked_customers() : [], fn($c) => in_array(gc_mandate_state($c['mandate_status']), ['active', 'pending'], true))),
        'stats' => [
            'customers' => (int)db_value('SELECT COUNT(*) FROM gocardless_customers'),
            'active'    => (int)db_value("SELECT COUNT(*) FROM gocardless_customers WHERE mandate_status = 'active'"),
            'linked'    => (int)db_value('SELECT COUNT(*) FROM accounts WHERE gocardless_customer_id IS NOT NULL'),
            'open_links' => (int)db_value("SELECT COUNT(*) FROM gocardless_setup_links WHERE status = 'open' AND (expires_at IS NULL OR expires_at > NOW())"),
        ],
        'summary' => json_decode((string)setting('gocardless_last_sync_summary', 'null'), true),
    ], 'GoCardless');
}


/**
 * Field values of a record as people read them (labels, not IDs), keyed by field
 * label, for before/after comparisons in the audit trail.
 */
function record_snapshot(string $name, array $entity, int $id): array
{
    $row = find($name, $id);
    if (!$row) {
        return [];
    }
    $out = [];
    foreach ($entity['fields'] as $field => $def) {
        if (!empty($def['virtual']) || in_array($field, ['created_at', 'updated_at'], true)) {
            continue;
        }
        $out[$def['label']] = mb_substr(export_value($entity, $field, $row), 0, 300);
    }
    if ($name === 'accounts') {
        // The main and accounts contacts live on the contacts table; include their details.
        foreach (['main_contact_id' => 'Main contact', 'billing_contact_id' => 'Accounts contact'] as $column => $label) {
            $c = $row[$column] ? db_one('SELECT name, email, phone FROM contacts WHERE id = ?', [$row[$column]]) : null;
            $out[$label] = (string)($c['name'] ?? '');
            $out[$label . ' email'] = (string)($c['email'] ?? '');
            $out[$label . ' phone'] = (string)($c['phone'] ?? '');
        }
    }
    return $out;
}

/** A short human label for a record, for the audit log. */
function record_label(array $entity, array $row): string
{
    foreach (['reference', 'name', 'title', 'identifier', 'subject', 'sku', 'account_number'] as $f) {
        if (!empty($row[$f])) {
            return '"' . mb_substr((string)$row[$f], 0, 80) . '"';
        }
    }
    return '#' . ($row['id'] ?? '?');
}


function audit_controller(): void
{
    require_permission('audit.view');
    $where = [];
    $params = [];
    if ($u = query_int('user_id')) {
        $where[] = 'a.user_id = ?';
        $params[] = $u;
    }
    if (($action = query('action_type')) !== '') {
        $where[] = 'a.action LIKE ?';
        $params[] = addcslashes($action, '%_\\') . '%';
    }
    if (($entity = query('entity')) !== '') {
        $where[] = 'a.entity = ?';
        $params[] = $entity;
        if ($eid = query_int('entity_id')) {
            $where[] = 'a.entity_id = ?';
            $params[] = $eid;
        }
    }
    if ($acc = query_int('account_id')) {
        $where[] = 'a.account_id = ?';
        $params[] = $acc;
    }
    if (($from = query('from')) !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = 'a.created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if (($to = query('to')) !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = 'a.created_at <= ?';
        $params[] = $to . ' 23:59:59';
    }
    if (($q = query('q')) !== '') {
        $where[] = '(a.summary LIKE ? OR a.ip LIKE ?)';
        $params[] = '%' . addcslashes($q, '%_\\') . '%';
        $params[] = '%' . addcslashes($q, '%_\\') . '%';
    }
    $sql = ' FROM audit_log a LEFT JOIN users u ON u.id = a.user_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $total = (int)db_value('SELECT COUNT(*)' . $sql, $params);
    $page = max(1, query_int('p') ?? 1);
    $rows = db_all('SELECT a.*, u.name AS user_name' . $sql . ' ORDER BY a.id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50), $params);
    $actions = array_column(db_all('SELECT DISTINCT action FROM audit_log ORDER BY action'), 'action');
    $users = db_all('SELECT id, name FROM users ORDER BY name');
    $accountName = $acc ? db_value('SELECT name FROM accounts WHERE id = ?', [$acc]) : null;
    if (query('export') === '1') {
        audit('export', 'Audit trail exported to CSV');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['When', 'Who', 'Action', 'Record', 'Summary', 'Changes', 'IP'], escape: '');
        foreach (db_all('SELECT a.*, u.name AS user_name' . $sql . ' ORDER BY a.id DESC LIMIT 50000', $params) as $r) {
            fputcsv($out, array_map('csv_safe', [$r['created_at'], (string)($r['user_name'] ?? 'System'), $r['action'],
                trim(($r['entity'] ?? '') . ' ' . ($r['entity_id'] ?? '')), (string)$r['summary'], audit_changes_text($r['changes']), (string)$r['ip']]), escape: '');
        }
        fclose($out);
        return;
    }
    page('audit', compact('rows', 'total', 'page', 'actions', 'users', 'accountName'), 'Audit trail');
}


/** Decoded audit changes as [[field, from, to], ...]. */
function audit_changes(?string $json): array
{
    $data = json_decode((string)$json, true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $field => $v) {
        if (is_array($v) && (array_key_exists('from', $v) || array_key_exists('to', $v))) {
            $out[] = [(string)$field, (string)($v['from'] ?? ''), (string)($v['to'] ?? '')];
        } elseif (is_array($v)) {
            $out[] = [(string)$field, '', implode('; ', array_map(fn($k, $x) => "$k: " . (is_scalar($x) ? $x : json_encode($x)), array_keys($v), $v))];
        } else {
            $out[] = [(string)$field, '', (string)$v];
        }
    }
    return $out;
}

function audit_changes_text(?string $json): string
{
    return implode("\n", array_map(fn($c) => $c[0] . ': ' . ($c[1] === '' ? '' : $c[1] . ' → ') . $c[2], audit_changes($json)));
}

/** Super admins: the newest entries in the CRM's error log (public/app/crm-error.log). */
function error_log_controller(): void
{
    if (!is_super_admin()) {
        forbidden();
    }
    $path = (string)ini_get('error_log');
    if ($path === '' || !str_ends_with($path, 'crm-error.log')) {
        $path = APP_ROOT . '/public/app/crm-error.log';
    }
    if (is_post()) {
        verify_csrf();
        if (is_file($path) && @file_put_contents($path, '') !== false) {
            audit('settings', 'Error log cleared');
            flash('Error log cleared.');
        } else {
            flash('Couldn\'t clear the log file. Check it is writable.', 'error');
        }
        redirect(url('error_log'));
    }
    $lines = [];
    $size = is_file($path) ? (int)filesize($path) : 0;
    if ($size) {
        // Only the end of a large log.
        $fh = fopen($path, 'r');
        fseek($fh, max(0, $size - 256 * 1024));
        $chunk = (string)stream_get_contents($fh);
        fclose($fh);
        // Multi-line entries (stack traces) stay with the line that starts them.
        $entries = preg_split('/\n(?=\[\d{2}-\w{3}-\d{4} )/', trim($chunk)) ?: [];
        $lines = array_reverse(array_slice($entries, -300));
    }
    page('error_log', ['lines' => $lines, 'path' => $path, 'size' => $size], 'Error log');
}

/** Every email the CRM has sent or tried to send, newest first, with the reason for any failure. */
function mail_log_controller(): void
{
    require_permission('settings.manage');
    $q = trim((string)query('q', ''));
    $failed = query('status') === 'failed';
    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = '(to_email LIKE ? OR to_name LIKE ? OR subject LIKE ?)';
        array_push($params, "%$q%", "%$q%", "%$q%");
    }
    if ($failed) {
        $where[] = "status = 'failed'";
    }
    $rows = db_all('SELECT * FROM mail_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 300', $params);
    $failures = (int)db_value("SELECT COUNT(*) FROM mail_log WHERE status = 'failed' AND created_at > NOW() - INTERVAL 7 DAY");
    page('mail_log', compact('rows', 'q', 'failed', 'failures'), 'Email log');
}

/**
 * Set a company's contact type: customer, supplier and/or dealer. $supplier is null to leave it as it is.
 * Returns what changed ([label => [from, to]]); throws IntegrationException if it can't be done.
 */
function account_set_types(array $account, bool $customer, ?bool $supplier, bool $dealer): array
{
    $wasSupplier = (bool)account_supplier((int)$account['id']);
    $supplier ??= $wasSupplier;
    if (!$customer && !$dealer && !$supplier) {
        throw new IntegrationException('Tick at least one: customer, supplier or dealer.');
    }
    if (!$dealer && $account['is_dealer'] && ($n = (int)db_value('SELECT COUNT(*) FROM accounts WHERE parent_id = ?', [$account['id']]))) {
        throw new IntegrationException("{$account['name']} has $n customer" . ($n === 1 ? '' : 's') . ' under them as a dealer. Move them to another dealer first.');
    }
    $changes = [];
    foreach (['Customer' => [(bool)$account['is_customer'], $customer], 'Supplier' => [$wasSupplier, $supplier], 'Dealer' => [(bool)$account['is_dealer'], $dealer]] as $label => [$was, $now]) {
        if ($was !== $now) {
            $changes[$label] = ['from' => $was ? 'Yes' : 'No', 'to' => $now ? 'Yes' : 'No'];
        }
    }
    if (!$changes) {
        return [];
    }
    db_exec('UPDATE accounts SET is_customer = ?, is_dealer = ? WHERE id = ?', [$customer ? 1 : 0, $dealer ? 1 : 0, $account['id']]);
    if ($supplier !== $wasSupplier) {
        account_set_supplier((int)$account['id'], $supplier);
    }
    audit('update', "Contact type of {$account['name']} changed", 'accounts', (int)$account['id'], null, $changes, (int)$account['id']);
    return $changes;
}

/** Contact type (from the customer's page). */
function account_types_controller(): void
{
    require_permission('customers.edit');
    if (!is_post()) {
        redirect(url('accounts'));
    }
    verify_csrf();
    $account = db_one('SELECT * FROM accounts WHERE id = ?', [query_int('id') ?? 0]) ?? not_found('Customer not found.');
    try {
        $changed = account_set_types($account, !empty($_POST['is_customer']), can('suppliers.edit') ? !empty($_POST['is_supplier']) : null, !empty($_POST['is_dealer']));
        if ($changed) {
            flash('Contact type saved.');
        }
    } catch (IntegrationException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect(url('accounts', ['action' => 'view', 'id' => $account['id']]));
}
