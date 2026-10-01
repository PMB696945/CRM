<?php
// Stand-in for the Anthropic Messages API: checks the request shape, then returns invoice fields as structured JSON.
$state = getenv('MOCK_STATE');
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
$h = fn($k) => $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $k))] ?? '';
$problems = [];
if ($h('x-api-key') !== 'sk-ant-test') $problems[] = 'bad key';
if ($h('anthropic-version') !== '2023-06-01') $problems[] = 'version';
if ($h('anthropic-beta') !== 'server-side-fallback-2026-07-01') $problems[] = 'beta header';
if (($body['fallbacks'] ?? null) !== 'default') $problems[] = 'fallbacks';
if (($body['output_config']['format']['type'] ?? '') !== 'json_schema') $problems[] = 'format';
if (isset($body['thinking']) || isset($body['temperature'])) $problems[] = 'unsupported params';
$file = $body['messages'][0]['content'][0] ?? [];
if (!in_array($file['type'] ?? '', ['document', 'image'], true) || ($file['source']['type'] ?? '') !== 'base64') $problems[] = 'file block';
file_put_contents($state, json_encode(['last' => $body, 'problems' => $problems]));
header('Content-Type: application/json');
if ($problems) {
    http_response_code(400);
    echo json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => implode(', ', $problems)]]);
    exit;
}
$fields = ['supplier_name' => 'Kit Distribution Ltd', 'supplier_vat_number' => 'GB123456789', 'supplier_email' => 'accounts@kit.example', 'invoice_number' => 'KD-5001',
    'invoice_date' => '2026-10-03', 'due_date' => '2026-11-02', 'purchase_order_numbers' => [is_file("$state.po") ? trim(file_get_contents("$state.po")) : 'PO-000001'], 'currency' => 'GBP',
    'net_total' => (float)(getenv('MOCK_NET') ?: 36.5), 'vat_total' => 7.3, 'gross_total' => 43.8,
    'lines' => [['description' => 'Switch', 'quantity' => 2, 'unit_price' => 18.25, 'net_amount' => 36.5]]];
echo json_encode(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'], 'stop_reason' => 'end_turn',
    'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'x'], ['type' => 'text', 'text' => json_encode($fields)]],
    'usage' => ['input_tokens' => 1000, 'output_tokens' => 200]]);
