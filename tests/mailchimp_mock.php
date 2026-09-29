<?php
declare(strict_types=1);

/*
 * Stand-in for the Mailchimp Marketing API v3 and Mailchimp Transactional
 * (Mandrill) used by the tests:
 *   MOCK_STATE=/tmp/m.json php -S 127.0.0.1:8996 tests/mailchimp_mock.php
 * Checks Basic auth (any user, key as password), keeps audience members,
 * segments and campaigns in the state file.
 */

const API_KEY = 'mc-test-key-us21';
const MANDRILL_KEY = 'md-test-key';

$stateFile = getenv('MOCK_STATE') ?: sys_get_temp_dir() . '/mailchimp_mock.json';
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
$state += ['members' => [], 'merge_fields' => ['FNAME', 'LNAME', 'ADDRESS', 'PHONE'], 'segments' => [], 'campaigns' => [], 'calls' => [], 'mandrill' => []];
$save = function () use (&$state, $stateFile) {
    file_put_contents($stateFile, json_encode($state));
};
$out = function (int $status, mixed $body = null) {
    http_response_code($status);
    header('Content-Type: application/json');
    if ($body !== null) {
        echo json_encode($body);
    }
    exit;
};

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input') ?: 'null', true);
$state['calls'][] = "$method $path";
$save();

if ($path === '/api/1.0/messages/send.json') {
    if (($body['key'] ?? '') !== MANDRILL_KEY) {
        $out(500, ['status' => 'error', 'code' => -1, 'name' => 'Invalid_Key', 'message' => 'Invalid API key']);
    }
    $to = $body['message']['to'][0]['email'];
    $state['mandrill'][] = $body['message'];
    $save();
    $out(200, [['email' => $to, 'status' => str_ends_with($to, '@rejected.example') ? 'rejected' : 'sent', 'reject_reason' => str_ends_with($to, '@rejected.example') ? 'hard-bounce' : null, '_id' => 'abc']]);
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!str_starts_with($auth, 'Basic ') || explode(':', base64_decode(substr($auth, 6)), 2)[1] !== API_KEY) {
    $out(401, ['title' => 'API Key Invalid', 'status' => 401, 'detail' => 'Your API key may be invalid, or you\'ve attempted to access the wrong datacenter.']);
}
$path = preg_replace('#^/3\.0#', '', $path);

if ($method === 'GET' && $path === '/lists') {
    $out(200, ['lists' => [['id' => 'list123', 'name' => 'Customers', 'stats' => ['member_count' => count($state['members'])]]]]);
}
if (!preg_match('#^/lists/list123(/.*)?$#', $path, $m) && !str_starts_with($path, '/campaigns')) {
    $out(404, ['title' => 'Resource Not Found', 'status' => 404, 'detail' => $path]);
}
$sub = $m[1] ?? '';

if (str_starts_with($path, '/lists/list123')) {
    if ($method === 'GET' && $sub === '/merge-fields') {
        $out(200, ['merge_fields' => array_map(fn($t) => ['tag' => $t], $state['merge_fields'])]);
    }
    if ($method === 'POST' && $sub === '/merge-fields') {
        $state['merge_fields'][] = $body['tag'];
        $save();
        $out(200, ['tag' => $body['tag']]);
    }
    if ($method === 'PUT' && preg_match('#^/members/([0-9a-f]{32})$#', $sub, $mm)) {
        if (md5(strtolower($body['email_address'])) !== $mm[1]) {
            $out(400, ['title' => 'Invalid Resource', 'status' => 400, 'detail' => 'hash mismatch']);
        }
        $existing = $state['members'][$mm[1]] ?? null;
        $status = $existing['status'] ?? ($body['status_if_new'] ?? 'pending');
        $state['members'][$mm[1]] = ['email_address' => strtolower($body['email_address']), 'status' => $status, 'merge_fields' => $body['merge_fields'] ?? []];
        $save();
        $out(200, $state['members'][$mm[1]]);
    }
    if ($method === 'GET' && $sub === '/members') {
        $list = array_values(array_filter($state['members'], fn($x) => $x['status'] === ($_GET['status'] ?? $x['status'])));
        $out(200, ['members' => array_slice($list, (int)($_GET['offset'] ?? 0), (int)($_GET['count'] ?? 10)), 'total_items' => count($list)]);
    }
    if ($method === 'POST' && $sub === '/segments') {
        $id = count($state['segments']) + 101;
        $state['segments'][$id] = ['name' => $body['name'], 'emails' => $body['static_segment'] ?? []];
        $save();
        $out(200, ['id' => $id, 'name' => $body['name']]);
    }
    if ($method === 'POST' && preg_match('#^/segments/(\d+)$#', $sub, $mm)) {
        foreach ($body['members_to_add'] as $email) {
            if (!isset($state['members'][md5(strtolower($email))])) {
                $out(400, ['title' => 'Invalid Resource', 'status' => 400, 'detail' => "$email is not a member"]);
            }
            $state['segments'][$mm[1]]['emails'][] = strtolower($email);
        }
        $save();
        $out(200, ['total_added' => count($body['members_to_add'])]);
    }
}

if ($method === 'POST' && $path === '/campaigns') {
    $id = 'cmp' . (count($state['campaigns']) + 1);
    if (empty($body['settings']['reply_to']) || empty($body['settings']['subject_line'])) {
        $out(400, ['title' => 'Invalid Resource', 'status' => 400, 'detail' => 'missing settings']);
    }
    $state['campaigns'][$id] = $body + ['status' => 'save'];
    $save();
    $out(200, ['id' => $id]);
}
if ($method === 'PUT' && preg_match('#^/campaigns/(\w+)/content$#', $path, $mm)) {
    $state['campaigns'][$mm[1]]['html'] = $body['html'];
    $save();
    $out(200, ['html' => $body['html']]);
}
if ($method === 'POST' && preg_match('#^/campaigns/(\w+)/actions/send$#', $path, $mm)) {
    if (!str_contains($state['campaigns'][$mm[1]]['html'] ?? '', '*|UNSUB|*')) {
        $out(400, ['title' => 'Bad Request', 'status' => 400, 'detail' => 'Your Campaign is not ready to send. (missing unsubscribe link)']);
    }
    $state['campaigns'][$mm[1]]['status'] = 'sent';
    $save();
    $out(204);
}
$out(404, ['title' => 'Resource Not Found', 'status' => 404, 'detail' => "$method $path"]);
