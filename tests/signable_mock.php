<?php
declare(strict_types=1);

/*
 * Stand-in for the Signable v1 API used by the tests:
 *   MOCK_STATE=/tmp/s.json php -S 127.0.0.1:8997 tests/signable_mock.php
 * Checks HTTP Basic auth, form-encoded fields with JSON documents/parties,
 * and that documents are real .docx files. POST /__sign/{fp} simulates signing.
 */

const API_KEY = 'signable-test-key';

$stateFile = getenv('MOCK_STATE') ?: sys_get_temp_dir() . '/signable_mock.json';
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
$state += ['envelopes' => [], 'webhooks' => [], 'calls' => []];
$save = function () use (&$state, $stateFile) {
    file_put_contents($stateFile, json_encode($state));
};
$out = function (int $status, array $body) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
};

$method = $_SERVER['REQUEST_METHOD'];
$path = preg_replace('#^/v1#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$state['calls'][] = "$method $path";
$save();

if ($method === 'GET' && preg_match('#^/files/(\w+)\.pdf$#', $path, $m)) {
    header('Content-Type: application/pdf');
    exit("%PDF-1.4\n% signed copy of envelope {$m[1]}\n%%EOF");
}
if ($method === 'POST' && preg_match('#^/__sign/(\w+)$#', $path, $m)) {
    $state['envelopes'][$m[1]]['envelope_status'] = 'signed';
    $state['envelopes'][$m[1]]['envelope_signed_pdf'] = 'http://' . $_SERVER['HTTP_HOST'] . '/files/' . $m[1] . '.pdf';
    $save();
    $out(200, ['ok' => true]);
}

$auth = base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'] ?? '', 6));
if (!str_starts_with($auth, API_KEY . ':')) {
    $out(401, ['http' => 401, 'message' => 'Invalid API key']);
}

if ($method === 'POST' && $path === '/envelopes') {
    $docs = json_decode($_POST['envelope_documents'] ?? '', true);
    $parties = json_decode($_POST['envelope_parties'] ?? '', true);
    if (!$docs || !$parties || empty($_POST['envelope_title'])) {
        $out(400, ['http' => 400, 'message' => 'Missing envelope fields']);
    }
    foreach ($docs as $d) {
        $bin = base64_decode($d['document_file_content'] ?? '', true);
        if ($bin === false || !str_starts_with($bin, "PK") || !str_ends_with($d['document_file_name'] ?? '', '.docx')) {
            $out(400, ['http' => 400, 'message' => 'Invalid document']);
        }
    }
    if (($parties[0]['party_role'] ?? '') !== 'signer1' || !filter_var($parties[0]['party_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        $out(400, ['http' => 400, 'message' => 'Invalid party']);
    }
    $fp = bin2hex(random_bytes(16));
    $state['envelopes'][$fp] = ['envelope_fingerprint' => $fp, 'envelope_title' => $_POST['envelope_title'], 'envelope_status' => 'sent',
        'documents' => $docs, 'parties' => $parties, 'meta' => $_POST['envelope_meta'] ?? null];
    $save();
    $out(202, ['http' => 202, 'message' => 'Envelope has been queued for sending', 'envelope_fingerprint' => $fp]);
}
if ($method === 'GET' && preg_match('#^/envelopes/(\w+)$#', $path, $m)) {
    $env = $state['envelopes'][$m[1]] ?? $out(404, ['http' => 404, 'message' => 'Envelope not found']);
    unset($env['documents']);
    $out(200, $env);
}
if ($method === 'GET' && $path === '/envelopes') {
    $out(200, ['http' => 200, 'offset' => 0, 'limit' => 1, 'total_envelopes' => count($state['envelopes']), 'envelopes' => []]);
}
if ($method === 'PUT' && preg_match('#^/envelopes/(\w+)/(cancel|remind)$#', $path, $m)) {
    if ($m[2] === 'cancel') {
        $state['envelopes'][$m[1]]['envelope_status'] = 'cancelled';
        $save();
    }
    $out(200, ['http' => 200, 'message' => 'ok']);
}
if ($method === 'POST' && $path === '/webhooks') {
    $state['webhooks'][] = ['type' => $_POST['webhook_type'] ?? '', 'url' => $_POST['webhook_url'] ?? ''];
    $save();
    $out(201, ['http' => 201, 'webhook_id' => count($state['webhooks'])]);
}
$out(404, ['http' => 404, 'message' => "No route for $method $path"]);
