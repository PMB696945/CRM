<?php
declare(strict_types=1);

/*
 * A small stand-in for the GoCardless API, used by the tests:
 *   MOCK_STATE=/tmp/gc.json php -S 127.0.0.1:8998 tests/gocardless_mock.php
 *
 * Checks the Bearer token, GoCardless-Version and Idempotency-Key headers,
 * uses cursor pagination (limit/after), returns a one-off 429, and supports
 * billing requests + flows. POST /__complete/{BRQ} simulates the customer
 * finishing the hosted setup page.
 */

const RW_TOKEN = 'sandbox_mock_readwrite';
const RO_TOKEN = 'sandbox_mock_readonly';

$stateFile = getenv('MOCK_STATE') ?: sys_get_temp_dir() . '/gc_mock_state.json';
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
$state += ['billing_requests' => [], 'flows' => [], 'extra_customers' => [], 'extra_mandates' => [], 'rate_limited' => false, 'last_bodies' => [], 'calls' => []];

function save(): void
{
    global $stateFile, $state;
    file_put_contents($stateFile, json_encode($state));
}

function out(int $status, array $body, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $h) {
        header($h);
    }
    echo json_encode($body);
    exit;
}

function fail(int $status, string $type, string $message, array $errors = []): never
{
    out($status, ['error' => ['type' => $type, 'code' => $status, 'message' => $message, 'errors' => $errors]]);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state['calls'][] = "$method $path" . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
save();

// Test hook: the customer completes the hosted page.
if ($method === 'POST' && preg_match('#^/__complete/(BRQ\w+)$#', $path, $m)) {
    $br = &$state['billing_requests'][$m[1]];
    $customerId = $br['links']['customer'] ?? 'CU_NEW_' . substr($m[1], 4);
    if (!isset($br['links']['customer'])) {
        $prefill = $state['flows_by_br'][$m[1]]['prefilled_customer'] ?? [];
        $state['extra_customers'][$customerId] = ['id' => $customerId, 'company_name' => $prefill['company_name'] ?? 'New Customer', 'email' => $prefill['email'] ?? null, 'metadata' => []];
    }
    $mandateId = 'MD_NEW_' . substr($m[1], 4);
    $state['extra_mandates'][$mandateId] = ['id' => $mandateId, 'status' => 'pending_submission', 'scheme' => 'bacs', 'reference' => 'CRM-' . substr($m[1], 4), 'created_at' => gmdate('c'), 'next_possible_charge_date' => date('Y-m-d', strtotime('+5 days')), 'links' => ['customer' => $customerId]];
    $br['status'] = 'fulfilled';
    $br['links']['customer'] = $customerId;
    $br['links']['mandate_request_mandate'] = $mandateId;
    save();
    out(200, ['ok' => true]);
}

// ---- Authentication & headers ---------------------------------------------
$token = substr($_SERVER['HTTP_AUTHORIZATION'] ?? '', 7);
if (!in_array($token, [RW_TOKEN, RO_TOKEN], true)) {
    fail(401, 'invalid_api_usage', 'Access token not found');
}
if (($_SERVER['HTTP_GOCARDLESS_VERSION'] ?? '') !== '2015-07-06') {
    fail(400, 'invalid_api_usage', 'GoCardless-Version header missing or invalid');
}
if ($method === 'POST') {
    if ($token === RO_TOKEN) {
        fail(403, 'invalid_api_usage', 'Access token does not have permission to perform this action');
    }
    if (empty($_SERVER['HTTP_IDEMPOTENCY_KEY'])) {
        fail(400, 'invalid_api_usage', 'Idempotency-Key header required by this mock');
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $state['last_bodies'][$path] = $body;
    save();
}

// ---- Data -------------------------------------------------------------------
$customers = [
    ['id' => 'CU0001', 'company_name' => 'Harbour View Dental Ltd', 'email' => 'lucy@harbour.example.co.uk', 'metadata' => []],
    ['id' => 'CU0002', 'given_name' => 'David', 'family_name' => 'Wilson', 'email' => 'davidw@example.com', 'metadata' => []],
    ['id' => 'CU0003', 'company_name' => 'Northgate Motors', 'email' => 'accounts@northgate.example.co.uk', 'metadata' => []],
];
for ($i = 1; $i <= 600; $i++) {
    $customers[] = ['id' => sprintf('CU9%05d', $i), 'given_name' => 'Filler', 'family_name' => (string)$i, 'email' => "filler$i@example.com", 'metadata' => []];
}
$customers = array_merge($customers, array_values($state['extra_customers']));

$mandates = [
    ['id' => 'MD0001', 'status' => 'cancelled', 'scheme' => 'bacs', 'reference' => 'OLD-1', 'created_at' => '2023-01-01T10:00:00Z', 'links' => ['customer' => 'CU0001']],
    ['id' => 'MD0002', 'status' => 'active', 'scheme' => 'bacs', 'reference' => 'HVD-0002', 'created_at' => '2025-03-01T10:00:00Z', 'next_possible_charge_date' => '2026-10-02', 'links' => ['customer' => 'CU0001']],
    ['id' => 'MD0003', 'status' => 'pending_customer_approval', 'scheme' => 'bacs', 'reference' => 'DW-0003', 'created_at' => '2026-09-20T10:00:00Z', 'links' => ['customer' => 'CU0002']],
    ['id' => 'MD0004', 'status' => 'failed', 'scheme' => 'bacs', 'reference' => 'NGM-0004', 'created_at' => '2026-06-01T10:00:00Z', 'links' => ['customer' => 'CU0003']],
];
$mandates = array_merge($mandates, array_values($state['extra_mandates']));

/** Cursor pagination over a list, like the real API. */
function page_of(array $items, string $key): never
{
    $limit = min(500, max(1, (int)($_GET['limit'] ?? 50)));
    $start = 0;
    if (!empty($_GET['after'])) {
        foreach ($items as $i => $item) {
            if ($item['id'] === $_GET['after']) {
                $start = $i + 1;
            }
        }
    }
    $slice = array_slice($items, $start, $limit);
    $more = $start + $limit < count($items);
    out(200, [$key => $slice, 'meta' => ['cursors' => ['before' => null, 'after' => $more ? end($slice)['id'] : null], 'limit' => $limit]]);
}

// ---- Routes -------------------------------------------------------------------
if ($method === 'GET' && $path === '/creditors') {
    out(200, ['creditors' => [['id' => 'CR0001', 'name' => 'Mock Telecom Ltd']], 'meta' => ['cursors' => ['after' => null], 'limit' => 1]]);
}
if ($method === 'GET' && $path === '/customers') {
    page_of($customers, 'customers');
}
if ($method === 'GET' && preg_match('#^/customers/(\w+)$#', $path, $m)) {
    foreach ($customers as $c) {
        if ($c['id'] === $m[1]) {
            out(200, ['customers' => $c]);
        }
    }
    fail(404, 'invalid_api_usage', 'Resource not found');
}
if ($method === 'GET' && $path === '/mandates') {
    if (!$state['rate_limited']) {
        $state['rate_limited'] = true;
        save();
        fail(429, 'invalid_api_usage', 'Rate limit exceeded', []);
    }
    if (!empty($_GET['customer'])) {
        $mandates = array_values(array_filter($mandates, fn($m) => $m['links']['customer'] === $_GET['customer']));
    }
    page_of($mandates, 'mandates');
}
if ($method === 'POST' && $path === '/billing_requests') {
    $req = $body['billing_requests'] ?? null;
    if (empty($req['mandate_request']['scheme'])) {
        fail(422, 'validation_failed', 'Validation failed', [['field' => 'mandate_request', 'message' => 'must be present']]);
    }
    $id = 'BRQ' . str_pad((string)(count($state['billing_requests']) + 1), 6, '0', STR_PAD_LEFT);
    $state['billing_requests'][$id] = ['id' => $id, 'status' => 'pending', 'mandate_request' => $req['mandate_request'], 'metadata' => $req['metadata'] ?? [], 'links' => array_filter(['customer' => $req['links']['customer'] ?? null])];
    save();
    out(201, ['billing_requests' => $state['billing_requests'][$id]]);
}
if ($method === 'GET' && preg_match('#^/billing_requests/(BRQ\w+)$#', $path, $m)) {
    $br = $state['billing_requests'][$m[1]] ?? fail(404, 'invalid_api_usage', 'Resource not found');
    out(200, ['billing_requests' => $br]);
}
if ($method === 'POST' && $path === '/billing_request_flows') {
    $flow = $body['billing_request_flows'] ?? [];
    $br = $flow['links']['billing_request'] ?? '';
    if (!isset($state['billing_requests'][$br])) {
        fail(422, 'validation_failed', 'Validation failed', [['field' => 'links.billing_request', 'message' => 'not found']]);
    }
    $id = 'BRF' . str_pad((string)(count($state['flows']) + 1), 6, '0', STR_PAD_LEFT);
    $state['flows'][$id] = $flow;
    $state['flows_by_br'][$br] = $flow;
    save();
    out(201, ['billing_request_flows' => [
        'id' => $id,
        'authorisation_url' => 'https://pay-sandbox.gocardless.com/billing/static/flow?id=' . $id,
        'expires_at' => gmdate('Y-m-d\TH:i:s.000\Z', time() + 7 * 86400),
        'links' => ['billing_request' => $br],
    ]]);
}

fail(404, 'invalid_api_usage', "No route for $method $path");
