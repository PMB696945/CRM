<?php
declare(strict_types=1);

/*
 * Closing or deleting a customer. People with the customers.close /
 * customers.delete permission can do it straight away; everyone else with
 * customers.edit raises a request that someone with approvals.decide must
 * approve. Everything is recorded in the audit trail.
 */

const APPROVAL_TYPES = [
    'close_account'  => 'Close customer',
    'delete_account' => 'Delete customer',
];

function approval_permission(string $type): string
{
    return $type === 'delete_account' ? 'customers.delete' : 'customers.close';
}

function pending_approvals_count(): int
{
    try {
        return (int)db_value("SELECT COUNT(*) FROM approval_requests WHERE status = 'pending'");
    } catch (PDOException) {
        return 0;
    }
}

function pending_request_for(int $accountId): ?array
{
    return db_one("SELECT r.*, u.name AS requested_by_name FROM approval_requests r LEFT JOIN users u ON u.id = r.requested_by
        WHERE r.account_id = ? AND r.status = 'pending' ORDER BY r.id DESC LIMIT 1", [$accountId]);
}

/** Carry out a close or delete. Returns a message for the user. */
function execute_account_action(string $type, array $account, string $reason, array $options, ?int $requestId = null): string
{
    $id = (int)$account['id'];
    $label = $account['name'] . ' (' . $account['account_number'] . ')';
    $via = $requestId ? " (request #$requestId)" : '';

    if ($type === 'delete_account') {
        // Log first: the customer's own row is about to disappear.
        audit('delete', "Customer $label deleted$via. Reason: $reason", 'accounts', $id, null, [
            'status' => ['from' => $account['status'], 'to' => 'deleted'],
            'live services' => ['from' => (string)db_value("SELECT COUNT(*) FROM services WHERE account_id = ? AND status = 'active'", [$id]), 'to' => '0'],
        ], $id);
        delete_row('accounts', $id);
        return "$label deleted.";
    }

    $ceased = 0;
    db()->beginTransaction();
    try {
        db_exec("UPDATE accounts SET status = 'churned', closed_at = NOW(), closed_reason = ? WHERE id = ?", [mb_substr($reason, 0, 500), $id]);
        $ceasedIds = [];
        if (!empty($options['cease_services'])) {
            $ceasedIds = db_all("SELECT * FROM services WHERE account_id = ? AND status IN ('active','pending','suspended')", [$id]);
            $ceased = db_exec("UPDATE services SET status = 'ceased' WHERE account_id = ? AND status IN ('active','pending','suspended')", [$id]);
        }
        db()->commit();
        foreach ($ceasedIds as $before) {
            service_changed((int)$before['id'], $before); // billing ends
        }
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    audit('account_close', "Customer $label closed$via" . ($ceased ? ", $ceased service" . ($ceased === 1 ? '' : 's') . ' ceased' : '') . ". Reason: $reason", 'accounts', $id, null, [
        'status' => ['from' => $account['status'], 'to' => 'churned'],
        'closed_reason' => ['from' => '', 'to' => $reason],
    ]);
    return "$label closed" . ($ceased ? " and $ceased service" . ($ceased === 1 ? '' : 's') . ' marked as ceased' : '') . '.';
}

function approvals_notify(array $users, string $subject, string $message, string $link): void
{
    if (!mail_configured()) {
        return;
    }
    foreach ($users as $u) {
        if ((int)$u['id'] === (int)(current_user()['id'] ?? 0)) {
            continue;
        }
        try {
            send_mail($u['email'], $u['name'], $subject, email_layout($subject, '<p>' . nl2br(h($message)) . '</p>' . email_button(app_url() . '/' . $link, 'Open in the CRM')));
        } catch (IntegrationException $e) {
            error_log('Approval email failed: ' . $e->getMessage());
        }
    }
}

function approvals_controller(): void
{
    $action = query('action', 'list');
    $user = current_user();

    if (is_post()) {
        verify_csrf();
        $reason = trim((string)($_POST['reason'] ?? ''));

        if (in_array($action, ['request', 'now'], true)) {
            $type = (string)($_POST['type'] ?? '');
            $account = db_one('SELECT * FROM accounts WHERE id = ?', [(int)($_POST['account_id'] ?? 0)]) ?? not_found();
            $back = url('accounts', ['action' => 'view', 'id' => $account['id']]);
            if (!isset(APPROVAL_TYPES[$type])) {
                not_found();
            }
            if ($reason === '') {
                flash('Please give a reason.', 'error');
                redirect($back);
            }
            $options = ['cease_services' => !empty($_POST['cease_services'])];

            if ($action === 'now') {
                require_permission(approval_permission($type));
                $message = execute_account_action($type, $account, $reason, $options);
                db_exec("UPDATE approval_requests SET status = 'cancelled', decided_by = ?, decided_at = NOW(), decision_note = 'Done directly' WHERE account_id = ? AND status = 'pending'", [$user['id'], $account['id']]);
                flash($message);
                redirect($type === 'delete_account' ? url('accounts') : $back);
            }

            require_permission('customers.edit');
            if (pending_request_for((int)$account['id'])) {
                flash('There is already a request waiting for approval for this customer.', 'error');
                redirect($back);
            }
            db_exec('INSERT INTO approval_requests (type, account_id, account_label, reason, options, requested_by) VALUES (?, ?, ?, ?, ?, ?)',
                [$type, $account['id'], $account['name'] . ' (' . $account['account_number'] . ')', mb_substr($reason, 0, 5000), json_encode($options), $user['id']]);
            $requestId = (int)db()->lastInsertId();
            audit('approval_request', APPROVAL_TYPES[$type] . " requested for {$account['name']}. Reason: $reason", 'accounts', (int)$account['id']);
            approvals_notify(users_with_permission('approvals.decide'), APPROVAL_TYPES[$type] . ' request: ' . $account['name'],
                "{$user['name']} has asked to " . strtolower(APPROVAL_TYPES[$type]) . " {$account['name']} ({$account['account_number']}).\n\nReason: $reason",
                url('approvals', ['action' => 'view', 'id' => $requestId]));
            flash('Request sent. An approver will review it; the customer stays as it is until then.');
            redirect($back);
        }

        $request = db_one('SELECT * FROM approval_requests WHERE id = ?', [query_int('id') ?? 0]) ?? not_found();
        $back = url('approvals', ['action' => 'view', 'id' => $request['id']]);
        if ($request['status'] !== 'pending') {
            flash('This request has already been dealt with.', 'error');
            redirect($back);
        }

        if ($action === 'cancel') {
            if ((int)$request['requested_by'] !== (int)$user['id'] && !can('approvals.decide')) {
                forbidden();
            }
            db_exec("UPDATE approval_requests SET status = 'cancelled', decided_by = ?, decided_at = NOW() WHERE id = ?", [$user['id'], $request['id']]);
            audit('approval_cancel', APPROVAL_TYPES[$request['type']] . " request #{$request['id']} for {$request['account_label']} withdrawn", 'approval_requests', (int)$request['id'], null, null, $request['account_id'] ? (int)$request['account_id'] : null);
            flash('Request withdrawn.');
            redirect($back);
        }

        require_permission('approvals.decide');
        if ((int)$request['requested_by'] === (int)$user['id'] && !is_super_admin()) {
            flash('Someone else needs to approve your own request.', 'error');
            redirect($back);
        }
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
        $requester = db_one('SELECT id, name, email FROM users WHERE id = ?', [$request['requested_by'] ?? 0]);

        if ($action === 'approve') {
            $account = $request['account_id'] ? db_one('SELECT * FROM accounts WHERE id = ?', [$request['account_id']]) : null;
            if (!$account) {
                db_exec("UPDATE approval_requests SET status = 'cancelled', decided_by = ?, decided_at = NOW(), decision_note = 'Customer no longer exists' WHERE id = ?", [$user['id'], $request['id']]);
                flash('That customer no longer exists, so the request was closed.', 'error');
                redirect($back);
            }
            // Mark approved first so the record survives the customer being deleted.
            db_exec("UPDATE approval_requests SET status = 'approved', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?", [$user['id'], $note ?: null, $request['id']]);
            $message = execute_account_action($request['type'], $account, $request['reason'], json_decode((string)$request['options'], true) ?: [], (int)$request['id']);
            audit('approval_approve', APPROVAL_TYPES[$request['type']] . " request #{$request['id']} for {$request['account_label']} approved" . ($note ? ": $note" : ''), 'approval_requests', (int)$request['id'], null, null, $request['type'] === 'delete_account' ? null : (int)$account['id']);
            if ($requester) {
                approvals_notify([$requester], 'Approved: ' . APPROVAL_TYPES[$request['type']] . ' ' . $request['account_label'],
                    "{$user['name']} approved your request. $message" . ($note ? "\n\nNote: $note" : ''), url('approvals', ['action' => 'view', 'id' => $request['id']]));
            }
            flash('Approved. ' . $message);
            redirect($back);
        }

        if ($action === 'reject') {
            if ($note === '') {
                flash('Please say why you are rejecting the request.', 'error');
                redirect($back);
            }
            db_exec("UPDATE approval_requests SET status = 'rejected', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?", [$user['id'], $note, $request['id']]);
            audit('approval_reject', APPROVAL_TYPES[$request['type']] . " request #{$request['id']} for {$request['account_label']} rejected: $note", 'approval_requests', (int)$request['id'], null, null, $request['account_id'] ? (int)$request['account_id'] : null);
            if ($requester) {
                approvals_notify([$requester], 'Rejected: ' . APPROVAL_TYPES[$request['type']] . ' ' . $request['account_label'],
                    "{$user['name']} rejected your request.\n\nReason: $note", url('approvals', ['action' => 'view', 'id' => $request['id']]));
            }
            flash('Request rejected.');
            redirect($back);
        }
        not_found();
    }

    $select = 'SELECT r.*, u.name AS requested_by_name, d.name AS decided_by_name FROM approval_requests r
        LEFT JOIN users u ON u.id = r.requested_by LEFT JOIN users d ON d.id = r.decided_by';

    if ($action === 'view') {
        $request = db_one("$select WHERE r.id = ?", [query_int('id') ?? 0]) ?? not_found();
        if (!can('approvals.decide') && (int)$request['requested_by'] !== (int)$user['id']) {
            forbidden();
        }
        $account = $request['account_id'] ? db_one('SELECT * FROM accounts WHERE id = ?', [$request['account_id']]) : null;
        $impact = $account ? [
            'services' => (int)db_value("SELECT COUNT(*) FROM services WHERE account_id = ? AND status IN ('active','pending','suspended')", [$account['id']]),
            'mrr'      => (float)db_value("SELECT COALESCE(SUM(monthly_price),0) FROM services WHERE account_id = ? AND status = 'active'", [$account['id']]),
            'tickets'  => (int)db_value("SELECT COUNT(*) FROM tickets WHERE account_id = ? AND status NOT IN ('resolved','closed')", [$account['id']]),
            'children' => (int)db_value('SELECT COUNT(*) FROM accounts WHERE parent_id = ?', [$account['id']]),
            'contracts' => (int)db_value("SELECT COUNT(*) FROM contracts WHERE account_id = ? AND status = 'sent'", [$account['id']]),
        ] : null;
        page('approval', compact('request', 'account', 'impact'), 'Request #' . $request['id']);
        return;
    }

    $status = in_array(query('status'), ['pending', 'approved', 'rejected', 'cancelled', 'all'], true) ? query('status') : 'pending';
    $where = [];
    $params = [];
    if ($status !== 'all') {
        $where[] = 'r.status = ?';
        $params[] = $status;
    }
    if (!can('approvals.decide')) {
        $where[] = 'r.requested_by = ?';
        $params[] = $user['id'];
    }
    $rows = db_all("$select" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY r.id DESC LIMIT 200', $params);
    page('approvals', compact('rows', 'status'), 'Approvals');
}
