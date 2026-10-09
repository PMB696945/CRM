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

/**
 * A Giacom address as the CRM's address fields: line 1 (unit, building name, number and street),
 * line 2, town, county and postcode, plus the organisation at the address.
 */
function giacom_address_fields(array $a): array
{
    $t = fn($k) => trim((string)($a[$k] ?? ''));
    $building = $t('building');
    $premise = $t('premise');
    $street = $t('street');
    $numbered = $building !== '' && preg_match('/^\d+[A-Za-z]?(?:\s*-\s*\d+[A-Za-z]?)?$/', $building);
    $names = array_values(array_unique(array_filter([$t('sub-premise'), $premise !== $building ? $premise : '', $numbered ? '' : $building])));
    $road = trim(($numbered ? $building . ' ' : '') . $street);
    // A named building goes on line 1 with the road on line 2; a numbered one shares line 1 with the road.
    if ($names && !$numbered && $road !== '') {
        [$line1, $line2] = [implode(', ', $names), implode(', ', array_filter([$road, $t('locality')]))];
    } else {
        [$line1, $line2] = [implode(', ', array_filter([...$names, $road])), $t('locality')];
    }
    return [
        'address' => $line1, 'address2' => $line2, 'city' => ucwords(strtolower($t('city'))) ?: $t('city'),
        'county' => $t('county'), 'postcode' => giacom_postcode($t('postcode')), 'organisation' => $t('organisation'),
    ];
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
    // The UPRN lets Giacom include CityFibre FTTP; address_match knows it for each address.
    if (empty($address['uprn']) && !empty($address['postcode'])) {
        try {
            foreach (giacom_call('address_match', ['postcode' => giacom_postcode($address['postcode'])])['addresses'] ?? [] as $m) {
                if (($m['addressRef'] ?? null) === ($address['address-reference'] ?? '') && !empty($m['uprn'])) {
                    $address['uprn'] = (string)$m['uprn'];
                    break;
                }
            }
        } catch (GiacomException) {
            // Not essential: the check still works without it.
        }
    }
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
            // Giacom leaves technology-type empty on some products (FTTP); fall back to the reference.
            'technology' => strtolower((string)(($p['technology-type'] ?? '') ?: giacom_tech_label('', (string)($p['supplier-product-reference'] ?? ''), (string)($p['product-name'] ?? '')))),
            'supplier_ref' => trim(($p['supplier-product-reference'] ?? '') . ' ' . ($p['supplier-product-subtype'] ?? '')),
            'supplier' => giacom_supplier_name((string)($p['supplier'] ?? ($p['network'] ?? '')), (string)($p['supplier-product-reference'] ?? ''), (string)($p['product-name'] ?? '')),
            'supplier_code' => $p['supplier'] ?? null,
            'tech_label' => giacom_tech_label((string)($p['technology-type'] ?? ''), (string)($p['supplier-product-reference'] ?? ''), (string)($p['product-name'] ?? '')),
            'install_type' => $p['expected-install-type'] ?? null,
            'speed' => ($speed = giacom_product_speed($p, $estimates[$key] ?? null))['down'] !== null ? $speed['down'] * 1000000 : null,
            'down_mbps' => $speed['down'],
            'up_mbps' => $speed['up'],
            'contract_months' => giacom_contract_months((string)($p['product-name'] ?? '')),
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
        'min_visit' => ['new_line' => giacom_visit_code($a['minimum-svr-new-line'] ?? null), 'existing_line' => giacom_visit_code($a['minimum-svr-existing-line'] ?? null)],
        'site_classification' => ($a['site-classification'] ?? '') ?: null,
        'ont' => giacom_ont_details($r['ont-details'] ?? ($a['ont-details'] ?? null)),
        'fttc' => isset($a['fttc-qualification']['likely-max-speed-down']) ? [(float)$a['fttc-qualification']['likely-max-speed-down'], (float)($a['fttc-qualification']['likely-max-speed-up'] ?? 0)] : null,
        'products' => $products,
        'raw' => $r,
    ];
}

/**
 * A product's headline speed in Mbps: ['down' => float|null, 'up' => float|null].
 * Giacom is inconsistent: the product subtype or name usually carries the package
 * speed ("80/20", "1000/115"); likely-max-range is bits/s; service-speed is a
 * kbit/s range ("40000.00 - 72000.00"), a plain bits/s figure or empty.
 */
function giacom_product_speed(array $p, ?array $estimate = null): array
{
    foreach ([(string)($p['supplier-product-subtype'] ?? ''), (string)($p['product-name'] ?? '')] as $text) {
        if (preg_match('~(?<![\d.])(\d{1,4}(?:\.\d+)?)\s*/\s*(\d{1,4}(?:\.\d+)?)(?![\d.])~', $text, $m)) {
            return ['down' => (float)$m[1], 'up' => (float)$m[2]];
        }
    }
    $down = null;
    if (is_numeric($p['likely-max-range'] ?? null) && (float)$p['likely-max-range'] > 0) {
        $down = (float)$p['likely-max-range'] / 1000000;
    } elseif (($s = trim((string)($p['service-speed'] ?? ''))) !== '') {
        $nums = array_map('floatval', preg_split('/\s*-\s*/', $s));
        $max = max($nums);
        // A range, or a figure small enough to be kbit/s; otherwise bits/s.
        $down = count($nums) > 1 || $max < 1000000 ? $max / 1000 : $max / 1000000;
    } elseif ($estimate && ($e = giacom_range_max($estimate['down'] ?? null)) !== null) {
        $down = $e / 1000;
    }
    $up = $estimate ? giacom_range_max($estimate['up'] ?? null) : null;
    return ['down' => $down !== null && $down > 0 ? round($down, 1) : null, 'up' => $up ? round($up / 1000, 1) : null];
}

/**
 * FTTP ONT details at the address: ['type', 'new_ont', 'existing_ont', 'onts' => [[reference, serial, max_speed, ports => [[number, type, status]]]]].
 * Giacom sends one ONT (or port) as an object and several as a list.
 */
function giacom_ont_details(mixed $d): ?array
{
    if (!is_array($d) || !$d) {
        return null;
    }
    $list = fn($v) => is_array($v) ? (array_is_list($v) ? $v : [$v]) : [];
    $onts = [];
    foreach ($list($d['ont-list']['ont'] ?? ($d['ont-list'] ?? [])) as $o) {
        if (!is_array($o) || empty($o['reference'])) {
            continue;
        }
        $onts[] = [
            'reference' => (string)$o['reference'],
            'serial' => $o['serial_number'] ?? ($o['serial-number'] ?? null),
            'max_speed' => $o['max_speed'] ?? ($o['max-speed'] ?? null),
            'ports' => array_map(fn($p) => ['number' => $p['number'] ?? null, 'type' => $p['type'] ?? null, 'status' => $p['status'] ?? null],
                array_filter($list($o['port'] ?? ($o['ports']['port'] ?? [])), 'is_array')),
        ];
    }
    return [
        'type' => ($d['ont-type'] ?? '') ?: null,
        'new_ont' => ($d['new-ont'] ?? '') === 'Y',
        'existing_ont' => ($d['existing-ont'] ?? '') === 'Y',
        'onts' => $onts,
    ];
}

/** The top of a range like "40000 - 72000" (or a plain number). */
function giacom_range_max(mixed $v): ?float
{
    if ($v === null || $v === '' || is_array($v)) {
        return null;
    }
    $nums = array_filter(array_map('trim', preg_split('/\s*-\s*/', (string)$v)), 'is_numeric');
    return $nums ? max(array_map('floatval', $nums)) : null;
}

/** Contract length from a product name such as "SKY SOGEA 80/20 (36 Months)". */
function giacom_contract_months(string $name): ?int
{
    return preg_match('/(\d{1,2})\s*months?/i', $name, $m) ? (int)$m[1] : null;
}

/** Giacom's minimum site visit ("Standard", "Premium", "Advanced", "None") → the order code. */
function giacom_visit_code(mixed $v): ?string
{
    return match (strtolower(trim((string)$v))) {
        'advanced', 'advanced_install' => 'ADVANCED',
        'premium', 'premium_install' => 'PREMIUM',
        'standard', 'standard_install' => 'STANDARD',
        'none', 'no_site_visit', 'no site visit' => 'NO_SITE_VISIT',
        default => null,
    };
}

/** IP address options on an order: dynamic, one static IP, or a routed block of static IPs (its size). */
const GIACOM_IP_OPTIONS = [
    'dynamic' => ['Dynamic IP', 0],
    'static'  => ['1 static IP', 1],
    'block4'  => ['Block of 4 static IPs (/30)', 4],
    'block8'  => ['Block of 8 static IPs (/29)', 8],
    'block16' => ['Block of 16 static IPs (/28)', 16],
];

/** Engineer visits, least first. Advanced is for FTTP only (e.g. a new ONT needing extra work). */
const GIACOM_VISITS = ['NO_SITE_VISIT' => 'Not needed', 'STANDARD' => 'Standard install', 'PREMIUM' => 'Premium install', 'ADVANCED' => 'Advanced install'];

/** The visits to offer for a product: Advanced only for FTTP, or when the address needs it. */
function giacom_visits_for(array $product, ?string $min = null): array
{
    return array_filter(GIACOM_VISITS, fn($k) => $k !== 'ADVANCED' || giacom_is_fttp($product) || $min === 'ADVANCED', ARRAY_FILTER_USE_KEY);
}

/** Make Giacom's "Site Visit Reason is not in the list of valid values (…)" say what to do. */
function giacom_explain_visit_error(string $message): string
{
    if (!preg_match('/Site Visit Reason is not in the list of valid values \(([A-Z_, ]+)\)/i', $message, $m)) {
        return $message;
    }
    $valid = array_map(fn($c) => GIACOM_VISITS[strtoupper(trim($c))] ?? trim($c), explode(',', $m[1]));
    return $message . ' This address can only have: ' . implode(' or ', $valid) . '. Choose that under Engineer visit and order again.';
}

/** The least engineer visit Giacom says the address needs for this kind of order (null if it didn't say). */
function giacom_min_visit(array $result, string $orderType): ?string
{
    return $result['min_visit'][$orderType === 'migrate' ? 'existing_line' : 'new_line'] ?? null;
}

/** A visit no lower than the minimum (Not needed < Standard < Premium). */
function giacom_visit_at_least(string $visit, ?string $min): string
{
    $rank = array_flip(array_keys(GIACOM_VISITS));
    return $min !== null && isset($rank[$min]) && ($rank[$visit] ?? 0) < $rank[$min] ? $min : (isset($rank[$visit]) ? $visit : ($min ?? 'NO_SITE_VISIT'));
}

/** A saved check's result, re-read from Giacom's raw response so parsing fixes apply to old checks. */
function giacom_check_result(array $check): array
{
    $result = json_decode((string)$check['result'], true) ?: ['products' => []];
    return !empty($result['raw']) && is_array($result['raw']) ? giacom_summarise_availability($result['raw']) : $result;
}

/** Which network a product is on, from Giacom's supplier field or its product reference. */
function giacom_supplier_name(string $supplier, string $ref, string $name = ''): string
{
    $hay = strtolower($supplier . ' ' . $ref . ' ' . $name);
    return match (true) {
        str_contains($hay, 'cityfibre') || str_contains($hay, 'city fibre') || preg_match('/(^|[\s_])cf[_\s]/', $hay) === 1 => 'CityFibre',
        preg_match('/(^|[\s_])sky([_\s]|$)/', $hay) === 1 => 'Sky',
        str_contains($hay, 'vodafone') || preg_match('/(^|[\s_])(vf|voda)([_\s]|$)/', $hay) === 1 => 'Vodafone',
        str_contains($hay, 'ttb') || str_contains($hay, 'talktalk') => 'TalkTalk',
        str_contains($hay, 'bt_') || str_contains($hay, 'bt ') || str_contains($hay, 'openreach') || str_contains($hay, 'btw') => 'BT Wholesale',
        $supplier !== '' => ucfirst($supplier),
        default => 'Other',
    };
}

/** A short technology label: SOADSL, SOGEA, FTTP, FTTC, G.fast… */
function giacom_tech_label(string $tech, string $ref, string $name = ''): string
{
    $hay = strtoupper($ref . ' ' . $name . ' ' . $tech);
    return match (true) {
        str_contains($hay, 'SOGFAST') => 'SOGFAST',
        str_contains($hay, 'SOADSL') || (str_contains($hay, 'SOGEA') && str_contains($hay, 'ADSL')) => 'SOADSL',
        str_contains($hay, 'SOGEA') => 'SOGEA',
        str_contains($hay, 'FTTP') => 'FTTP',
        str_contains($hay, 'GFAST') || str_contains($hay, 'G.FAST') => 'G.fast',
        str_contains($hay, 'FTTC') || strtoupper($tech) === 'VDSL' => 'FTTC',
        default => strtoupper($tech) ?: 'Other',
    };
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
    // Giacom's reference for the order: the one given on the order, or else the customer's account number.
    $clientRef = mb_substr(trim((string)$o['client_ref']) !== '' ? trim((string)$o['client_ref']) : (string)$account['account_number'], 0, 60);

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
        'force-new-ont' => ($o['force_new_ont'] ?? '') ?: (giacom_is_fttp($product) ? giacom_default_ont($type) : null),
        'fixed-ip' => isset(GIACOM_IP_OPTIONS[$o['ip_option'] ?? '']) ? (($o['ip_option'] ?? '') === 'dynamic' ? 'N' : 'Y') : null,
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

    // Who's at the address, for access and the engineer (required by Giacom as well as the customer).
    $siteContact = array_filter([
        'title' => ($o['site_title'] ?? '') ?: null,
        'forename' => $o['site_forename'] ?? '',
        'surname' => $o['site_surname'] ?? '',
        'telephone' => preg_replace('/[^\d+]/', '', (string)($o['site_telephone'] ?? '')),
        'email' => ($o['site_email'] ?? '') ?: null,
        'pass-phrase' => ($o['site_passphrase'] ?? '') ?: null,
        'site-notes' => ($o['site_notes'] ?? '') ?: null,
        'hazard-notes' => ($o['hazard_notes'] ?? '') ?: null,
    ], fn($v) => $v !== null && $v !== '');
    $r = giacom_call($type, ['order' => $order, 'customer' => $customer, 'site-contact' => $siteContact]);
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
            'carrier' => 'Giacom', 'status' => 'pending', 'monthly_price' => $o['service_monthly_price'] ?? null, 'setup_fee' => $o['service_setup_fee'] ?? null,
            'start_date' => null, 'term_months' => null, 'contract_end_date' => null,
            'install_address' => $check['site_id'] ? null : mb_substr((string)$check['address_label'], 0, 255),
            'notes' => "Giacom $type order $orderId: {$product['name']}" . ($fullUsername ? "\nBroadband username: $fullUsername" : ''),
            'login_username' => $fullUsername ?: null,
            'ip_details' => giacom_ip_pending_label($o['ip_option'] ?? null),
        ]);
        if ($o['bb_password'] !== '') {
            db_exec('UPDATE services SET login_password = ? WHERE id = ?', [encrypt_secret((string)$o['bb_password']), $serviceId]);
        }
        db_exec('INSERT INTO giacom_orders (account_id, site_id, service_id, check_id, order_type, giacom_order_id, giacom_service_id, cli, product_id, product_name,
                technology_type, broadband_username, address_label, crd, client_ref, status, status_updated_at, details, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)', [
            $account['id'], $check['site_id'] ?: null, $serviceId, $check['id'], $type, $orderId, $r['service-id'] ?? null, $o['cli'] ?: null,
            $product['product_id'], mb_substr($product['name'], 0, 190), $product['technology'], $fullUsername ?: null,
            $check['address_label'], $o['crd'], $clientRef, 'Placed',
            json_encode(['care_level' => $o['care_level'], 'contact' => trim($o['forename'] . ' ' . $o['surname']), 'telephone' => $o['telephone'], 'email' => $o['email'],
                'site_contact' => trim(($o['site_forename'] ?? '') . ' ' . ($o['site_surname'] ?? '')), 'site_telephone' => $o['site_telephone'] ?? '',
                'site_email' => $o['site_email'] ?? '',
                'ip_option' => $o['ip_option'] ?? null,
                // Kept (encrypted) so the customer's setup details can be emailed again.
                'bb_password' => $o['bb_password'] !== '' ? encrypt_secret((string)$o['bb_password']) : null,
                'site_visit_reason' => $o['site_visit_reason'] ?? null]),
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
    service_changed($serviceId, null);
    if ((GIACOM_IP_OPTIONS[$o['ip_option'] ?? ''][1] ?? 0) > 1) {
        try {
            giacom_request_ip_block(db_one('SELECT * FROM giacom_orders WHERE id = ?', [$id]));
        } catch (GiacomException $e) {
            // The order stands: the block can be asked for again from the order page.
            db_exec('UPDATE giacom_orders SET last_error = ? WHERE id = ?', ['Order placed, but the static IP block couldn\'t be requested: '
                . mb_substr($e->getMessage(), 0, 380) . ' Request it again from this page.', $id]);
        }
    }
    return $id;
}

/** Ask Giacom for the order's routed block of static IPs (its size from the order), and keep the addresses it gives. */
function giacom_request_ip_block(array $order): array
{
    $details = json_decode((string)$order['details'], true) ?: [];
    $size = GIACOM_IP_OPTIONS[$details['ip_option'] ?? ''][1] ?? 0;
    if ($size < 2) {
        throw new GiacomException('This order isn\'t for a block of static IPs.');
    }
    if (!$order['giacom_service_id']) {
        throw new GiacomException('Giacom hasn\'t given this order a service ID yet. Refresh the order and try again.');
    }
    $r = giacom_call('change_ips', ['service-id' => $order['giacom_service_id'], 'fixed-ip' => 'Y', 'routed-ip' => 'Y', 'allocation-size' => (string)$size]);
    $details['ip_address'] = (string)($r['ip-address'] ?? '') ?: null;
    $details['ip_block'] = (string)($r['cidr'] ?? '') ?: null;
    db_exec('UPDATE giacom_orders SET details = ?, last_error = NULL WHERE id = ?', [json_encode($details), $order['id']]);
    giacom_store_event((int)$order['id'], date('Y-m-d H:i:s'), 'ip', 'Static IP block of ' . $size . ' requested' . ($details['ip_block'] ? ': ' . $details['ip_block'] : ''));
    return $details;
}

/** What's known about the IP address before the service is live (static addresses are only allocated then). */
function giacom_ip_pending_label(?string $option): ?string
{
    if ($option === null || !isset(GIACOM_IP_OPTIONS[$option])) {
        return null;
    }
    return $option === 'dynamic' ? 'Dynamic' : GIACOM_IP_OPTIONS[$option][0] . ' (allocated when the service goes live)';
}

/** The broadband login and IP details for a placed order (for the customer, the dealer and staff), from its service. */
function giacom_setup_details(array $order): array
{
    $svc = $order['service_id'] ? db_one('SELECT * FROM services WHERE id = ?', [$order['service_id']]) : null;
    $details = json_decode((string)$order['details'], true) ?: [];
    $ip = (string)($svc['ip_details'] ?? '') ?: (string)giacom_ip_pending_label($details['ip_option'] ?? null);
    if (str_contains($ip, 'allocated when')) {
        $ip = str_replace('(allocated when the service goes live)', '(the addresses are confirmed when your service goes live)', $ip);
    }
    return array_filter([
        'Broadband username' => (string)(($svc['login_username'] ?? '') ?: $order['broadband_username']),
        'Broadband password' => (string)(decrypt_secret(($svc['login_password'] ?? null) ?: ($details['bb_password'] ?? null)) ?? ''),
        'IP address' => $ip,
    ], fn($v) => $v !== '');
}

/** When an order goes live: the service's IP address(es) and login as Giacom now has them (service_details). */
function giacom_fetch_live_details(array $order): void
{
    if (!$order['giacom_service_id'] || !$order['service_id']) {
        return;
    }
    $r = giacom_call('service_details', ['service-id' => $order['giacom_service_id'], 'detailed' => 'Y']);
    $sd = $r['service-details'] ?? [];
    $details = json_decode((string)$order['details'], true) ?: [];
    $ip = trim((string)($sd['ip-address'] ?? ''));
    $block = (string)($details['ip_block'] ?? '');
    $ipText = $ip !== '' ? $ip . ($block !== '' && !str_starts_with($block, $ip) ? ' (block ' . $block . ')' : '') : (($details['ip_option'] ?? 'dynamic') === 'dynamic' ? 'Dynamic' : null);
    $sets = [];
    $params = [];
    if ($ipText !== null) {
        $sets[] = 'ip_details = ?';
        $params[] = mb_substr($ipText, 0, 255);
    }
    if (($pw = (string)($sd['password'] ?? '')) !== '') {
        $sets[] = 'login_password = ?';
        $params[] = encrypt_secret($pw);
    }
    if (($user = trim((string)($sd['username'] ?? ''))) !== '' && str_contains($user, '@')) {
        $sets[] = 'login_username = ?';
        $params[] = mb_substr($user, 0, 190);
    }
    if ($sets) {
        db_exec('UPDATE services SET ' . implode(', ', $sets) . ' WHERE id = ?', [...$params, $order['service_id']]);
    }
    if ($ip !== '') {
        giacom_store_event((int)$order['id'], date('Y-m-d H:i:s'), 'ip', 'Live with IP ' . $ipText);
    }
}

/** Setup details as an email table. */
function giacom_setup_details_html(array $order): string
{
    $rows = giacom_setup_details($order);
    if (!$rows) {
        return '';
    }
    $html = '<p style="margin-top:20px"><b>Your broadband setup details</b></p><table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:8px 0;background:#f9fafb;border:1px solid #e4e7ec;border-radius:8px">';
    foreach ($rows as $label => $value) {
        $mono = $label !== 'IP address' ? 'font-family:monospace;font-size:15px;' : '';
        $html .= '<tr><td style="padding:8px 12px;color:#667085;white-space:nowrap">' . h($label) . '</td><td style="padding:8px 12px;' . $mono . '">' . h($value) . '</td></tr>';
    }
    return $html . '</table><p style="color:#667085;font-size:13px">If your router wasn\'t supplied ready to use, enter the username and password in its broadband (PPP) settings. Please keep these details safe.</p>';
}

/** Is this an FTTP product (which has an ONT on the wall)? */
function giacom_is_fttp(array $product): bool
{
    return str_contains(strtolower((string)($product['technology'] ?? '')), 'fttp') || ($product['tech_label'] ?? '') === 'FTTP';
}

/** The ONT choice for an FTTP order: new for a new service, the existing one for a take-over. */
function giacom_default_ont(string $orderType): string
{
    return $orderType === 'migrate' ? 'N' : 'Y';
}

/** A placed order's product as it was in the availability check (supplier, line type), for asking about dates again. */
function giacom_order_product(array $order, ?array $check): array
{
    foreach ($check ? (giacom_check_result($check)['products'] ?? []) : [] as $p) {
        if ((string)($p['product_id'] ?? '') === (string)$order['product_id']) {
            return $p;
        }
    }
    return ['technology' => $order['technology_type'], 'name' => $order['product_name'], 'supplier' => null, 'supplier_code' => null];
}

/** The engineer visit for a placed order: as ordered, else the least Giacom said the address needs. */
function giacom_order_visit(array $order, ?array $check): string
{
    $details = json_decode((string)$order['details'], true) ?: [];
    $min = $check ? giacom_min_visit(giacom_check_result($check), (string)$order['order_type']) : null;
    return giacom_visit_at_least(giacom_visit_code($details['site_visit_reason'] ?? null) ?? $min ?? 'NO_SITE_VISIT', $min);
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

/** Is the order finished without going live? Only once Giacom confirms it: "Cancellation requested" isn't cancelled yet. */
function giacom_is_cancelled(string $status): bool
{
    if (preg_match('/request|pending|refus|in progress|awaiting/i', $status)) {
        return false;
    }
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
                service_changed((int)$svc['id'], $svc); // live: billing starts from the real date
            }
        }
        try {
            giacom_fetch_live_details(db_one('SELECT * FROM giacom_orders WHERE id = ?', [$order['id']]));
        } catch (GiacomException $e) {
            giacom_store_event((int)$order['id'], date('Y-m-d H:i:s'), 'ip', 'Couldn\'t fetch the live IP details: ' . mb_substr($e->getMessage(), 0, 300));
        }
        if ($order['account_id']) {
            log_activity((int)$order['account_id'], 'note', "Giacom order {$order['giacom_order_id']} completed: {$order['product_name']}");
        }
    } elseif (giacom_is_cancelled($status) && $order['service_id']) {
        $before = db_one('SELECT * FROM services WHERE id = ?', [$order['service_id']]);
        if (db_exec("UPDATE services SET status = 'ceased' WHERE id = ? AND status = 'pending'", [$order['service_id']])) {
            service_changed((int)$order['service_id'], $before);
        }
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
    // Since Sept 2026 Giacom says whether the cancel worked (cancel-status success/error), with the reasons when it didn't.
    $status = strtolower(trim((string)($r['cancel-status'] ?? '')));
    $problems = [];
    foreach (is_array($r['errors'] ?? null) ? $r['errors'] : [] as $e) {
        $problems[] = trim((string)(is_array($e) ? ($e['error'] ?? '') : $e));
    }
    $warnings = [];
    foreach (is_array($r['components'] ?? null) ? $r['components'] : [] as $c) {
        if (!is_array($c)) {
            continue;
        }
        $label = !empty($c['component']) ? 'Component ' . $c['component'] . ': ' : '';
        if (trim((string)($c['error'] ?? '')) !== '') {
            $problems[] = $label . trim((string)$c['error']);
        }
        if (trim((string)($c['warn'] ?? '')) !== '') {
            $warnings[] = $label . trim((string)$c['warn']);
        }
    }
    $problems = array_values(array_unique(array_filter($problems)));
    $message = trim((string)($r['message'] ?? ''));
    $accountId = $order['account_id'] ? (int)$order['account_id'] : null;

    if ($status === 'error' || ($problems && $status !== 'success')) {
        $why = implode('; ', $problems) ?: ($message ?: 'Giacom didn\'t give a reason');
        giacom_store_event((int)$order['id'], date('Y-m-d H:i:s'), 'cancel', 'Cancellation refused: ' . $why . ' (reason given: ' . $reason . ')');
        audit('giacom_abort', "Giacom refused to cancel order {$order['giacom_order_id']}: $why", 'accounts', $accountId);
        throw new GiacomException("Giacom couldn't cancel this order: $why");
    }

    $note = 'Cancellation accepted' . ($message !== '' ? " ($message)" : '') . ': ' . $reason . ($warnings ? ' · Warnings: ' . implode('; ', $warnings) : '');
    giacom_store_event((int)$order['id'], date('Y-m-d H:i:s'), 'cancel', $note);
    // Older replies gave the new status itself (e.g. "Cancelled").
    $legacy = (string)($r['cancel-status'] ?? '');
    giacom_set_status($order, giacom_is_cancelled($legacy) ? $legacy : 'Cancellation requested');
    audit('giacom_abort', "Giacom order {$order['giacom_order_id']} cancellation accepted: $reason", 'accounts', $accountId);
    try {
        giacom_refresh_order(db_one('SELECT * FROM giacom_orders WHERE id = ?', [$order['id']]));
    } catch (GiacomException) {
        // The cron job picks up the final status.
    }
    $now = (string)db_value('SELECT status FROM giacom_orders WHERE id = ?', [$order['id']]);
    return 'Cancellation accepted. The order is now: ' . $now . ($warnings ? '. Warnings: ' . implode('; ', $warnings) : '') . '.';
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

/**
 * Install/engineer appointments Giacom can offer for a product at an address.
 * Returns ['appointments' => [['date', 'slot', 'ref'], ...], 'error' => ?string].
 */
/**
 * What Giacom's appointment search calls the line type: [technology-type, order-type]. Giacom accepts FTTP, FTTC,
 * SOADSL, and SOGEA as SOGEA_NEW (a new line) or SOGEA_EXISTING (taking over an existing one).
 */
function giacom_appointment_service(array $product, string $orderType): array
{
    $tech = strtoupper((string)(($product['tech_label'] ?? '') ?: giacom_tech_label((string)($product['technology'] ?? ''), (string)($product['supplier_ref'] ?? ''), (string)($product['name'] ?? ''))));
    return match ($tech) {
        'SOGEA' => ['SOGEA', $orderType === 'migrate' ? 'SOGEA_EXISTING' : 'SOGEA_NEW'],
        'FTTP', 'FTTC', 'SOADSL' => [$tech, null],
        default => [strtoupper((string)($product['technology'] ?? '')) ?: $tech, null],
    };
}

function giacom_appointments(array $check, array $product, string $visitReason = 'NO_SITE_VISIT', string $orderType = 'provide'): array
{
    $address = json_decode((string)$check['address'], true) ?: [];
    [$technologyType, $giacomOrderType] = giacom_appointment_service($product, $orderType);
    $supplier = $product['supplier_code'] ?? null;
    if (!$supplier && ($product['supplier'] ?? '') === 'Sky') {
        $supplier = 'SKY';
    }
    try {
        $r = giacom_call('available_appointments', array_filter([
            'technology-type' => $technologyType,
            'address-reference' => $address['address-reference'] ?? null,
            'css-database-code' => $address['css-database-code'] ?? null,
            'uprn' => $address['uprn'] ?? null,
            'supplier' => $supplier,
            'site-visit-reason' => $visitReason ?: null,
            'order-type' => $giacomOrderType,
        ], fn($v) => $v !== null && $v !== ''), '2.0.1');
    } catch (GiacomException $e) {
        return ['appointments' => [], 'error' => $e->getMessage()];
    }
    $out = [];
    foreach ($r['appointments'] ?? [] as $a) {
        $date = $a['date'] ?? ($a['appointment-date'] ?? null);
        if (!$date) {
            continue;
        }
        $out[] = [
            'date' => substr((string)$date, 0, 10),
            'slot' => (string)($a['timeslot'] ?? ($a['appointment-slot'] ?? ($a['slot'] ?? ''))),
            'ref'  => (string)($a['appointment-ref'] ?? ($a['appointment-reference'] ?? ($a['reference'] ?? ''))),
        ];
    }
    usort($out, fn($x, $y) => strcmp($x['date'] . $x['slot'], $y['date'] . $y['slot']));
    return ['appointments' => $out, 'error' => null];
}

/** "2026-10-14|AM|ref" for an appointment radio button, and back. */
function giacom_appointment_key(array $a): string
{
    return $a['date'] . '|' . $a['slot'] . '|' . $a['ref'];
}

/** Book (or change) the appointment on an order that's been placed. */
function giacom_book_appointment(array $order, array $appointment): void
{
    giacom_call('amend_order', array_filter([
        'order-id' => $order['giacom_order_id'],
        'appointment-date' => $appointment['date'],
        'appointment-slot' => $appointment['slot'] ?: null,
        'appointment-ref' => $appointment['ref'] ?: null,
        'required-by-date' => $appointment['date'],
    ], fn($v) => $v !== null && $v !== ''));
    $details = json_decode((string)$order['details'], true) ?: [];
    $details['appointment'] = $appointment;
    db_exec('UPDATE giacom_orders SET crd = ?, details = ?, last_error = NULL WHERE id = ?', [$appointment['date'], json_encode($details), $order['id']]);
    giacom_store_event((int)$order['id'], date('Y-m-d H:i:s'), 'appointment', 'Booked for ' . fmt_date($appointment['date']) . ($appointment['slot'] ? ' ' . $appointment['slot'] : ''));
    audit('giacom_appointment', "Giacom order {$order['giacom_order_id']}: appointment booked for {$appointment['date']} {$appointment['slot']}", 'accounts', $order['account_id'] ? (int)$order['account_id'] : null);
}

/**
 * Email the customer a confirmation of their broadband order: what, where and when the install is.
 * In our name only (the supplier isn't mentioned). Returns the address used; throws IntegrationException.
 */
function giacom_email_confirmation(array $order, ?string $to = null): string
{
    $details = json_decode((string)$order['details'], true) ?: [];
    $to = trim((string)($to ?? ($details['email'] ?? '')));
    if ($to === '') {
        throw new IntegrationException('There\'s no customer email address on this order.');
    }
    $name = trim((string)($details['contact'] ?? ''));
    $first = trim(explode(' ', preg_replace('/^(mr|mrs|ms|miss|dr)\.?\s+/i', '', $name))[0] ?? '') ?: 'there';
    $account = $order['account_id'] ? db_one('SELECT name, account_number FROM accounts WHERE id = ?', [$order['account_id']]) : null;
    $product = $order['service_id'] ? db_value('SELECT p.name FROM services s JOIN products p ON p.id = s.product_id WHERE s.id = ?', [$order['service_id']]) : null;
    $product = $product ?: trim(preg_replace('/\bgiacom\b/i', '', (string)$order['product_name']));
    $appointment = $details['appointment'] ?? null;
    $visit = $details['site_visit_reason'] ?? null;
    $rows = array_filter([
        'Service' => $product ?: 'Broadband',
        'Order' => $order['order_type'] === 'migrate' ? 'Taking over your existing service' : 'New service',
        'Address' => (string)$order['address_label'],
        'Phone number' => (string)$order['cli'],
        $appointment ? 'Engineer appointment' : 'Expected by' => $appointment
            ? fmt_date($appointment['date']) . ($appointment['slot'] ? ' (' . $appointment['slot'] . ')' : '')
            : ($order['crd'] ? fmt_date($order['crd']) : ''),
        'Engineer visit' => $visit && $visit !== 'NO_SITE_VISIT' ? 'Yes – someone will need to be at the address to let the engineer in' : '',
        'Your account' => (string)($account['account_number'] ?? ''),
    ], fn($v) => $v !== '');
    $table = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:12px 0">';
    foreach ($rows as $label => $value) {
        $table .= '<tr><td style="padding:6px 12px 6px 0;color:#667085;vertical-align:top;white-space:nowrap">' . h($label) . '</td><td style="padding:6px 0">' . h($value) . '</td></tr>';
    }
    $table .= '</table>';
    $company = company('name', config('app_name'));
    $body = '<p>Hi ' . h($first) . ',</p>'
        . '<p>Thank you for your order. We\'ve placed it and will keep you updated as it progresses.</p>' . $table
        . giacom_setup_details_html($order)
        . '<p>If any of these details are wrong, or the date doesn\'t suit you, please reply to this email as soon as possible.</p>';
    send_mail($to, $name, "Your broadband order with $company", email_layout('Your broadband order is confirmed', $body));
    giacom_store_event((int)$order['id'], date('Y-m-d H:i:s'), 'email', 'Order confirmation emailed to ' . $to);
    if ($order['account_id']) {
        log_activity((int)$order['account_id'], 'email', 'Broadband order confirmation sent to ' . ($name ? "$name <$to>" : $to));
    }
    return $to;
}

/** Send the confirmation after placing an order, as a sentence for the flash message (the order stands either way). */
function giacom_try_confirmation(int $orderId): string
{
    try {
        return ' A confirmation was emailed to ' . giacom_email_confirmation(db_one('SELECT * FROM giacom_orders WHERE id = ?', [$orderId])) . '.';
    } catch (IntegrationException $e) {
        db_exec('UPDATE giacom_orders SET last_error = ? WHERE id = ?', ['The order confirmation couldn\'t be emailed: ' . mb_substr($e->getMessage(), 0, 400), $orderId]);
        return ' The confirmation email couldn\'t be sent: ' . $e->getMessage();
    }
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
            page('giacom_result', ['check' => $check, 'result' => giacom_check_result($check)], 'Availability');
            return;

        case 'order':
            require_permission('orders.place');
            $check = db_one('SELECT * FROM giacom_checks WHERE id = ?', [query_int('check') ?? 0]) ?? not_found('Check not found.');
            if (!$check['account_id']) {
                flash('Run the check from a customer\'s page to order for them.', 'error');
                redirect(url('giacom', ['action' => 'result', 'id' => $check['id']]));
            }
            $result = giacom_check_result($check);
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
            // The customer (end user) is the main contact; the site contact is whoever's at the address (the site's contact, if it has one).
            $contact = db_one('SELECT * FROM contacts WHERE id = ?', [$account['main_contact_id'] ?: (($site['contact_id'] ?? null) ?: 0)]);
            $siteContact = db_one('SELECT * FROM contacts WHERE id = ?', [($site['contact_id'] ?? null) ?: ($account['main_contact_id'] ?: 0)]);
            [$title, $forename, $surname] = giacom_split_name((string)($contact['name'] ?? ''));
            [$siteTitle, $siteForename, $siteSurname] = giacom_split_name((string)($siteContact['name'] ?? ''));
            // Ask Giacom for the actual install dates for this product and address.
            // Giacom says the least site visit the address needs (new line vs existing line).
            $orderType = in_array($result['quick_result'] ?? null, [4, 10], true) || $check['cli'] ? 'migrate' : 'provide';
            // Never less of a visit than Giacom says the address needs (for the order type being placed).
            $postedType = is_post() && isset($_POST['order_type']) ? (($_POST['order_type'] === 'migrate') ? 'migrate' : 'provide') : $orderType;
            $minVisit = giacom_min_visit($result, $postedType);
            $visit = giacom_visit_at_least(giacom_visit_code($_POST['site_visit_reason'] ?? null) ?? $minVisit ?? 'NO_SITE_VISIT', $minVisit);
            $slots = giacom_appointments($check, $product, $visit, $postedType);
            $appointments = $slots['appointments'];
            $appointmentsError = $slots['error'];
            $lead = $appointments[0]['date'] ?? ($product['leadtime']['first_date'] ?? null);
            $leadSource = $appointments ? 'appointment' : (($product['leadtime']['first_date'] ?? null) ? 'lead time' : null);
            $lead ??= date('Y-m-d', strtotime('+10 weekdays'));
            $values = [
                'order_type' => $orderType,
                'cli' => (string)$check['cli'], 'crd' => max($lead, date('Y-m-d', strtotime('+1 weekday'))),
                'bb_username' => giacom_suggest_username($account), 'bb_password' => substr(strtr(base64_encode(random_bytes(9)), '+/', 'Kq'), 0, 12),
                'bb_suffix' => (string)setting('giacom_username_suffix'), 'realm' => (string)setting('giacom_realm'),
                'care_level' => in_array(setting('giacom_care_level'), $product['care_levels'] ?: array_keys(GIACOM_CARE_LEVELS), true) ? setting('giacom_care_level') : ($product['care_default'] ?? 'standard'),
                'site_visit_reason' => $visit, 'access_line_id' => '', 'client_ref' => '',
                'force_new_ont' => giacom_is_fttp($product) ? giacom_default_ont($orderType) : '',
                'appointment' => $appointments ? giacom_appointment_key($appointments[0]) : '',
                'title' => $title, 'forename' => $forename, 'surname' => $surname,
                'telephone' => (string)(($contact['phone'] ?? '') ?: ($contact['mobile'] ?? '') ?: ($site['phone'] ?? '') ?: $account['phone']),
                'email' => (string)(($contact['email'] ?? '') ?: ($account['email'] ?? '') ?: ($siteContact['email'] ?? '')), 'crm_product_id' => '',
                'site_title' => $siteTitle, 'site_forename' => $siteForename, 'site_surname' => $siteSurname,
                'site_telephone' => (string)(($siteContact['phone'] ?? '') ?: ($siteContact['mobile'] ?? '') ?: ($site['phone'] ?? '') ?: $account['phone']),
                'site_email' => (string)(($siteContact['email'] ?? '') ?: ($contact['email'] ?? '') ?: ($account['email'] ?? '')), 'site_passphrase' => '', 'site_notes' => '', 'hazard_notes' => '',
                'send_confirmation' => '1', 'ip_option' => 'dynamic',
            ];
            $errors = [];
            if (is_post()) {
                verify_csrf();
                foreach ($values as $k => $v) {
                    $values[$k] = trim((string)($_POST[$k] ?? ''));
                }
                $values['order_type'] = $values['order_type'] === 'migrate' ? 'migrate' : 'provide';
                $values['site_visit_reason'] = giacom_visit_at_least(giacom_visit_code($values['site_visit_reason']) ?? 'NO_SITE_VISIT', giacom_min_visit($result, $values['order_type']));
                if (!empty($_POST['refresh'])) {
                    // The install type changed, so Giacom was asked again: start from its earliest date for this visit.
                    $values['crd'] = max($lead, date('Y-m-d', strtotime('+1 weekday')));
                    $values['appointment'] = $appointments ? giacom_appointment_key($appointments[0]) : '';
                }
                // The appointment slot follows the required-by date.
                $chosen = null;
                foreach ($appointments as $a) {
                    if (giacom_appointment_key($a) === $values['appointment']) {
                        $chosen = $a;
                    }
                }
                if ($values['appointment'] !== '' && !$chosen && empty($_POST['refresh'])) {
                    $errors['appointment'] = 'That appointment is no longer available. Choose another.';
                }
                // The required-by date leads: a slot only counts if it's on that date (the first slot that day if none was picked).
                if ($chosen && $chosen['date'] !== $values['crd']) {
                    $chosen = null;
                }
                if (!$chosen && !isset($errors['appointment'])) {
                    $chosen = array_values(array_filter($appointments, fn($a) => $a['date'] === $values['crd']))[0] ?? null;
                }
                $values['appointment'] = $chosen ? giacom_appointment_key($chosen) : '';
                // FTTP: a new service gets a new ONT, a take-over keeps the existing one (unless changed on the form).
                $values['force_new_ont'] = !giacom_is_fttp($product) ? ''
                    : (in_array($values['force_new_ont'], ['Y', 'N'], true) ? $values['force_new_ont'] : giacom_default_ont($values['order_type']));
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
                    $errors['crd'] = 'Giacom\'s earliest ' . ($appointments ? 'appointment' : 'date') . ' for this product is ' . fmt_date($lead) . '. Choose that date or later.';
                }
                // The suffix and realm are added on sending, so drop them if typed into the username too.
                if (str_contains($values['bb_username'], '@')) {
                    $values['bb_username'] = substr($values['bb_username'], 0, strpos($values['bb_username'], '@'));
                }
                // The username suffix and realm are set for the whole account under Admin → Giacom, not per order.
                $values['bb_suffix'] = (string)setting('giacom_username_suffix');
                $values['realm'] = (string)setting('giacom_realm');
                $offered = array_map('strtolower', $product['realms'] ?? []);
                if ($values['realm'] === '') {
                    $errors['_'] = 'Giacom needs a realm to set up the broadband login, and none is set. ' . (can('settings.manage') ? 'Add it under Admin → Giacom.' : 'Ask an admin to add it under Admin → Giacom.');
                } elseif ($offered && !in_array(strtolower(giacom_realm_value($values['bb_suffix'], $values['realm'])), $offered, true)) {
                    $errors['_'] = 'Giacom only offers these realms for this product: ' . implode(', ', $product['realms']) . '. The realm under Admin → Giacom ('
                        . giacom_realm_value($values['bb_suffix'], $values['realm']) . ') isn\'t one of them.';
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
                // Giacom sends the customer its updates by email, and it can't be added once the order is placed.
                if ($values['email'] === '') {
                    $errors['email'] = 'Enter the customer\'s email address.';
                } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'That isn\'t a valid email address.';
                }
                // Giacom (and the carrier's engineer) need someone at the address.
                if ($values['site_forename'] === '' || $values['site_surname'] === '') {
                    $errors['site_surname'] = 'Enter the site contact\'s first name and surname.';
                }
                if (!preg_match('/^[\d +]{10,16}$/', $values['site_telephone'])) {
                    $errors['site_telephone'] = 'Enter the site contact\'s phone number.';
                }
                if ($values['site_email'] !== '' && !filter_var($values['site_email'], FILTER_VALIDATE_EMAIL)) {
                    $errors['site_email'] = 'That isn\'t a valid email address.';
                }
                if (!isset(GIACOM_IP_OPTIONS[$values['ip_option']])) {
                    $errors['ip_option'] = 'Choose dynamic or static IP.';
                }
                if ($values['care_level'] !== '' && !isset(GIACOM_CARE_LEVELS[$values['care_level']])) {
                    $errors['care_level'] = 'Choose a care level.';
                }
                if ($values['crm_product_id'] !== '' && !db_value('SELECT id FROM products WHERE id = ?', [(int)$values['crm_product_id']])) {
                    $values['crm_product_id'] = '';
                }
                if (!empty($_POST['refresh'])) {
                    $errors = []; // just updating the appointment list
                } elseif (!$errors) {
                    try {
                        $id = giacom_place_order($check, $product, $values);
                        if ($chosen) {
                            try {
                                giacom_book_appointment(db_one('SELECT * FROM giacom_orders WHERE id = ?', [$id]), $chosen);
                            } catch (GiacomException $e) {
                                db_exec('UPDATE giacom_orders SET last_error = ? WHERE id = ?', ['Order placed, but the appointment couldn\'t be booked: ' . mb_substr($e->getMessage(), 0, 400), $id]);
                                flash('Order placed with Giacom, but the appointment couldn\'t be booked: ' . $e->getMessage() . ' Choose another on the order page,'
                                    . ' then send the customer their confirmation from there.', 'error');
                                redirect(url('giacom', ['action' => 'view', 'id' => $id]));
                            }
                        }
                        $note = '';
                        if ($values['send_confirmation'] === '1') {
                            $note = giacom_try_confirmation($id);
                        }
                        flash('Order placed with Giacom. Its progress will show here and on the customer\'s page.' . $note, str_contains($note, 'couldn\'t be sent') ? 'error' : 'success');
                        redirect(url('giacom', ['action' => 'view', 'id' => $id]));
                    } catch (GiacomException $e) {
                        $errors['_'] = giacom_explain_visit_error($e->getMessage());
                    }
                }
            }
            $crmProducts = ref_options('products', null, "category = 'broadband'");
            page('giacom_order', compact('check', 'result', 'product', 'account', 'site', 'values', 'errors', 'crmProducts', 'appointments', 'appointmentsError', 'lead', 'leadSource'), 'Place broadband order');
            return;

        case 'view':
            $order = db_one('SELECT o.*, a.name AS account_name, s.name AS site_name, u.name AS user_name FROM giacom_orders o
                LEFT JOIN accounts a ON a.id = o.account_id LEFT JOIN sites s ON s.id = o.site_id LEFT JOIN users u ON u.id = o.created_by WHERE o.id = ?',
                [query_int('id') ?? 0]) ?? not_found('Order not found.');
            if (is_post()) {
                verify_csrf();
                try {
                    if (query('do') === 'appointment') {
                        require_permission('orders.place');
                        $check = $order['check_id'] ? db_one('SELECT * FROM giacom_checks WHERE id = ?', [$order['check_id']]) : null;
                        $product = giacom_order_product($order, $check);
                        $key = (string)($_POST['appointment'] ?? '');
                        $visit = giacom_visit_at_least(giacom_visit_code($_POST['visit'] ?? null) ?? giacom_order_visit($order, $check),
                            $check ? giacom_min_visit(giacom_check_result($check), (string)$order['order_type']) : null);
                        $offered = $check ? giacom_appointments($check, $product, $visit, (string)$order['order_type'])['appointments'] : [];
                        $chosen = array_values(array_filter($offered, fn($a) => giacom_appointment_key($a) === $key))[0] ?? null;
                        if (!$chosen) {
                            throw new GiacomException('That appointment is no longer available. Show the dates again and choose another.');
                        }
                        giacom_book_appointment($order, $chosen);
                        flash('Appointment booked for ' . fmt_date($chosen['date']) . ($chosen['slot'] ? ' ' . $chosen['slot'] : '') . '.');
                    } elseif (query('do') === 'confirmation') {
                        require_permission('orders.place');
                        $to = trim((string)($_POST['email'] ?? ''));
                        try {
                            flash('Order confirmation emailed to ' . giacom_email_confirmation($order, $to !== '' ? $to : null) . '.');
                        } catch (IntegrationException $e) {
                            flash('The confirmation couldn\'t be sent: ' . $e->getMessage(), 'error');
                        }
                    } elseif (query('do') === 'ips') {
                        require_permission('orders.place');
                        $d = giacom_request_ip_block($order);
                        flash('Static IP block requested' . (!empty($d['ip_block']) ? ': ' . $d['ip_block'] : '') . '.');
                    } elseif (query('do') === 'abort') {
                        require_permission('orders.place');
                        $reason = trim((string)($_POST['reason'] ?? ''));
                        if ($reason === '') {
                            throw new GiacomException('Please give a reason for cancelling.');
                        }
                        flash(giacom_abort_order($order, mb_substr($reason, 0, 250)));
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
            $slots = null;
            $check = $order['check_id'] ? db_one('SELECT * FROM giacom_checks WHERE id = ?', [$order['check_id']]) : null;
            $minVisit = $check ? giacom_min_visit(giacom_check_result($check), (string)$order['order_type']) : null;
            $visit = giacom_visit_at_least(giacom_visit_code(query('visit')) ?? giacom_order_visit($order, $check), $minVisit);
            if (query('appointments') === '1' && can('orders.place')) {
                $slots = $check ? giacom_appointments($check, giacom_order_product($order, $check), $visit, (string)$order['order_type'])
                    : ['appointments' => [], 'error' => 'The availability check for this order is no longer available.'];
            }
            page('giacom_view', compact('order', 'events', 'slots', 'visit', 'minVisit'), 'Giacom order ' . $order['giacom_order_id']);
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
