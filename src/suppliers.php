<?php
declare(strict_types=1);

/*
 * Suppliers: who we buy from, what they sell us at what cost (supplier
 * products, kept up to date from their price files), purchase orders, and
 * documents kept against them. A supplier product marked "preferred" sets the
 * cost price of the CRM product it's linked to.
 */

/* ---------------------------------------------------------- Costs --- */

/** After a supplier product is saved: one preferred supplier per product, and the product's cost follows it. */
function supplier_product_saved(int $id): void
{
    $sp = db_one('SELECT * FROM supplier_products WHERE id = ?', [$id]);
    if (!$sp || !$sp['preferred'] || !$sp['product_id']) {
        return;
    }
    db_exec('UPDATE supplier_products SET preferred = 0 WHERE product_id = ? AND id <> ?', [$sp['product_id'], $id]);
    supplier_sync_product_cost($id);
}

/** A supplier's cost expressed per the product's own billing cycle. */
function supplier_cost_for_product(array $sp, array $product): float
{
    // One-off costs aren't spread over time: a one-off cost is the one-off product's cost as it is.
    if (($sp['billing_frequency'] ?? '') === 'one_off' || ($product['billing_frequency'] ?? '') === 'one_off') {
        return round((float)$sp['cost_price'], 2);
    }
    $monthly = (float)$sp['cost_price'] * (BILLING_PER_MONTH[$sp['billing_frequency']] ?? 1);
    return round($monthly / (BILLING_PER_MONTH[$product['billing_frequency'] ?? 'monthly'] ?? 1), 2);
}

/**
 * Copy a preferred supplier product's cost onto its CRM product.
 * Returns [product name, old cost, new cost] when it changed, else null.
 */
function supplier_sync_product_cost(int $spId): ?array
{
    $sp = db_one('SELECT sp.*, s.name AS supplier_name FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id WHERE sp.id = ?', [$spId]);
    if (!$sp || !$sp['preferred'] || !$sp['product_id'] || !$sp['active']) {
        return null;
    }
    $product = db_one('SELECT * FROM products WHERE id = ?', [$sp['product_id']]);
    if (!$product) {
        return null;
    }
    $cost = supplier_cost_for_product($sp, $product);
    if ($product['cost_price'] !== null && abs((float)$product['cost_price'] - $cost) < 0.005) {
        return null;
    }
    db_exec('UPDATE products SET cost_price = ? WHERE id = ?', [$cost, $product['id']]);
    audit('update', "Product {$product['name']} cost price updated from {$sp['supplier_name']}'s price", 'products', (int)$product['id'], null,
        ['Cost price' => ['from' => $product['cost_price'] === null ? '' : money($product['cost_price']), 'to' => money($cost)]]);
    return [$product['name'], $product['cost_price'], $cost];
}

/** Supplier prices for a CRM product (for the product page). */
function product_supplier_prices(int $productId): array
{
    return db_all('SELECT sp.*, s.name AS supplier_name FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id
        WHERE sp.product_id = ? ORDER BY sp.preferred DESC, sp.active DESC, sp.cost_price', [$productId]);
}

/** Details a customer/dealer record and its supplier record share (same column names in both). */
const COMPANY_SHARED_FIELDS = ['name', 'phone', 'address', 'address2', 'city', 'county', 'postcode'];

/** The supplier record linked to a customer or dealer, if any. */
function account_supplier(int $accountId): ?array
{
    return db_one('SELECT * FROM suppliers WHERE account_id = ?', [$accountId]);
}

/**
 * Copy the shared details from one side of a linked company to the other.
 * Empty values never wipe the other side; with $blanksOnly, only empty values on the other side are filled.
 */
function company_sync(string $from, int $id, bool $blanksOnly = false): void
{
    [$src, $dst] = $from === 'accounts'
        ? [db_one('SELECT * FROM accounts WHERE id = ?', [$id]), account_supplier($id)]
        : [$s = db_one('SELECT * FROM suppliers WHERE id = ?', [$id]), !empty($s['account_id']) ? db_one('SELECT * FROM accounts WHERE id = ?', [$s['account_id']]) : null];
    if (!$src || !$dst) {
        return;
    }
    $set = [];
    foreach (COMPANY_SHARED_FIELDS as $f) {
        $v = trim((string)$src[$f]);
        if ($v !== '' && $v !== (string)$dst[$f] && (!$blanksOnly || trim((string)$dst[$f]) === '')) {
            $set[$f] = $f === 'postcode' ? strtoupper($v) : $v;
        }
    }
    if ($set) {
        $table = $from === 'accounts' ? 'suppliers' : 'accounts';
        db_exec("UPDATE $table SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $dst['id']]);
    }
}

/** Tick or untick "also a supplier" on a customer: link (or create) its supplier record, or unlink it (the supplier is kept). */
function account_set_supplier(int $accountId, bool $on): void
{
    $current = account_supplier($accountId);
    if (!$on) {
        if ($current) {
            db_exec('UPDATE suppliers SET account_id = NULL WHERE id = ?', [$current['id']]);
            audit('update', "Supplier {$current['name']} unlinked from its customer record", 'suppliers', (int)$current['id']);
        }
        return;
    }
    if ($current) {
        return;
    }
    $account = db_one('SELECT * FROM accounts WHERE id = ?', [$accountId]);
    // An existing supplier of the same name (or Xero contact) is linked rather than duplicated.
    $match = array_values(array_filter(db_all('SELECT id, name, xero_contact_id FROM suppliers WHERE account_id IS NULL'),
        fn($s) => ($account['xero_contact_id'] && (int)$s['xero_contact_id'] === (int)$account['xero_contact_id'])
            || company_match_key($s['name']) === company_match_key($account['name'])));
    if (count($match) === 1) {
        db_exec('UPDATE suppliers SET account_id = ? WHERE id = ?', [$accountId, $match[0]['id']]);
        company_sync('suppliers', (int)$match[0]['id'], true);
        company_sync('accounts', $accountId, true);
        audit('update', "Supplier {$match[0]['name']} linked to customer {$account['name']}", 'suppliers', (int)$match[0]['id']);
        return;
    }
    $cols = ['account_id' => $accountId, 'ordering' => 'email'] + array_intersect_key($account, array_flip(COMPANY_SHARED_FIELDS));
    $cols['email'] = $account['email'];
    db_exec('INSERT INTO suppliers (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($cols));
    audit('create', "Supplier {$account['name']} added (also a customer)", 'suppliers', (int)db()->lastInsertId());
}

/** Make a customer record for a supplier that is also a customer or dealer. */
function supplier_make_account(array $supplier, bool $dealer): int
{
    $cols = ['name' => $supplier['name'], 'type' => 'business', 'status' => 'active', 'is_dealer' => $dealer ? 1 : 0, 'email' => $supplier['email'],
        'xero_contact_id' => $supplier['xero_contact_id'] && !db_value('SELECT 1 FROM accounts WHERE xero_contact_id = ?', [$supplier['xero_contact_id']]) ? $supplier['xero_contact_id'] : null]
        + array_intersect_key($supplier, array_flip(COMPANY_SHARED_FIELDS));
    $accountId = insert_row('accounts', $cols);
    db_exec('UPDATE suppliers SET account_id = ? WHERE id = ?', [$accountId, $supplier['id']]);
    audit('create', "Customer {$supplier['name']} added from the supplier record" . ($dealer ? ' (dealer)' : ''), 'accounts', $accountId, null, null, $accountId);
    return $accountId;
}

function supplier_view(array $entity, array $supplier): void
{
    $id = (int)$supplier['id'];
    $products = list_rows('supplier_products', ['filters' => ['supplier_id' => $id], 'per_page' => 0, 'sort' => 'description'])['rows'];
    $orders = list_rows('purchase_orders', ['filters' => ['supplier_id' => $id], 'per_page' => 20, 'sort' => 'created_at', 'dir' => 'desc'])['rows'];
    $files = supplier_documents($id);
    $canWrite = can('suppliers.edit');
    $invoices = db_all('SELECT * FROM supplier_invoices WHERE supplier_id = ? ORDER BY id DESC LIMIT 10', [$id]);
    page('supplier', compact('entity', 'supplier', 'products', 'orders', 'files', 'canWrite', 'invoices'), $supplier['name']);
}

/* ------------------------------------------------ Purchase orders --- */

function po_lines(int $poId): array
{
    return db_all('SELECT * FROM purchase_order_lines WHERE po_id = ? ORDER BY sort, id', [$poId]);
}

function po_total(array $lines): float
{
    return round(array_sum(array_map(fn($l) => (int)$l['quantity'] * (float)$l['unit_cost'], $lines)), 2);
}

/** Validate posted PO lines (parallel arrays). Returns [lines, errors]. */
function po_parse_lines(array $post, int $supplierId): array
{
    $lines = [];
    $errors = [];
    $n = count((array)($post['line_description'] ?? []));
    for ($i = 0; $i < $n; $i++) {
        $get = fn($k) => trim((string)(((array)($post[$k] ?? []))[$i] ?? ''));
        $desc = $get('line_description');
        // The item picked: "sp:<id>" from this supplier's price list, "p:<id>" from our products & tariffs.
        $item = $get('line_item') ?: (ctype_digit($sp = $get('line_supplier_product_id')) ? "sp:$sp" : '');
        [$kind, $itemId] = array_pad(explode(':', $item, 2), 2, '');
        $sp = $kind === 'sp' ? $itemId : '';
        $productId = $kind === 'p' && ctype_digit($itemId) && db_value('SELECT 1 FROM products WHERE id = ?', [$itemId]) ? (int)$itemId : null;
        if ($desc === '' && $sp === '' && !$productId) {
            continue;
        }
        $row = $i + 1;
        $cost = str_replace([',', '£', ' '], '', $get('line_unit_cost'));
        $line = [
            'supplier_product_id' => ctype_digit($sp) && db_value('SELECT 1 FROM supplier_products WHERE id = ? AND supplier_id = ?', [$sp, $supplierId]) ? (int)$sp : null,
            'product_id' => $productId,
            'sku' => mb_substr($get('line_sku'), 0, 80) ?: null,
            'description' => mb_substr($desc, 0, 255),
            'quantity' => (int)$get('line_quantity'),
            'unit_cost' => round((float)$cost, 2),
        ];
        if ($line['description'] === '') {
            $errors[] = "Line $row needs a description.";
        }
        if ($line['quantity'] < 1 || $line['quantity'] > 100000) {
            $errors[] = "Line $row: quantity must be between 1 and 100,000.";
        }
        if (!is_numeric($cost === '' ? '0' : $cost) || $line['unit_cost'] < 0) {
            $errors[] = "Line $row: enter a unit cost.";
        }
        $lines[] = $line;
    }
    if (!$lines) {
        $errors[] = 'Add at least one line to the order.';
    }
    return [$lines, $errors];
}

/**
 * Lines for one of our products the supplier has no price for yet: add it to their price list
 * (linked to the product, so customer orders for it raise purchase orders with them).
 * Returns the descriptions added.
 */
function po_link_new_products(int $supplierId, array &$lines): array
{
    $added = [];
    foreach ($lines as &$l) {
        if (!empty($l['supplier_product_id']) || empty($l['product_id'])) {
            continue;
        }
        $existing = db_value('SELECT id FROM supplier_products WHERE supplier_id = ? AND product_id = ? ORDER BY active DESC, id LIMIT 1', [$supplierId, $l['product_id']]);
        if ($existing) {
            $l['supplier_product_id'] = (int)$existing;
            continue;
        }
        $product = db_one('SELECT * FROM products WHERE id = ?', [$l['product_id']]);
        $l['supplier_product_id'] = insert_row('supplier_products', [
            'supplier_id' => $supplierId, 'product_id' => (int)$product['id'], 'supplier_sku' => $l['sku'], 'description' => $l['description'],
            'cost_price' => $l['unit_cost'], 'billing_frequency' => $product['billing_frequency'] ?: 'monthly', 'active' => 1,
            // The only supplier of the product becomes its preferred one.
            'preferred' => db_value('SELECT 1 FROM supplier_products WHERE product_id = ? AND active = 1', [$product['id']]) ? 0 : 1,
            'price_updated_at' => date('Y-m-d H:i:s'),
        ]);
        audit('create', "Supplier price for {$product['name']} added from a purchase order", 'supplier_products', $l['supplier_product_id']);
        $added[] = $product['name'];
    }
    return $added;
}

function po_save_lines(int $poId, array $lines): void
{
    db_exec('DELETE FROM purchase_order_lines WHERE po_id = ?', [$poId]);
    foreach (array_values($lines) as $i => $l) {
        db_exec('INSERT INTO purchase_order_lines (po_id, supplier_product_id, sku, description, quantity, unit_cost, sort) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$poId, $l['supplier_product_id'], $l['sku'], $l['description'], $l['quantity'], $l['unit_cost'], $i]);
    }
    db_exec('UPDATE purchase_orders SET total = ? WHERE id = ?', [po_total($lines), $poId]);
}

/** Our delivery address: the customer's (when the order is for one) or head office. */
function po_default_delivery(?array $account): string
{
    $parts = $account
        ? [$account['name'], $account['address'], $account['address2'], $account['city'], $account['county'], $account['postcode']]
        : [company('name', config('app_name')), company('address')];
    return implode("\n", array_filter(array_map(fn($v) => trim((string)$v), $parts)));
}

/** Email a purchase order to the supplier. */
function po_send(array $po, string $email, string $name = ''): void
{
    $supplier = db_one('SELECT * FROM suppliers WHERE id = ?', [$po['supplier_id']]);
    $lines = po_lines((int)$po['id']);
    if (!$lines) {
        throw new IntegrationException('Add at least one line before sending the order.');
    }
    $company = company('name', config('app_name'));
    $cell = 'padding:8px;border-bottom:1px solid #eaecf0;';
    $rows = '';
    foreach ($lines as $l) {
        $rows .= '<tr><td style="' . $cell . '">' . h((string)$l['sku']) . '</td><td style="' . $cell . '">' . h($l['description']) . '</td>'
            . '<td style="' . $cell . 'text-align:right">' . (int)$l['quantity'] . '</td><td style="' . $cell . 'text-align:right">' . h(money($l['unit_cost'])) . '</td>'
            . '<td style="' . $cell . 'text-align:right">' . h(money($l['quantity'] * $l['unit_cost'])) . '</td></tr>';
    }
    $body = '<p>Hello' . ($name !== '' ? ' ' . h(explode(' ', $name)[0]) : '') . ',</p>'
        . '<p>Please supply the following on purchase order <b>' . h($po['reference']) . '</b>'
        . ($supplier['account_number'] ? ' for account <b>' . h($supplier['account_number']) . '</b>' : '') . '.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px;margin:8px 0">'
        . '<tr style="color:#667085;text-align:left"><th style="' . $cell . '">Code</th><th style="' . $cell . '">Description</th><th style="' . $cell . 'text-align:right">Qty</th>'
        . '<th style="' . $cell . 'text-align:right">Unit cost</th><th style="' . $cell . 'text-align:right">Total</th></tr>' . $rows
        . '<tr><td colspan="4" style="padding:8px;text-align:right;font-weight:bold">Total (ex VAT)</td><td style="padding:8px;text-align:right;font-weight:bold">' . h(money(po_total($lines))) . '</td></tr></table>'
        . ($po['expected_date'] ? '<p><b>Required by:</b> ' . h(fmt_date($po['expected_date'])) . '</p>' : '')
        . ($po['deliver_to'] ? '<p><b>Deliver to:</b><br>' . nl2br(h($po['deliver_to'])) . '</p>' : '')
        . ($po['notes'] ? '<p>' . nl2br(h($po['notes'])) . '</p>' : '')
        . '<p>Please quote ' . h($po['reference']) . ' on your confirmation and invoice. Reply to this email with any questions.</p>'
        . '<p>Thank you,<br>' . h(current_user()['name'] ?? '') . '<br>' . h($company) . '</p>';
    send_mail($email, $name ?: $supplier['name'], "Purchase order {$po['reference']} from $company", email_layout("Purchase order {$po['reference']}", $body));
    db_exec("UPDATE purchase_orders SET status = IF(status = 'draft', 'sent', status), sent_at = NOW(), order_date = COALESCE(order_date, CURDATE()) WHERE id = ?", [$po['id']]);
}

function purchase_orders_controller(): void
{
    $action = query('action', 'list');
    if (in_array($action, ['list', 'export'], true)) {
        entity_controller('purchase_orders');
        return;
    }
    if (!can('suppliers.view') && !can('purchasing.edit')) {
        forbidden();
    }
    $id = query_int('id');
    $po = $id ? (db_one('SELECT * FROM purchase_orders WHERE id = ?', [$id]) ?? not_found('Purchase order not found.')) : null;
    $back = $po ? url('purchase_orders', ['action' => 'view', 'id' => $po['id']]) : url('purchase_orders');
    if ($action !== 'view') {
        require_permission('purchasing.edit');
    }

    switch ($action) {
        case 'view':
            $supplier = db_one('SELECT * FROM suppliers WHERE id = ?', [$po['supplier_id']]);
            $account = $po['account_id'] ? db_one('SELECT id, name FROM accounts WHERE id = ?', [$po['account_id']]) : null;
            $lines = po_lines((int)$po['id']);
            $creator = $po['created_by'] ? db_value('SELECT name FROM users WHERE id = ?', [$po['created_by']]) : null;
            $customerOrder = $po['customer_order_id'] ? db_one('SELECT id, reference, status FROM customer_orders WHERE id = ?', [$po['customer_order_id']]) : null;
            $invoices = db_all('SELECT * FROM supplier_invoices WHERE po_id = ? ORDER BY id DESC', [$po['id']]);
            page('purchase_order', compact('po', 'supplier', 'account', 'lines', 'creator', 'customerOrder', 'invoices'), $po['reference']);
            return;

        case 'new':
        case 'edit':
            if ($po && $po['status'] !== 'draft') {
                flash('Only draft orders can be changed.', 'error');
                redirect($back);
            }
            $supplierId = $po ? (int)$po['supplier_id'] : (int)(query_int('supplier_id') ?? ($_POST['supplier_id'] ?? 0));
            $supplier = $supplierId ? db_one('SELECT * FROM suppliers WHERE id = ?', [$supplierId]) : null;
            if (!$supplier) {
                // Pick the supplier first: the lines come from their products.
                page('purchase_order_pick', ['suppliers' => db_all('SELECT id, name, category FROM suppliers WHERE active = 1 ORDER BY name'),
                    'accountId' => query_int('account_id'), 'orderId' => query_int('customer_order_id')], 'New purchase order');
                return;
            }
            $account = null;
            $values = $po ?? ['account_id' => query_int('account_id'), 'order_date' => date('Y-m-d'), 'expected_date' => null, 'supplier_ref' => null, 'notes' => null, 'deliver_to' => null];
            $lines = $po ? po_lines((int)$po['id']) : [];
            if (!$po && ($spId = query_int('supplier_product_id')) && ($sp = db_one('SELECT * FROM supplier_products WHERE id = ? AND supplier_id = ?', [$spId, $supplierId]))) {
                $lines = [['supplier_product_id' => $sp['id'], 'sku' => $sp['supplier_sku'], 'description' => $sp['description'], 'quantity' => 1, 'unit_cost' => $sp['setup_cost'] ?? $sp['cost_price']]];
            }
            $errors = [];
            if (is_post()) {
                verify_csrf();
                [$data, $errors] = validate(entity('purchase_orders'), ['supplier_id' => $supplierId] + $_POST);
                [$lines, $lineErrors] = po_parse_lines($_POST, $supplierId);
                if ($lineErrors) {
                    $errors['_lines'] = implode(' ', $lineErrors);
                }
                $values = $data + $values;
                if (!$errors) {
                    $cols = ['account_id', 'order_date', 'expected_date', 'supplier_ref', 'deliver_to', 'notes'];
                    if ($po) {
                        db_exec('UPDATE purchase_orders SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?',
                            array_merge(array_map(fn($c) => $data[$c], $cols), [$po['id']]));
                        $poId = (int)$po['id'];
                        audit('update', "Purchase order {$po['reference']} updated", 'purchase_orders', $poId);
                    } else {
                        $orderId = (int)($_POST['customer_order_id'] ?? 0);
                        $orderId = $orderId && db_value('SELECT 1 FROM customer_orders WHERE id = ?', [$orderId]) ? $orderId : null;
                        db_exec('INSERT INTO purchase_orders (supplier_id, ' . implode(', ', $cols) . ', customer_order_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                            array_merge([$supplierId], array_map(fn($c) => $data[$c], $cols), [$orderId, current_user()['id']]));
                        $poId = (int)db()->lastInsertId();
                        $ref = sprintf('PO-%06d', $poId);
                        db_exec('UPDATE purchase_orders SET reference = ? WHERE id = ?', [$ref, $poId]);
                        audit('create', "Purchase order $ref raised with {$supplier['name']}", 'purchase_orders', $poId, null, null, $data['account_id'] ? (int)$data['account_id'] : null);
                    }
                    $added = po_link_new_products($supplierId, $lines);
                    po_save_lines($poId, $lines);
                    flash('Purchase order saved.' . ($added ? ' Added to ' . $supplier['name'] . "'s price list: " . implode(', ', $added) . '.' : ''));
                    redirect(url('purchase_orders', ['action' => 'view', 'id' => $poId]));
                }
            }
            if (empty($values['deliver_to'])) {
                $values['deliver_to'] = po_default_delivery(!empty($values['account_id']) ? db_one('SELECT * FROM accounts WHERE id = ?', [$values['account_id']]) : null);
            }
            $products = db_all('SELECT sp.id, sp.supplier_sku, sp.description, sp.cost_price, sp.setup_cost, sp.billing_frequency, p.name AS product_name
                FROM supplier_products sp LEFT JOIN products p ON p.id = sp.product_id WHERE sp.supplier_id = ? AND sp.active = 1 ORDER BY sp.description', [$supplierId]);
            // Our products & tariffs this supplier has no price for yet.
            $catalogue = db_all('SELECT p.id, p.sku, p.name, p.category, p.cost_price FROM products p WHERE p.active = 1
                AND NOT EXISTS (SELECT 1 FROM supplier_products sp WHERE sp.product_id = p.id AND sp.supplier_id = ? AND sp.active = 1) ORDER BY p.category, p.name', [$supplierId]);
            page('purchase_order_form', compact('po', 'supplier', 'values', 'lines', 'errors', 'products', 'catalogue'), $po ? 'Edit ' . $po['reference'] : 'New purchase order');
            return;
    }

    if (!is_post() || !$po) {
        redirect($back);
    }
    verify_csrf();
    try {
        switch ($action) {
            case 'send':
                if (in_array($po['status'], ['cancelled', 'received'], true)) {
                    throw new IntegrationException('This order has been ' . $po['status'] . '.');
                }
                $email = trim((string)($_POST['email'] ?? ''));
                po_send($po, $email, trim((string)($_POST['name'] ?? '')));
                audit('po_send', "Purchase order {$po['reference']} emailed to $email", 'purchase_orders', (int)$po['id']);
                flash("Purchase order emailed to $email.");
                break;
            case 'mark_sent':
                db_exec("UPDATE purchase_orders SET status = 'sent', sent_at = COALESCE(sent_at, NOW()), order_date = COALESCE(order_date, CURDATE()) WHERE id = ? AND status = 'draft'", [$po['id']]);
                audit('po_status', "Purchase order {$po['reference']} marked as sent (ordered another way)", 'purchase_orders', (int)$po['id']);
                flash('Marked as sent.');
                break;
            case 'receive':
                if ($po['status'] !== 'sent') {
                    throw new IntegrationException('Only sent orders can be received.');
                }
                $ref = trim((string)($_POST['supplier_ref'] ?? ''));
                db_exec("UPDATE purchase_orders SET status = 'received', received_at = NOW(), supplier_ref = COALESCE(NULLIF(?, ''), supplier_ref) WHERE id = ?", [$ref, $po['id']]);
                audit('po_status', "Purchase order {$po['reference']} received", 'purchase_orders', (int)$po['id']);
                flash('Order marked as received.');
                break;
            case 'cancel':
                db_exec("UPDATE purchase_orders SET status = 'cancelled' WHERE id = ? AND status IN ('draft','sent')", [$po['id']]);
                audit('po_status', "Purchase order {$po['reference']} cancelled", 'purchase_orders', (int)$po['id']);
                flash('Order cancelled.');
                break;
            case 'reopen':
                db_exec("UPDATE purchase_orders SET status = 'draft', sent_at = NULL WHERE id = ? AND status IN ('sent','cancelled')", [$po['id']]);
                audit('po_status', "Purchase order {$po['reference']} put back into draft", 'purchase_orders', (int)$po['id']);
                flash('The order is back in draft.');
                break;
            case 'duplicate':
                db_exec('INSERT INTO purchase_orders (supplier_id, account_id, order_date, deliver_to, notes, created_by) VALUES (?, ?, CURDATE(), ?, ?, ?)',
                    [$po['supplier_id'], $po['account_id'], $po['deliver_to'], $po['notes'], current_user()['id']]);
                $newId = (int)db()->lastInsertId();
                db_exec('UPDATE purchase_orders SET reference = ? WHERE id = ?', [sprintf('PO-%06d', $newId), $newId]);
                po_save_lines($newId, po_lines((int)$po['id']));
                audit('create', 'Purchase order ' . sprintf('PO-%06d', $newId) . " copied from {$po['reference']}", 'purchase_orders', $newId);
                flash('Copy created.');
                redirect(url('purchase_orders', ['action' => 'view', 'id' => $newId]));
            case 'delete':
                if ($po['status'] !== 'draft') {
                    throw new IntegrationException('Only draft orders can be deleted. Cancel it instead.');
                }
                db_exec('DELETE FROM purchase_orders WHERE id = ?', [$po['id']]);
                audit('delete', "Purchase order {$po['reference']} deleted", 'purchase_orders', (int)$po['id']);
                flash('Purchase order deleted.');
                redirect(url('suppliers', ['action' => 'view', 'id' => $po['supplier_id']]));
            default:
                not_found();
        }
    } catch (IntegrationException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect($back);
}

/* ------------------------------------------------ Price files --- */

/** What a price file's columns can be mapped to. */
const PRICE_FILE_FIELDS = [
    'supplier_sku' => 'Supplier code / SKU',
    'description'  => 'Description',
    'cost_price'   => 'Cost price',
    'setup_cost'   => 'Setup / one-off cost',
];

/**
 * Read a price file (CSV or Excel .xlsx) into rows of cells.
 * Returns the rows including the header row.
 */
function price_file_rows(string $path, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return xlsx_rows($path);
    }
    if (!in_array($ext, ['csv', 'txt'], true)) {
        throw new IntegrationException('Price files must be CSV or Excel (.xlsx). For older .xls files, open them in Excel and save as .xlsx or CSV.');
    }
    $raw = (string)file_get_contents($path);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    // The delimiter used most over the first lines (a title line may use another).
    $head = implode("\n", array_slice(explode("\n", $raw), 0, 10));
    $delimiter = ',';
    $best = 0;
    foreach ([',', ';', "\t", '|'] as $d) {
        if (substr_count($head, $d) > $best) {
            $best = substr_count($head, $d);
            $delimiter = $d;
        }
    }
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw);
    rewind($fh);
    $rows = [];
    while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
        if ($row !== [null] && implode('', array_map('trim', array_map('strval', $row))) !== '') {
            $rows[] = array_map(fn($v) => trim((string)$v), $row);
        }
    }
    fclose($fh);
    return $rows;
}

/** The first worksheet of an .xlsx file as rows of cell text. */
function xlsx_rows(string $path): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new IntegrationException('This server can\'t read Excel files (the PHP zip extension is missing). Save the price file as CSV instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new IntegrationException('That doesn\'t look like an Excel .xlsx file.');
    }
    $strings = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $doc = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        foreach ($doc ? $doc->si : [] as $si) {
            $strings[] = isset($si->t) ? (string)$si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]') ?: []));
        }
    }
    // The first sheet in the workbook's order.
    $sheet = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false && preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $wb, $m)
        && preg_match('/<Relationship\b[^>]*Id="' . preg_quote($m[1], '/') . '"[^>]*Target="([^"]+)"/', $rels, $t)) {
        $sheet = 'xl/' . ltrim(str_replace('/xl/', '', $t[1]), '/');
    }
    $xml = $zip->getFromName($sheet);
    $zip->close();
    if ($xml === false) {
        throw new IntegrationException('Couldn\'t find a worksheet in that Excel file.');
    }
    $doc = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_PARSEHUGE);
    if (!$doc) {
        throw new IntegrationException('Couldn\'t read that Excel file.');
    }
    $rows = [];
    foreach ($doc->sheetData->row as $r) {
        $row = [];
        foreach ($r->c as $c) {
            $col = 0;
            foreach (str_split(preg_replace('/\d+/', '', (string)$c['r'])) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $type = (string)$c['t'];
            $value = match ($type) {
                's' => $strings[(int)$c->v] ?? '',
                'inlineStr' => (string)($c->is->t ?? ''),
                default => (string)$c->v,
            };
            $row[max(0, $col - 1)] = trim($value);
        }
        if ($row && implode('', $row) !== '') {
            $filled = [];
            for ($i = 0, $max = max(array_keys($row)); $i <= $max; $i++) {
                $filled[] = $row[$i] ?? '';
            }
            $rows[] = $filled;
        }
    }
    return $rows;
}

/** Guess which column is which from the header row. */
function price_file_guess(array $headers): array
{
    $patterns = [
        'supplier_sku' => '/\b(sku|code|part|product ?(code|id|no)|item ?(code|no)|ref(erence)?|stock ?code)\b/i',
        'description'  => '/\b(desc(ription)?|name|product|item|title)\b/i',
        'cost_price'   => '/\b(cost|price|buy|trade|net|monthly|rental|dealer)\b/i',
        'setup_cost'   => '/\b(setup|set-up|set up|connection|install(ation)?|one[- ]?off|upfront)\b/i',
    ];
    $map = [];
    // More specific first, so "Setup price" isn't taken as the cost price.
    foreach (['setup_cost', 'supplier_sku', 'cost_price', 'description'] as $field) {
        foreach ($headers as $i => $h) {
            if (!in_array($i, $map, true) && preg_match($patterns[$field], (string)$h)) {
                $map[$field] = $i;
                break;
            }
        }
    }
    return $map;
}

function price_parse_money(string $v): ?float
{
    $clean = preg_replace('/[£$€\s,]|GBP/i', '', $v);
    if ($clean === '' || !is_numeric($clean) || (float)$clean < 0) {
        return null;
    }
    return round((float)$clean, 2);
}

/**
 * Work out what a price file would change for a supplier.
 * $map: field => column index. $opts: add_new (bool), frequency (for new lines).
 * Returns ['new' => [...], 'changed' => [...], 'same' => n, 'errors' => [...], 'missing' => [...]].
 */
function price_import_plan(int $supplierId, array $rows, array $map, array $opts = []): array
{
    if (!isset($map['supplier_sku'], $map['cost_price'])) {
        throw new IntegrationException('Choose which columns hold the supplier code and the cost price.');
    }
    $existing = [];
    foreach (db_all('SELECT * FROM supplier_products WHERE supplier_id = ? AND supplier_sku IS NOT NULL', [$supplierId]) as $sp) {
        $existing[mb_strtolower($sp['supplier_sku'])] = $sp;
    }
    $plan = ['new' => [], 'changed' => [], 'same' => 0, 'errors' => [], 'missing' => []];
    $seen = [];
    foreach (array_slice($rows, 1) as $i => $row) {
        $line = $i + 2;
        $sku = mb_substr(trim((string)($row[$map['supplier_sku']] ?? '')), 0, 80);
        if ($sku === '') {
            continue; // section headings and blank lines
        }
        $key = mb_strtolower($sku);
        if (isset($seen[$key])) {
            $plan['errors'][] = "Row $line: $sku appears more than once; only the first is used.";
            continue;
        }
        $seen[$key] = true;
        $cost = price_parse_money((string)($row[$map['cost_price']] ?? ''));
        if ($cost === null) {
            $plan['errors'][] = "Row $line: $sku has no valid cost price (\"" . mb_substr((string)($row[$map['cost_price']] ?? ''), 0, 30) . '").';
            continue;
        }
        $setup = isset($map['setup_cost']) ? price_parse_money((string)($row[$map['setup_cost']] ?? '')) : null;
        $desc = isset($map['description']) ? mb_substr(trim((string)($row[$map['description']] ?? '')), 0, 255) : '';
        $item = ['sku' => $sku, 'description' => $desc, 'cost_price' => $cost, 'setup_cost' => $setup, 'line' => $line];
        if (isset($existing[$key])) {
            $sp = $existing[$key];
            $costChanged = abs((float)$sp['cost_price'] - $cost) >= 0.005;
            $setupChanged = isset($map['setup_cost']) && $setup !== null && abs((float)$sp['setup_cost'] - $setup) >= 0.005;
            if ($costChanged || $setupChanged || !$sp['active']) {
                $plan['changed'][] = $item + ['id' => (int)$sp['id'], 'old_cost' => (float)$sp['cost_price'], 'old_setup' => $sp['setup_cost'],
                    'old_description' => $sp['description'], 'reactivate' => !$sp['active'], 'preferred' => (bool)$sp['preferred'] && $sp['product_id']];
            } else {
                $plan['same']++;
            }
        } elseif (!empty($opts['add_new'])) {
            if ($desc === '') {
                $item['description'] = $sku;
            }
            $plan['new'][] = $item;
        }
    }
    foreach ($existing as $key => $sp) {
        if (!isset($seen[$key]) && $sp['active']) {
            $plan['missing'][] = $sp;
        }
    }
    return $plan;
}

/** Apply a planned price file import. Returns a summary. */
function price_import_apply(int $supplierId, array $plan, array $opts = []): array
{
    $supplier = db_one('SELECT * FROM suppliers WHERE id = ?', [$supplierId]);
    $frequency = isset(BILLING_FREQUENCIES[$opts['frequency'] ?? '']) ? $opts['frequency'] : 'monthly';
    $synced = [];
    db()->beginTransaction();
    try {
        foreach ($plan['changed'] as $c) {
            db_exec('UPDATE supplier_products SET cost_price = ?, setup_cost = COALESCE(?, setup_cost), active = 1, price_updated_at = NOW()'
                . (!empty($opts['update_descriptions']) && $c['description'] !== '' ? ', description = ?' : '') . ' WHERE id = ?',
                array_merge([$c['cost_price'], $c['setup_cost']], !empty($opts['update_descriptions']) && $c['description'] !== '' ? [$c['description']] : [], [$c['id']]));
            if ($r = supplier_sync_product_cost($c['id'])) {
                $synced[] = $r;
            }
        }
        foreach ($plan['new'] as $n) {
            db_exec('INSERT INTO supplier_products (supplier_id, supplier_sku, description, cost_price, setup_cost, billing_frequency, price_updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$supplierId, $n['sku'], $n['description'], $n['cost_price'], $n['setup_cost'], $frequency]);
        }
        $retired = 0;
        if (!empty($opts['retire_missing']) && $plan['missing']) {
            foreach ($plan['missing'] as $m) {
                db_exec('UPDATE supplier_products SET active = 0 WHERE id = ?', [$m['id']]);
                $retired++;
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    $summary = ['updated' => count($plan['changed']), 'added' => count($plan['new']), 'unchanged' => $plan['same'], 'retired' => $retired, 'products' => $synced];
    $changes = [];
    foreach (array_slice($plan['changed'], 0, 200) as $c) {
        $changes[$c['sku']] = ['from' => money($c['old_cost']), 'to' => money($c['cost_price'])];
    }
    audit('price_import', "Price file imported for {$supplier['name']}: {$summary['updated']} prices changed, {$summary['added']} added"
        . ($retired ? ", $retired no longer listed marked unavailable" : '') . ($synced ? ', ' . count($synced) . ' product cost price(s) updated' : ''),
        'suppliers', $supplierId, null, $changes ?: null);
    return $summary;
}

function price_import_controller(): void
{
    require_permission('suppliers.edit');
    $supplier = db_one('SELECT * FROM suppliers WHERE id = ?', [query_int('supplier_id') ?? 0]) ?? not_found('Supplier not found.');
    $step = 'upload';
    $error = null;
    $headers = [];
    $map = json_decode((string)$supplier['price_file_mapping'], true) ?: [];
    $opts = ['add_new' => true, 'retire_missing' => false, 'update_descriptions' => false, 'frequency' => 'monthly'];
    $plan = null;
    $fileName = '';

    if (is_post()) {
        verify_csrf();
        try {
            $token = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token'] ?? ''));
            if (isset($_FILES['file'])) {
                $file = $_FILES['file'];
                if ((int)$file['error'] !== UPLOAD_ERR_OK) {
                    throw new IntegrationException('Choose the price file to upload' . ((int)$file['error'] === UPLOAD_ERR_INI_SIZE ? ' (it is larger than the server allows)' : '') . '.');
                }
                $fileName = basename((string)$file['name']);
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $token = bin2hex(random_bytes(12));
                if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
                    throw new IntegrationException('Price files must be CSV or Excel (.xlsx).');
                }
                if (!move_uploaded_file($file['tmp_name'], storage_path('tmp') . "/price-$token.$ext")) {
                    throw new IntegrationException('Couldn\'t save the file. Check the CRM\'s storage folder is writable.');
                }
                $_SESSION['price_import'][$token] = ['name' => $fileName, 'ext' => $ext, 'supplier' => (int)$supplier['id']];
            }
            $meta = $_SESSION['price_import'][$token] ?? null;
            if (!$meta || $meta['supplier'] !== (int)$supplier['id'] || !is_file($path = storage_path('tmp') . "/price-$token.{$meta['ext']}")) {
                throw new IntegrationException('Upload the price file again.');
            }
            $fileName = $meta['name'];
            $rows = price_file_rows($path, $fileName);
            if (count($rows) < 2) {
                throw new IntegrationException('The file needs a header row and at least one price.');
            }
            // Some price files have a title or notes above the real header: use the first row with 3+ filled cells.
            foreach ($rows as $i => $r) {
                if (count(array_filter($r, fn($v) => $v !== '')) >= min(3, count($r))) {
                    $rows = array_slice($rows, $i);
                    break;
                }
            }
            $headers = array_map(fn($h, $i) => $h !== '' ? $h : 'Column ' . ($i + 1), $rows[0], array_keys($rows[0]));
            $step = 'map';
            if (isset($_POST['map'])) {
                $map = [];
                foreach (PRICE_FILE_FIELDS as $f => $label) {
                    $v = $_POST['map'][$f] ?? '';
                    if ($v !== '' && ctype_digit((string)$v) && isset($headers[(int)$v])) {
                        $map[$f] = (int)$v;
                    }
                }
                $opts = ['add_new' => !empty($_POST['add_new']), 'retire_missing' => !empty($_POST['retire_missing']),
                    'update_descriptions' => !empty($_POST['update_descriptions']), 'frequency' => (string)($_POST['frequency'] ?? 'monthly')];
                $plan = price_import_plan((int)$supplier['id'], $rows, $map, $opts);
                $step = 'preview';
                // Only apply what was previewed: changing the columns or options needs another look first.
                $planKey = hash('sha256', json_encode([$map, $opts, $token]));
                if (($_POST['apply'] ?? '') === '1' && !hash_equals($planKey, (string)($_POST['plan_key'] ?? ''))) {
                    $error = 'You changed the columns or options, so check the changes below before applying them.';
                } elseif (($_POST['apply'] ?? '') === '1') {
                    $summary = price_import_apply((int)$supplier['id'], $plan, $opts);
                    db_exec('UPDATE suppliers SET price_file_mapping = ? WHERE id = ?', [json_encode(['headers' => $headers] + $map), $supplier['id']]);
                    @unlink($path);
                    unset($_SESSION['price_import'][$token]);
                    flash("Prices imported: {$summary['updated']} changed, {$summary['added']} added, {$summary['unchanged']} unchanged"
                        . ($summary['retired'] ? ", {$summary['retired']} marked unavailable" : '') . '.'
                        . ($summary['products'] ? ' Cost price updated on ' . implode(', ', array_map(fn($p) => $p[0], $summary['products'])) . '.' : ''));
                    redirect(url('suppliers', ['action' => 'view', 'id' => $supplier['id']]));
                }
            } else {
                // First look at the file: reuse last time's columns when the headers match, else guess.
                $saved = $map;
                $map = ($saved['headers'] ?? null) === $headers ? array_intersect_key($saved, PRICE_FILE_FIELDS) : price_file_guess($headers);
            }
            $sample = array_slice($rows, 1, 5);
            $planKey ??= '';
            page('price_import', compact('supplier', 'step', 'headers', 'map', 'opts', 'plan', 'fileName', 'token', 'sample', 'error', 'planKey'), 'Import price file');
            return;
        } catch (IntegrationException $e) {
            $error = $e->getMessage();
            if ($headers) {
                $sample = array_slice($rows ?? [], 1, 5);
                $step = 'map';
                $plan = null;
                $planKey = '';
                page('price_import', compact('supplier', 'step', 'headers', 'map', 'opts', 'plan', 'fileName', 'token', 'sample', 'error', 'planKey'), 'Import price file');
                return;
            }
        }
    }
    $step = 'upload';
    page('price_import', compact('supplier', 'step', 'headers', 'map', 'opts', 'plan', 'fileName', 'error') + ['token' => '', 'sample' => [], 'planKey' => ''], 'Import price file');
}
