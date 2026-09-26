<?php
declare(strict_types=1);

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

    page('dashboard', compact('stats', 'mrrByType', 'renewals', 'tickets', 'tasks', 'recent', 'window'), 'Dashboard');
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
    if (!in_array($ref, ['services', 'contacts'], true) || !$accountId) {
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

    $mrr = 0.0;
    $activeCount = 0;
    foreach ($services as $s) {
        if ($s['status'] === 'active') {
            $mrr += (float)$s['monthly_price'];
            $activeCount++;
        }
    }
    $openTickets = count(array_filter($tickets, fn($t) => !in_array($t['status'], ['resolved', 'closed'], true)));

    page('account', compact('entity', 'account', 'contacts', 'services', 'tickets', 'opps', 'activities', 'mrr', 'activeCount', 'openTickets'), $account['name']);
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
    $columns = array_keys($entity['fields'] + ($entity['computed'] ?? []));

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
