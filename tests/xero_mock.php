<?php
declare(strict_types=1);

/*
 * A small stand-in for Xero's identity + accounting APIs, used by the tests:
 *   MOCK_STATE=/tmp/state.json php -S 127.0.0.1:8999 tests/xero_mock.php
 *
 * Behaviour mirrors the real API closely enough to exercise the client:
 * OAuth code exchange and rotating refresh tokens, Bearer + xero-tenant-id
 * checks, 100-per-page paging, a one-off 429 rate limit, and /Date()/ dates.
 */

const CLIENT_ID = 'MOCKCLIENTID0000000000000000000A';
const CLIENT_SECRET = 'mock-secret';
const TENANT = '11111111-2222-3333-4444-555555555555';

$stateFile = getenv('MOCK_STATE') ?: sys_get_temp_dir() . '/xero_mock_state.json';
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
$state += ['tokens' => [], 'refresh' => [], 'calls' => [], 'rate_limited' => false, 'expire_next' => false];

function save(array $state): void
{
    global $stateFile;
    file_put_contents($stateFile, json_encode($state));
}

function json_out(int $status, mixed $body, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $h) {
        header($h);
    }
    echo json_encode($body);
    exit;
}

function issue_tokens(array &$state): array
{
    $access = 'access-' . bin2hex(random_bytes(6));
    $refresh = 'refresh-' . bin2hex(random_bytes(6));
    $state['tokens'][$access] = true;
    $state['refresh'][$refresh] = true;
    return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => 1800, 'token_type' => 'Bearer', 'scope' => 'offline_access accounting.contacts.read accounting.invoices.read'];
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state['calls'][] = $_SERVER['REQUEST_METHOD'] . ' ' . $path . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
save($state);

// ---- Identity ------------------------------------------------------------
if ($path === '/identity/connect/authorize') {
    if (($_GET['client_id'] ?? '') !== CLIENT_ID || !str_contains($_GET['scope'] ?? '', 'offline_access')) {
        http_response_code(400);
        exit('invalid_request');
    }
    $sep = str_contains($_GET['redirect_uri'], '?') ? '&' : '?';
    header('Location: ' . $_GET['redirect_uri'] . $sep . http_build_query(['code' => 'mock-code', 'state' => $_GET['state'], 'scope' => $_GET['scope']]));
    exit;
}

if ($path === '/connect/token') {
    $auth = base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'] ?? '', 6));
    if ($auth !== CLIENT_ID . ':' . CLIENT_SECRET) {
        json_out(400, ['error' => 'invalid_client']);
    }
    if (($_POST['grant_type'] ?? '') === 'authorization_code') {
        if (($_POST['code'] ?? '') !== 'mock-code' || empty($_POST['redirect_uri'])) {
            json_out(400, ['error' => 'invalid_grant']);
        }
    } elseif (($_POST['grant_type'] ?? '') === 'refresh_token') {
        $rt = $_POST['refresh_token'] ?? '';
        if (empty($state['refresh'][$rt])) {
            json_out(400, ['error' => 'invalid_grant']);
        }
        unset($state['refresh'][$rt]); // refresh tokens rotate
    } else {
        json_out(400, ['error' => 'unsupported_grant_type']);
    }
    $tokens = issue_tokens($state);
    save($state);
    json_out(200, $tokens);
}

if ($path === '/connect/revocation') {
    unset($state['refresh'][$_POST['token'] ?? '']);
    save($state);
    json_out(200, []);
}

// ---- API (Bearer required) ------------------------------------------------
$bearer = substr($_SERVER['HTTP_AUTHORIZATION'] ?? '', 7);
if (empty($state['tokens'][$bearer])) {
    json_out(401, ['Title' => 'Unauthorized', 'Detail' => 'TokenExpired']);
}
if ($state['expire_next']) {
    // Simulate an access token that Xero has expired early.
    $state['expire_next'] = false;
    unset($state['tokens'][$bearer]);
    save($state);
    json_out(401, ['Title' => 'Unauthorized', 'Detail' => 'TokenExpired']);
}

if ($path === '/connections') {
    json_out(200, [['id' => 'conn-1', 'tenantId' => TENANT, 'tenantType' => 'ORGANISATION', 'tenantName' => 'Mock Telecom Ltd']]);
}

if (($_SERVER['HTTP_XERO_TENANT_ID'] ?? '') !== TENANT) {
    json_out(403, ['Title' => 'Forbidden', 'Detail' => 'AuthenticationUnsuccessful']);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$ms = fn(string $date) => '/Date(' . (strtotime($date . ' 00:00:00 UTC') * 1000) . '+0000)/';
$past = date('Y-m-d', strtotime('-20 days'));
$older = date('Y-m-d', strtotime('-45 days'));
$future = date('Y-m-d', strtotime('+10 days'));

if (preg_match('#^/api.xro/2.0/Contacts/([0-9a-f-]{36})$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $state['pushed'][] = $body['Contacts'][0] ?? null;
    save($state);
    json_out(200, ['Contacts' => [$body['Contacts'][0] + ['ContactID' => $m[1]]]]);
}

if ($path === '/api.xro/2.0/Accounts') {
    json_out(200, ['Accounts' => [
        ['Code' => '200', 'Name' => 'Sales', 'Class' => 'REVENUE', 'Status' => 'ACTIVE'],
        ['Code' => '205', 'Name' => 'Airtime sales', 'Class' => 'REVENUE', 'Status' => 'ACTIVE'],
        ['Code' => '310', 'Name' => 'Cost of Goods Sold', 'Class' => 'EXPENSE', 'Status' => 'ACTIVE'],
        ['Name' => 'Bank', 'Class' => 'ASSET', 'Status' => 'ACTIVE'],
    ]]);
}

if ($path === '/api.xro/2.0/Items' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $out = [];
    foreach ($body['Items'] as $item) {
        $errors = [];
        if (mb_strlen($item['Code'] ?? '') > 30) $errors[] = ['Message' => 'Code must be 30 characters or less'];
        if (mb_strlen($item['Name'] ?? '') > 50) $errors[] = ['Message' => 'Name must be 50 characters or less'];
        if (($item['SalesDetails']['AccountCode'] ?? '200') === '999') $errors[] = ['Message' => 'Account code \'999\' is not a valid code for this document.'];
        $existing = $state['items'][$item['Code']] ?? null;
        $item['ItemID'] = $item['ItemID'] ?? $existing['ItemID'] ?? sprintf('i0000000-0000-0000-0000-%012d', count($state['items'] ?? []) + 1);
        if (!$errors) $state['items'][$item['Code']] = $item;
        $out[] = $item + ($errors ? ['ValidationErrors' => $errors, 'StatusAttributeString' => 'ERROR'] : ['StatusAttributeString' => 'OK']);
    }
    $state['item_posts'][] = $_SERVER['QUERY_STRING'] ?? '';
    save($state);
    json_out(200, ['Items' => $out]);
}

if ($path === '/api.xro/2.0/Contacts') {
    $contacts = [
        ['ContactID' => 'c1000000-0000-0000-0000-000000000001', 'Name' => 'Harbour View Dental Ltd', 'EmailAddress' => 'accounts@harbour.example.co.uk', 'ContactStatus' => 'ACTIVE'],
        ['ContactID' => 'c1000000-0000-0000-0000-000000000002', 'Name' => 'Kestrel Logistics (Midlands) Limited', 'AccountNumber' => 'ACC-KESTREL', 'ContactStatus' => 'ACTIVE'],
        ['ContactID' => 'c1000000-0000-0000-0000-000000000003', 'Name' => 'Copper Kettle Cafe', 'ContactStatus' => 'ACTIVE'],
        ['ContactID' => 'c1000000-0000-0000-0000-000000000004', 'Name' => 'Northgate Motors', 'ContactStatus' => 'ACTIVE'],
        ['ContactID' => 'c1000000-0000-0000-0000-000000000005', 'Name' => 'Northgate Motors', 'ContactStatus' => 'ACTIVE'], // duplicate name: must not auto-link
    ];
    for ($i = 1; $i <= 145; $i++) {
        $contacts[] = ['ContactID' => sprintf('d0000000-0000-0000-0000-%012d', $i), 'Name' => "Supplier $i", 'ContactStatus' => 'ACTIVE'];
    }
    // Suppliers (Xero sets IsSupplier once a bill has been entered).
    $contacts[5] += ['IsSupplier' => true, 'EmailAddress' => 'Accounts@Supplier1.example', 'FirstName' => 'Sam', 'LastName' => 'Vendor', 'Website' => 'www.supplier1.example',
        'Phones' => [['PhoneType' => 'DEFAULT', 'PhoneAreaCode' => '0161', 'PhoneNumber' => '496 0000'], ['PhoneType' => 'MOBILE', 'PhoneNumber' => '07700 900000']],
        'Addresses' => [['AddressType' => 'POBOX', 'AddressLine1' => 'PO Box 1', 'City' => 'Leeds', 'PostalCode' => 'ls1 1aa'],
                        ['AddressType' => 'STREET', 'AddressLine1' => '1 Mill Lane', 'AddressLine2' => 'Unit 4', 'City' => 'Bolton', 'Region' => 'Lancs', 'PostalCode' => 'bl1 1aa']],
        'PaymentTerms' => ['Bills' => ['Day' => 30, 'Type' => 'DAYSAFTERBILLDATE']]];
    $contacts[6] = ['ContactID' => $contacts[6]['ContactID'], 'Name' => 'Giacom Limited', 'ContactStatus' => 'ACTIVE', 'IsSupplier' => true, 'EmailAddress' => 'billing@giacom.example'];
    $contacts[7] = ['IsSupplier' => true, 'ContactStatus' => 'ARCHIVED'] + $contacts[7];
    json_out(200, ['Contacts' => array_slice($contacts, ($page - 1) * 100, 100)]);
}

if ($path === '/api.xro/2.0/Invoices') {
    if (!$state['rate_limited']) {
        $state['rate_limited'] = true;
        save($state);
        json_out(429, ['Title' => 'Too Many Requests'], ['Retry-After: 1']);
    }
    $invoices = [
        // Harbour: 120 overdue + 80 not yet due
        ['Type' => 'ACCREC', 'Status' => 'AUTHORISED', 'Contact' => ['ContactID' => 'c1000000-0000-0000-0000-000000000001', 'Name' => 'Harbour View Dental Ltd'],
         'AmountDue' => 120.00, 'DueDate' => $ms($older), 'DueDateString' => $older . 'T00:00:00', 'CurrencyCode' => 'GBP', 'CurrencyRate' => 1.0],
        ['Type' => 'ACCREC', 'Status' => 'AUTHORISED', 'Contact' => ['ContactID' => 'c1000000-0000-0000-0000-000000000001', 'Name' => 'Harbour View Dental Ltd'],
         'AmountDue' => 80.00, 'DueDate' => $ms($future), 'CurrencyCode' => 'GBP', 'CurrencyRate' => 1.0],
        // Kestrel: USD 100 at 1.25 USD per GBP = £80, overdue, only /Date()/ format
        ['Type' => 'ACCREC', 'Status' => 'AUTHORISED', 'Contact' => ['ContactID' => 'c1000000-0000-0000-0000-000000000002', 'Name' => 'Kestrel'],
         'AmountDue' => 100.00, 'DueDate' => $ms($past), 'CurrencyCode' => 'USD', 'CurrencyRate' => 1.25],
        // Should be ignored: paid, a supplier bill, a draft
        ['Type' => 'ACCREC', 'Status' => 'PAID', 'Contact' => ['ContactID' => 'c1000000-0000-0000-0000-000000000002'], 'AmountDue' => 0, 'DueDate' => $ms($older)],
        ['Type' => 'ACCPAY', 'Status' => 'AUTHORISED', 'Contact' => ['ContactID' => 'd0000000-0000-0000-0000-000000000001'], 'AmountDue' => 999, 'DueDate' => $ms($older)],
        ['Type' => 'ACCREC', 'Status' => 'DRAFT', 'Contact' => ['ContactID' => 'c1000000-0000-0000-0000-000000000003'], 'AmountDue' => 55, 'DueDate' => $ms($older)],
        // Contact not in the Contacts list (e.g. archived)
        ['Type' => 'ACCREC', 'Status' => 'AUTHORISED', 'Contact' => ['ContactID' => 'e0000000-0000-0000-0000-000000000009', 'Name' => 'Greenfield Farm Supplies'],
         'AmountDue' => 50.00, 'DueDate' => $ms($past), 'CurrencyRate' => 1.0],
    ];
    json_out(200, ['Invoices' => $page === 1 ? $invoices : []]);
}

if ($path === '/api.xro/2.0/CreditNotes') {
    $notes = [
        ['Type' => 'ACCRECCREDIT', 'Status' => 'AUTHORISED', 'Contact' => ['ContactID' => 'c1000000-0000-0000-0000-000000000001'], 'RemainingCredit' => 20.00, 'CurrencyRate' => 1.0],
        ['Type' => 'ACCRECCREDIT', 'Status' => 'PAID', 'Contact' => ['ContactID' => 'c1000000-0000-0000-0000-000000000001'], 'RemainingCredit' => 0],
    ];
    json_out(200, ['CreditNotes' => $page === 1 ? $notes : []]);
}

json_out(404, ['Title' => 'Not found', 'Detail' => $path]);
