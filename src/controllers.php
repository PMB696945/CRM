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
    if (is_post()) {
        verify_csrf();
        $email = (string)($_POST['email'] ?? '');
        if (attempt_login($email, (string)($_POST['password'] ?? ''))) {
            redirect(url('dashboard'));
        }
        $error = 'Incorrect email or password.';
    }
    render('login', ['error' => $error, 'email' => $email]);
}

function dashboard_controller(): void
{
    $window = (int)config('renewal_window_days');
    $stats = [
        'customers' => (int)db_value("SELECT COUNT(*) FROM accounts WHERE status = 'active'"),
        'prospects' => (int)db_value("SELECT COUNT(*) FROM accounts WHERE status = 'prospect'"),
        'mrr'       => (float)db_value("SELECT COALESCE(SUM(monthly_price),0) FROM services WHERE status = 'active'"),
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
    if (xero_connected()) {
        $stats['xero_overdue'] = (float)db_value('SELECT COALESCE(SUM(overdue),0) FROM xero_contacts');
        $stats['xero_outstanding'] = (float)db_value('SELECT COALESCE(SUM(GREATEST(outstanding,0)),0) FROM xero_contacts');
        $stats['xero_debtors'] = (int)db_value('SELECT COUNT(*) FROM xero_contacts WHERE overdue > 0');
        $debtors = db_all('SELECT a.id, a.name, a.status, a.credit_limit, x.outstanding, x.overdue, x.oldest_due_date
            FROM accounts a JOIN xero_contacts x ON x.id = a.xero_contact_id
            WHERE x.overdue > 0 ORDER BY x.overdue DESC LIMIT 8');
    }

    if (gc_configured()) {
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
        foreach (['accounts', 'contacts', 'services', 'tickets', 'opportunities'] as $name) {
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
    if (!in_array($ref, ['services', 'contacts', 'opportunities'], true) || !$accountId) {
        echo json_encode([]);
        return;
    }
    $out = [];
    foreach (ref_options($ref, $accountId) as $id => $label) {
        $out[] = ['id' => $id, 'label' => $label];
    }
    echo json_encode($out);
}

function users_controller(): void
{
    require_admin();
    $action = query('action', 'list');
    $id = query_int('id');
    $errors = [];
    $values = ['name' => '', 'email' => '', 'role' => 'agent', 'active' => 1];

    if ($id) {
        $values = db_one('SELECT id, name, email, role, active FROM users WHERE id = ?', [$id]) ?? not_found();
    }

    if (in_array($action, ['new', 'edit'], true)) {
        if (is_post()) {
            verify_csrf();
            $values = [
                'name'   => trim((string)($_POST['name'] ?? '')),
                'email'  => strtolower(trim((string)($_POST['email'] ?? ''))),
                'role'   => ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'agent',
                'active' => empty($_POST['active']) ? 0 : 1,
            ];
            $password = (string)($_POST['password'] ?? '');
            if ($values['name'] === '') {
                $errors['name'] = 'Name is required.';
            }
            if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'A valid email is required.';
            } elseif (db_value('SELECT id FROM users WHERE email = ? AND id <> ?', [$values['email'], $id ?? 0])) {
                $errors['email'] = 'That email is already in use.';
            }
            if ((!$id || $password !== '') && strlen($password) < 8) {
                $errors['password'] = 'Password must be at least 8 characters.';
            }
            if ($id === (int)current_user()['id'] && ($values['role'] !== 'admin' || !$values['active'])) {
                $errors['role'] = 'You cannot remove your own admin access.';
            }
            if (!$errors) {
                if ($id) {
                    db_exec('UPDATE users SET name = ?, email = ?, role = ?, active = ? WHERE id = ?',
                        [$values['name'], $values['email'], $values['role'], $values['active'], $id]);
                    if ($password !== '') {
                        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
                    }
                } else {
                    db_exec('INSERT INTO users (name, email, role, active, password_hash) VALUES (?, ?, ?, ?, ?)',
                        [$values['name'], $values['email'], $values['role'], $values['active'], password_hash($password, PASSWORD_DEFAULT)]);
                }
                flash('User saved.');
                redirect(url('users'));
            }
        }
        page('user_form', ['values' => $values, 'errors' => $errors, 'id' => $id], $id ? 'Edit user' : 'New user');
        return;
    }

    $users = db_all('SELECT id, name, email, role, active, created_at FROM users ORDER BY name');
    page('users', ['users' => $users], 'Users');
}

function profile_controller(): void
{
    $user = current_user();
    $errors = [];
    if (is_post()) {
        verify_csrf();
        $hash = db_value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        $new = (string)($_POST['new_password'] ?? '');
        if (!password_verify((string)($_POST['current_password'] ?? ''), (string)$hash)) {
            $errors['current_password'] = 'Current password is incorrect.';
        }
        if (strlen($new) < 8) {
            $errors['new_password'] = 'New password must be at least 8 characters.';
        } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }
        if (!$errors) {
            db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            flash('Password changed.');
            redirect(url('profile'));
        }
    }
    page('profile', ['user' => $user, 'errors' => $errors], 'My profile');
}

/** Generic CRUD for everything in entities(). */
function entity_controller(string $name): void
{
    $entity = entity($name);
    $action = query('action', 'list');
    $id = query_int('id');
    $canWrite = empty($entity['admin_write']) || is_admin();

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
            if ($name === 'accounts') {
                account_view($entity, $row);
            } elseif ($name === 'tickets') {
                ticket_view($entity, $row);
            } else {
                page('view', compact('name', 'entity', 'row', 'canWrite'), $entity['label']);
            }
            return;

        case 'new':
        case 'edit':
            if (!$canWrite) {
                forbidden();
            }
            $existing = null;
            if ($action === 'edit') {
                $existing = $id ? find($name, $id) : null;
                if (!$existing) {
                    not_found();
                }
            }
            $values = $existing ?? defaults_for($entity);
            $errors = [];
            if (is_post()) {
                verify_csrf();
                [$data, $errors] = validate($entity, $_POST);
                $errors += validate_scoped_refs($entity, $data);
                $errors += validate_rules($name, $data, $existing ? (int)$existing['id'] : null);
                $values = $data + $values;
                if (!$errors) {
                    try {
                        if ($existing) {
                            update_row($name, $id, $data);
                            $savedId = $id;
                        } else {
                            $savedId = insert_row($name, $data);
                        }
                        flash($entity['label'] . ' saved.');
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
            if (!$canWrite) {
                forbidden();
            }
            if ($id) {
                delete_row($name, $id);
            }
            flash($entity['label'] . ' deleted.');
            redirect(safe_return($_POST['_return'] ?? null, url($name)));

        case 'comment':
            if ($name !== 'tickets' || !is_post() || !$id) {
                not_found();
            }
            verify_csrf();
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

    $mrr = 0.0;
    $activeCount = 0;
    foreach ($services as $s) {
        if ($s['status'] === 'active') {
            $mrr += (float)$s['monthly_price'];
            $activeCount++;
        }
    }
    $openTickets = count(array_filter($tickets, fn($t) => !in_array($t['status'], ['resolved', 'closed'], true)));

    $xero = (xero_connected() && $account['xero_contact_id'])
        ? db_one('SELECT * FROM xero_contacts WHERE id = ?', [$account['xero_contact_id']])
        : null;

    $dd = null;
    if (gc_configured()) {
        $dd = [
            'customer' => $account['gocardless_customer_id'] ? db_one('SELECT * FROM gocardless_customers WHERE id = ?', [$account['gocardless_customer_id']]) : null,
            'link'     => gc_open_setup_link($id),
        ];
    }

    page('account', compact('entity', 'account', 'contacts', 'services', 'tickets', 'opps', 'activities', 'mrr', 'activeCount', 'openTickets', 'xero', 'dd', 'children', 'quotes', 'contracts'), $account['name']);
}

function ticket_view(array $entity, array $ticket): void
{
    $comments = db_all('SELECT c.*, u.name AS user_name FROM ticket_comments c LEFT JOIN users u ON u.id = c.user_id
        WHERE c.ticket_id = ? ORDER BY c.created_at, c.id', [$ticket['id']]);
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
    flash($body !== '' ? 'Update added.' : 'Ticket updated.');
    redirect(url('tickets', ['action' => 'view', 'id' => $id]));
}

function export_csv(string $name, array $entity, array $opts): void
{
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

function xero_controller(): void
{
    $action = query('action');

    if ($action === 'sync') {
        if (!is_post()) {
            redirect(url('xero'));
        }
        verify_csrf();
        try {
            $s = xero_sync();
            flash(sprintf('Xero sync complete: %d contacts, %d unpaid invoices, %d customers newly linked.', $s['contacts'], $s['invoices'], $s['linked']));
        } catch (XeroException | PDOException $e) {
            flash('Xero sync failed: ' . $e->getMessage(), 'error');
        }
        redirect(safe_return($_POST['_return'] ?? null, url('xero')));
    }

    require_admin();

    if (is_post()) {
        verify_csrf();
        switch ($action) {
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
    if (in_array($action, ['link', 'check', 'sync'], true)) {
        if (!is_post()) {
            redirect(url('gocardless'));
        }
        verify_csrf();
        $back = $accountId ? url('accounts', ['action' => 'view', 'id' => $accountId]) : url('gocardless');
        try {
            if ($action === 'sync') {
                $s = gc_sync();
                flash(sprintf('GoCardless sync complete: %d customers, %d with an active mandate, %d newly linked.', $s['customers'], $s['active'], $s['linked']));
            } else {
                $account = $accountId ? db_one('SELECT * FROM accounts WHERE id = ?', [$accountId]) : null;
                if (!$account) {
                    not_found();
                }
                if ($action === 'link') {
                    gc_create_setup_link($account);
                    flash('Direct Debit setup link created. Copy it or email it to the customer; it expires in 7 days.');
                } else {
                    gc_refresh_account($accountId);
                    flash('Direct Debit status refreshed from GoCardless.');
                }
            }
        } catch (IntegrationException | PDOException $e) {
            flash($e->getMessage(), 'error');
        }
        redirect(safe_return($_POST['_return'] ?? null, $back));
    }

    require_admin();

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
            flash('GoCardless access token removed. Customer links are kept but hidden until you add a token again.');
        }
        redirect(url('gocardless'));
    }

    page('gocardless', [
        'stats' => [
            'customers' => (int)db_value('SELECT COUNT(*) FROM gocardless_customers'),
            'active'    => (int)db_value("SELECT COUNT(*) FROM gocardless_customers WHERE mandate_status = 'active'"),
            'linked'    => (int)db_value('SELECT COUNT(*) FROM accounts WHERE gocardless_customer_id IS NOT NULL'),
            'open_links' => (int)db_value("SELECT COUNT(*) FROM gocardless_setup_links WHERE status = 'open' AND (expires_at IS NULL OR expires_at > NOW())"),
        ],
        'summary' => json_decode((string)setting('gocardless_last_sync_summary', 'null'), true),
    ], 'GoCardless');
}
