<?php
declare(strict_types=1);

/*
 * Giacom (Cloud Market) comms API: broadband availability checks and orders.
 * The API takes an XML <Request> POSTed to one URL and answers with an XML
 * <Response>; <status no="0"/> means success, anything else carries a "text"
 * explaining the problem. Every request signs in with an auth block.
 */

final class GiacomException extends IntegrationException
{
}

const GIACOM_DEFAULT_URL = 'https://comms-api.cloud.market/';

/** What each availability "quick result" code means (Giacom appendix A). */
const GIACOM_QUICK_RESULTS = [
    0  => 'Not available at this line/address (see the other checks).',
    2  => 'Something on the customer\'s line (e.g. ISDN or an alarm) prevents broadband being added. It must be removed first.',
    3  => 'Another provider-side service on the line prevents broadband being added. It must be removed first.',
    4  => 'Broadband is already active on this line: place a migrate order rather than a new provide.',
    5  => 'This line is suitable for a new provide order.',
    6  => 'An order already exists at BT for this line. It must be aborted before another can be placed.',
    9  => 'A cease is in progress and must complete before a new order can be placed.',
    10 => 'There is already LLU VDSL on the line. You can order if you have a MAC.',
    11 => 'The MAC is invalid or expired.',
    12 => 'MPF is available.',
];

const GIACOM_EXCHANGE_STATES = [
    'E' => 'Enabled', 'P' => 'Planned', 'N' => 'Not planned', 'S' => 'Not supported (planned)', 'L' => 'Not supported (live)',
    'H' => 'Reviewed – not viable', 'F' => 'Planned for review', 'R' => 'Under review',
];

const GIACOM_CARE_LEVELS = ['standard' => 'Standard', 'enhanced' => 'Enhanced', 'premium' => 'Premium'];

function giacom_configured(): bool
{
    return (bool)setting('giacom_username') && (bool)setting('giacom_password');
}

function giacom_url(): string
{
    return (string)(config('giacom_url') ?: setting('giacom_url') ?: GIACOM_DEFAULT_URL);
}

/* ----------------------------------------------------------------- XML --- */

/** Add <a name=...> and <block name=...> children from a PHP array. Lists become unnamed <block>s. */
function giacom_xml_fill(DOMDocument $doc, DOMElement $parent, array $data): void
{
    foreach ($data as $name => $value) {
        if ($value === null) {
            continue;
        }
        if (is_array($value)) {
            $block = $doc->createElement('block');
            if (!is_int($name)) {
                $block->setAttribute('name', (string)$name);
            }
            giacom_xml_fill($doc, $block, $value);
            $parent->appendChild($block);
            continue;
        }
        $a = $doc->createElement('a');
        $a->setAttribute('name', (string)$name);
        $a->appendChild($doc->createTextNode(is_bool($value) ? ($value ? 'Y' : 'N') : (string)$value));
        $parent->appendChild($a);
    }
}

function giacom_request_xml(string $call, array $params, string $version = '1.0', ?array $auth = null): string
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $req = $doc->createElement('Request');
    $req->setAttribute('module', 'dwapi');
    $req->setAttribute('call', $call);
    $req->setAttribute('id', bin2hex(random_bytes(16)));
    $req->setAttribute('version', $version);
    $doc->appendChild($req);
    $auth ??= ['username' => (string)setting('giacom_username'), 'password' => (string)setting('giacom_password'), 'client-id' => (string)(setting('giacom_client_id') ?: '')];
    giacom_xml_fill($doc, $req, ['auth' => array_filter($auth, fn($v) => $v !== '')] + $params);
    return (string)$doc->saveXML();
}

/** Turn a <Response> (or block) into a PHP array: named values and blocks by name, unnamed blocks as a list. */
function giacom_xml_to_array(SimpleXMLElement $el): array
{
    $out = [];
    foreach ($el->children() as $child) {
        $name = isset($child['name']) ? (string)$child['name'] : null;
        if ($child->getName() === 'a') {
            if ($name !== null) {
                $out[$name] = trim((string)$child);
            }
        } elseif ($child->getName() === 'block') {
            if ($name === null) {
                $out[] = giacom_xml_to_array($child);
            } else {
                $out[$name] = giacom_xml_to_array($child);
            }
        }
    }
    return $out;
}

/** Call the API. Returns the response as an array; throws GiacomException with Giacom's reason on failure. */
function giacom_call(string $call, array $params = [], string $version = '1.0', ?array $auth = null): array
{
    if ($auth === null && !giacom_configured()) {
        throw new GiacomException('Giacom isn\'t set up yet. An admin can add the API login under Admin → Giacom.');
    }
    if (!class_exists('SimpleXMLElement') || !class_exists('DOMDocument')) {
        throw new GiacomException('This server\'s PHP is missing the XML extensions (dom and simplexml) needed for Giacom. Ask your host to enable them.');
    }
    try {
        [$status, $body] = http_request('POST', giacom_url(), ['Content-Type: text/xml; charset=utf-8', 'Accept: text/xml'], giacom_request_xml($call, $params, $version, $auth));
    } catch (GiacomException $e) {
        throw $e;
    } catch (IntegrationException $e) {
        throw new GiacomException('Could not reach Giacom: ' . $e->getMessage());
    }
    $raw = is_string($body) ? $body : json_encode($body);
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string)$raw, 'SimpleXMLElement', LIBXML_NONET);
    libxml_use_internal_errors($previous);
    if ($xml === false || $xml->getName() !== 'Response') {
        throw new GiacomException("Giacom sent an unexpected reply (HTTP $status).");
    }
    $no = isset($xml->status['no']) ? (string)$xml->status['no'] : '0';
    if ($no !== '0') {
        $text = trim((string)($xml->status['text'] ?? '')) ?: trim((string)$xml->status);
        $data = giacom_xml_to_array($xml);
        $extra = [];
        foreach ($data['errors'] ?? [] as $e) {
            $extra[] = trim(($e['error'] ?? '') . ' ' . ($e['message'] ?? ''));
        }
        throw new GiacomException('Giacom said: ' . ($text !== '' ? $text : "error $no") . ($extra ? ' (' . implode('; ', array_filter($extra)) . ')' : ''));
    }
    return giacom_xml_to_array($xml);
}

/* -------------------------------------------------------- Availability --- */

/** "bb52sb" → "BB5 2SB" (Giacom's order validation needs the space). */
function giacom_postcode(?string $postcode): string
{
    $p = strtoupper(preg_replace('/\s+/', '', (string)$postcode));
    return strlen($p) > 3 ? substr($p, 0, -3) . ' ' . substr($p, -3) : $p;
}

/** Addresses at a postcode (optionally narrowed by building/street), as a simple list. */
function giacom_address_search(string $postcode, string $building = '', string $street = ''): array
{
    $postcode = strtoupper(preg_replace('/\s+/', '', $postcode));
    if (!preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $postcode)) {
        throw new GiacomException('Enter a full UK postcode.');
    }
    $r = giacom_call('address_search', array_filter(['postcode' => $postcode, 'building' => $building, 'street' => $street], fn($v) => $v !== ''));
    $out = [];
    foreach ($r['addresses'] ?? [] as $a) {
        if (empty($a['address-reference'])) {
            continue;
        }
        if (!empty($a['postcode'])) {
            $a['postcode'] = giacom_postcode($a['postcode']);
        }
        $a['label'] = giacom_address_label($a);
        $out[] = $a;
    }
    usort($out, fn($x, $y) => strnatcasecmp($x['label'], $y['label']));
    return $out;
}

function giacom_address_label(array $a): string
{
    $first = trim(implode(' ', array_filter([$a['sub-premise'] ?? '', $a['organisation'] ?? ''])));
    $building = $a['building'] ?? '';
    if (($a['premise'] ?? '') !== '' && $a['premise'] !== $building) {
        $building = trim($a['premise'] . ', ' . $building, ', ');
    }
    return implode(', ', array_filter([$first, trim($building . ' ' . ($a['street'] ?? '')), $a['locality'] ?? '', $a['city'] ?? '', $a['postcode'] ?? ''], fn($v) => trim((string)$v) !== ''));
}

/** Run an availability check at an address and save it. Returns the saved check id. */
function giacom_check(array $address, ?string $cli, ?int $accountId, ?int $siteId): int
{
    $cli = $cli ? preg_replace('/\D/', '', $cli) : null;
    $r = giacom_call('availability', array_filter([
        'cli' => $cli ?: null,
        'postcode' => !empty($address['postcode']) ? giacom_postcode($address['postcode']) : null,
        'detailed' => 'Y',
        'address-reference' => $address['address-reference'] ?? null,
        'css-database-code' => $address['css-database-code'] ?? null,
        'uprn' => $address['uprn'] ?? null,
    ], fn($v) => $v !== null && $v !== ''), '2.0.1');
    $result = giacom_summarise_availability($r);
    db_exec('INSERT INTO giacom_checks (account_id, site_id, postcode, cli, address_label, address_reference, css_database_code, uprn, address, result, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $accountId, $siteId, $address['postcode'] ?? null, $cli, mb_substr((string)($address['label'] ?? giacom_address_label($address)), 0, 255),
        $address['address-reference'] ?? null, $address['css-database-code'] ?? null, $address['uprn'] ?? null,
        json_encode($address), json_encode($result), current_user()['id'] ?? null,
    ]);
    $id = (int)db()->lastInsertId();
    audit('giacom_check', 'Broadband availability checked at ' . ($address['label'] ?? ($address['postcode'] ?? '')) . ': ' . count($result['products']) . ' products available',
        $accountId ? 'accounts' : null, $accountId);
    return $id;
}

/** The parts of an availability response people need, in a stable shape. */
function giacom_summarise_availability(array $r): array
{
    $a = $r['availability'] ?? [];
    // Lead times: the earliest date Giacom can deliver each product. Looked for
    // anywhere in the response, as well as on the product itself.
    $leadtimes = [];
    $walk = function (array $node) use (&$walk, &$leadtimes): void {
        if (!empty($node['product-id']) && (isset($node['first-date-text']) || isset($node['first-date-int']) || isset($node['leadtime']))) {
            $date = $node['first-date-text'] ?? (isset($node['first-date-int']) && ctype_digit((string)$node['first-date-int']) ? date('Y-m-d', (int)$node['first-date-int']) : null);
            $leadtimes[(string)$node['product-id']] ??= ['days' => $node['leadtime'] ?? null, 'first_date' => $date ? substr((string)$date, 0, 10) : null];
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $walk($child);
            }
        }
    };
    $walk($r);
    // Realistic speed estimates, by supplier product reference (+ subtype).
    $estimates = [];
    foreach (['business', 'residential', 'none'] as $segment) {
        foreach ($a['realistic-speeds'][$segment]['products'] ?? [] as $p) {
            $key = ($p['supplier-product-reference'] ?? '') . '|' . ($p['supplier-product-subtype'] ?? '');
            $estimates[$key] ??= [
                'down' => $p['estimated-download-range'] ?? ($p['down-speed-estimate'] ?? null),
                'up'   => $p['estimated-upload-range'] ?? ($p['up-speed-estimate'] ?? null),
                'min_guaranteed' => $p['minimum-guaranteed-speed'] ?? null,
            ];
        }
    }
    $products = [];
    foreach ($r['products'] ?? [] as $p) {
        if (empty($p['product-id'])) {
            continue;
        }
        $key = ($p['supplier-product-reference'] ?? '') . '|' . ($p['supplier-product-subtype'] ?? '');
        $products[] = [
            'product_id' => (string)$p['product-id'],
            'name' => (string)($p['product-name'] ?? $p['product-id']),
            'technology' => strtolower((string)($p['technology-type'] ?? '')),
            'supplier_ref' => trim(($p['supplier-product-reference'] ?? '') . ' ' . ($p['supplier-product-subtype'] ?? '')),
            'speed' => isset($p['service-speed']) ? (float)$p['service-speed'] : null,
            'likely_range' => isset($p['likely-min-range'], $p['likely-max-range']) ? [(float)$p['likely-min-range'], (float)$p['likely-max-range']] : null,
            'estimate' => $estimates[$key] ?? null,
            'care_levels' => array_values(array_filter(array_map('trim', explode(',', (string)($p['care-level-options'] ?? ''))))),
            'realms' => array_values(array_unique(array_filter(array_map(fn($r) => trim((string)($r['realm'] ?? '')), is_array($p['realms'] ?? null) ? $p['realms'] : [])))),
            'care_default' => $p['care-level'] ?? null,
            'leadtime' => $leadtimes[(string)$p['product-id']] ?? null,
        ];
    }
    $quick = isset($a['quick-result']) && $a['quick-result'] !== '' ? (int)$a['quick-result'] : null;
    return [
        'quick_result' => $quick,
        'quick_text' => $quick !== null ? (GIACOM_QUICK_RESULTS[$quick] ?? "Result code $quick") : null,
        'exchange' => isset($a['exchange']) ? [
            'name' => $a['exchange']['name'] ?? null, 'code' => $a['exchange']['code'] ?? null,
            'state' => GIACOM_EXCHANGE_STATES[$a['exchange']['state'] ?? ''] ?? ($a['exchange']['state'] ?? null),
        ] : null,
        'fttc' => isset($a['fttc-qualification']['likely-max-speed-down']) ? [(float)$a['fttc-qualification']['likely-max-speed-down'], (float)($a['fttc-qualification']['likely-max-speed-up'] ?? 0)] : null,
        'products' => $products,
        'raw' => $r,
    ];
}

/** Speeds come in bits/s (qualifications) or kbit/s (estimates); show Mbps. */
function giacom_mbps(mixed $value, string $unit = 'bps'): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    if (is_string($value) && str_contains($value, '-')) {
        return implode(' – ', array_map(fn($v) => giacom_mbps(trim($v), $unit), explode('-', $value))) ;
    }
    $mbps = (float)$value / ($unit === 'kbps' ? 1000 : 1000000);
    return ($mbps >= 10 ? number_format($mbps, 0) : rtrim(rtrim(number_format($mbps, 1), '0'), '.')) . ' Mbps';
}

/* -------------------------------------------------------------- Orders --- */

/** Broadband technology → CRM service type. */
function giacom_service_type(string $technology): string
{
    return 'broadband';
}

/**
 * Place a provide (new line/service) or migrate (take over an existing service)
 * order. $o holds the form values; returns the CRM order id.
 */
function giacom_place_order(array $check, array $product, array $o): int
{
    $account = db_one('SELECT * FROM accounts WHERE id = ?', [$check['account_id']]) ?? throw new GiacomException('Customer not found.');
    $address = json_decode((string)$check['address'], true) ?: [];
    $type = $o['order_type'] === 'migrate' ? 'migrate' : 'provide';
    $clientRef = mb_substr($account['account_number'] . ($o['client_ref'] !== '' ? ' ' . $o['client_ref'] : ''), 0, 60);

    $fullUsername = giacom_full_username($o['bb_username'], $o['bb_suffix'] ?? '', $o['realm']);
    if (!str_contains($fullUsername, '@')) {
        throw new GiacomException('Enter the broadband realm: Giacom needs the username as user@realm.');
    }
    $realmAttr = giacom_realm_value($o['bb_suffix'] ?? '', $o['realm']);
    $attributes = array_filter([
        'password' => $o['bb_password'],
        'realm' => $realmAttr,
        'care-level' => $o['care_level'] ?: null,
        'site-visit-reason' => $o['site_visit_reason'] ?: null,
        'force-new-ont' => $o['force_new_ont'] ?? null,
    ], fn($v) => $v !== null && $v !== '');
    $order = array_filter([
        'client-ref' => $clientRef,
        'cli' => $o['cli'] ?: null,
        'prod-id' => $product['product_id'],
        'crd' => $o['crd'],
        'username' => $fullUsername,
        'address-reference' => $address['address-reference'] ?? null,
        'access-line-id' => $type === 'migrate' ? ($o['access_line_id'] ?: null) : null,
    ], fn($v) => $v !== null && $v !== '') + ['attributes' => $attributes];
    $customer = array_filter([
        'company' => $account['type'] === 'business' ? $account['name'] : null,
        'title' => $o['title'] ?: null,
        'forename' => $o['forename'],
        'surname' => $o['surname'],
        'sub-premise' => $address['sub-premise'] ?? null,
        'building' => ($address['building'] ?? '') ?: ($address['premise'] ?? null),
        'street' => $address['street'] ?? null,
        'city' => $address['city'] ?? null,
        'county' => $address['county'] ?? null,
        'postcode' => giacom_postcode($address['postcode'] ?? $check['postcode']),
        'telephone' => preg_replace('/[^\d+]/', '', (string)$o['telephone']),
        'email' => $o['email'] ?: null,
    ], fn($v) => $v !== null && $v !== '');

    $r = giacom_call($type, ['order' => $order, 'customer' => $customer]);
    $orderId = (string)($r['order-id'] ?? '');
    if ($orderId === '') {
        throw new GiacomException('Giacom accepted the request but didn\'t return an order number. Check the Giacom portal before trying again.');
    }

    db()->beginTransaction();
    try {
        // A pending service on the customer, made live when Giacom completes the order.
        $serviceId = insert_row('services', [
            'account_id' => $account['id'], 'site_id' => $check['site_id'] ?: null, 'product_id' => $o['crm_product_id'] ?: null,
            'service_type' => giacom_service_type($product['technology']), 'identifier' => $o['cli'] ?: $fullUsername,
            'carrier' => 'Giacom', 'status' => 'pending', 'monthly_price' => null, 'setup_fee' => null,
            'start_date' => null, 'term_months' => null, 'contract_end_date' => null,
            'install_address' => $check['site_id'] ? null : mb_substr((string)$check['address_label'], 0, 255),
            'notes' => "Giacom $type order $orderId: {$product['name']}" . ($fullUsername ? "\nBroadband username: $fullUsername" : ''),
        ]);
        db_exec('INSERT INTO giacom_orders (account_id, site_id, service_id, check_id, order_type, giacom_order_id, giacom_service_id, cli, product_id, product_name,
                technology_type, broadband_username, address_label, crd, client_ref, status, status_updated_at, details, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)', [
            $account['id'], $check['site_id'] ?: null, $serviceId, $check['id'], $type, $orderId, $r['service-id'] ?? null, $o['cli'] ?: null,
            $product['product_id'], mb_substr($product['name'], 0, 190), $product['technology'], $fullUsername ?: null,
            $check['address_label'], $o['crd'], $clientRef, 'Placed',
            json_encode(['care_level' => $o['care_level'], 'contact' => trim($o['forename'] . ' ' . $o['surname']), 'telephone' => $o['telephone'], 'email' => $o['email']]),
            current_user()['id'] ?? null,
        ]);
        $id = (int)db()->lastInsertId();
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        // The order exists at Giacom even if saving it here failed: say so clearly.
        throw new GiacomException("Giacom order $orderId was placed, but saving it in the CRM failed: " . $e->getMessage());
    }
    audit('giacom_order', "Giacom $type order $orderId placed: {$product['name']} at {$check['address_label']}" . ($o['cli'] ? " (CLI {$o['cli']})" : ''),
        'accounts', (int)$account['id'], null, ['Product' => ['from' => '', 'to' => $product['name']], 'Required by' => ['from' => '', 'to' => (string)$o['crd']]]);
    log_activity((int)$account['id'], 'note', "Giacom $type order $orderId placed: {$product['name']}");
    return $id;
}

/** Refresh one order's status and history from Giacom. */
function giacom_refresh_order(array $order): array
{
    $view = giacom_call('order_view', ['order-id' => $order['giacom_order_id']]);
    $d = $view['order-details'] ?? [];
    $status = (string)($d['order-status'] ?? '');
    if ($status === '') {
        $s = giacom_call('order_status', ['order-id' => $order['giacom_order_id']]);
        $status = (string)($s['order']['status'] ?? '');
    }
    foreach ($d['order-history'] ?? [] as $e) {
        giacom_store_event((int)$order['id'], $e['event-date'] ?? null, $e['event'] ?? 'event', trim(($e['event-description'] ?? '') . (!empty($e['operator']) ? ' (' . $e['operator'] . ')' : '')));
    }
    try {
        foreach (giacom_call('order_eventlog_history', ['order-id' => $order['giacom_order_id']])['eventlog'] ?? [] as $e) {
            giacom_store_event((int)$order['id'], $e['date'] ?? null, $e['name'] ?? '', $e['value'] ?? '');
        }
    } catch (GiacomException) {
        // Not every order type has an event log.
    }
    if ($status !== '') {
        giacom_set_status($order, $status, $d['order-crd'] ?? null, $d['service-id'] ?? null);
    }
    return db_one('SELECT * FROM giacom_orders WHERE id = ?', [$order['id']]);
}

/** Save an order event; returns false if it was already saved. */
function giacom_store_event(int $orderId, ?string $date, string $name, string $value): bool
{
    return (bool)db_exec('INSERT IGNORE INTO giacom_order_events (order_id, event_date, name, value) VALUES (?, ?, ?, ?)',
        [$orderId, $date ?: null, mb_substr($name, 0, 60), mb_substr($value, 0, 500)]);
}

function giacom_is_complete(string $status): bool
{
    return (bool)preg_match('/^(completed?|live|active)\b/i', trim($status));
}

function giacom_is_cancelled(string $status): bool
{
    return (bool)preg_match('/cancel|abort|reject|fail/i', $status);
}

/** Record a new status; completing an order makes its CRM service live. */
function giacom_set_status(array $order, string $status, ?string $crd = null, ?string $serviceId = null): void
{
    $changed = $status !== (string)$order['status'];
    db_exec('UPDATE giacom_orders SET status = ?, status_updated_at = IF(?, NOW(), status_updated_at), crd = COALESCE(?, crd),
            giacom_service_id = COALESCE(giacom_service_id, ?), last_error = NULL WHERE id = ?',
        [mb_substr($status, 0, 100), $changed ? 1 : 0, $crd ?: null, $serviceId ?: null, $order['id']]);
    if (!$changed) {
        return;
    }
    audit('giacom_status', "Giacom order {$order['giacom_order_id']}: {$order['status']} → $status", 'accounts', $order['account_id'] ? (int)$order['account_id'] : null, null,
        ['Status' => ['from' => (string)$order['status'], 'to' => $status]]);
    if (giacom_is_complete($status) && empty($order['completed_at'])) {
        db_exec('UPDATE giacom_orders SET completed_at = NOW() WHERE id = ?', [$order['id']]);
        if ($order['service_id']) {
            $svc = db_one('SELECT * FROM services WHERE id = ?', [$order['service_id']]);
            if ($svc && $svc['status'] === 'pending') {
                db_exec("UPDATE services SET status = 'active', start_date = COALESCE(start_date, CURDATE()) WHERE id = ?", [$svc['id']]);
            }
        }
        if ($order['account_id']) {
            log_activity((int)$order['account_id'], 'note', "Giacom order {$order['giacom_order_id']} completed: {$order['product_name']}");
        }
    } elseif (giacom_is_cancelled($status) && $order['service_id']) {
        db_exec("UPDATE services SET status = 'ceased' WHERE id = ? AND status = 'pending'", [$order['service_id']]);
    }
}

/** Pick up status changes for all our orders since the last sync (cron and the Sync button). */
function giacom_sync(): array
{
    $since = setting('giacom_events_since') ?: date('Y-m-d H:i:s', strtotime('-7 days'));
    $started = date('Y-m-d H:i:s');
    $events = giacom_call('order_eventlog_changes', ['date' => $since], '2.0.1')['eventlog'] ?? [];
    $orders = [];
    foreach (db_all('SELECT * FROM giacom_orders WHERE giacom_order_id IS NOT NULL') as $o) {
        $orders[(string)$o['giacom_order_id']] = $o;
    }
    $matched = 0;
    $statusChanges = 0;
    foreach ($events as $e) {
        $o = $orders[(string)($e['order-id'] ?? '')] ?? null;
        if (!$o) {
            continue;
        }
        // Events already seen (the date window overlaps between runs) are skipped.
        if (!giacom_store_event((int)$o['id'], $e['date'] ?? null, $e['name'] ?? '', $e['value'] ?? '')) {
            continue;
        }
        $matched++;
        if (($e['name'] ?? '') === 'status' && ($e['value'] ?? '') !== '') {
            giacom_set_status($o, (string)$e['value']);
            $orders[(string)$o['giacom_order_id']] = db_one('SELECT * FROM giacom_orders WHERE id = ?', [$o['id']]);
            $statusChanges++;
        }
    }
    set_setting('giacom_events_since', $started);
    set_setting('giacom_last_sync_at', $started);
    return ['events' => $matched, 'status_changes' => $statusChanges];
}

/** Cancel an order at Giacom. */
function giacom_abort_order(array $order, string $reason): string
{
    $r = giacom_call('order_abort', ['order-id' => $order['giacom_order_id'], 'reason' => $reason]);
    $status = (string)($r['cancel-status'] ?? 'Cancellation requested');
    giacom_store_event((int)$order['id'], date('Y-m-d H:i:s'), 'cancel', $status . ': ' . $reason);
    giacom_set_status($order, giacom_is_cancelled($status) ? $status : 'Cancellation requested');
    audit('giacom_abort', "Giacom order {$order['giacom_order_id']} cancellation requested: $reason", 'accounts', $order['account_id'] ? (int)$order['account_id'] : null);
    return $status;
}

/** "Dr. Priya Shah" → ['Dr', 'Priya', 'Shah']. */
function giacom_split_name(string $name): array
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $title = '';
    if (count($parts) > 2 && preg_match('/^(mr|mrs|ms|miss|mx|dr|prof|sir|rev)\.?$/i', $parts[0])) {
        $title = ucfirst(strtolower(rtrim(array_shift($parts), '.')));
    }
    $surname = count($parts) > 1 ? array_pop($parts) : '';
    return [$title, implode(' ', $parts), $surname];
}

/**
 * Split a realm as Giacom writes it into the part added after the username and
 * the realm itself: "-Finn@surfdsluk" → ['-Finn', 'surfdsluk'], "@surfdsluk" → ['', 'surfdsluk'].
 */
function giacom_split_realm(string $realm): array
{
    $realm = trim($realm);
    $at = strrpos($realm, '@');
    return $at === false ? ['', $realm] : [substr($realm, 0, $at), substr($realm, $at + 1)];
}

/** The realm in Giacom's own form: "-Finn@surfdsluk", or just "surfdsluk" when there's no suffix. */
function giacom_realm_value(string $suffix, string $realm): string
{
    [$inRealm, $realm] = giacom_split_realm($realm);
    $suffix = trim($suffix) !== '' ? trim($suffix) : $inRealm;
    return $suffix !== '' ? $suffix . '@' . $realm : $realm;
}

/**
 * The full broadband username Giacom expects, e.g. joebloggs + "-Finn" + surfdsluk
 * → joebloggs-Finn@surfdsluk (Giacom works out the realm from it).
 */
function giacom_full_username(string $user, string $suffix, string $realm): string
{
    $user = trim($user);
    if (str_contains($user, '@')) {
        $user = substr($user, 0, strpos($user, '@'));
    }
    [$inRealm, $realm] = giacom_split_realm($realm);
    if ($realm === '') {
        return $user;
    }
    $suffix = trim($suffix) !== '' ? trim($suffix) : $inRealm;
    // Don't add the suffix twice if it was typed into the username as well.
    if ($suffix !== '' && str_ends_with(strtolower($user), strtolower($suffix))) {
        $suffix = '';
    }
    return $user . $suffix . '@' . $realm;
}

/** A broadband username suggestion for a new order, e.g. acc10001-2 (the realm is sent separately). */
function giacom_suggest_username(array $account): string
{
    $n = 1 + (int)db_value('SELECT COUNT(*) FROM giacom_orders WHERE account_id = ?', [$account['id']]);
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $account['account_number'])) . '-' . $n;
}

/** Engineer appointment dates Giacom offers at an address (empty if it can't say). */
function giacom_appointments(array $check, array $product): array
{
    $address = json_decode((string)$check['address'], true) ?: [];
    try {
        $r = giacom_call('available_appointments', array_filter([
            'technology-type' => strtoupper($product['technology']),
            'address-reference' => $address['address-reference'] ?? null,
            'css-database-code' => $address['css-database-code'] ?? null,
            'uprn' => $address['uprn'] ?? null,
        ], fn($v) => $v !== null && $v !== ''), '2.0.1');
    } catch (GiacomException) {
        return [];
    }
    $out = [];
    foreach ($r['appointments'] ?? [] as $a) {
        if (!empty($a['date'])) {
            $out[] = ['date' => substr($a['date'], 0, 10), 'slot' => $a['timeslot'] ?? ''];
        }
    }
    usort($out, fn($x, $y) => strcmp($x['date'], $y['date']));
    return $out;
}

/* ---------------------------------------------------------- Controller --- */

function giacom_controller(): void
{
    $action = query('action', 'orders');
    $back = fn(?array $o = null) => $o ? url('giacom', ['action' => 'view', 'id' => $o['id']]) : url('giacom');

    if ($action === 'settings') {
        require_permission('settings.manage');
        if (is_post()) {
            verify_csrf();
            if (query('do') === 'remove') {
                foreach (['giacom_username', 'giacom_password', 'giacom_client_id'] as $k) {
                    set_setting($k, null);
                }
                audit('settings', 'Giacom login removed');
                flash('Giacom login removed. Existing orders are kept.');
                redirect(url('giacom', ['action' => 'settings']));
            }
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $clientId = trim((string)($_POST['client_id'] ?? ''));
            $apiUrl = trim((string)($_POST['api_url'] ?? ''));
            if ($apiUrl !== '' && !preg_match('#^https://[^\s]+$#', $apiUrl)) {
                flash('The API address must start with https://', 'error');
                redirect(url('giacom', ['action' => 'settings']));
            }
            // "-Finn@surfdsluk" typed as the realm is split into its two parts.
            [$suffixInRealm, $realmOnly] = giacom_split_realm((string)($_POST['realm'] ?? ''));
            $suffix = trim((string)($_POST['username_suffix'] ?? '')) ?: $suffixInRealm;
            set_setting('giacom_realm', $realmOnly !== '' ? $realmOnly : null);
            set_setting('giacom_username_suffix', $suffix !== '' ? $suffix : null);
            set_setting('giacom_care_level', isset(GIACOM_CARE_LEVELS[$_POST['care_level'] ?? '']) ? $_POST['care_level'] : null);
            set_setting('giacom_url', $apiUrl === '' || $apiUrl === GIACOM_DEFAULT_URL ? null : $apiUrl);
            if ($username !== '') {
                $auth = ['username' => $username, 'password' => $password !== '' ? $password : (string)setting('giacom_password'), 'client-id' => $clientId];
                try {
                    giacom_call('check_api_service_status', [], '1.0', $auth);
                } catch (GiacomException $e) {
                    flash('Those details didn\'t work, so they weren\'t saved. ' . $e->getMessage(), 'error');
                    redirect(url('giacom', ['action' => 'settings']));
                }
                set_setting('giacom_username', $username);
                set_setting('giacom_client_id', $clientId ?: null);
                if ($password !== '') {
                    set_setting('giacom_password', $password);
                }
                audit('settings', 'Giacom login saved and tested (' . $username . ')');
                flash('Connected to Giacom. You can now check availability and place orders from customer pages.');
            } else {
                audit('settings', 'Giacom settings saved');
                flash('Saved.');
            }
            redirect(url('giacom', ['action' => 'settings']));
        }
        $status = null;
        if (giacom_configured() && query('test') === '1') {
            try {
                $status = giacom_call('check_api_service_status')['check'] ?? [];
            } catch (GiacomException $e) {
                flash($e->getMessage(), 'error');
            }
        }
        page('giacom_settings', ['status' => $status], 'Giacom');
        return;
    }

    require_permission('orders.check');

    switch ($action) {
        case 'check':
            // Step 1: find the address; step 2: run the check on the chosen one.
            $account = db_one('SELECT * FROM accounts WHERE id = ?', [query_int('account_id') ?? (int)($_POST['account_id'] ?? 0)]);
            $site = ($sid = query_int('site_id') ?? (int)($_POST['site_id'] ?? 0)) ? db_one('SELECT * FROM sites WHERE id = ?', [$sid]) : null;
            if ($site && $account && (int)$site['account_id'] !== (int)$account['id']) {
                $site = null;
            }
            $where = $site ?? $account ?? [];
            $values = [
                'postcode' => (string)($_POST['postcode'] ?? ($where['postcode'] ?? '')),
                'building' => (string)($_POST['building'] ?? ''),
                'cli' => (string)($_POST['cli'] ?? ''),
            ];
            $addresses = null;
            $error = null;
            if (is_post()) {
                verify_csrf();
                try {
                    if (($_POST['step'] ?? '') === 'check') {
                        $address = json_decode((string)($_POST['address'] ?? ''), true);
                        if (!is_array($address) || empty($address['address-reference'])) {
                            throw new GiacomException('Choose an address from the list.');
                        }
                        $address = array_map(fn($v) => is_scalar($v) ? mb_substr((string)$v, 0, 120) : '', $address);
                        $id = giacom_check($address, $values['cli'] ?: null, $account ? (int)$account['id'] : null, $site ? (int)$site['id'] : null);
                        redirect(url('giacom', ['action' => 'result', 'id' => $id]));
                    }
                    $addresses = giacom_address_search($values['postcode'], trim($values['building']));
                    if (!$addresses) {
                        $error = 'Giacom found no addresses at that postcode.';
                    }
                } catch (GiacomException $e) {
                    $error = $e->getMessage();
                }
            }
            page('giacom_check', compact('account', 'site', 'values', 'addresses', 'error'), 'Check broadband availability');
            return;

        case 'result':
            $check = db_one('SELECT c.*, a.name AS account_name, s.name AS site_name, u.name AS user_name FROM giacom_checks c
                LEFT JOIN accounts a ON a.id = c.account_id LEFT JOIN sites s ON s.id = c.site_id LEFT JOIN users u ON u.id = c.created_by WHERE c.id = ?',
                [query_int('id') ?? 0]) ?? not_found('Check not found.');
            page('giacom_result', ['check' => $check, 'result' => json_decode((string)$check['result'], true) ?: ['products' => []]], 'Availability');
            return;

        case 'order':
            require_permission('orders.place');
            $check = db_one('SELECT * FROM giacom_checks WHERE id = ?', [query_int('check') ?? 0]) ?? not_found('Check not found.');
            if (!$check['account_id']) {
                flash('Run the check from a customer\'s page to order for them.', 'error');
                redirect(url('giacom', ['action' => 'result', 'id' => $check['id']]));
            }
            $result = json_decode((string)$check['result'], true) ?: [];
            $product = null;
            foreach ($result['products'] ?? [] as $i => $p) {
                if ((string)$i === query('product')) {
                    $product = $p;
                }
            }
            if (!$product) {
                not_found('Choose a product from the availability results.');
            }
            $account = db_one('SELECT * FROM accounts WHERE id = ?', [$check['account_id']]);
            $site = $check['site_id'] ? db_one('SELECT * FROM sites WHERE id = ?', [$check['site_id']]) : null;
            $contact = db_one('SELECT * FROM contacts WHERE id = ?', [($site['contact_id'] ?? null) ?: ($account['main_contact_id'] ?: 0)]);
            [$title, $forename, $surname] = giacom_split_name((string)($contact['name'] ?? ''));
            // Earliest date: Giacom's lead time for the product, else its first appointment.
            $appointments = [];
            $lead = $product['leadtime']['first_date'] ?? null;
            $leadSource = $lead ? 'lead time' : null;
            if (!$lead) {
                $appointments = giacom_appointments($check, $product);
                $lead = $appointments[0]['date'] ?? null;
                $leadSource = $lead ? 'appointment' : null;
            }
            $lead ??= date('Y-m-d', strtotime('+10 weekdays'));
            $values = [
                'order_type' => in_array($result['quick_result'] ?? null, [4, 10], true) || $check['cli'] ? 'migrate' : 'provide',
                'cli' => (string)$check['cli'], 'crd' => max($lead, date('Y-m-d', strtotime('+1 weekday'))),
                'bb_username' => giacom_suggest_username($account), 'bb_password' => substr(strtr(base64_encode(random_bytes(9)), '+/', 'Kq'), 0, 12),
                'bb_suffix' => (string)setting('giacom_username_suffix'), 'realm' => (string)setting('giacom_realm'),
                'care_level' => in_array(setting('giacom_care_level'), $product['care_levels'] ?: array_keys(GIACOM_CARE_LEVELS), true) ? setting('giacom_care_level') : ($product['care_default'] ?? 'standard'),
                'site_visit_reason' => 'NO_SITE_VISIT', 'access_line_id' => '', 'client_ref' => '', 'force_new_ont' => '',
                'title' => $title, 'forename' => $forename, 'surname' => $surname,
                'telephone' => (string)(($contact['phone'] ?? '') ?: ($contact['mobile'] ?? '') ?: ($site['phone'] ?? '') ?: $account['phone']),
                'email' => (string)($contact['email'] ?? $account['email']), 'crm_product_id' => '',
            ];
            $errors = [];
            if (is_post()) {
                verify_csrf();
                foreach ($values as $k => $v) {
                    $values[$k] = trim((string)($_POST[$k] ?? ''));
                }
                $values['order_type'] = $values['order_type'] === 'migrate' ? 'migrate' : 'provide';
                $values['force_new_ont'] = in_array($values['force_new_ont'], ['Y', 'N'], true) ? $values['force_new_ont'] : '';
                $values['cli'] = preg_replace('/\D/', '', $values['cli']);
                if ($values['cli'] !== '' && !preg_match('/^0\d{9,10}$/', $values['cli'])) {
                    $errors['cli'] = 'Enter the phone number as 10 or 11 digits starting with 0.';
                }
                if ($values['order_type'] === 'migrate' && $values['cli'] === '' && $values['access_line_id'] === '') {
                    $errors['cli'] = 'A migrate order needs the existing line\'s number or its access line ID.';
                }
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $values['crd']);
                if (!$d || $d->format('Y-m-d') !== $values['crd'] || $values['crd'] <= date('Y-m-d')) {
                    $errors['crd'] = 'Choose a date in the future.';
                }
                elseif ($leadSource && $values['crd'] < $lead) {
                    $errors['crd'] = 'Giacom\'s earliest date for this product is ' . fmt_date($lead) . '.';
                }
                // The suffix and realm are added on sending, so drop them if typed into the username too.
                if (str_contains($values['bb_username'], '@')) {
                    $values['bb_username'] = substr($values['bb_username'], 0, strpos($values['bb_username'], '@'));
                }
                [$typedSuffix, $values['realm']] = giacom_split_realm($values['realm']);
                if ($typedSuffix !== '' && $values['bb_suffix'] === '') {
                    $values['bb_suffix'] = $typedSuffix;
                }
                if ($values['bb_suffix'] !== '' && !preg_match('/^[A-Za-z0-9._+-]{1,40}$/', $values['bb_suffix'])) {
                    $errors['bb_suffix'] = 'Use letters, numbers and . _ - only.';
                }
                $offered = array_map('strtolower', $product['realms'] ?? []);
                if ($values['realm'] === '') {
                    $errors['realm'] = 'Giacom needs a realm to set up the broadband login. Set a default under Admin → Giacom.';
                } elseif ($offered && !in_array(strtolower(giacom_realm_value($values['bb_suffix'], $values['realm'])), $offered, true)) {
                    $errors['realm'] = 'Giacom offers these realms for this product: ' . implode(', ', $product['realms']) . '.';
                }
                if (!preg_match('/^[A-Za-z0-9._+-]{2,100}$/', $values['bb_username'])) {
                    $errors['bb_username'] = 'Use letters, numbers and . _ - only.';
                }
                if (strlen($values['bb_password']) < 6) {
                    $errors['bb_password'] = 'At least 6 characters.';
                }
                if ($values['forename'] === '' || $values['surname'] === '') {
                    $errors['surname'] = 'Enter the contact\'s first name and surname.';
                }
                if (!preg_match('/^[\d +]{10,16}$/', $values['telephone'])) {
                    $errors['telephone'] = 'Enter a contact phone number.';
                }
                if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'That isn\'t a valid email address.';
                }
                if ($values['care_level'] !== '' && !isset(GIACOM_CARE_LEVELS[$values['care_level']])) {
                    $errors['care_level'] = 'Choose a care level.';
                }
                if ($values['crm_product_id'] !== '' && !db_value('SELECT id FROM products WHERE id = ?', [(int)$values['crm_product_id']])) {
                    $values['crm_product_id'] = '';
                }
                if (!$errors) {
                    try {
                        $id = giacom_place_order($check, $product, $values);
                        flash('Order placed with Giacom. Its progress will show here and on the customer\'s page.');
                        redirect(url('giacom', ['action' => 'view', 'id' => $id]));
                    } catch (GiacomException $e) {
                        $errors['_'] = $e->getMessage();
                    }
                }
            }
            $crmProducts = ref_options('products', null, "category = 'broadband'");
            page('giacom_order', compact('check', 'result', 'product', 'account', 'site', 'values', 'errors', 'crmProducts', 'appointments', 'lead', 'leadSource'), 'Place broadband order');
            return;

        case 'view':
            $order = db_one('SELECT o.*, a.name AS account_name, s.name AS site_name, u.name AS user_name FROM giacom_orders o
                LEFT JOIN accounts a ON a.id = o.account_id LEFT JOIN sites s ON s.id = o.site_id LEFT JOIN users u ON u.id = o.created_by WHERE o.id = ?',
                [query_int('id') ?? 0]) ?? not_found('Order not found.');
            if (is_post()) {
                verify_csrf();
                try {
                    if (query('do') === 'abort') {
                        require_permission('orders.place');
                        $reason = trim((string)($_POST['reason'] ?? ''));
                        if ($reason === '') {
                            throw new GiacomException('Please give a reason for cancelling.');
                        }
                        flash('Giacom says: ' . giacom_abort_order($order, mb_substr($reason, 0, 250)));
                    } else {
                        giacom_refresh_order($order);
                        flash('Order refreshed from Giacom.');
                    }
                } catch (GiacomException $e) {
                    db_exec('UPDATE giacom_orders SET last_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 500), $order['id']]);
                    flash($e->getMessage(), 'error');
                }
                redirect($back($order));
            }
            $events = db_all('SELECT * FROM giacom_order_events WHERE order_id = ? ORDER BY event_date DESC, id DESC', [$order['id']]);
            page('giacom_view', compact('order', 'events'), 'Giacom order ' . $order['giacom_order_id']);
            return;

        case 'sync':
            if (!is_post()) {
                redirect(url('giacom'));
            }
            verify_csrf();
            try {
                $r = giacom_sync();
                flash("Checked Giacom for updates: {$r['events']} update" . ($r['events'] === 1 ? '' : 's') . " to your orders, {$r['status_changes']} status change" . ($r['status_changes'] === 1 ? '' : 's') . '.');
            } catch (GiacomException $e) {
                flash($e->getMessage(), 'error');
            }
            redirect(url('giacom'));
    }

    // Orders list
    $filter = query('show', 'open');
    $where = match ($filter) {
        'completed' => 'o.completed_at IS NOT NULL',
        'all'       => '1=1',
        default     => "o.completed_at IS NULL AND NOT (o.status REGEXP 'cancel|abort|reject|fail')",
    };
    $orders = db_all("SELECT o.*, a.name AS account_name, u.name AS user_name FROM giacom_orders o LEFT JOIN accounts a ON a.id = o.account_id
        LEFT JOIN users u ON u.id = o.created_by WHERE $where ORDER BY o.id DESC LIMIT 300");
    $checks = db_all('SELECT c.*, a.name AS account_name FROM giacom_checks c LEFT JOIN accounts a ON a.id = c.account_id ORDER BY c.id DESC LIMIT 15');
    page('giacom_orders', compact('orders', 'checks', 'filter'), 'Broadband orders');
}
