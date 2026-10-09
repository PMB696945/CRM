<?php
declare(strict_types=1);

/*
 * Stand-in for the Giacom comms API (XML over HTTP POST) used by the tests:
 *   MOCK_STATE=/tmp/g.json php -S 127.0.0.1:8995 tests/giacom_mock.php
 * Answers like the real API: <status no="0"/> on success, <status no="10" text="..."/>
 * with HTTP 500 on failure. POST /__status/{order-id}/{status} changes an order.
 */

$stateFile = getenv('MOCK_STATE') ?: sys_get_temp_dir() . '/giacom_mock.json';
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
$state += ['orders' => [], 'calls' => [], 'events' => [], 'next' => 700100];
$save = function () use (&$state, $stateFile) { file_put_contents($stateFile, json_encode($state)); };

function reply(array $data, int $no = 0, string $text = ''): never
{
    http_response_code($no ? 500 : 200);
    header('Content-Type: text/html;charset=UTF-8');
    $doc = new DOMDocument('1.0');
    $r = $doc->createElement('Response');
    $r->setAttribute('id', 'mock');
    $doc->appendChild($r);
    $status = $doc->createElement('status');
    $status->setAttribute('no', (string)$no);
    if ($text !== '') {
        $status->setAttribute('text', $text);
    }
    $r->appendChild($status);
    $fill = function (DOMElement $parent, array $d) use (&$fill, $doc) {
        foreach ($d as $k => $v) {
            if (is_array($v)) {
                $b = $doc->createElement('block');
                if (!is_int($k)) {
                    $b->setAttribute('name', $k);
                }
                $fill($b, $v);
                $parent->appendChild($b);
            } else {
                $a = $doc->createElement('a', htmlspecialchars((string)$v));
                $a->setAttribute('name', $k);
                $a->setAttribute('format', 'text');
                $parent->appendChild($a);
            }
        }
    };
    $fill($r, $data);
    echo $doc->saveXML();
    exit;
}
function parse(SimpleXMLElement $el): array
{
    $out = [];
    foreach ($el->children() as $c) {
        $n = isset($c['name']) ? (string)$c['name'] : null;
        if ($c->getName() === 'a') { $out[$n] = (string)$c; }
        elseif ($n === null) { $out[] = parse($c); }
        else { $out[$n] = parse($c); }
    }
    return $out;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/__status/(\d+)/(.+)$#', $path, $m)) {
    $state['orders'][$m[1]]['status'] = urldecode($m[2]);
    $state['events'][] = ['order-id' => $m[1], 'date' => date('Y-m-d H:i:s'), 'name' => 'status', 'value' => urldecode($m[2])];
    $save();
    exit('ok');
}

$xml = simplexml_load_string(file_get_contents('php://input'));
if (!$xml || $xml->getName() !== 'Request') {
    reply([], 1, 'Invalid request XML');
}
$call = (string)$xml['call'];
$req = parse($xml);
$state['calls'][] = $call;
$state['last'][$call] = $req;
$save();
$auth = $req['auth'] ?? [];
if (($auth['username'] ?? '') !== 'crm-api' || ($auth['password'] ?? '') !== 'secret-pw') {
    reply([], 10, 'Un-authorised user: ' . ($auth['username'] ?? ''));
}

switch ($call) {
    case 'check_api_service_status':
        reply(['check' => [['service' => 'Ordering', 'status' => 'OK'], ['service' => 'Availability', 'status' => 'OK']]]);
    case 'address_search':
        // Like the real API when BT's checker doesn't answer: fails the first time (or always for ZZ98).
        if (($req['postcode'] ?? '') === 'ZZ980ZZ' || (($req['postcode'] ?? '') === 'ZZ970ZZ' && empty($state['glitched']))) {
            $state['glitched'] = true;
            $save();
            reply([], 500, 'An upstream processing server returned a blank response');
        }
        if (($req['postcode'] ?? '') === 'ZZ990ZZ') {
            reply(['addresses' => []]);
        }
        reply(['addresses' => [
            ['building' => '12', 'street' => 'Canal Street', 'city' => 'Manchester', 'postcode' => 'M13HE', 'address-reference' => 'A00012345678', 'css-database-code' => 'LC'],
            ['building' => '10', 'sub-premise' => 'Unit 2', 'organisation' => 'Bramble <Dental> & Co', 'street' => 'Canal Street', 'city' => 'Manchester', 'postcode' => 'M13HE', 'address-reference' => 'A00012345679', 'css-database-code' => 'LC'],
        ]]);
    case 'availability':
        if (empty($req['address-reference']) && empty($req['cli'])) {
            reply([], 2, 'An address or CLI is required');
        }
        reply([
            'availability' => [
                'realistic-speeds' => ['business' => ['products' => [
                    ['supplier-product-reference' => 'BT_21CN_FTTC', 'supplier-product-subtype' => 'plus', 'estimated-download-range' => '68400.00', 'estimated-upload-range' => '17100.00'],
                ]]],
                'exchange' => ['code' => 'LVMAN', 'name' => 'MANCHESTER CENTRAL', 'state' => 'E'],
                'fttc-qualification' => ['likely-max-speed-down' => '63100000', 'likely-max-speed-up' => '19000000'],
                'quick-result' => empty($req['cli']) ? '5' : '4',
                'minimum-svr-new-line' => 'Premium', 'minimum-svr-existing-line' => 'Standard', 'site-classification' => 'Property Shell',
                'fttp' => ['leadtimes' => [['product-id' => '34360', 'first-date-int' => (string)strtotime('+30 days 00:00 UTC'), 'leadtime' => '20']]],
            ],
            'ont-details' => ['mdu-build-complete' => 'N', 'ont-type' => 'EXISTING', 'existing-ont' => 'N', 'new-ont' => 'Y',
                'ont-list' => ['ont' => ['reference' => 'ONT0064647241', 'serial_number' => 'ADTN224818B9', 'max_speed' => 'Up to 1000', 'port' => ['type' => 'Data', 'status' => 'Working', 'number' => '1']]]],
            'leadtimes' => [['product-id' => '34350', 'leadtime' => '10', 'first-date-text' => date('Y-m-d', strtotime('+14 days'))]],
            'products' => [
                ['product-id' => '34350', 'product-name' => 'Business FTTC 80/20', 'technology-type' => 'fttc', 'supplier-product-reference' => 'BT_21CN_FTTC', 'supplier-product-subtype' => 'plus',
                    'service-speed' => '80000000', 'care-level' => 'standard', 'care-level-options' => 'standard,enhanced'],
                ['product-id' => '34370', 'product-name' => 'CityFibre FTTP 500', 'technology-type' => 'fttp', 'supplier' => 'CITYFIBRE', 'supplier-product-reference' => 'CF_FTTP', 'service-speed' => '500000000', 'expected-install-type' => 'Standard'],
                ['product-id' => '34380', 'product-name' => 'SOGEA 80/20', 'technology-type' => 'sogea', 'supplier-product-reference' => 'BT_21CN_SOGEA', 'service-speed' => '80000000'],
                ['product-id' => '34360', 'product-name' => 'Business FTTP 900', 'technology-type' => 'fttp', 'supplier-product-reference' => 'BT_FTTP', 'service-speed' => '900000000', 'realms' => [['realm' => 'isp.example'], ['realm' => '-public@GreatDSL']]],
                // Shapes as seen in a real response (GL53 0ED).
                ['product-id' => '59310', 'product-name' => 'BTW FTTP 80/20 ELEVATED', 'likely-max-range' => '72000000', 'likely-min-range' => '40000000', 'service-speed' => '40000.00 - 72000.00',
                    'technology-type' => '', 'supplier-product-reference' => 'BT_21CN_FTTP', 'supplier-product-subtype' => '80/20', 'care-level-options' => 'standard,enhanced,premium'],
                ['product-id' => '53733', 'product-name' => 'VODA FTTP 40/10', 'technology-type' => '', 'supplier-product-reference' => 'VF_FTTP', 'supplier-product-subtype' => '40/10', 'service-speed' => '20000.00 - 36000.00'],
                ['product-id' => '72299', 'product-name' => 'SKY SOGEA 80/20 (36 Months)', 'service-speed' => '', 'technology-type' => 'sogea', 'supplier-product-reference' => 'SKY_SOGEA', 'supplier-product-subtype' => '80/20'],
                ['product-id' => '55453', 'product-name' => 'TTB MPF ADSL2+', 'service-speed' => '9185000', 'technology-type' => 'mpf', 'supplier-product-reference' => 'TTB_MPF', 'supplier-product-subtype' => ''],
            ],
        ]);
    case 'provide':
    case 'migrate':
        $o = $req['order'] ?? [];
        foreach (['prod-id', 'crd', 'username', 'address-reference'] as $f) {
            if (empty($o[$f])) {
                reply([], 20, "Missing field: $f");
            }
        }
        if ($call === 'migrate' && empty($o['cli']) && empty($o['access-line-id'])) {
            reply([], 21, 'A CLI or access line ID is required for a migrate');
        }
        if (!str_contains($o['username'], '@')) {
            reply([], 24, 'Broadband realm for ' . $o['username'] . ' not found');
        }
        if (!preg_match('/^[A-Z0-9]{2,4} [0-9][A-Z]{2}$/', $req['customer']['postcode'] ?? '')) {
            reply([], 23, 'Failed validation: Post code must contain a space.');
        }
        if (($req['customer']['surname'] ?? '') === '') {
            reply([], 22, 'Customer surname is required');
        }
        // As the real API: the site contact is required too.
        $missing = [];
        foreach (['telephone' => 'Telephone', 'forename' => 'Forename', 'surname' => 'Surname'] as $k => $label) {
            if (($req['site-contact'][$k] ?? '') === '') {
                $missing[] = "[Site Contact] $label not present";
            }
        }
        if ($missing) {
            reply([], 30, 'Cannot provision due to validation errors: ' . implode(', ', $missing));
        }
        $id = (string)$state['next']++;
        $state['orders'][$id] = ['type' => $call, 'status' => 'Awaiting Processing', 'request' => $req];
        $save();
        reply(['order-id' => $id, 'service-id' => '9' . $id]);
    case 'available_appointments':
        // As the real API: the service type comes from technology-type, or the order-type for SOGEA.
        $tech = strtoupper((string)($req['technology-type'] ?? ''));
        $serviceType = $tech === 'SOGEA' ? (string)($req['order-type'] ?? '') : $tech;
        $state['appointment_requests'][] = $req;
        $save();
        if (in_array($req['site-visit-reason'] ?? '', ['PREMIUM', 'NO_SITE_VISIT'], true)
            && !in_array($serviceType, ['FTTP', 'FTTC', 'SOGEA_NEW', 'SOGEA_EXISTING', 'SOADSL'], true)) {
            reply([], 50, "Failed to get list of appointments: Appointment Error - if appointmentType is one of 'PREMIUM, NO_SITE_VISIT' then serviceType is one of 'FTTP, FTTC, SOGEA_NEW, SOGEA_EXISTING, SOADSL'.");
        }
        $extra = ($req['site-visit-reason'] ?? '') === 'STANDARD' ? 5 : 0;
        reply(['appointments' => [
            ['date' => date('Y-m-d', strtotime('+' . (9 + $extra) . ' days')), 'timeslot' => 'PM', 'appointment-ref' => 'APT9'],
            ['date' => date('Y-m-d', strtotime('+' . (8 + $extra) . ' days')), 'timeslot' => 'AM', 'appointment-ref' => 'APT8'],
        ]]);
    case 'address_match':
        reply(['addresses' => [['addressRef' => 'A00012345679', 'uprn' => '77001234', 'postCode' => 'M1 3HE']]]);
    case 'service_details':
        reply(['service-details' => ['service-id' => $req['service-id'] ?? '', 'ip-address' => '81.2.69.160', 'password' => $state['live_password'] ?? '', 'live' => 'Y']]);
    case 'change_ips':
        $state['change_ips'][] = $req;
        $save();
        if (($req['allocation-size'] ?? '') === '16') {
            reply([], 1, 'Allocation size not available');
        }
        reply(['ip-address' => '89.145.195.160', 'cidr' => '89.145.253.136/' . (32 - (int)log((int)($req['allocation-size'] ?? 1), 2))]);
    case 'amend_order':
        $state['orders'][$req['order-id']]['appointment'] = $req;
        $save();
        reply([]);
    case 'order_view':
        $o = $state['orders'][$req['order-id'] ?? ''] ?? null;
        if (!$o) {
            reply([], 30, 'Order not found');
        }
        reply(['order-details' => ['order-id' => $req['order-id'], 'order-status' => $o['status'], 'order-crd' => $o['request']['order']['crd'], 'service-id' => '9' . $req['order-id'],
            'order-history' => [['event-date' => '2026-09-30 09:51:33', 'operator' => 'Giacom Provisioning', 'event' => 'provision', 'event-description' => 'Order accepted']]]]);
    case 'order_eventlog_history':
        reply(['eventlog' => array_values(array_map(fn($e) => array_diff_key($e, ['order-id' => 1]), array_filter($state['events'], fn($e) => $e['order-id'] === ($req['order-id'] ?? ''))))]);
    case 'order_eventlog_changes':
        reply(['eventlog' => array_values($state['events'])]);
    case 'order_abort':
        // As the API since Sept 2026: cancel-status success/error, with the reasons in an errors block.
        if (!empty($state['refuse_cancel'])) {
            reply(['cancel-status' => 'error', 'errors' => [['error' => 'Order is too far progressed to cancel']],
                'components' => [['component' => '9175', 'error' => 'Engineer appointment already confirmed', 'info' => '', 'warn' => '']], 'message' => 'Cancellation failed']);
        }
        $state['orders'][$req['order-id']]['status'] = 'Cancelled';
        $save();
        reply(['cancel-status' => 'success', 'message' => 'Order cancelled']);
}
reply([], 99, "Unknown call $call");
