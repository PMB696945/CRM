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
                'fttp' => ['leadtimes' => [['product-id' => '34360', 'first-date-int' => (string)strtotime('+30 days 00:00 UTC'), 'leadtime' => '20']]],
            ],
            'leadtimes' => [['product-id' => '34350', 'leadtime' => '10', 'first-date-text' => date('Y-m-d', strtotime('+14 days'))]],
            'products' => [
                ['product-id' => '34350', 'product-name' => 'Business FTTC 80/20', 'technology-type' => 'fttc', 'supplier-product-reference' => 'BT_21CN_FTTC', 'supplier-product-subtype' => 'plus',
                    'service-speed' => '80000000', 'care-level' => 'standard', 'care-level-options' => 'standard,enhanced'],
                ['product-id' => '34360', 'product-name' => 'Business FTTP 900', 'technology-type' => 'fttp', 'supplier-product-reference' => 'BT_FTTP', 'service-speed' => '900000000', 'realms' => [['realm' => 'isp.example'], ['realm' => '-public@GreatDSL']]],
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
        $id = (string)$state['next']++;
        $state['orders'][$id] = ['type' => $call, 'status' => 'Awaiting Processing', 'request' => $req];
        $save();
        reply(['order-id' => $id, 'service-id' => '9' . $id]);
    case 'available_appointments':
        reply(['appointments' => [['date' => date('Y-m-d', strtotime('+9 days')), 'timeslot' => 'PM'], ['date' => date('Y-m-d', strtotime('+8 days')), 'timeslot' => 'AM']]]);
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
        $state['orders'][$req['order-id']]['status'] = 'Cancelled';
        $save();
        reply(['cancel-status' => 'Cancelled']);
}
reply([], 99, "Unknown call $call");
