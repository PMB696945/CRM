<?php
declare(strict_types=1);

/*
 * Dealer portal: a separate site (its own subdomain, or portal.php) where dealers sign in to see their
 * customers, check broadband availability at their dealer prices and submit orders. Orders wait for our
 * approval; approving places them with the supplier exactly as staff orders are placed.
 *
 * Dealers must never learn which wholesale supplier we use, so nothing shown on the portal (pages, messages,
 * emails, scripts) names it: products are shown as our own products, orders by our reference, and any
 * supplier message is passed through portal_clean() first.
 */

const PORTAL_LOCK_AFTER = 5;          // wrong passwords before the account is locked for a while
const PORTAL_LOCK_MINUTES = 15;
const PORTAL_TEMP_PASSWORD_DAYS = 7;
const DEALER_ORDER_STATUSES = ['submitted' => 'Awaiting approval', 'placed' => 'Placed', 'rejected' => 'Not accepted', 'withdrawn' => 'Withdrawn'];

/** The portal's own address (e.g. partners.example.co.uk), set under Settings. */
function portal_host(): string
{
    return strtolower(trim((string)setting('portal_host'), " /"));
}

/** Is this request for the portal (its subdomain, or portal.php)? */
function portal_requested(): bool
{
    if (defined('CRM_PORTAL')) {
        return true;
    }
    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    return portal_host() !== '' && $host === preg_replace('#^https?://#', '', portal_host());
}

/** A link within the portal. */
function portal_url(string $go = 'orders', array $params = []): string
{
    $base = defined('CRM_PORTAL') ? 'portal.php' : 'index.php';
    return $base . '?' . http_build_query(['go' => $go] + array_filter($params, fn($v) => $v !== null && $v !== ''));
}

/** The portal's full address, for emails. */
function portal_public_url(string $go = 'orders', array $params = []): string
{
    $host = portal_host();
    $base = $host !== '' ? (str_starts_with($host, 'http') ? $host : 'https://' . $host) . '/index.php' : app_url() . '/portal.php';
    return $base . '?' . http_build_query(['go' => $go] + $params);
}

/** Take the wholesale supplier's name out of anything a dealer will read. */
function portal_clean(string $text): string
{
    $text = preg_replace('/\b(giacom|cloud\s*market)\s+(said|says)\s*:\s*/i', '', $text);
    return trim((string)preg_replace('/\b(giacom|cloud\s*market)(\'s)?\b/i', 'our supplier$2', $text));
}

/* ---------------------------------------------------------------- Users --- */

function portal_user(): ?array
{
    $id = (int)($_SESSION['portal_user_id'] ?? 0);
    if (!$id) {
        return null;
    }
    // Signed out after the same idle time as staff (Settings), and at least every 12 hours.
    $idle = max(5, (int)(setting('session_idle_minutes') ?: 60)) * 60;
    $now = time();
    if ($now - (int)($_SESSION['portal_last'] ?? $now) > $idle || $now - (int)($_SESSION['portal_since'] ?? $now) > SESSION_MAX_HOURS * 3600) {
        unset($_SESSION['portal_user_id'], $_SESSION['portal_last'], $_SESSION['portal_since']);
        $_SESSION['flash'] = ['message' => 'You were signed out after a period of inactivity. Please sign in again.', 'type' => 'error'];
        return null;
    }
    $_SESSION['portal_last'] = $now;
    $u = db_one('SELECT u.*, a.name AS dealer_name, a.account_number AS dealer_number FROM dealer_users u JOIN accounts a ON a.id = u.account_id
        WHERE u.id = ? AND u.active = 1 AND a.is_dealer = 1', [$id]);
    if (!$u) {
        unset($_SESSION['portal_user_id']);
    }
    return $u;
}

/** Sign in. Returns the user, or throws with a reason the person can act on. */
function portal_login(string $email, string $password): array
{
    $u = db_one('SELECT u.* FROM dealer_users u JOIN accounts a ON a.id = u.account_id WHERE u.email = ? AND a.is_dealer = 1', [strtolower(trim($email))]);
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
        db_exec('UPDATE dealer_users SET failed_logins = ?, locked_until = ? WHERE id = ?',
            [$failed >= PORTAL_LOCK_AFTER ? 0 : $failed, $failed >= PORTAL_LOCK_AFTER ? date('Y-m-d H:i:s', time() + PORTAL_LOCK_MINUTES * 60) : null, $u['id']]);
        throw new RuntimeException($fail);
    }
    if ($u['must_change_password'] && $u['temp_password_expires_at'] && strtotime($u['temp_password_expires_at']) < time()) {
        throw new RuntimeException('That temporary password has expired. Use "Forgotten your password?" to get a new one.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['portal_user_id'] = (int)$u['id'];
    $_SESSION['portal_since'] = $_SESSION['portal_last'] = time();
    db_exec('UPDATE dealer_users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?', [$u['id']]);
    return $u;
}

/** Give a dealer user a temporary password (changed at first sign-in) and email it. Returns ['emailed' => bool, 'error' => ?string]. */
function portal_send_password(int $userId, bool $welcome): array
{
    $u = db_one('SELECT u.*, a.name AS dealer_name FROM dealer_users u JOIN accounts a ON a.id = u.account_id WHERE u.id = ?', [$userId]);
    $pw = temporary_password();
    db_exec('UPDATE dealer_users SET password_hash = ?, must_change_password = 1, temp_password_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY), failed_logins = 0, locked_until = NULL WHERE id = ?',
        [password_hash($pw, PASSWORD_DEFAULT), PORTAL_TEMP_PASSWORD_DAYS, $userId]);
    $company = company('name', config('app_name'));
    $first = explode(' ', trim($u['name']))[0] ?: $u['name'];
    $body = '<p>Hi ' . h($first) . ',</p>'
        . ($welcome ? '<p>You now have access to the ' . h($company) . ' partner portal for <b>' . h($u['dealer_name']) . '</b>. You can check broadband availability for your customers, place orders and follow them there.</p>'
            : '<p>Here\'s a new password for the ' . h($company) . ' partner portal.</p>')
        . '<p style="font-size:15px;line-height:1.7">Email: <b>' . h($u['email']) . '</b><br>Temporary password: <b style="font-family:monospace;font-size:16px">' . h($pw) . '</b></p>'
        . email_button(portal_public_url('login'), 'Sign in')
        . '<p>You\'ll choose your own password when you sign in. The temporary password stops working after ' . PORTAL_TEMP_PASSWORD_DAYS . ' days.</p>'
        . '<p style="color:#667085">If you weren\'t expecting this, you can ignore it.</p>';
    try {
        send_mail($u['email'], $u['name'], $welcome ? "Your $company partner portal access" : "Your $company partner portal password",
            email_layout($welcome ? 'Welcome' : 'New password', $body));
        return ['emailed' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['emailed' => false, 'error' => $e->getMessage()];
    }
}

/* ------------------------------------------------------------ Customers --- */

function portal_customers(int $dealerId): array
{
    return db_all("SELECT a.*, c.name AS contact_name, c.email AS contact_email, c.phone AS contact_phone FROM accounts a
        LEFT JOIN contacts c ON c.id = a.main_contact_id WHERE a.parent_id = ? AND a.status <> 'churned' ORDER BY a.name", [$dealerId]);
}

/** One of the dealer's customers (or null: never another dealer's). */
function portal_customer(int $dealerId, int $accountId): ?array
{
    return db_one("SELECT a.*, c.name AS contact_name, c.email AS contact_email, c.phone AS contact_phone, c.mobile AS contact_mobile FROM accounts a
        LEFT JOIN contacts c ON c.id = a.main_contact_id WHERE a.id = ? AND a.parent_id = ?", [$accountId, $dealerId]);
}

/** A dealer adds a customer: created in the CRM under the dealer. */
function portal_add_customer(array $dealerUser, array $v): int
{
    $id = insert_row('accounts', [
        'name' => mb_substr($v['name'], 0, 190), 'type' => $v['type'] === 'residential' ? 'residential' : 'business', 'status' => 'active',
        'parent_id' => $dealerUser['account_id'], 'parent_relationship' => 'billed_via_dealer',
        'company_number' => $v['company_number'] ?: null, 'email' => $v['email'] ?: null, 'phone' => $v['phone'] ?: null,
        'address' => $v['address'] ?: null, 'address2' => $v['address2'] ?: null, 'city' => $v['city'] ?: null, 'postcode' => strtoupper($v['postcode']) ?: null,
        'notes' => 'Added by ' . $dealerUser['name'] . ' on the partner portal',
    ]);
    if ($v['contact_name'] !== '') {
        $cid = insert_row('contacts', ['account_id' => $id, 'name' => $v['contact_name'], 'email' => $v['email'] ?: null, 'phone' => $v['phone'] ?: null, 'is_primary' => 1]);
        db_exec('UPDATE accounts SET main_contact_id = ? WHERE id = ?', [$cid, $id]);
    }
    audit('create', "Customer {$v['name']} added by dealer user {$dealerUser['name']} ({$dealerUser['dealer_name']}) on the portal", 'accounts', $id, null, null, $id);
    log_activity($id, 'note', 'Customer added on the partner portal by ' . $dealerUser['name'] . ' (' . $dealerUser['dealer_name'] . ')');
    return $id;
}

/* --------------------------------------------------------------- Offers --- */

/** Products a supplier product ID belongs to, as set on our products ("Supplier product IDs"). */
function portal_product_ids(?string $list): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)$list) ?: [])));
}

/**
 * What a dealer can order at a checked address: our products (with a dealer price) that cover a supplier
 * product available there. [['product' => our product, 'supplier' => the supplier product, 'index' => its index in the check]].
 */
function portal_offers(array $check): array
{
    $result = giacom_check_result($check);
    $ours = db_all('SELECT * FROM products WHERE active = 1 AND dealer_price IS NOT NULL AND supplier_product_ids IS NOT NULL AND supplier_product_ids <> \'\'');
    $offers = [];
    foreach ($result['products'] ?? [] as $i => $sp) {
        foreach ($ours as $p) {
            if (!in_array((string)$sp['product_id'], portal_product_ids($p['supplier_product_ids']), true)) {
                continue;
            }
            $current = $offers[$p['id']] ?? null;
            // Several supplier products can sit behind one of ours: offer the fastest.
            if (!$current || (float)($sp['down_mbps'] ?? 0) > (float)($current['supplier']['down_mbps'] ?? 0)) {
                $offers[$p['id']] = ['product' => $p, 'supplier' => $sp, 'index' => $i];
            }
        }
    }
    uasort($offers, fn($a, $b) => (float)$a['product']['dealer_price'] <=> (float)$b['product']['dealer_price'] ?: strcmp($a['product']['name'], $b['product']['name']));
    return array_values($offers);
}

/** The offer for one of our products at a checked address, or null. */
function portal_offer(array $check, int $productId): ?array
{
    foreach (portal_offers($check) as $o) {
        if ((int)$o['product']['id'] === $productId) {
            return $o;
        }
    }
    return null;
}

/* ----------------------------------------------------------- Agreements --- */

/*
 * Dealers contract with us directly: they sign our master terms (a dealer MSA, sent from the CRM as for any
 * dealer) before ordering, and every order they place has its own agreement with them, signed online
 * through the normal e-signature (Contract Summary first where required) before we approve it.
 */

/** The dealer's signed master terms, or null. */
function dealer_msa(int $dealerId): ?array
{
    return db_one("SELECT * FROM contracts WHERE account_id = ? AND kind = 'msa' AND status = 'signed' ORDER BY signed_at DESC LIMIT 1", [$dealerId]);
}

/** Master terms sent to the dealer and waiting to be signed, or null. */
function dealer_msa_waiting(int $dealerId): ?array
{
    return db_one("SELECT * FROM contracts WHERE account_id = ? AND kind = 'msa' AND status = 'sent' ORDER BY id DESC LIMIT 1", [$dealerId]);
}

/** The agreement for a dealer order (or null). */
function dealer_order_agreement(array $d): ?array
{
    return $d['contract_id'] ? db_one('SELECT * FROM contracts WHERE id = ?', [$d['contract_id']]) : null;
}

/** The order as a contract line: our product at the dealer's price, for their customer at the address. */
function dealer_order_line(array $d): array
{
    $product = db_one('SELECT * FROM products WHERE id = ?', [$d['product_id']]) ?? throw new IntegrationException('The product on this order no longer exists.');
    $address = $d['check_id'] ? (string)db_value('SELECT address_label FROM giacom_checks WHERE id = ?', [$d['check_id']]) : '';
    return [
        'product_id' => $product['id'], 'service_type' => $product['category'], 'quantity' => 1,
        'description' => $product['name'] . ' for ' . $d['account_name'] . ($address !== '' ? ', ' . $address : ''),
        'monthly_price' => (float)$product['dealer_price'], 'setup_fee' => (float)($product['dealer_setup_fee'] ?? 0), 'term_months' => (int)$product['term_months'],
    ];
}

/**
 * Create the order's agreement with the dealer (not their customer) and email it to the dealer user who
 * placed the order to sign. Replaces an earlier one that wasn't signed. Returns the contract.
 */
function dealer_order_send_agreement(array $d): array
{
    if ($d['status'] !== 'submitted') {
        throw new IntegrationException('Only an order waiting for approval needs an agreement.');
    }
    $old = dealer_order_agreement($d);
    if ($old && $old['status'] === 'signed') {
        throw new IntegrationException('The agreement for this order has already been signed.');
    }
    if ($old && $old['status'] === 'sent') {
        return contract_send($old, true);
    }
    if (!$d['user_email']) {
        throw new IntegrationException('The dealer user who placed this order has no email address.');
    }
    $dealer = db_one('SELECT * FROM accounts WHERE id = ?', [$d['dealer_id']]);
    $line = dealer_order_line($d);
    $contract = contract_generate($dealer, contract_templates_for($dealer, [$line]), "Order {$d['reference']}: " . $line['description'], 'services',
        (string)$d['user_name'], (string)$d['user_email']);
    db_exec('UPDATE dealer_orders SET contract_id = ?, last_error = NULL WHERE id = ?', [$contract['id'], $d['id']]);
    return contract_send($contract);
}

/** Called when any contract is signed: if it's a dealer order's agreement, the order is ready to approve. */
function dealer_order_agreement_signed(array $contract): void
{
    $d = db_one("SELECT id FROM dealer_orders WHERE contract_id = ? AND status = 'submitted'", [$contract['id']]);
    if (!$d) {
        return;
    }
    $d = dealer_order((int)$d['id']);
    audit('update', "Dealer order {$d['reference']}: agreement {$contract['reference']} signed by {$contract['signer_name']}", 'accounts', (int)$d['account_id'], null, null, (int)$d['account_id']);
    dealer_order_notify_team($d, 'The dealer has signed the agreement, so it\'s ready to approve.');
}

/* --------------------------------------------------------------- Orders --- */

function dealer_order(int $id, ?int $dealerId = null): ?array
{
    return db_one('SELECT d.*, a.name AS account_name, a.account_number, dl.name AS dealer_name, p.name AS product_name, u.name AS user_name, u.email AS user_email
        FROM dealer_orders d JOIN accounts a ON a.id = d.account_id JOIN accounts dl ON dl.id = d.dealer_id LEFT JOIN products p ON p.id = d.product_id
        LEFT JOIN dealer_users u ON u.id = d.dealer_user_id WHERE d.id = ?' . ($dealerId ? ' AND d.dealer_id = ?' : ''), $dealerId ? [$id, $dealerId] : [$id]);
}

/** The dealer's view of an order's progress: [label, detail] (never the supplier's references). */
function dealer_order_progress(array $d): array
{
    if ($d['status'] !== 'placed') {
        return [DEALER_ORDER_STATUSES[$d['status']] ?? ucfirst($d['status']), $d['status'] === 'rejected' ? (string)$d['decision_note'] : ''];
    }
    $g = $d['giacom_order_id'] ? db_one('SELECT status, crd, details FROM giacom_orders WHERE id = ?', [$d['giacom_order_id']]) : null;
    if (!$g) {
        return ['Placed', ''];
    }
    $appt = (json_decode((string)$g['details'], true) ?: [])['appointment'] ?? null;
    $label = match (true) {
        giacom_is_complete((string)$g['status']) => 'Live',
        giacom_is_cancelled((string)$g['status']) => 'Cancelled',
        default => 'In progress',
    };
    $detail = $label === 'In progress'
        ? ($appt ? 'Install ' . fmt_date($appt['date']) . (!empty($appt['slot']) ? ' ' . $appt['slot'] : '') : ($g['crd'] ? 'Required by ' . fmt_date($g['crd']) : ''))
        : '';
    return [$label, portal_clean($detail)];
}

/** Waiting for approval (for the staff menu). */
function dealer_orders_waiting(): int
{
    try {
        return (int)db_value("SELECT COUNT(*) FROM dealer_orders WHERE status = 'submitted'");
    } catch (PDOException) {
        return 0;
    }
}

/** Does anyone use the portal yet? (Shows Dealer orders in the staff menu.) */
function dealer_portal_in_use(): bool
{
    try {
        return (bool)db_value('SELECT 1 FROM dealer_users LIMIT 1') || (bool)db_value('SELECT 1 FROM dealer_orders LIMIT 1');
    } catch (PDOException) {
        return false;
    }
}

/** A dealer submits an order: saved for our approval, and the team is told. */
function portal_submit_order(array $user, array $customer, array $check, array $offer, array $values): array
{
    db_exec('INSERT INTO dealer_orders (dealer_id, account_id, dealer_user_id, check_id, product_id, supplier_product, details) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        $user['account_id'], $customer['id'], $user['id'], $check['id'], $offer['product']['id'], $offer['supplier']['product_id'], json_encode($values),
    ]);
    $id = (int)db()->lastInsertId();
    $ref = sprintf('DO-%06d', $id);
    db_exec('UPDATE dealer_orders SET reference = ? WHERE id = ?', [$ref, $id]);
    audit('create', "Dealer order $ref submitted by {$user['name']} ({$user['dealer_name']}) for {$customer['name']}: {$offer['product']['name']}", 'accounts', (int)$customer['id'], null, null, (int)$customer['id']);
    log_activity((int)$customer['id'], 'task', "Dealer order $ref submitted on the partner portal: {$offer['product']['name']}", 'Approve it under Sales → Dealer orders');
    $order = dealer_order($id);
    try {
        dealer_order_send_agreement($order);
    } catch (Throwable $e) {
        // The order stands: staff see why and can send the agreement from the order.
        db_exec('UPDATE dealer_orders SET last_error = ? WHERE id = ?', ['The agreement couldn\'t be sent: ' . mb_substr($e->getMessage(), 0, 450), $id]);
        dealer_order_notify_team(dealer_order($id), 'Its agreement couldn\'t be sent to the dealer: ' . $e->getMessage());
    }
    return dealer_order($id);
}

/** Tell the onboarding team (or the company inbox) there's a dealer order to approve. */
function dealer_order_notify_team(array $d, string $why = ''): void
{
    if (!mail_configured()) {
        return;
    }
    $team = order_team();
    $to = $team && $team['email'] ? [['email' => $team['email'], 'name' => $team['name']]] : ($team ? order_team_members() : []);
    if (!$to && ($ours = setting('company_email') ?: setting('mail_from_email'))) {
        $to = [['email' => $ours, 'name' => company('name', config('app_name'))]];
    }
    $body = '<p><b>' . h($d['dealer_name']) . '</b> has submitted order <b>' . h($d['reference']) . '</b> for ' . h($d['account_name']) . ': ' . h((string)$d['product_name']) . '.</p>'
        . ($why !== '' ? '<p>' . h($why) . '</p>' : '')
        . email_button(app_url() . '/' . url('dealer_orders', ['action' => 'view', 'id' => $d['id']]), 'Check and approve');
    foreach ($to as $t) {
        try {
            send_mail($t['email'], $t['name'], "Dealer order {$d['reference']} to approve ({$d['dealer_name']})", email_layout('Dealer order to approve', $body));
        } catch (IntegrationException $e) {
            error_log('Dealer order notification failed: ' . $e->getMessage());
        }
    }
}

/** Email the dealer user about their order (approved/placed or not accepted). */
function dealer_order_notify_dealer(array $d, string $subject, string $html): void
{
    if (!$d['user_email'] || !mail_configured()) {
        return;
    }
    try {
        send_mail($d['user_email'], (string)$d['user_name'], $subject, email_layout($subject, $html
            . email_button(portal_public_url('order', ['id' => $d['id']]), 'View the order')));
    } catch (IntegrationException $e) {
        error_log('Dealer email failed: ' . $e->getMessage());
    }
}

/**
 * Approve a dealer order: place it with the supplier as a staff order would be (generating the broadband
 * login), book the chosen appointment if it's still offered, and tell the dealer.
 */
function dealer_order_approve(array $d): array
{
    if ($d['status'] !== 'submitted') {
        throw new IntegrationException('This order has already been ' . strtolower(DEALER_ORDER_STATUSES[$d['status']] ?? $d['status']) . '.');
    }
    if (!dealer_msa((int)$d['dealer_id'])) {
        throw new IntegrationException("{$d['dealer_name']} hasn't signed their master terms yet.");
    }
    $agreement = dealer_order_agreement($d);
    if (!$agreement || $agreement['status'] !== 'signed') {
        throw new IntegrationException('The dealer hasn\'t signed the agreement for this order yet' . ($agreement ? " ({$agreement['reference']} is " . $agreement['status'] . ')' : '') . '.');
    }
    $check = db_one('SELECT * FROM giacom_checks WHERE id = ?', [$d['check_id']]) ?? throw new IntegrationException('The availability check for this order is no longer there. Ask the dealer to check again.');
    $product = null;
    foreach (giacom_check_result($check)['products'] ?? [] as $p) {
        if ((string)$p['product_id'] === (string)$d['supplier_product']) {
            $product = $p;
        }
    }
    $product ?? throw new IntegrationException('That product is no longer available at this address.');
    $v = json_decode((string)$d['details'], true) ?: [];
    $account = db_one('SELECT * FROM accounts WHERE id = ?', [$d['account_id']]);
    $o = $v + [
        'bb_username' => giacom_suggest_username($account), 'bb_password' => substr(strtr(base64_encode(random_bytes(9)), '+/', 'Kq'), 0, 12),
        'bb_suffix' => (string)setting('giacom_username_suffix'), 'realm' => (string)setting('giacom_realm'),
        'care_level' => setting('giacom_care_level') ?: ($product['care_default'] ?? 'standard'), 'access_line_id' => '',
        'crm_product_id' => (string)$d['product_id'], 'appointment' => '',
    ];
    $o['client_ref'] = trim($d['reference'] . ' ' . ($v['client_ref'] ?? ''));
    db_exec('UPDATE dealer_orders SET last_error = NULL WHERE id = ?', [$d['id']]);
    try {
        $giacomId = giacom_place_order($check, $product, $o);
    } catch (IntegrationException $e) {
        db_exec('UPDATE dealer_orders SET last_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 500), $d['id']]);
        throw $e;
    }
    db_exec("UPDATE dealer_orders SET status = 'placed', giacom_order_id = ?, decided_by = ?, decided_at = NOW() WHERE id = ?", [$giacomId, current_user()['id'] ?? null, $d['id']]);
    $note = '';
    if (!empty($v['appointment'])) {
        $chosen = array_values(array_filter(giacom_appointments($check, $product, (string)$v['site_visit_reason'], (string)$v['order_type'])['appointments'],
            fn($a) => giacom_appointment_key($a) === $v['appointment']))[0] ?? null;
        try {
            $chosen ? giacom_book_appointment(db_one('SELECT * FROM giacom_orders WHERE id = ?', [$giacomId]), $chosen) : $note = 'The chosen appointment was no longer available; we\'ll confirm the install date.';
        } catch (IntegrationException $e) {
            $note = 'The chosen appointment couldn\'t be booked; we\'ll confirm the install date.';
        }
    }
    audit('update', "Dealer order {$d['reference']} approved and placed", 'accounts', (int)$d['account_id'], null, null, (int)$d['account_id']);
    $d = dealer_order((int)$d['id']);
    $placed = db_one('SELECT * FROM giacom_orders WHERE id = ?', [$giacomId]);
    $ipProblem = $placed && str_contains((string)$placed['last_error'], 'static IP block') ? 'The static IP block will follow; we\'ll confirm the addresses.' : '';
    dealer_order_notify_dealer($d, "Order {$d['reference']} accepted", '<p>Your order <b>' . h($d['reference']) . '</b> for ' . h($d['account_name']) . ' (' . h((string)$d['product_name']) . ') has been accepted and placed.</p>'
        . ($note !== '' ? '<p>' . h($note) . '</p>' : '') . ($ipProblem !== '' ? '<p>' . h($ipProblem) . '</p>' : '')
        . ($placed ? giacom_setup_details_html($placed) : '') . '<p>You can follow its progress on the portal.</p>');
    return ['order' => $d, 'note' => $note, 'giacom_order_id' => $giacomId];
}

/** An order that won't go ahead: its agreement, if not signed yet, can no longer be signed. */
function dealer_order_cancel_agreement(array $d): void
{
    $agreement = dealer_order_agreement($d);
    if ($agreement && in_array($agreement['status'], ['draft', 'sent', 'failed'], true)) {
        contract_cancel($agreement);
    }
}

function dealer_order_reject(array $d, string $reason): void
{
    if ($d['status'] !== 'submitted') {
        throw new IntegrationException('Only an order waiting for approval can be turned down.');
    }
    db_exec("UPDATE dealer_orders SET status = 'rejected', decision_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?", [mb_substr($reason, 0, 500), current_user()['id'] ?? null, $d['id']]);
    dealer_order_cancel_agreement($d);
    audit('update', "Dealer order {$d['reference']} not accepted: $reason", 'accounts', (int)$d['account_id'], null, null, (int)$d['account_id']);
    dealer_order_notify_dealer(dealer_order((int)$d['id']), "Order {$d['reference']} not accepted",
        '<p>We couldn\'t accept your order <b>' . h($d['reference']) . '</b> for ' . h($d['account_name']) . '.</p><p><b>Reason:</b> ' . nl2br(h($reason)) . '</p>');
}

/* ---------------------------------------------------------- Assets --- */

/*
 * The portal's stylesheet, script and fonts. When the portal's address doesn't serve the CRM's assets folder
 * (e.g. a subdomain pointed at a folder of its own), they're served through the portal itself instead.
 */

/** The file on disk for an asset name, or null. Only the portal's own assets can be fetched. */
function portal_asset_file(string $name): ?string
{
    if (!preg_match('#^(app\.css|app\.js|fonts/[A-Za-z0-9._-]+\.woff2)$#', $name)) {
        return null;
    }
    $here = dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) . '/assets/';
    foreach ([$here, APP_ROOT . '/public/assets/', dirname(__DIR__) . '/public/assets/'] as $dir) {
        if (is_file($dir . $name)) {
            return $dir . $name;
        }
    }
    return null;
}

/** The address to load an asset from: the assets folder if this site has it, otherwise through the portal. */
function portal_asset_url(string $name): string
{
    $local = dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) . '/assets/' . $name;
    if (is_file($local)) {
        return 'assets/' . $name . '?v=' . filemtime($local);
    }
    $file = portal_asset_file($name);
    return portal_url('asset', ['f' => $name, 'v' => $file ? (string)filemtime($file) : null]);
}

/** Send an asset (go=asset&f=...). */
function portal_send_asset(string $name): never
{
    $file = portal_asset_file($name);
    if (!$file) {
        if (!headers_sent()) {
            http_response_code(404);
        }
        portal_exit('');
    }
    $type = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'woff2' => 'font/woff2'][pathinfo($file, PATHINFO_EXTENSION)];
    $body = (string)file_get_contents($file);
    if ($name === 'app.css') {
        // Fonts are fetched the same way as the stylesheet.
        $body = (string)preg_replace_callback('#url\((["\']?)(fonts/[A-Za-z0-9._-]+\.woff2)\1\)#', fn($m) => 'url(' . portal_asset_url($m[2]) . ')', $body);
    }
    if (defined('PORTAL_TESTING')) {
        throw new PortalExit($body);
    }
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=604800');
    header_remove('Set-Cookie');
    echo $body;
    exit;
}

/* ----------------------------------------------------------- The portal --- */

/** Thrown instead of sending the page when the tests drive the portal (PORTAL_TESTING). */
final class PortalExit extends Exception
{
    public function __construct(public readonly string $html = '', public readonly ?string $location = null)
    {
        parent::__construct('Portal response');
    }
}

/** Send a page or a redirect, and stop. */
function portal_exit(string $html, ?string $location = null): never
{
    if (defined('PORTAL_TESTING')) {
        throw new PortalExit($html, $location);
    }
    if ($location !== null) {
        redirect($location);
    }
    echo $html;
    exit;
}

function portal_page(string $template, array $vars, string $title): never
{
    ob_start();
    try {
        render('portal/' . $template, $vars);
        $content = (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    ob_start();
    render('portal/layout', ['content' => $content, 'title' => $title, 'user' => array_key_exists('user', $vars) ? $vars['user'] : portal_user()]);
    portal_exit((string)ob_get_clean());
}

function portal_redirect(string $go, array $params = []): never
{
    portal_exit('', portal_url($go, $params));
}

/** The dealer portal (its subdomain, or portal.php). */
function portal_dispatch(): never
{
    $go = (string)($_GET['go'] ?? 'orders');
    if ($go === 'asset') {
        portal_send_asset((string)($_GET['f'] ?? ''));
    }
    if (!defined('PORTAL_TESTING')) {
        start_session();
    }
    $user = portal_user();
    $error = null;

    // Signing in, forgotten passwords and signing out work without being signed in.
    if ($go === 'logout') {
        unset($_SESSION['portal_user_id'], $_SESSION['portal_last'], $_SESSION['portal_since']);
        portal_redirect('login');
    }
    if ($go === 'forgot') {
        $sent = false;
        if (is_post()) {
            verify_csrf();
            $u = db_one('SELECT u.* FROM dealer_users u JOIN accounts a ON a.id = u.account_id WHERE u.email = ? AND u.active = 1 AND a.is_dealer = 1', [strtolower(trim((string)($_POST['email'] ?? '')))]);
            // At most one email every 10 minutes, and the same answer whether or not the address is known.
            if ($u && (!$u['reset_sent_at'] || strtotime($u['reset_sent_at']) < time() - 600)) {
                db_exec('UPDATE dealer_users SET reset_sent_at = NOW() WHERE id = ?', [$u['id']]);
                portal_send_password((int)$u['id'], false);
            }
            $sent = true;
        }
        portal_page('forgot', ['sent' => $sent, 'user' => null], 'Forgotten password');
    }
    if (!$user || $go === 'login') {
        if (is_post() && $go === 'login') {
            verify_csrf();
            try {
                portal_login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
            if ($error === null) {
                portal_redirect('orders');
            }
        }
        if ($user && $go === 'login' && !is_post()) {
            portal_redirect('orders');
        }
        portal_page('login', ['error' => $error, 'email' => (string)($_POST['email'] ?? ''), 'user' => null], 'Sign in');
    }

    // A temporary password must be changed first.
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
                db_exec('UPDATE dealer_users SET password_hash = ?, must_change_password = 0, temp_password_expires_at = NULL WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
                flash('Your password has been changed.');
                portal_redirect('orders');
            }
        }
        portal_page('password', ['user' => $user, 'error' => $error, 'forced' => (bool)$user['must_change_password']], 'Your password');
    }

    $dealerId = (int)$user['account_id'];
    switch ($go) {
        case 'customers':
            portal_page('customers', ['user' => $user, 'customers' => portal_customers($dealerId)], 'Customers');

        case 'customer_new':
            $v = ['name' => '', 'type' => 'business', 'company_number' => '', 'contact_name' => '', 'email' => '', 'phone' => '', 'address' => '', 'address2' => '', 'city' => '', 'postcode' => ''];
            $errors = [];
            if (is_post()) {
                verify_csrf();
                foreach ($v as $k => $_) {
                    $v[$k] = trim((string)($_POST[$k] ?? ''));
                }
                if ($v['name'] === '') {
                    $errors['name'] = 'Enter the customer\'s name.';
                }
                if ($v['contact_name'] === '') {
                    $errors['contact_name'] = 'Enter a contact name.';
                }
                if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'That isn\'t a valid email address.';
                }
                if ($v['postcode'] === '' || !preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/i', $v['postcode'])) {
                    $errors['postcode'] = 'Enter a full UK postcode.';
                }
                if (!$errors) {
                    $id = portal_add_customer($user, $v);
                    flash('Customer added.');
                    portal_redirect('check', ['customer' => $id]);
                }
            }
            portal_page('customer_form', ['user' => $user, 'v' => $v, 'errors' => $errors], 'Add a customer');

        case 'check':
            // Step 1: the customer and postcode; step 2: choose the address, which runs the check.
            $customers = portal_customers($dealerId);
            $customer = (int)($_REQUEST['customer'] ?? 0) ? portal_customer($dealerId, (int)$_REQUEST['customer']) : null;
            $values = ['postcode' => (string)($_POST['postcode'] ?? ($customer['postcode'] ?? '')), 'building' => (string)($_POST['building'] ?? ''), 'cli' => (string)($_POST['cli'] ?? '')];
            $addresses = null;
            if (is_post()) {
                verify_csrf();
                try {
                    if (!$customer) {
                        throw new IntegrationException('Choose the customer first.');
                    }
                    if (isset($_POST['address'])) {
                        $address = json_decode((string)$_POST['address'], true);
                        if (!is_array($address) || empty($address['address-reference'])) {
                            throw new IntegrationException('Choose an address from the list.');
                        }
                        $id = giacom_check($address, $values['cli'] ?: null, (int)$customer['id'], null);
                        $_SESSION['portal_checks'][$id] = true;
                        portal_redirect('result', ['check' => $id]);
                    }
                    $addresses = giacom_address_search($values['postcode'], trim($values['building']));
                    if (!$addresses) {
                        $error = 'No addresses were found at that postcode.';
                    }
                } catch (IntegrationException $e) {
                    $error = portal_clean($e->getMessage());
                }
            }
            portal_page('check', compact('user', 'customers', 'customer', 'values', 'addresses', 'error'), 'Check availability');

        case 'result':
            [$check, $customer] = portal_check_for($dealerId, (int)($_GET['check'] ?? 0));
            portal_page('result', ['user' => $user, 'check' => $check, 'customer' => $customer, 'offers' => portal_offers($check), 'result' => giacom_check_result($check)], 'Availability');

        case 'order_new':
            if (!dealer_msa($dealerId)) {
                portal_page('message', ['title' => 'Please sign our master terms first', 'message' => portal_msa_message($dealerId), 'sign' => dealer_msa_waiting($dealerId)], 'Master terms');
            }
            [$check, $customer] = portal_check_for($dealerId, (int)($_REQUEST['check'] ?? 0));
            $offer = portal_offer($check, (int)($_REQUEST['product'] ?? 0)) ?? portal_not_found('That product isn\'t available at this address.');
            portal_order_form($user, $customer, $check, $offer);

        case 'order':
            $d = dealer_order((int)($_GET['id'] ?? 0), $dealerId) ?? portal_not_found('Order not found.');
            if (is_post() && ($_POST['action'] ?? '') === 'withdraw' && $d['status'] === 'submitted') {
                verify_csrf();
                db_exec("UPDATE dealer_orders SET status = 'withdrawn' WHERE id = ? AND status = 'submitted'", [$d['id']]);
                dealer_order_cancel_agreement($d);
                audit('update', "Dealer order {$d['reference']} withdrawn by {$user['name']}", 'accounts', (int)$d['account_id']);
                flash('Order withdrawn.');
                portal_redirect('order', ['id' => $d['id']]);
            }
            $placed = $d['giacom_order_id'] ? db_one('SELECT * FROM giacom_orders WHERE id = ?', [$d['giacom_order_id']]) : null;
            portal_page('order_view', ['user' => $user, 'd' => $d, 'progress' => dealer_order_progress($d), 'v' => json_decode((string)$d['details'], true) ?: [],
                'agreement' => dealer_order_agreement($d), 'setup' => $placed ? giacom_setup_details($placed) : []], 'Order ' . $d['reference']);

        case 'orders':
        default:
            $orders = db_all('SELECT d.*, a.name AS account_name, p.name AS product_name FROM dealer_orders d JOIN accounts a ON a.id = d.account_id
                LEFT JOIN products p ON p.id = d.product_id WHERE d.dealer_id = ? ORDER BY d.id DESC LIMIT 200', [$dealerId]);
            portal_page('orders', ['user' => $user, 'orders' => $orders], 'Orders');
    }
}

/** Why a dealer can't order yet, and what to do. */
function portal_msa_message(int $dealerId): string
{
    return dealer_msa_waiting($dealerId)
        ? 'Before placing orders, your master terms need to be signed. We\'ve emailed them to you; you can also review and sign them now.'
        : 'Before placing orders, your master terms need to be signed. Please contact us and we\'ll send them to you to sign online. You can still check availability in the meantime.';
}

function portal_not_found(string $message): never
{
    if (!headers_sent()) {
        http_response_code(404);
    }
    portal_page('message', ['title' => 'Not found', 'message' => $message], 'Not found');
}

/** An availability check belonging to one of the dealer's customers: [check, customer]. */
function portal_check_for(int $dealerId, int $checkId): array
{
    $check = $checkId ? db_one('SELECT * FROM giacom_checks WHERE id = ?', [$checkId]) : null;
    $customer = $check && $check['account_id'] ? portal_customer($dealerId, (int)$check['account_id']) : null;
    if (!$check || !$customer) {
        portal_not_found('That availability check wasn\'t found. Please check again.');
    }
    return [$check, $customer];
}

/** The order form (install type, date and appointment, contacts), and submitting it for approval. */
function portal_order_form(array $user, array $customer, array $check, array $offer): never
{
    $result = giacom_check_result($check);
    $sp = $offer['supplier'];
    $defaultType = in_array($result['quick_result'] ?? null, [4, 10], true) || $check['cli'] ? 'migrate' : 'provide';
    $postedType = is_post() && isset($_POST['order_type']) ? ($_POST['order_type'] === 'migrate' ? 'migrate' : 'provide') : $defaultType;
    $minVisit = giacom_min_visit($result, $postedType);
    $visit = giacom_visit_at_least(giacom_visit_code($_POST['site_visit_reason'] ?? null) ?? $minVisit ?? 'NO_SITE_VISIT', $minVisit);
    $slots = giacom_appointments($check, $sp, $visit, $postedType);
    $appointments = $slots['appointments'];
    $lead = $appointments[0]['date'] ?? ($sp['leadtime']['first_date'] ?? date('Y-m-d', strtotime('+10 weekdays')));
    [$title, $forename, $surname] = giacom_split_name((string)($customer['contact_name'] ?? ''));
    $phone = (string)(($customer['contact_phone'] ?? '') ?: ($customer['contact_mobile'] ?? '') ?: $customer['phone']);
    $values = [
        'order_type' => $postedType, 'cli' => (string)$check['cli'], 'site_visit_reason' => $visit,
        'crd' => max($lead, date('Y-m-d', strtotime('+1 weekday'))), 'appointment' => $appointments ? giacom_appointment_key($appointments[0]) : '',
        'force_new_ont' => giacom_is_fttp($sp) ? giacom_default_ont($postedType) : '',
        'title' => $title, 'forename' => $forename, 'surname' => $surname, 'telephone' => $phone, 'email' => (string)(($customer['contact_email'] ?? '') ?: ($customer['email'] ?? '')),
        'site_title' => $title, 'site_forename' => $forename, 'site_surname' => $surname, 'site_telephone' => $phone, 'site_email' => (string)(($customer['contact_email'] ?? '') ?: ($customer['email'] ?? '')),
        'site_passphrase' => '', 'site_notes' => '', 'hazard_notes' => '', 'client_ref' => '', 'ip_option' => 'dynamic',
    ];
    $errors = [];
    if (is_post()) {
        verify_csrf();
        foreach ($values as $k => $_) {
            $values[$k] = trim((string)($_POST[$k] ?? ''));
        }
        $values['order_type'] = $postedType;
        $values['site_visit_reason'] = $visit;
        if (!empty($_POST['refresh'])) {
            // The install type changed: start again from the earliest date for it.
            $values['crd'] = max($lead, date('Y-m-d', strtotime('+1 weekday')));
            $values['appointment'] = $appointments ? giacom_appointment_key($appointments[0]) : '';
        } else {
            $values['cli'] = preg_replace('/\D/', '', $values['cli']);
            if ($values['cli'] !== '' && !preg_match('/^0\d{9,10}$/', $values['cli'])) {
                $errors['cli'] = 'Enter the phone number as 10 or 11 digits starting with 0.';
            }
            if ($postedType === 'migrate' && $values['cli'] === '') {
                $errors['cli'] = 'To take over an existing line we need its phone number.';
            }
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $values['crd']);
            if (!$d || $d->format('Y-m-d') !== $values['crd'] || $values['crd'] <= date('Y-m-d')) {
                $errors['crd'] = 'Choose a date in the future.';
            } elseif ($values['crd'] < $lead) {
                $errors['crd'] = 'The earliest date is ' . fmt_date($lead) . '.';
            }
            $chosen = array_values(array_filter($appointments, fn($a) => giacom_appointment_key($a) === $values['appointment'] && $a['date'] === $values['crd']))[0]
                ?? array_values(array_filter($appointments, fn($a) => $a['date'] === $values['crd']))[0] ?? null;
            $values['appointment'] = $chosen ? giacom_appointment_key($chosen) : '';
            $values['force_new_ont'] = giacom_is_fttp($sp) ? (in_array($values['force_new_ont'], ['Y', 'N'], true) ? $values['force_new_ont'] : giacom_default_ont($postedType)) : '';
            foreach (['forename' => 'first name', 'surname' => 'surname', 'site_forename' => 'site contact\'s first name', 'site_surname' => 'site contact\'s surname'] as $k => $label) {
                if ($values[$k] === '') {
                    $errors[$k] = "Enter the $label.";
                }
            }
            foreach (['telephone', 'site_telephone'] as $k) {
                if (!preg_match('/^[\d +]{10,16}$/', $values[$k])) {
                    $errors[$k] = 'Enter a phone number.';
                }
            }
            if (!isset(GIACOM_IP_OPTIONS[$values['ip_option']])) {
                $errors['ip_option'] = 'Choose dynamic or static IP.';
            }
            if ($values['email'] === '') {
                $errors['email'] = 'Enter the customer\'s email address.';
            }
            foreach (['email', 'site_email'] as $k) {
                if ($values[$k] !== '' && !isset($errors[$k]) && !filter_var($values[$k], FILTER_VALIDATE_EMAIL)) {
                    $errors[$k] = 'That isn\'t a valid email address.';
                }
            }
            if (!$errors && !dealer_msa((int)$user['account_id'])) {
                $errors['_'] = portal_msa_message((int)$user['account_id']);
            }
            if (!$errors) {
                $d = portal_submit_order($user, $customer, $check, $offer, $values);
                flash($d['contract_id'] ? "Order {$d['reference']} received. We've emailed you its agreement: once it's signed we'll check the order and let you know when it's placed."
                    : "Order {$d['reference']} received. We'll email you its agreement to sign shortly.");
                portal_redirect('order', ['id' => $d['id']]);
            }
        }
    }
    $slotsError = $slots['error'] ? portal_clean($slots['error']) : null;
    portal_page('order_form', compact('user', 'customer', 'check', 'offer', 'result', 'values', 'errors', 'appointments', 'lead', 'minVisit', 'slotsError'), 'Order ' . $offer['product']['name']);
}

/** A labelled input for portal forms. */
function portal_field(array $values, array $errors, string $name, string $label, string $type = 'text', string $help = '', bool $required = false): string
{
    $err = $errors[$name] ?? null;
    return '<div class="field ' . ($err ? 'has-error' : '') . '"><label for="p_' . $name . '">' . h($label) . ($required ? ' <span class="req">*</span>' : '') . '</label>'
        . '<input id="p_' . $name . '" type="' . $type . '" name="' . $name . '" value="' . h((string)($values[$name] ?? '')) . '"' . ($required ? ' required' : '') . ' autocomplete="off">'
        . ($err ? '<div class="error">' . h($err) . '</div>' : ($help ? '<div class="help">' . h($help) . '</div>' : '')) . '</div>';
}

/** The portal's name, e.g. "Netcomm partner portal". */
function portal_name(): string
{
    return company('name', config('app_name')) . ' partner portal';
}

/* ---------------------------------------------------------- Staff pages --- */

/** Staff: dealer orders to approve, one order (approve / turn down / agreement), and dealers' portal users. */
function dealer_orders_controller(): void
{
    $action = query('action', 'list');
    if ($action === 'users') {
        require_permission('customers.edit');
        verify_csrf();
        $dealer = db_one('SELECT * FROM accounts WHERE id = ? AND is_dealer = 1', [query_int('account_id') ?? 0]) ?? not_found('Dealer not found.');
        $back = url('accounts', ['action' => 'view', 'id' => $dealer['id'], 'tab' => 'dealer']) . '#portal-users';
        $do = query('do');
        if ($do === 'add') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('Enter a name and a valid email address.', 'error');
                redirect($back);
            }
            if ($other = db_one('SELECT u.id, a.name FROM dealer_users u JOIN accounts a ON a.id = u.account_id WHERE u.email = ?', [$email])) {
                flash("$email already has portal access (for {$other['name']}).", 'error');
                redirect($back);
            }
            db_exec('INSERT INTO dealer_users (account_id, name, email) VALUES (?, ?, ?)', [$dealer['id'], mb_substr($name, 0, 150), $email]);
            $uid = (int)db()->lastInsertId();
            audit('create', "Partner portal access given to $name <$email> for {$dealer['name']}", 'accounts', (int)$dealer['id'], null, null, (int)$dealer['id']);
            $r = portal_send_password($uid, true);
            flash($r['emailed'] ? "Portal access added. $name has been emailed a temporary password." : "Portal access added, but the welcome email couldn't be sent: {$r['error']}", $r['emailed'] ? 'success' : 'error');
            redirect($back);
        }
        $u = db_one('SELECT * FROM dealer_users WHERE id = ? AND account_id = ?', [query_int('user_id') ?? 0, $dealer['id']]) ?? not_found('Portal user not found.');
        if ($do === 'reset') {
            $r = portal_send_password((int)$u['id'], $u['last_login_at'] === null);
            audit('update', "Partner portal password reset for {$u['name']} <{$u['email']}>", 'accounts', (int)$dealer['id'], null, null, (int)$dealer['id']);
            flash($r['emailed'] ? "A new temporary password has been emailed to {$u['email']}." : "The email couldn't be sent: {$r['error']}", $r['emailed'] ? 'success' : 'error');
        } elseif ($do === 'toggle') {
            db_exec('UPDATE dealer_users SET active = ? WHERE id = ?', [$u['active'] ? 0 : 1, $u['id']]);
            audit('update', 'Partner portal access ' . ($u['active'] ? 'removed from' : 'restored for') . " {$u['name']} <{$u['email']}>", 'accounts', (int)$dealer['id'], null, null, (int)$dealer['id']);
            flash($u['active'] ? "{$u['name']} can no longer sign in to the portal." : "{$u['name']} can sign in to the portal again.");
        }
        redirect($back);
    }

    require_permission('orders.check');
    if ($action === 'view') {
        $d = dealer_order(query_int('id') ?? 0) ?? not_found('Dealer order not found.');
        if (is_post()) {
            verify_csrf();
            require_permission('orders.place');
            try {
                switch (query('do')) {
                    case 'approve':
                        $r = dealer_order_approve($d);
                        flash("Order {$d['reference']} approved and placed." . ($r['note'] !== '' ? ' ' . $r['note'] : ''));
                        break;
                    case 'reject':
                        $reason = trim((string)($_POST['reason'] ?? ''));
                        if ($reason === '') {
                            throw new IntegrationException('Give the dealer a reason.');
                        }
                        dealer_order_reject($d, $reason);
                        flash("Order {$d['reference']} turned down. The dealer has been told why.");
                        break;
                    case 'agreement':
                        $c = dealer_order_send_agreement($d);
                        flash("Agreement {$c['reference']} emailed to {$c['signer_email']} to sign.");
                        break;
                }
            } catch (IntegrationException $e) {
                flash($e->getMessage(), 'error');
            }
            redirect(url('dealer_orders', ['action' => 'view', 'id' => $d['id']]));
        }
        $check = $d['check_id'] ? db_one('SELECT * FROM giacom_checks WHERE id = ?', [$d['check_id']]) : null;
        page('dealer_order', ['d' => $d, 'v' => json_decode((string)$d['details'], true) ?: [], 'agreement' => dealer_order_agreement($d),
            'msa' => dealer_msa((int)$d['dealer_id']), 'check' => $check, 'progress' => dealer_order_progress($d)], 'Dealer order ' . $d['reference']);
        return;
    }
    $show = query('show', 'waiting');
    $where = $show === 'all' ? '1=1' : "d.status = 'submitted'";
    $orders = db_all("SELECT d.*, a.name AS account_name, dl.name AS dealer_name, p.name AS product_name, c.status AS agreement_status, c.reference AS agreement_reference
        FROM dealer_orders d JOIN accounts a ON a.id = d.account_id JOIN accounts dl ON dl.id = d.dealer_id LEFT JOIN products p ON p.id = d.product_id
        LEFT JOIN contracts c ON c.id = d.contract_id WHERE $where ORDER BY d.id DESC LIMIT 300");
    page('dealer_orders', ['orders' => $orders, 'show' => $show], 'Dealer orders');
}

/** For a dealer's page: their portal users, master terms and recent orders. */
function dealer_portal_summary(int $dealerId): array
{
    return [
        'users' => db_all('SELECT * FROM dealer_users WHERE account_id = ? ORDER BY active DESC, name', [$dealerId]),
        'msa' => dealer_msa($dealerId),
        'msaWaiting' => dealer_msa_waiting($dealerId),
        'orders' => db_all('SELECT d.*, a.name AS account_name, p.name AS product_name FROM dealer_orders d JOIN accounts a ON a.id = d.account_id
            LEFT JOIN products p ON p.id = d.product_id WHERE d.dealer_id = ? ORDER BY d.id DESC LIMIT 10', [$dealerId]),
    ];
}
