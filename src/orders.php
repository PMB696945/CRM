<?php
declare(strict_types=1);

/*
 * Customer orders. Accepting a quote creates an order. When the quote has an
 * agreement to sign, the order waits until it's signed; then the onboarding team
 * (a group, set in Settings) is alerted. Someone picks it up and moves it
 * through the steps; at each step the customer can be emailed, and they can
 * follow progress on a tracking page.
 */

const ORDER_STEPS = [
    'accepted'   => 'Quotation accepted',
    'processing' => 'Order processing',
    'confirmed'  => 'Order confirmed',
    'completed'  => 'Order completed',
];
const ORDER_STATUSES = ORDER_STEPS + ['cancelled' => 'Cancelled'];

/** What the customer is told at each step, unless changed in Settings or on the day. */
const ORDER_DEFAULT_MESSAGES = [
    'processing' => "We've started work on your order. We're placing it with our suppliers and will be in touch if we need anything from you.",
    'confirmed'  => "Your order has been confirmed by our suppliers. We'll let you know as soon as everything is up and running.",
    'completed'  => "Your order is complete and your services are now live. Thank you for choosing us. If you need anything, just reply to this email.",
    'cancelled'  => "Your order has been cancelled. If you weren't expecting this, please get in touch.",
];

/** Milestones recorded on an order's timeline that aren't steps staff move it to. */
const ORDER_CONTRACT_EVENTS = ['contract_sent' => 'Agreement sent to sign', 'contract_signed' => 'Agreement signed'];

function order_status_label(?string $status): string
{
    return ORDER_STATUSES[$status] ?? ORDER_CONTRACT_EVENTS[$status] ?? humanize((string)$status);
}

/**
 * The steps to show for an order, with the contract's progress between "accepted" and "processing" when there
 * is one (or contracts are made automatically): [['label', 'state' => done|current|waiting|todo]].
 */
function order_progress(array $order, ?array $contract): array
{
    $steps = array_keys(ORDER_STEPS);
    $at = array_search($order['status'], $steps, true);
    $out = [];
    foreach (ORDER_STEPS as $key => $label) {
        $i = array_search($key, $steps, true);
        $out[$key] = ['label' => $label, 'state' => $order['status'] === 'cancelled' ? 'todo' : ($i < $at ? 'done' : ($i === $at ? 'current' : 'todo'))];
    }
    if (!$contract && setting('contracts_auto_on_accept', '1') !== '1') {
        return array_values($out);
    }
    $cs = $contract['status'] ?? null;
    $sent = in_array($cs, ['sent', 'signed'], true);
    $signed = $cs === 'signed';
    $contractSteps = [
        'contract_sent' => ['label' => 'Agreement sent', 'state' => $sent ? 'done' : 'todo'],
        'contract_signed' => ['label' => 'Agreement signed', 'state' => $signed ? 'done' : ($sent ? 'waiting' : 'todo')],
    ];
    return array_values(['accepted' => $out['accepted']] + $contractSteps + array_slice($out, 1, null, true));
}

function order_default_message(string $status): string
{
    return (string)(setting('order_message_' . $status) ?: (ORDER_DEFAULT_MESSAGES[$status] ?? ''));
}

/** The step after this one, or null at the end. */
function order_next_step(string $status): ?string
{
    $keys = array_keys(ORDER_STEPS);
    $i = array_search($status, $keys, true);
    return $i !== false && isset($keys[$i + 1]) ? $keys[$i + 1] : null;
}

function order_is_open(array $order): bool
{
    return !in_array($order['status'], ['completed', 'cancelled'], true);
}

/** The order's agreement, if it has one that still needs signing (the order can't go further until it's signed). */
function order_unsigned_contract(array $order): ?array
{
    $contract = order_contract($order);
    return $contract && $contract['status'] !== 'signed' ? $contract : null;
}

/** Stop work on an order whose agreement hasn't been signed. */
function order_require_signed(array $order): void
{
    if ($c = order_unsigned_contract($order)) {
        throw new IntegrationException("Agreement {$c['reference']} hasn't been signed yet, so the order can't go any further. "
            . 'If it was signed another way, use "Mark as signed" on the agreement.');
    }
}

function order_tracking_url(array $order): string
{
    return app_url() . '/order.php?t=' . rawurlencode((string)$order['token']);
}

/** The onboarding team (a group), from Settings. */
function order_team(): ?array
{
    $id = (int)setting('order_group_id');
    return $id ? db_one('SELECT * FROM ticket_groups WHERE id = ? AND active = 1', [$id]) : null;
}

function order_team_members(): array
{
    $team = order_team();
    return $team ? db_all('SELECT u.id, u.name, u.email FROM ticket_group_members m JOIN users u ON u.id = m.user_id WHERE m.group_id = ? AND u.active = 1 ORDER BY u.name', [$team['id']]) : [];
}

/** Is the signed-in user someone who works orders (in the team, or allowed to)? */
function order_is_team_member(): bool
{
    $team = order_team();
    return $team && in_array((int)(current_user()['id'] ?? 0), array_map('intval', array_column(order_team_members(), 'id')), true);
}

/** Open orders nobody has picked up yet. */
function orders_waiting_count(): int
{
    return (int)db_value("SELECT COUNT(*) FROM customer_orders WHERE assigned_to IS NULL AND status NOT IN ('completed','cancelled')");
}

function order_events(int $orderId, bool $customerOnly = false): array
{
    return db_all('SELECT e.*, u.name AS user_name FROM customer_order_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.order_id = ?'
        . ($customerOnly ? ' AND e.status IS NOT NULL' : '') . ' ORDER BY e.id', [$orderId]);
}

function order_add_event(int $orderId, ?string $status, ?string $message, ?string $note, ?string $emailedTo = null): void
{
    db_exec('INSERT INTO customer_order_events (order_id, status, message, note, emailed_to, user_id) VALUES (?, ?, ?, ?, ?, ?)',
        [$orderId, $status, $message !== '' ? $message : null, $note !== '' ? $note : null, $emailedTo, current_user()['id'] ?? null]);
}

/** Create the order for an accepted quote (once), and alert the onboarding team unless told not to (it waits for the agreement). */
function order_create_from_quote(array $quote, bool $notify = true): array
{
    if ($existing = db_one('SELECT * FROM customer_orders WHERE quote_id = ?', [$quote['id']])) {
        return $existing;
    }
    $totals = quote_totals(quote_lines((int)$quote['id']));
    db_exec('INSERT INTO customer_orders (quote_id, account_id, title, contact_name, contact_email, token, monthly_total, setup_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        $quote['id'], $quote['account_id'], mb_substr((string)$quote['title'], 0, 200),
        ($quote['response_name'] ?? null) ?: $quote['recipient_name'], ($quote['response_email'] ?? null) ?: $quote['recipient_email'],
        bin2hex(random_bytes(24)), $totals['monthly'], $totals['setup'],
    ]);
    $id = (int)db()->lastInsertId();
    $ref = sprintf('ORD-%06d', $id);
    db_exec('UPDATE customer_orders SET reference = ? WHERE id = ?', [$ref, $id]);
    order_add_event($id, 'accepted', 'Thank you for accepting quote ' . $quote['reference'] . '. Your order has been passed to our onboarding team.',
        "Created from quote {$quote['reference']}");
    audit('create', "Order $ref created from quote {$quote['reference']}", 'customer_orders', $id, null, null, (int)$quote['account_id']);
    log_activity((int)$quote['account_id'], 'note', "Order $ref created from quote {$quote['reference']}");
    $order = db_one('SELECT * FROM customer_orders WHERE id = ?', [$id]);
    if ($notify) {
        order_notify_team($order);
    }
    return $order;
}

/** Email the onboarding team about a new order (the group's shared email, or each member). */
function order_notify_team(array $order): void
{
    $team = order_team();
    if (!$team || !mail_configured()) {
        return;
    }
    $to = $team['email'] ? [['email' => $team['email'], 'name' => $team['name']]] : order_team_members();
    $account = db_one('SELECT name FROM accounts WHERE id = ?', [$order['account_id']]);
    $subject = "New order {$order['reference']} for {$account['name']}";
    $contract = order_contract($order);
    $body = '<p>' . ($contract && $contract['status'] === 'signed' ? 'The agreement has been signed' : 'A quote has been accepted') . ' and order <b>' . h($order['reference']) . '</b> is waiting to be picked up.</p>'
        . '<p><b>' . h($account['name']) . '</b> – ' . h($order['title']) . '<br>' . h(money($order['monthly_total'])) . ' a month, ' . h(money($order['setup_total'])) . ' one-off</p>'
        . email_button(app_url() . '/' . url('customer_orders', ['action' => 'view', 'id' => $order['id']]), 'Open the order');
    foreach ($to as $u) {
        try {
            send_mail($u['email'], $u['name'], $subject, email_layout('New order to process', $body));
        } catch (IntegrationException $e) {
            error_log('Order notification failed: ' . $e->getMessage());
        }
    }
}

/** Take an unassigned order (atomic, so two people can't both pick it up). */
function order_pick_up(array $order, int $userId): bool
{
    $ok = db_exec('UPDATE customer_orders SET assigned_to = ?, picked_up_at = NOW() WHERE id = ? AND assigned_to IS NULL', [$userId, $order['id']]) > 0;
    if ($ok) {
        $name = db_value('SELECT name FROM users WHERE id = ?', [$userId]);
        order_add_event((int)$order['id'], null, null, "Picked up by $name");
        audit('update', "Order {$order['reference']} picked up by $name", 'customer_orders', (int)$order['id']);
    }
    return $ok;
}

/** A row of the steps for emails, with the current one highlighted. */
function order_progress_email_html(string $status): string
{
    $keys = array_keys(ORDER_STEPS);
    $at = array_search($status, $keys, true);
    $cells = '';
    foreach (ORDER_STEPS as $key => $label) {
        $i = array_search($key, $keys, true);
        $done = $at !== false && $i <= $at;
        $cells .= '<td style="padding:8px 4px;text-align:center;font-size:12px;border-top:4px solid ' . ($done ? '#465fff' : '#e4e7ec') . ';color:' . ($done ? '#1d2939' : '#98a2b3') . ';'
            . ($i === $at ? 'font-weight:bold' : '') . '">' . ($done ? '&#10003; ' : '') . h($label) . '</td>';
    }
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:separate;border-spacing:4px 0;margin:12px 0"><tr>' . $cells . '</tr></table>';
}

/** Email the customer about their order's progress. Returns the address used, or null. */
function order_email_customer(array $order, string $status, string $message): ?string
{
    if (!$order['contact_email'] || !mail_configured()) {
        return null;
    }
    $company = company('name', config('app_name'));
    $first = trim(explode(' ', preg_replace('/^(mr|mrs|ms|miss|dr)\.?\s+/i', '', (string)$order['contact_name']))[0] ?? '');
    $label = order_status_label($status);
    $body = '<p>Hi ' . h($first ?: 'there') . ',</p>'
        . '<p>An update on your order <b>' . h($order['reference']) . '</b> – ' . h($order['title']) . ':</p>'
        . ($status !== 'cancelled' ? order_progress_email_html($status) : '<p><b>' . h($label) . '</b></p>')
        . ($message !== '' ? '<p>' . nl2br(h($message)) . '</p>' : '')
        . email_button(order_tracking_url($order), 'Track your order')
        . '<p style="color:#667085;font-size:13px">Questions? Just reply to this email.</p>';
    send_mail($order['contact_email'], (string)$order['contact_name'], "Your order {$order['reference']}: $label", email_layout($label, $body));
    return $order['contact_email'];
}

/**
 * Move an order to a step (or cancel it), record it, and tell the customer if asked.
 * Returns the address the customer was emailed at, or null.
 */
function order_set_status(array $order, string $status, string $message, bool $notify, string $note = ''): ?string
{
    if (!isset(ORDER_STATUSES[$status])) {
        throw new IntegrationException('Unknown order step.');
    }
    if (!order_is_open($order)) {
        throw new IntegrationException('This order is ' . strtolower(order_status_label($order['status'])) . '.');
    }
    if ($status !== 'cancelled') {
        order_require_signed($order);
    }
    $user = current_user();
    $old = $order['status'];
    db_exec('UPDATE customer_orders SET status = ?, assigned_to = COALESCE(assigned_to, ?), picked_up_at = COALESCE(picked_up_at, IF(? IS NULL, NULL, NOW())),
        confirmed_at = IF(? = \'confirmed\', COALESCE(confirmed_at, NOW()), confirmed_at), completed_at = IF(? = \'completed\', NOW(), completed_at) WHERE id = ?',
        [$status, $user['id'] ?? null, $user['id'] ?? null, $status, $status, $order['id']]);
    $order = db_one('SELECT * FROM customer_orders WHERE id = ?', [$order['id']]);
    $emailed = null;
    $failure = '';
    if ($notify) {
        try {
            $emailed = order_email_customer($order, $status, $message);
        } catch (IntegrationException $e) {
            $failure = 'Customer email failed: ' . $e->getMessage();
            error_log('Order update email failed: ' . $e->getMessage());
        }
    }
    order_add_event((int)$order['id'], $status, $message, trim($note . ($failure !== '' ? "\n$failure" : '')), $emailed);
    $label = order_status_label($status);
    audit('update', "Order {$order['reference']}: $label" . ($emailed ? " (customer emailed at $emailed)" : ''), 'customer_orders', (int)$order['id'], null,
        ['Step' => ['from' => order_status_label($old), 'to' => $label]], (int)$order['account_id']);
    log_activity((int)$order['account_id'], $emailed ? 'email' : 'note', "Order {$order['reference']}: $label" . ($emailed ? " – customer emailed" : ''), $message ?: null);
    if ($failure !== '') {
        throw new IntegrationException("Moved to \"$label\", but the customer couldn't be emailed: " . preg_replace('/^Customer email failed: /', '', $failure));
    }
    return $emailed;
}

function customer_orders_controller(): void
{
    $action = query('action', 'list');
    if (in_array($action, ['list', 'export'], true)) {
        entity_controller('customer_orders');
        return;
    }
    $id = query_int('id');
    $order = $id ? db_one('SELECT * FROM customer_orders WHERE id = ?', [$id]) : null;

    if ($action === 'create' && is_post()) {
        // For a quote accepted before orders existed.
        verify_csrf();
        require_permission('onboarding.edit');
        $quote = db_one("SELECT * FROM quotes WHERE id = ? AND status = 'accepted'", [query_int('quote_id') ?? 0]) ?? not_found('Accepted quote not found.');
        $order = order_create_from_quote($quote);
        flash("Order {$order['reference']} created.");
        redirect(url('customer_orders', ['action' => 'view', 'id' => $order['id']]));
    }
    if (!$order) {
        not_found('Order not found.');
    }
    $back = url('customer_orders', ['action' => 'view', 'id' => $order['id']]);

    if ($action === 'view') {
        $account = db_one('SELECT * FROM accounts WHERE id = ?', [$order['account_id']]);
        $quote = $order['quote_id'] ? db_one('SELECT * FROM quotes WHERE id = ?', [$order['quote_id']]) : null;
        $lines = $quote ? quote_lines((int)$quote['id']) : [];
        $contracts = $quote ? db_all('SELECT * FROM contracts WHERE quote_id = ? ORDER BY id DESC', [$quote['id']]) : [];
        $contract = order_contract($order);
        $hasTemplates = (bool)db_value('SELECT 1 FROM contract_templates WHERE active = 1 LIMIT 1');
        $events = order_events((int)$order['id']);
        $assignee = $order['assigned_to'] ? db_one('SELECT id, name FROM users WHERE id = ?', [$order['assigned_to']]) : null;
        $users = db_all('SELECT id, name FROM users WHERE active = 1 ORDER BY name');
        $purchaseOrders = can('suppliers.view') ? order_purchase_orders((int)$order['id']) : [];
        $poPlan = can('purchasing.edit') && order_is_open($order) ? order_po_plan($order) : ['suppliers' => [], 'skipped' => []];
        $giacomOrders = can('orders.check') ? db_all('SELECT * FROM giacom_orders WHERE account_id = ? AND created_at >= ? ORDER BY id DESC', [$order['account_id'], $order['created_at']]) : [];
        page('customer_order', compact('order', 'account', 'quote', 'lines', 'contracts', 'contract', 'hasTemplates', 'events', 'assignee', 'users', 'purchaseOrders', 'giacomOrders', 'poPlan'),
            $order['reference'] . ' ' . $order['title']);
        return;
    }

    if (!is_post()) {
        redirect($back);
    }
    verify_csrf();
    require_permission('onboarding.edit');
    try {
        switch ($action) {
            case 'pick_up':
                if (!order_pick_up($order, (int)current_user()['id'])) {
                    throw new IntegrationException('Someone else has already picked this order up.');
                }
                flash('The order is yours.');
                break;
            case 'assign':
                $to = (int)($_POST['assigned_to'] ?? 0);
                $name = $to ? db_value('SELECT name FROM users WHERE id = ? AND active = 1', [$to]) : null;
                if ($to && !$name) {
                    throw new IntegrationException('Choose someone to give the order to.');
                }
                db_exec('UPDATE customer_orders SET assigned_to = ?, picked_up_at = IF(? IS NULL, NULL, COALESCE(picked_up_at, NOW())) WHERE id = ?', [$to ?: null, $to ?: null, $order['id']]);
                order_add_event((int)$order['id'], null, null, $name ? "Given to $name" : 'Put back in the queue');
                audit('update', "Order {$order['reference']} " . ($name ? "given to $name" : 'put back in the queue'), 'customer_orders', (int)$order['id']);
                flash($name ? "Order given to $name." : 'The order is back in the queue.');
                break;
            case 'status':
                $status = (string)($_POST['status'] ?? '');
                $raised = null;
                if (!empty($_POST['raise_pos']) && $status === 'processing' && order_is_open($order) && can('purchasing.edit')) {
                    $raised = order_raise_purchase_orders($order, null, true);
                }
                $emailed = order_set_status(db_one('SELECT * FROM customer_orders WHERE id = ?', [$order['id']]), $status, trim((string)($_POST['message'] ?? '')),
                    !empty($_POST['notify']), trim((string)($_POST['note'] ?? '')));
                flash('Order moved to "' . order_status_label($status) . '".' . ($emailed ? " The customer has been emailed at $emailed." : '')
                    . ($raised !== null ? ' ' . order_raise_message($raised) : ''), $raised && array_filter(array_column($raised, 'problem')) ? 'error' : 'success');
                break;
            case 'raise_pos':
                require_permission('purchasing.edit');
                $ids = array_map('intval', is_array($_POST['suppliers'] ?? null) ? $_POST['suppliers'] : []);
                if (!$ids) {
                    throw new IntegrationException('Tick at least one supplier.');
                }
                $r = order_raise_purchase_orders($order, $ids, !empty($_POST['send']));
                flash(order_raise_message($r), array_filter(array_column($r, 'problem')) ? 'error' : 'success');
                break;
            case 'note':
                $note = trim((string)($_POST['note'] ?? ''));
                if ($note === '') {
                    throw new IntegrationException('Write a note first.');
                }
                order_add_event((int)$order['id'], null, null, mb_substr($note, 0, 5000));
                flash('Note added.');
                break;
            case 'contact':
                $email = trim((string)($_POST['contact_email'] ?? ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new IntegrationException('Enter a valid email address.');
                }
                db_exec('UPDATE customer_orders SET contact_name = ?, contact_email = ? WHERE id = ?',
                    [mb_substr(trim((string)($_POST['contact_name'] ?? '')), 0, 150) ?: null, $email !== '' ? strtolower($email) : null, $order['id']]);
                audit('update', "Order {$order['reference']}: customer contact changed to $email", 'customer_orders', (int)$order['id']);
                flash('Contact updated.');
                break;
            default:
                not_found();
        }
    } catch (IntegrationException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect($back);
}

/* ------------------------------------------------- Purchase orders --- */

const SUPPLIER_ORDERING = ['email' => 'Purchase order by email', 'portal' => 'Their online portal', 'api' => 'Automatically (integration, e.g. Giacom)'];

/** Purchase orders raised for an order. */
function order_purchase_orders(int $orderId): array
{
    return db_all('SELECT p.*, s.name AS supplier_name, s.email AS supplier_email FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id
        WHERE p.customer_order_id = ? ORDER BY p.id', [$orderId]);
}

/**
 * Work out which suppliers need a purchase order for an order: each quote line's
 * product, bought from its preferred supplier (or the cheapest active one).
 * Returns ['suppliers' => [id => ['supplier' => row, 'lines' => [po lines]]], 'skipped' => [[line, reason]]].
 */
function order_po_plan(array $order): array
{
    $plan = ['suppliers' => [], 'skipped' => []];
    $lines = $order['quote_id'] ? quote_lines((int)$order['quote_id']) : [];
    $raised = [];
    foreach (order_purchase_orders((int)$order['id']) as $po) {
        if ($po['status'] !== 'cancelled') {
            $raised[(int)$po['supplier_id']] = $po['reference'];
        }
    }
    foreach ($lines as $l) {
        if (!$l['product_id']) {
            $plan['skipped'][] = [$l['description'], 'Not linked to a product, so there is no supplier to order from'];
            continue;
        }
        $sp = db_one('SELECT sp.*, s.name AS supplier_name, s.ordering, s.active AS supplier_active FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id
            WHERE sp.product_id = ? AND sp.active = 1 AND s.active = 1 ORDER BY sp.preferred DESC, sp.cost_price LIMIT 1', [$l['product_id']]);
        if (!$sp) {
            $plan['skipped'][] = [$l['description'], 'No supplier price is set up for this product'];
            continue;
        }
        if ($sp['ordering'] !== 'email') {
            $plan['skipped'][] = [$l['description'], "Ordered from {$sp['supplier_name']} " . ($sp['ordering'] === 'api' ? 'through the integration' : 'on their portal') . ', not by purchase order'];
            continue;
        }
        if (isset($raised[(int)$sp['supplier_id']])) {
            $plan['skipped'][] = [$l['description'], "Already on {$raised[(int)$sp['supplier_id']]} with {$sp['supplier_name']}"];
            continue;
        }
        $sid = (int)$sp['supplier_id'];
        $plan['suppliers'][$sid] ??= ['supplier' => db_one('SELECT * FROM suppliers WHERE id = ?', [$sid]), 'lines' => []];
        $freq = $sp['billing_frequency'] ?? 'monthly';
        $plan['suppliers'][$sid]['lines'][] = [
            'supplier_product_id' => (int)$sp['id'], 'sku' => $sp['supplier_sku'],
            'description' => $sp['description'] . ($freq ? ' (' . strtolower(BILLING_FREQUENCIES[$freq] ?? $freq) . ')' : ''),
            'quantity' => (int)$l['quantity'], 'unit_cost' => (float)$sp['cost_price'],
        ];
        if ((float)$sp['setup_cost'] > 0) {
            $plan['suppliers'][$sid]['lines'][] = [
                'supplier_product_id' => (int)$sp['id'], 'sku' => $sp['supplier_sku'], 'description' => 'Setup / one-off: ' . $sp['description'],
                'quantity' => (int)$l['quantity'], 'unit_cost' => (float)$sp['setup_cost'],
            ];
        }
    }
    return $plan;
}

/**
 * Raise the planned purchase orders (for the chosen suppliers, or all), linked to
 * the order, and email them to each supplier if asked. Returns a summary list.
 */
function order_raise_purchase_orders(array $order, ?array $supplierIds = null, bool $send = true): array
{
    order_require_signed($order);
    $plan = order_po_plan($order);
    $account = db_one('SELECT * FROM accounts WHERE id = ?', [$order['account_id']]);
    $out = [];
    foreach ($plan['suppliers'] as $sid => $p) {
        if ($supplierIds !== null && !in_array($sid, $supplierIds, true)) {
            continue;
        }
        db_exec('INSERT INTO purchase_orders (supplier_id, account_id, customer_order_id, order_date, deliver_to, notes, created_by) VALUES (?, ?, ?, CURDATE(), ?, ?, ?)', [
            $sid, $account['id'], $order['id'], po_default_delivery($account),
            "For our customer {$account['name']} (our order {$order['reference']}).", current_user()['id'] ?? null,
        ]);
        $poId = (int)db()->lastInsertId();
        $ref = sprintf('PO-%06d', $poId);
        db_exec('UPDATE purchase_orders SET reference = ? WHERE id = ?', [$ref, $poId]);
        po_save_lines($poId, $p['lines']);
        audit('create', "Purchase order $ref raised with {$p['supplier']['name']} for order {$order['reference']}", 'purchase_orders', $poId, null, null, (int)$account['id']);
        $result = ['po_id' => $poId, 'reference' => $ref, 'supplier' => $p['supplier']['name'], 'emailed_to' => null, 'problem' => null];
        if ($send) {
            if (!$p['supplier']['email']) {
                $result['problem'] = 'no orders email for this supplier';
            } else {
                try {
                    po_send(db_one('SELECT * FROM purchase_orders WHERE id = ?', [$poId]), $p['supplier']['email'], (string)$p['supplier']['contact_name']);
                    audit('po_send', "Purchase order $ref emailed to {$p['supplier']['email']}", 'purchase_orders', $poId);
                    $result['emailed_to'] = $p['supplier']['email'];
                } catch (IntegrationException $e) {
                    $result['problem'] = $e->getMessage();
                }
            }
        }
        $out[] = $result;
    }
    if ($out) {
        order_add_event((int)$order['id'], null, null, 'Purchase orders raised: ' . implode('; ', array_map(fn($r) => "{$r['reference']} with {$r['supplier']}"
            . ($r['emailed_to'] ? " (emailed to {$r['emailed_to']})" : ($r['problem'] ? " (not emailed: {$r['problem']})" : ' (not sent yet)')), $out)));
    }
    return $out;
}

function order_raise_message(array $results): string
{
    if (!$results) {
        return 'There were no purchase orders to raise.';
    }
    return 'Raised ' . implode(', ', array_map(fn($r) => "{$r['reference']} ({$r['supplier']}" . ($r['emailed_to'] ? ', emailed' : ($r['problem'] ? ", not emailed: {$r['problem']}" : '')) . ')', $results)) . '.';
}
