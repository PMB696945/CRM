<?php
declare(strict_types=1);

/*
 * New order: one page per customer where staff choose the service type and fill in what that
 * service needs. Broadband goes through the availability checker and is ordered with the
 * supplier straight away; everything else becomes a draft quote, priced from the price list,
 * with the order details attached. From there it's the usual process: send the quote, the
 * customer accepts and signs, and the order reaches the onboarding team with every detail.
 */

const ORDER_FORM_TYPES = ['broadband', 'leased_line', 'mobile', 'sip_trunk', 'hosted_pbx', 'hardware'];
const MOBILE_NETWORKS = ['O2', 'EE', 'Vodafone'];
const MOBILE_CONNECTIONS = ['new' => 'New connection', 'migration' => 'Migration', 'port' => 'Port'];
const PBX_LICENCES = ['basic' => 'Basic', 'enterprise' => 'Enterprise', 'ultimate' => 'Ultimate'];

/** Products to choose from on the order form: a category and/or item types. */
function order_products(?string $category, array $itemTypes = []): array
{
    $where = ['active = 1'];
    $params = [];
    if ($category) {
        $where[] = 'category = ?';
        $params[] = $category;
    }
    if ($itemTypes) {
        $where[] = 'item_type IN (' . implode(',', array_fill(0, count($itemTypes), '?')) . ')';
        array_push($params, ...$itemTypes);
    }
    return db_all('SELECT * FROM products WHERE ' . implode(' AND ', $where) . ' ORDER BY name', $params);
}

/** The price list's product for an item type (e.g. the Enterprise licence), preferring the given category. */
function order_product_for(string $itemType, ?string $category = null): ?array
{
    return db_one('SELECT * FROM products WHERE active = 1 AND item_type = ? ORDER BY (category = ?) DESC, id LIMIT 1', [$itemType, (string)$category]);
}

/** A quote line for a product (in monthly amounts, as quotes are), or a £0 line to price by hand when there's none. */
function order_line(?array $product, string $serviceType, string $description, int $quantity): array
{
    if (!$product) {
        return ['product_id' => null, 'service_type' => $serviceType, 'description' => mb_substr($description, 0, 255), 'quantity' => $quantity,
            'monthly_price' => 0.0, 'setup_fee' => 0.0, 'term_months' => 0];
    }
    $oneOff = $product['billing_frequency'] === 'one_off';
    return [
        'product_id' => (int)$product['id'], 'service_type' => $product['category'] ?: $serviceType, 'description' => mb_substr($description, 0, 255), 'quantity' => $quantity,
        'monthly_price' => $oneOff ? 0.0 : monthly_equivalent($product['monthly_price'], $product['billing_frequency']),
        'setup_fee' => round(($oneOff ? (float)$product['monthly_price'] : 0) + (float)$product['setup_fee'], 2),
        'term_months' => $oneOff ? 0 : (int)$product['term_months'],
    ];
}

/** The customer's addresses to pick from: head office, then their sites. */
function order_address_choices(array $account): array
{
    $choices = ['head' => ['label' => 'Head office: ' . implode(', ', array_filter([$account['address'], $account['city'], $account['postcode']])),
        'site_id' => null, 'address' => $account['address'], 'city' => $account['city'], 'postcode' => $account['postcode']]];
    foreach (db_all('SELECT * FROM sites WHERE account_id = ? ORDER BY name', [$account['id']]) as $s) {
        $choices[(string)$s['id']] = ['label' => $s['name'] . ': ' . implode(', ', array_filter([$s['address'], $s['city'], $s['postcode']])),
            'site_id' => (int)$s['id'], 'address' => $s['address'], 'city' => $s['city'], 'postcode' => $s['postcode']];
    }
    return $choices;
}

/** An address chosen from the address book, or typed in. */
function order_parse_address(array $account, array $post, string $prefix, string $what, array &$errors): ?array
{
    $choice = (string)($post[$prefix . '_choice'] ?? 'head');
    if ($choice !== 'other') {
        $a = order_address_choices($account)[$choice] ?? null;
        if (!$a) {
            $errors[$prefix] = "Choose the $what.";
            return null;
        }
        if (!trim((string)$a['postcode'])) {
            $errors[$prefix] = "That address has no postcode. Add it to the customer, or type the $what in.";
        }
        return ['site_id' => $a['site_id'], 'address' => (string)$a['address'], 'city' => (string)$a['city'], 'postcode' => (string)$a['postcode']];
    }
    $a = ['site_id' => null];
    foreach (['address' => 'first line', 'city' => 'town', 'postcode' => 'postcode'] as $k => $label) {
        $a[$k] = trim((string)($post[$prefix . '_' . $k] ?? ''));
        if ($a[$k] === '') {
            $errors[$prefix] = "Enter the $what: first line, town and postcode.";
        }
    }
    if ($a['postcode'] !== '' && !preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/i', $a['postcode'])) {
        $errors[$prefix] = 'Enter a full UK postcode for the ' . $what . '.';
    }
    $a['postcode'] = strtoupper($a['postcode']);
    return $a;
}

function order_address_text(?array $a): string
{
    return $a ? implode(', ', array_filter([$a['address'] ?? '', $a['city'] ?? '', $a['postcode'] ?? ''], fn($v) => trim((string)$v) !== '')) : '';
}

/** Phone numbers typed one per line (or separated by commas), tidied. Returns [numbers, bad ones]. */
function order_parse_numbers(string $text): array
{
    $numbers = $bad = [];
    foreach (preg_split('/[\r\n,;]+/', $text) as $n) {
        $digits = preg_replace('/[^\d+]/', '', $n);
        if ($digits === '') {
            continue;
        }
        $digits = preg_replace('/^\+44/', '0', $digits);
        preg_match('/^0\d{9,10}$/', $digits) ? $numbers[] = $digits : $bad[] = trim($n);
    }
    return [array_values(array_unique($numbers)), $bad];
}

/** Rows of products and quantities (handsets, hardware) from parallel arrays. */
function order_parse_item_rows(array $post, string $prefix, array $allowed, string $what, array &$errors): array
{
    $rows = [];
    foreach ((array)($post[$prefix . '_product'] ?? []) as $i => $pid) {
        $qty = (int)($post[$prefix . '_qty'][$i] ?? 0);
        if ((string)$pid === '' && $qty <= 1) {
            continue; // an empty row
        }
        if (!isset($allowed[(int)$pid])) {
            $errors[$prefix] = "Choose the $what for each row.";
            continue;
        }
        if ($qty < 1 || $qty > 999) {
            $errors[$prefix] = 'Enter a quantity of 1 or more for each row.';
            continue;
        }
        $rows[] = ['product_id' => (int)$pid, 'product' => $allowed[(int)$pid]['name'], 'qty' => $qty];
    }
    return $rows;
}

function order_int(array $post, string $key, int $min, int $max, string $message, array &$errors): int
{
    $raw = trim((string)($post[$key] ?? ''));
    $n = ctype_digit($raw) ? (int)$raw : -1;
    if ($n < $min || $n > $max) {
        $errors[$key] = $message;
    }
    return max(0, $n);
}

/** Whether a file was chosen for an upload field. */
function order_has_upload(array $files, string $field): bool
{
    return isset($files[$field]) && (int)($files[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/**
 * Check the order form. Returns [details, quote lines, errors, notes]: the details are kept with the
 * quote for the onboarding team; notes say what couldn't be priced from the price list.
 */
function new_order_parse(array $account, array $post, array $files = []): array
{
    $type = (string)($post['service_type'] ?? '');
    $errors = [];
    $lines = [];
    $missing = [];
    $details = ['type' => $type, 'notes' => trim((string)($post['notes'] ?? ''))];
    $price = function (string $itemType, string $category, string $description, int $qty, bool $optional = false) use (&$lines, &$missing): void {
        if ($qty < 1) {
            return;
        }
        $product = order_product_for($itemType, $category);
        if (!$product && $optional) {
            return;
        }
        if (!$product) {
            $missing[PRODUCT_ITEM_TYPES[$itemType]] = true;
        }
        $lines[] = order_line($product, $category, $product ? $product['name'] . ' – ' . $description : $description, $qty);
    };

    switch ($type) {
        case 'mobile':
            $tariffs = array_column(order_products('mobile'), null, 'id');
            $rows = [];
            $first = (array)($post['m_first'] ?? []);
            foreach ($first as $i => $_) {
                $r = [];
                foreach (['first', 'last', 'connection', 'network', 'current_network', 'number', 'pac', 'sim', 'product_id'] as $k) {
                    $r[$k] = trim((string)($post['m_' . $k][$i] ?? ''));
                }
                if (implode('', array_diff_key($r, ['connection' => 1, 'network' => 1])) === '') {
                    continue; // an empty row
                }
                $n = count($rows) + 1;
                $problems = [];
                if ($r['first'] === '' || $r['last'] === '') {
                    $problems[] = 'the user\'s first and last name';
                }
                if (!isset(MOBILE_CONNECTIONS[$r['connection']])) {
                    $problems[] = 'the connection type';
                }
                if (!in_array($r['network'], MOBILE_NETWORKS, true)) {
                    $problems[] = 'the network';
                }
                if (!isset($tariffs[(int)$r['product_id']])) {
                    $problems[] = 'the tariff';
                }
                if ($r['connection'] !== 'new') {
                    $r['pac'] = strtoupper(preg_replace('/\s+/', '', $r['pac']));
                    $r['sim'] = preg_replace('/\s+/', '', $r['sim']);
                    [$nums] = order_parse_numbers($r['number']);
                    $r['number'] = $nums[0] ?? $r['number'];
                    if (!preg_match('/^07\d{9}$/', $r['number'])) {
                        $problems[] = 'their mobile number (07…)';
                    }
                    if (!preg_match('/^[A-Z]{3}\d{6}$/', $r['pac'])) {
                        $problems[] = 'the PAC code (3 letters and 6 numbers, e.g. ABC123456)';
                    }
                    if ($r['current_network'] === '') {
                        $problems[] = 'the network they\'re on now';
                    }
                    $simNeeded = $r['connection'] === 'migration' || $r['current_network'] === $r['network'];
                    if ($simNeeded && !preg_match('/^\d{18,22}$/', $r['sim'])) {
                        $problems[] = 'the SIM card number (18 to 22 digits)' . ($r['connection'] === 'port' ? ', as they\'re staying on ' . $r['network'] : '');
                    }
                } else {
                    $r['pac'] = $r['sim'] = $r['number'] = $r['current_network'] = '';
                }
                if ($problems) {
                    $errors['mobile'][] = "Connection $n: enter " . implode(', ', $problems) . '.';
                }
                $r['product'] = $tariffs[(int)$r['product_id']]['name'] ?? '';
                $rows[] = $r;
                $label = trim($r['first'] . ' ' . $r['last']) . ' (' . (MOBILE_CONNECTIONS[$r['connection']] ?? '') . ($r['connection'] !== 'new' && $r['current_network'] ? ' from ' . $r['current_network'] : '') . ')';
                if (isset($tariffs[(int)$r['product_id']])) {
                    $lines[] = order_line($tariffs[(int)$r['product_id']], 'mobile', $tariffs[(int)$r['product_id']]['name'] . ' – ' . $label, 1);
                }
            }
            if (!$rows) {
                $errors['mobile'][] = 'Add at least one connection.';
            }
            $details['connections'] = $rows;
            $title = 'Mobile – ' . count($rows) . ' connection' . (count($rows) === 1 ? '' : 's');
            break;

        case 'sip_trunk':
            $details['address'] = order_parse_address($account, $post, 'sip_addr', 'installation address', $errors);
            $details['mode'] = in_array($post['sip_mode'] ?? '', ['new', 'port'], true) ? $post['sip_mode'] : '';
            if (!$details['mode']) {
                $errors['sip_mode'] = 'Choose new numbers or porting existing ones.';
            }
            $details['channels'] = order_int($post, 'sip_channels', 1, 500, 'Enter how many channels (1 or more).', $errors);
            if ($details['mode'] === 'new') {
                $details['std_code'] = preg_replace('/\s+/', '', (string)($post['sip_std'] ?? ''));
                if (!preg_match('/^0\d{2,4}$/', $details['std_code'])) {
                    $errors['sip_std'] = 'Enter the STD (area) code, e.g. 0161.';
                }
                $details['ddis'] = order_int($post, 'sip_ddis', 0, 10000, 'Enter how many DDIs (numbers) they need.', $errors);
            } elseif ($details['mode'] === 'port') {
                [$details['numbers'], $bad] = order_parse_numbers((string)($post['sip_numbers'] ?? ''));
                if ($bad || !$details['numbers']) {
                    $errors['sip_numbers'] = $bad ? 'These aren\'t UK phone numbers: ' . implode(', ', $bad) : 'Enter the numbers to port, one per line.';
                }
                $details['provider'] = trim((string)($post['sip_provider'] ?? ''));
                if ($details['provider'] === '') {
                    $errors['sip_provider'] = 'Enter who provides the numbers now.';
                }
                $details['bill_needed'] = !order_has_upload($files, 'sip_bill');
            }
            $where = order_address_text($details['address']);
            $price('sip_channel', 'sip_trunk', $details['channels'] . ' channels at ' . $where, $details['channels']);
            if ($details['mode'] === 'new') {
                $price('ddi', 'sip_trunk', 'new numbers (' . $details['std_code'] . ')', $details['ddis']);
            } elseif ($details['mode'] === 'port') {
                $price('ddi', 'sip_trunk', 'ported numbers', count($details['numbers'] ?? []));
                $price('number_port', 'sip_trunk', 'from ' . $details['provider'], count($details['numbers'] ?? []), true);
            }
            $title = 'SIP trunk – ' . $details['channels'] . ' channel' . ($details['channels'] === 1 ? '' : 's') . ($details['mode'] === 'port' ? ', porting numbers' : '');
            break;

        case 'hosted_pbx':
            $details['users'] = order_int($post, 'pbx_users', 1, 5000, 'Enter how many users / extensions (1 or more).', $errors);
            $details['licence'] = isset(PBX_LICENCES[$post['pbx_licence'] ?? '']) ? $post['pbx_licence'] : '';
            if (!$details['licence']) {
                $errors['pbx_licence'] = 'Choose the licence type.';
            }
            $details['mode'] = in_array($post['pbx_mode'] ?? '', ['new', 'migrate'], true) ? $post['pbx_mode'] : '';
            if (!$details['mode']) {
                $errors['pbx_mode'] = 'Choose a new system or migrating their system to us.';
            }
            if ($details['mode'] === 'new') {
                $details['ddis'] = order_int($post, 'pbx_ddis', 0, 10000, 'Enter how many DDIs (numbers) they need.', $errors);
            } elseif ($details['mode'] === 'migrate') {
                [$details['numbers'], $bad] = order_parse_numbers((string)($post['pbx_numbers'] ?? ''));
                if ($bad || !$details['numbers']) {
                    $errors['pbx_numbers'] = $bad ? 'These aren\'t UK phone numbers: ' . implode(', ', $bad) : 'Enter their existing numbers, one per line.';
                }
                $details['provider'] = trim((string)($post['pbx_provider'] ?? ''));
                if ($details['provider'] === '') {
                    $errors['pbx_provider'] = 'Enter their current provider.';
                }
                $details['current_address'] = order_parse_address($account, $post, 'pbx_addr', 'current installation address', $errors);
                $details['bill_needed'] = !order_has_upload($files, 'pbx_bill');
            }
            $handsets = array_column(order_products('hardware', ['handset', 'headset', 'accessory']) ?: order_products('hardware'), null, 'id');
            $details['handsets'] = order_parse_item_rows($post, 'pbx_handset', $handsets, 'handset', $errors);
            $tier = PBX_LICENCES[$details['licence']] ?? '';
            if ($details['licence']) {
                $price('licence_' . $details['licence'], 'hosted_pbx', $details['users'] . ' users', $details['users']);
            }
            if ($details['mode'] === 'new') {
                $price('ddi', 'hosted_pbx', 'new numbers', $details['ddis']);
            } elseif ($details['mode'] === 'migrate') {
                $price('ddi', 'hosted_pbx', 'numbers moving to us', count($details['numbers'] ?? []));
                $price('number_port', 'hosted_pbx', 'from ' . $details['provider'], count($details['numbers'] ?? []), true);
            }
            foreach ($details['handsets'] as $h) {
                $lines[] = order_line($handsets[$h['product_id']], 'hardware', $h['product'], $h['qty']);
            }
            $title = 'Hosted PBX – ' . $details['users'] . ' user' . ($details['users'] === 1 ? '' : 's') . ($tier ? ", $tier" : '') . ($details['mode'] === 'migrate' ? ', migrating' : '');
            break;

        case 'hardware':
            $details['address'] = order_parse_address($account, $post, 'hw_addr', 'delivery address', $errors);
            $items = array_column(order_products('hardware'), null, 'id');
            $details['items'] = order_parse_item_rows($post, 'hw_item', $items, 'item', $errors);
            if (!$details['items'] && !isset($errors['hw_item'])) {
                $errors['hw_item'] = 'Add at least one item.';
            }
            foreach ($details['items'] as $h) {
                $lines[] = order_line($items[$h['product_id']], 'hardware', $h['product'], $h['qty']);
            }
            $title = 'Hardware – ' . array_sum(array_column($details['items'], 'qty')) . ' item' . (array_sum(array_column($details['items'], 'qty')) === 1 ? '' : 's');
            break;

        case 'leased_line':
            $details['address'] = order_parse_address($account, $post, 'll_addr', 'installation address', $errors);
            $products = array_column(order_products('leased_line'), null, 'id');
            $pid = (int)($post['ll_product'] ?? 0);
            if (!isset($products[$pid])) {
                $errors['ll_product'] = $products ? 'Choose the leased line.' : 'There are no leased line products in the price list yet. Add them under Products.';
            }
            $details['product'] = $products[$pid]['name'] ?? '';
            $details['crd'] = (string)($post['ll_crd'] ?? '');
            if ($details['crd'] !== '' && (!strtotime($details['crd']) || $details['crd'] < date('Y-m-d'))) {
                $errors['ll_crd'] = 'Choose a date from today on, or leave it blank.';
            }
            $details['ip_option'] = isset(GIACOM_IP_OPTIONS[$post['ll_ip'] ?? '']) ? $post['ll_ip'] : 'dynamic';
            $details['contact_name'] = trim((string)($post['ll_contact_name'] ?? ''));
            $details['contact_phone'] = trim((string)($post['ll_contact_phone'] ?? ''));
            if ($details['contact_name'] === '' || $details['contact_phone'] === '') {
                $errors['ll_contact'] = 'Enter the site contact\'s name and phone number, for the survey and install.';
            }
            if (isset($products[$pid])) {
                $lines[] = order_line($products[$pid], 'leased_line', $products[$pid]['name'] . ' – ' . order_address_text($details['address']), 1);
            }
            $title = 'Leased line' . ($details['address'] ? ' – ' . ($details['address']['postcode'] ?? '') : '');
            break;

        default:
            $errors['service_type'] = 'Choose the service type.';
            $title = '';
    }
    if (isset($errors['mobile'])) {
        $errors['mobile'] = implode(' ', $errors['mobile']);
    }
    $notes = $missing ? ['The price list has no ' . implode(', ', array_keys($missing)) . ', so ' . (count($missing) === 1 ? 'that line is' : 'those lines are')
        . ' on the quote at £0. Set the price on the quote, or give a product that item type under Products.'] : [];
    $details['title'] = $title;
    return [$details, $lines, $errors, $notes];
}

/** Save the order as a draft quote with its details and uploaded bills. Returns the quote id. */
function new_order_create(array $account, array $details, array $lines, array $files = []): int
{
    foreach (['sip_bill' => 'sip_trunk', 'pbx_bill' => 'hosted_pbx'] as $field => $type) {
        if ($details['type'] === $type && order_has_upload($files, $field)) {
            $details['bill_document_id'] = document_store($files[$field], ['account_id' => (int)$account['id']],
                'Copy bill – ' . ($details['provider'] ?? 'current provider'), 'Uploaded with the ' . strtolower(SERVICE_TYPES[$type]) . ' order', is_uploaded_file($files[$field]['tmp_name']));
            $details['bill_needed'] = false;
        }
    }
    db_exec('INSERT INTO quotes (account_id, title, valid_until, order_details, created_by) VALUES (?, ?, ?, ?, ?)', [
        $account['id'], mb_substr($details['title'], 0, 200), date('Y-m-d', strtotime('+' . (int)(setting('quote_validity_days') ?: 30) . ' days')),
        json_encode($details), current_user()['id'] ?? null,
    ]);
    $qid = (int)db()->lastInsertId();
    db_exec('UPDATE quotes SET reference = ? WHERE id = ?', [sprintf('Q-%06d', $qid), $qid]);
    quote_save_lines($qid, $lines);
    audit('create', 'Quote ' . sprintf('Q-%06d', $qid) . ' created from the order form: ' . $details['title'], 'quotes', $qid, null, null, (int)$account['id']);
    return $qid;
}

function quote_order_details(?array $quote): ?array
{
    $d = $quote && !empty($quote['order_details']) ? json_decode((string)$quote['order_details'], true) : null;
    return is_array($d) ? $d : null;
}

/** Whether the order moves numbers from another provider, so the customer signs a letter of authority. */
function order_needs_loa(?array $details): bool
{
    return $details && (($details['type'] === 'sip_trunk' && ($details['mode'] ?? '') === 'port') || ($details['type'] === 'hosted_pbx' && ($details['mode'] ?? '') === 'migrate'));
}

/** The letter of authority for porting numbers, as Word document paragraphs. */
function order_loa_paragraphs(array $account, array $details, string $contractRef, string $signer): array
{
    $us = company('name', config('app_name'));
    $address = order_address_text($details['type'] === 'hosted_pbx' ? ($details['current_address'] ?? null) : ($details['address'] ?? null))
        ?: implode(', ', array_filter([$account['address'], $account['city'], $account['postcode']]));
    $p = [
        ['Letter of Authority', 'Title'],
        'Date: ' . date('j F Y'),
        'To: ' . ($details['provider'] ?? 'the current provider'),
        "We, {$account['name']}" . ($account['company_number'] ? " (company number {$account['company_number']})" : '') . ', are the account holder for the telephone numbers below, '
            . "installed at $address.",
        "We authorise $us, and the carriers it works with, to act on our behalf to transfer (port) these numbers from "
            . ($details['provider'] ?? 'our current provider') . " to $us, and to obtain any information about them needed to do so.",
        ['Numbers to transfer', 'Heading1'],
    ];
    foreach ($details['numbers'] ?? [] as $n) {
        $p[] = $n;
    }
    $p[] = ['Account holder', 'Heading1'];
    $p[] = 'Company: ' . $account['name'];
    $p[] = 'Address: ' . $address;
    $p[] = 'Authorised by: ' . $signer;
    $p[] = 'Signed electronically as part of agreement ' . $contractRef . '. The signature, date and time are recorded on the signing certificate.';
    $p[] = 'We understand that services on these numbers with the current provider may end when the transfer completes, and that any charges or notice due to them remain ours.';
    return $p;
}

function new_order_controller(): void
{
    require_permission('sales.edit');
    $account = ($id = query_int('account_id')) ? db_one('SELECT * FROM accounts WHERE id = ?', [$id]) : null;
    $type = (string)($_GET['type'] ?? ($_POST['service_type'] ?? ''));
    $errors = [];
    $values = $_POST;
    if ($account && is_post()) {
        verify_csrf();
        [$details, $lines, $errors, $notes] = new_order_parse($account, $_POST, $_FILES);
        if (!$errors) {
            $qid = new_order_create($account, $details, $lines, $_FILES);
            flash('Quote created from the order. Check it over' . ($notes ? ' (' . implode(' ', $notes) . ')' : '') . ', then send it to the customer.', $notes ? 'warning' : 'success');
            redirect(url('quotes', ['action' => 'view', 'id' => $qid]));
        }
    }
    $customers = $account ? [] : db_all("SELECT id, name, account_number FROM accounts WHERE is_customer = 1 AND status <> 'churned' ORDER BY name");
    page('new_order', compact('account', 'type', 'errors', 'values', 'customers'), $account ? 'New order for ' . $account['name'] : 'New order');
}
