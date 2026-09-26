<?php
declare(strict_types=1);

/*
 * Integration tests. Uses a separate database (default: <db_name>_test) which is
 * wiped and recreated on every run.
 *
 *   php tests/run.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/controllers.php';
require APP_ROOT . '/src/installer.php';

$testDb = getenv('CRM_TEST_DB_NAME') ?: config('db_name') . '_test';
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', config('db_host'), config('db_port')),
    config('db_user'), config('db_pass'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("DROP DATABASE IF EXISTS `$testDb`");
$pdo->exec("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4");
$pdo->exec("USE `$testDb`");
sync_timezone($pdo);
db($pdo);
foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents(APP_ROOT . '/install/schema.sql'))))) as $sql) {
    $pdo->exec($sql);
}

$passed = 0;
$failed = 0;
function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "  ✔ $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✘ $name\n      " . $e->getMessage() . ' (line ' . $e->getLine() . ")\n";
    }
}
function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected != $actual) {
        throw new Exception(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function ok(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new Exception($msg);
    }
}

/** Validate + insert like the controller does. */
function create(string $name, array $input): int
{
    [$data, $errors] = validate(entity($name), $input);
    $errors += validate_scoped_refs(entity($name), $data);
    if ($errors) {
        throw new Exception("validation failed for $name: " . json_encode($errors));
    }
    return insert_row($name, $data);
}

echo "Database upgrades\n";
test('upgrades a version 1 database and is safe to re-run', function () {
    eq(1, schema_version());
    eq(range(2, latest_schema_version()), migrate());
    eq(latest_schema_version(), schema_version());
    ok(column_exists('accounts', 'xero_contact_id'));
    ok(constraint_exists('accounts', 'fk_accounts_xero'));
    eq([], migrate(), 'nothing left to apply');
    set_setting('schema_version', '1');
    eq(range(2, latest_schema_version()), migrate(), 're-running migrations is harmless');
    ok(column_exists('accounts', 'gocardless_customer_id'));
});

db_exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Tester', 'test@example.com', ?, 'admin')", [password_hash('password123', PASSWORD_DEFAULT)]);
$_SESSION['user_id'] = 1;

echo "Helpers\n";
test('contract end date is day before anniversary', function () {
    eq('2027-01-14', contract_end_date('2025-01-15', 24));
    eq('2026-02-27', contract_end_date('2026-01-31', 1), 'clamps short months');
    eq('2025-12-31', contract_end_date('2025-01-01', 12));
});
test('escaping helper', fn() => eq('&lt;script&gt;&quot;x&quot;', h('<script>"x"')));
test('csv formula injection neutralised', function () {
    eq("'=HYPERLINK(1)", csv_safe('=HYPERLINK(1)'));
    eq('-12.50', csv_safe('-12.50'));
    eq('07700 900001', csv_safe('07700 900001'));
});
test('safe_return only allows in-app URLs', function () {
    eq('index.php?page=accounts', safe_return('index.php?page=accounts', 'x'));
    eq('x', safe_return('https://evil.example/', 'x'));
    eq('x', safe_return('//evil.example/index.php?', 'x'));
});

echo "Validation\n";
test('required, email, money and select validation', function () {
    [, $errors] = validate(entity('accounts'), ['name' => '', 'type' => 'business', 'status' => 'bogus', 'email' => 'nope', 'credit_limit' => '-5']);
    ok(isset($errors['name']), 'name required');
    ok(isset($errors['status']), 'bad enum rejected');
    ok(isset($errors['email']), 'bad email rejected');
    ok(isset($errors['credit_limit']), 'negative money rejected');
});
test('ref fields must exist', function () {
    [, $errors] = validate(entity('contacts'), ['account_id' => '9999', 'name' => 'X']);
    ok(isset($errors['account_id']));
});
test('dates are strictly validated', function () {
    [, $errors] = validate(entity('services'), ['start_date' => '2025-02-30']);
    ok(isset($errors['start_date']));
});
test('array input is rejected, not crashed on', function () {
    [, $errors] = validate(entity('accounts'), ['name' => ['a'], 'type' => 'business', 'status' => 'active']);
    ok(isset($errors['name']));
});

echo "Customers\n";
$acc = 0;
test('account number is auto-generated from id', function () use (&$acc) {
    $acc = create('accounts', ['name' => 'Acme Telecom Ltd', 'type' => 'business', 'status' => 'active', 'postcode' => 'ls1 5ab']);
    $row = find('accounts', $acc);
    eq(sprintf('ACC-%05d', 10000 + $acc), $row['account_number']);
    eq('LS1 5AB', $row['postcode'], 'postcode upper-cased');
    eq(1, $row['owner_id'], 'owner defaults to current user');
});
test('account number is kept on update when left blank', function () use (&$acc) {
    $before = find('accounts', $acc)['account_number'];
    [$data] = validate(entity('accounts'), ['name' => 'Acme Telecom Group', 'type' => 'business', 'status' => 'active', 'account_number' => '']);
    update_row('accounts', $acc, $data);
    eq($before, find('accounts', $acc)['account_number']);
    eq('Acme Telecom Group', find('accounts', $acc)['name']);
});
test('duplicate account numbers are rejected by the database', function () use (&$acc) {
    $num = find('accounts', $acc)['account_number'];
    try {
        create('accounts', ['name' => 'Dup', 'type' => 'business', 'status' => 'active', 'account_number' => $num]);
        throw new Exception('expected duplicate error');
    } catch (PDOException $e) {
        eq(1062, $e->errorInfo[1]);
    }
});

echo "Services\n";
$product = 0;
$svc = 0;
test('service copies price, term, type and carrier from product', function () use (&$acc, &$product, &$svc) {
    $product = create('products', ['sku' => 'BB-900', 'name' => 'Fibre 900', 'category' => 'broadband', 'carrier' => 'CityFibre', 'monthly_price' => '55', 'term_months' => '24', 'active' => '1']);
    $svc = create('services', ['account_id' => $acc, 'product_id' => $product, 'identifier' => 'CF-123', 'status' => 'active', 'start_date' => '2025-03-01']);
    $row = find('services', $svc);
    eq('broadband', $row['service_type']);
    eq('CityFibre', $row['carrier']);
    eq(55.00, (float)$row['monthly_price']);
    eq(24, (int)$row['term_months']);
    eq('2027-02-28', $row['contract_end_date']);
});
test('explicit price overrides product price', function () use (&$acc, &$product) {
    $id = create('services', ['account_id' => $acc, 'product_id' => $product, 'identifier' => 'CF-456', 'status' => 'active', 'monthly_price' => '49.99']);
    eq(49.99, (float)find('services', $id)['monthly_price']);
    eq(date('Y-m-d'), find('services', $id)['start_date'], 'active service starts today by default');
});
test('customer MRR sums active services only', function () use (&$acc) {
    create('services', ['account_id' => $acc, 'identifier' => '07700 900001', 'service_type' => 'mobile', 'status' => 'ceased', 'monthly_price' => '20']);
    eq(104.99, (float)find('accounts', $acc)['_mrr']);
});
test('renewal preset finds contracts ending soon', function () use (&$acc) {
    $id = create('services', ['account_id' => $acc, 'identifier' => 'EXPIRING-1', 'service_type' => 'voip', 'status' => 'active', 'monthly_price' => '10', 'contract_end_date' => date('Y-m-d', strtotime('+30 days'))]);
    $found = array_column(list_rows('services', ['preset' => 'expiring', 'per_page' => 0])['rows'], 'id');
    ok(in_array($id, $found), 'expiring service listed');
    eq(1, count($found), 'only the expiring service');
});
test('search matches identifiers and escapes LIKE wildcards', function () {
    eq(1, list_rows('services', ['q' => '900001'])['total']);
    eq(0, list_rows('services', ['q' => '%'])['total']);
});

echo "Tickets\n";
$ticket = 0;
test('ticket gets reference, SLA from priority and assignee', function () use (&$acc, &$svc, &$ticket) {
    $ticket = create('tickets', ['account_id' => $acc, 'service_id' => $svc, 'subject' => 'No sync', 'category' => 'fault', 'priority' => 'P1', 'status' => 'open']);
    $row = find('tickets', $ticket);
    eq(sprintf('TCK-%06d', $ticket), $row['reference']);
    $hours = (strtotime($row['sla_due_at']) - strtotime($row['created_at'])) / 3600;
    ok(abs($hours - 4) < 0.02, "P1 SLA should be 4h, got $hours");
    eq(1, $row['assigned_to']);
});
test('service must belong to the ticket customer', function () use (&$svc) {
    $other = create('accounts', ['name' => 'Other Co', 'type' => 'business', 'status' => 'active']);
    [$data] = validate(entity('tickets'), ['account_id' => $other, 'service_id' => $svc, 'subject' => 'x', 'category' => 'fault', 'priority' => 'P3', 'status' => 'open']);
    ok(isset(validate_scoped_refs(entity('tickets'), $data)['service_id']));
});
test('resolving sets resolved_at, reopening clears it', function () use (&$ticket) {
    $base = ['account_id' => find('tickets', $ticket)['account_id'], 'subject' => 'No sync', 'category' => 'fault', 'priority' => 'P1'];
    [$data] = validate(entity('tickets'), $base + ['status' => 'resolved']);
    update_row('tickets', $ticket, $data);
    ok(find('tickets', $ticket)['resolved_at'] !== null, 'resolved_at set');
    [$data] = validate(entity('tickets'), $base + ['status' => 'in_progress']);
    update_row('tickets', $ticket, $data);
    eq(null, find('tickets', $ticket)['resolved_at']);
});
test('changing priority recalculates SLA from opening time', function () use (&$ticket) {
    $row = find('tickets', $ticket);
    [$data] = validate(entity('tickets'), ['account_id' => $row['account_id'], 'subject' => 'No sync', 'category' => 'fault', 'priority' => 'P4', 'status' => 'open']);
    update_row('tickets', $ticket, $data);
    $row = find('tickets', $ticket);
    eq(72, (int)round((strtotime($row['sla_due_at']) - strtotime($row['created_at'])) / 3600));
});
test('breached preset lists overdue open tickets', function () use (&$ticket) {
    db_exec('UPDATE tickets SET sla_due_at = NOW() - INTERVAL 1 HOUR WHERE id = ?', [$ticket]);
    eq(1, list_rows('tickets', ['preset' => 'breached'])['total']);
});

echo "Opportunities\n";
test('probability follows stage unless overridden', function () use (&$acc) {
    $id = create('opportunities', ['account_id' => $acc, 'title' => 'Renewal', 'opp_type' => 'renewal', 'stage' => 'proposal', 'monthly_value' => '100']);
    $row = find('opportunities', $id);
    eq(50, (int)$row['probability']);
    eq(2400.0, (float)$row['_tcv'], 'TCV = 100 x 24 + 0');

    [$data] = validate(entity('opportunities'), ['account_id' => $acc, 'title' => 'Renewal', 'opp_type' => 'renewal', 'stage' => 'negotiation', 'monthly_value' => '100', 'probability' => '50']);
    update_row('opportunities', $id, $data);
    eq(75, (int)find('opportunities', $id)['probability'], 'untouched probability follows new stage');

    [$data] = validate(entity('opportunities'), ['account_id' => $acc, 'title' => 'Renewal', 'opp_type' => 'renewal', 'stage' => 'negotiation', 'monthly_value' => '100', 'probability' => '90']);
    update_row('opportunities', $id, $data);
    eq(90, (int)find('opportunities', $id)['probability'], 'manual override kept');

    [$data] = validate(entity('opportunities'), ['account_id' => $acc, 'title' => 'Renewal', 'opp_type' => 'renewal', 'stage' => 'won', 'monthly_value' => '100', 'probability' => '90']);
    update_row('opportunities', $id, $data);
    eq(100, (int)find('opportunities', $id)['probability'], 'won is 100%');
});

echo "Activities & deletes\n";
test('non-task activities are marked done and attributed', function () use (&$acc) {
    $id = create('activities', ['account_id' => $acc, 'type' => 'call', 'subject' => 'Called']);
    $row = find('activities', $id);
    eq(1, (int)$row['done']);
    eq(1, (int)$row['user_id']);
});
test('deleting a customer cascades to its records', function () use (&$acc) {
    delete_row('accounts', $acc);
    eq(0, (int)db_value('SELECT COUNT(*) FROM services WHERE account_id = ?', [$acc]));
    eq(0, (int)db_value('SELECT COUNT(*) FROM tickets WHERE account_id = ?', [$acc]));
    eq(0, (int)db_value('SELECT COUNT(*) FROM opportunities WHERE account_id = ?', [$acc]));
});

echo "Xero\n";
test('parses Xero date formats', function () {
    eq('2018-02-15', xero_date('/Date(1518652800000+0000)/'));
    eq('2026-09-10', xero_date('2026-09-10T00:00:00'));
    eq(null, xero_date(null));
});
test('normalises company names for matching', function () {
    eq('copper kettle cafe', company_match_key('The Copper Kettle Café'));
    eq('fenwick and rowe solicitors', company_match_key('Fenwick & Rowe Solicitors LLP'));
    eq('acme telecom', company_match_key('ACME Telecom Ltd.'));
});
test('calculates outstanding, overdue, credit and currency', function () {
    $c = fn($id) => ['ContactID' => $id];
    $b = xero_calculate_balances([
        ['Type' => 'ACCREC', 'Status' => 'AUTHORISED', 'Contact' => $c('A'), 'AmountDue' => 100, 'DueDateString' => '2026-01-01T00:00:00'],
        ['Type' => 'ACCREC', 'Status' => 'AUTHORISED', 'Contact' => $c('A'), 'AmountDue' => 50, 'DueDate' => '/Date(1893456000000+0000)/'], // 2030
        ['Type' => 'ACCREC', 'Status' => 'AUTHORISED', 'Contact' => $c('B'), 'AmountDue' => 120, 'CurrencyRate' => 1.2, 'DueDateString' => '2026-02-01T00:00:00'],
        ['Type' => 'ACCPAY', 'Status' => 'AUTHORISED', 'Contact' => $c('A'), 'AmountDue' => 999],
        ['Type' => 'ACCREC', 'Status' => 'DRAFT', 'Contact' => $c('A'), 'AmountDue' => 999],
    ], [
        ['Type' => 'ACCRECCREDIT', 'Status' => 'AUTHORISED', 'Contact' => $c('A'), 'RemainingCredit' => 30],
        ['Type' => 'ACCRECCREDIT', 'Status' => 'AUTHORISED', 'Contact' => $c('C'), 'RemainingCredit' => 15],
    ], '2026-09-26');
    eq(120.0, $b['A']['outstanding']);
    eq(100.0, $b['A']['overdue']);
    eq(2, $b['A']['open_invoices']);
    eq('2026-01-01', $b['A']['oldest_due_date']);
    eq(100.0, $b['B']['outstanding'], 'converted to base currency');
    eq(-15.0, $b['C']['outstanding'], 'customer in credit');
    eq(0.0, $b['C']['overdue']);
});

$mockState = sys_get_temp_dir() . '/crm_xero_mock_' . getmypid() . '.json';
$mockPort = 18000 + getmypid() % 1000;
$mock = proc_open([PHP_BINARY, '-S', "127.0.0.1:$mockPort", APP_ROOT . '/tests/xero_mock.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['MOCK_STATE' => $mockState] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $mockPort); $i++) {
    usleep(100000);
}
$mockBase = "http://127.0.0.1:$mockPort";
$cfg = &config_ref();
$cfg['xero_urls'] = [
    'authorize' => "$mockBase/identity/connect/authorize", 'token' => "$mockBase/connect/token",
    'revoke' => "$mockBase/connect/revocation", 'connections' => "$mockBase/connections", 'api' => "$mockBase/api.xro/2.0",
];
function mock_state(): array { global $mockState; return json_decode(file_get_contents($mockState), true); }

test('connects with the OAuth code flow', function () use ($mockBase) {
    set_setting('xero_client_id', 'MOCKCLIENTID0000000000000000000A');
    set_setting('xero_client_secret', 'mock-secret');
    ok(xero_configured() && !xero_connected());
    $redirect = 'https://crm.example.com/crm/xero-callback.php';
    $url = xero_authorize_url('state123', $redirect);
    ok(str_contains($url, 'scope=offline_access%20accounting.contacts.read%20accounting.invoices.read'), 'granular scopes requested');
    [$status, , $headers] = xero_http('GET', $url);
    eq(302, $status);
    parse_str(parse_url($headers['location'], PHP_URL_QUERY), $back);
    eq('state123', $back['state']);
    xero_exchange_code($back['code'], $redirect);
    $orgs = xero_connections();
    eq('Mock Telecom Ltd', $orgs[0]['tenantName']);
    set_setting('xero_tenant_id', $orgs[0]['tenantId']);
    set_setting('xero_tenant_name', $orgs[0]['tenantName']);
    ok(xero_connected());
});

test('syncs balances, paging, 429 retry and auto-links customers', function () {
    db_exec('DELETE FROM accounts');
    $mk = fn($name, $extra = []) => create('accounts', ['name' => $name, 'type' => 'business', 'status' => 'active'] + $extra);
    $harbour = $mk('Harbour View Dental', ['email' => 'accounts@harbour.example.co.uk']);  // by email
    $kestrel = $mk('Kestrel Logistics', ['account_number' => 'ACC-KESTREL']);             // by account number
    $kettle  = $mk('The Copper Kettle Café');                                             // by name
    $north   = $mk('Northgate Motors');                                                   // ambiguous: 2 Xero contacts
    $s = xero_sync();
    eq(150, $s['contacts'], 'both pages of contacts fetched');
    eq(7, $s['invoices']);
    eq(3, $s['linked']);
    $bal = fn($id) => db_one('SELECT x.* FROM accounts a JOIN xero_contacts x ON x.id = a.xero_contact_id WHERE a.id = ?', [$id]);
    eq(180.0, (float)$bal($harbour)['outstanding'], '120 + 80 - 20 credit');
    eq(120.0, (float)$bal($harbour)['overdue']);
    eq(80.0, (float)$bal($kestrel)['overdue'], 'USD 100 / 1.25');
    eq(0.0, (float)$bal($kettle)['outstanding'], 'draft invoice ignored');
    eq(null, db_value('SELECT xero_contact_id FROM accounts WHERE id = ?', [$north]), 'ambiguous name not linked');
    eq(50.0, (float)db_value("SELECT outstanding FROM xero_contacts WHERE contact_id = 'e0000000-0000-0000-0000-000000000009'"), 'archived contact kept');
    ok(count(array_filter(mock_state()['calls'], fn($c) => str_starts_with($c, 'GET /api.xro/2.0/Invoices'))) >= 2, 'rate-limited request retried');
    eq(null, setting('xero_last_sync_error'));
});

test('balances appear in customer lists and presets', function () {
    ok(isset(entities()['accounts']['computed']['_balance']), 'balance column added once connected');
    $arrears = list_rows('accounts', ['preset' => 'arrears']);
    eq(2, $arrears['total'], 'Harbour and Kestrel are overdue');
    db_exec("UPDATE accounts SET credit_limit = 100 WHERE name = 'Harbour View Dental'");
    eq(1, list_rows('accounts', ['preset' => 'over_limit'])['total']);
});

test('manual links survive re-sync; refresh token rotates; expired access token recovers', function () {
    $north = (int)db_value("SELECT id FROM accounts WHERE name = 'Northgate Motors'");
    $contact = (int)db_value("SELECT id FROM xero_contacts WHERE contact_id = 'c1000000-0000-0000-0000-000000000005'");
    db_exec('UPDATE accounts SET xero_contact_id = ? WHERE id = ?', [$contact, $north]);
    $oldRefresh = setting('xero_refresh_token');
    set_setting('xero_expires_at', '0'); // access token expired → refresh first
    xero_sync();
    ok(setting('xero_refresh_token') !== $oldRefresh, 'refresh token rotated');
    eq($contact, (int)db_value('SELECT xero_contact_id FROM accounts WHERE id = ?', [$north]));

    global $mockState;
    $st = mock_state(); $st['expire_next'] = true; file_put_contents($mockState, json_encode($st));
    xero_sync(); // 401 mid-sync → refresh and retry
    eq(null, setting('xero_last_sync_error'));
});

test('revoked connection gives a clear error and is marked disconnected', function () {
    global $mockState;
    $st = mock_state(); $st['refresh'] = []; $st['tokens'] = []; file_put_contents($mockState, json_encode($st));
    set_setting('xero_expires_at', '0');
    try {
        xero_sync();
        throw new Exception('expected failure');
    } catch (XeroException $e) {
        ok(str_contains($e->getMessage(), 'reconnect'), $e->getMessage());
    }
    ok(!xero_connected());
    ok(str_contains((string)setting('xero_last_sync_error'), 'reconnect'));
});

proc_terminate($mock);
@unlink($mockState);

echo "GoCardless\n";
test('mandate states and best mandate per customer', function () {
    eq('active', gc_mandate_state('active'));
    eq('pending', gc_mandate_state('pending_customer_approval'));
    eq('inactive', gc_mandate_state('cancelled'));
    eq('none', gc_mandate_state(null));
    $best = gc_best_mandates([
        ['id' => 'A', 'status' => 'cancelled', 'created_at' => '2026-01-01', 'links' => ['customer' => 'CU1']],
        ['id' => 'B', 'status' => 'active', 'created_at' => '2025-01-01', 'links' => ['customer' => 'CU1']],
        ['id' => 'C', 'status' => 'failed', 'created_at' => '2025-01-01', 'links' => ['customer' => 'CU2']],
        ['id' => 'D', 'status' => 'failed', 'created_at' => '2026-01-01', 'links' => ['customer' => 'CU2']],
    ]);
    eq('B', $best['CU1']['id'], 'active beats newer cancelled');
    eq('D', $best['CU2']['id'], 'newest wins a tie');
});

$gcState = sys_get_temp_dir() . '/crm_gc_mock_' . getmypid() . '.json';
$gcPort = 17000 + getmypid() % 1000;
$gcMock = proc_open([PHP_BINARY, '-S', "127.0.0.1:$gcPort", APP_ROOT . '/tests/gocardless_mock.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $gcPipes, null, ['MOCK_STATE' => $gcState] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $gcPort); $i++) {
    usleep(100000);
}
$cfg = &config_ref();
$cfg['gocardless_url'] = "http://127.0.0.1:$gcPort";
function gc_mock_state(): array { global $gcState; return json_decode(file_get_contents($gcState), true); }
function gc_mock_complete(string $br): void { global $gcPort; http_request('POST', "http://127.0.0.1:$gcPort/__complete/$br"); }

test('rejects a bad token and accepts a good one', function () {
    set_setting('gocardless_access_token', 'wrong');
    ok(gc_configured());
    try {
        gc_creditor_name();
        throw new Exception('expected failure');
    } catch (GoCardlessException $e) {
        ok(str_contains($e->getMessage(), 'rejected the access token'), $e->getMessage());
    }
    set_setting('gocardless_access_token', 'sandbox_mock_readwrite');
    eq('Mock Telecom Ltd', gc_creditor_name());
});

$gcIds = [];
test('syncs customers and mandates with paging, 429 retry and auto-linking', function () use (&$gcIds) {
    db_exec('DELETE FROM accounts');
    $mk = fn($name, $extra = []) => create('accounts', ['name' => $name, 'type' => 'business', 'status' => 'active'] + $extra);
    $gcIds['harbour'] = $mk('Harbour View Dental');
    create('contacts', ['account_id' => $gcIds['harbour'], 'name' => 'Lucy Grant', 'email' => 'lucy@harbour.example.co.uk', 'is_billing' => '1']); // match by contact email
    $gcIds['wilson'] = $mk('Mr David Wilson', ['type' => 'residential']);                                                                         // match by name, title ignored
    $gcIds['north'] = $mk('Northgate Motors');
    $gcIds['kettle'] = $mk('The Copper Kettle Café', ['email' => 'hello@kettle.example.co.uk', 'address' => '1 High St', 'city' => 'York', 'postcode' => 'YO1 8RS']);
    create('contacts', ['account_id' => $gcIds['kettle'], 'name' => 'Tom Hughes', 'email' => 'tom@kettle.example.co.uk', 'is_primary' => '1']);

    $s = gc_sync();
    eq(603, $s['customers'], 'both pages of customers');
    eq(1, $s['active']);
    eq(3, $s['linked']);
    $status = fn($id) => db_value('SELECT g.mandate_status FROM accounts a JOIN gocardless_customers g ON g.id = a.gocardless_customer_id WHERE a.id = ?', [$id]);
    eq('active', $status($gcIds['harbour']), 'active mandate beats older cancelled one');
    eq('pending_customer_approval', $status($gcIds['wilson']));
    eq('failed', $status($gcIds['north']));
    eq(null, $status($gcIds['kettle']));
    ok(count(array_filter(gc_mock_state()['calls'], fn($c) => str_starts_with($c, 'GET /mandates'))) >= 2, '429 retried');
});

test('No Direct Debit filter and list column', function () use (&$gcIds) {
    $rows = list_rows('accounts', ['preset' => 'no_dd', 'per_page' => 0])['rows'];
    $ids = array_map('intval', array_column($rows, 'id'));
    sort($ids);
    $expected = [$gcIds['kettle'], $gcIds['north']];
    sort($expected);
    eq($expected, $ids, 'failed mandate and no mandate');
    ok(in_array('_dd', entities()['accounts']['list'], true));
    eq('Setting up', export_value(entity('accounts'), '_dd', ['_dd' => 'pending_submission']));
});

test('creates a prefilled setup link for a new customer', function () use (&$gcIds) {
    $account = db_one('SELECT * FROM accounts WHERE id = ?', [$gcIds['kettle']]);
    $link = gc_create_setup_link($account);
    ok(str_starts_with($link['url'], 'https://pay-sandbox.gocardless.com/'), $link['url']);
    ok(strtotime($link['expires_at']) > strtotime('+6 days'), 'expires in ~7 days');
    $bodies = gc_mock_state()['last_bodies'];
    eq('bacs', $bodies['/billing_requests']['billing_requests']['mandate_request']['scheme']);
    eq('GBP', $bodies['/billing_requests']['billing_requests']['mandate_request']['currency']);
    eq($account['account_number'], $bodies['/billing_requests']['billing_requests']['metadata']['crm_account']);
    $prefill = $bodies['/billing_request_flows']['billing_request_flows']['prefilled_customer'];
    eq('The Copper Kettle Café', $prefill['company_name']);
    eq('Tom', $prefill['given_name']);
    eq('Hughes', $prefill['family_name']);
    eq('tom@kettle.example.co.uk', $prefill['email'], 'primary contact email preferred');
    eq('YO1 8RS', $prefill['postal_code']);
    // A second link replaces the first
    $second = gc_create_setup_link($account);
    ok($second['id'] !== $link['id']);
    eq(1, (int)db_value("SELECT COUNT(*) FROM gocardless_setup_links WHERE account_id = ? AND status = 'open'", [$gcIds['kettle']]));
    $mailto = gc_setup_mailto($account, $second['url']);
    ok(str_starts_with($mailto, 'mailto:tom%40kettle.example.co.uk?subject='), $mailto);
    ok(str_contains(rawurldecode($mailto), $second['url']));
});

test('completed setup link links the customer and shows the new mandate', function () use (&$gcIds) {
    $link = gc_open_setup_link($gcIds['kettle']);
    gc_mock_complete($link['billing_request_id']);
    gc_refresh_account($gcIds['kettle']);
    $row = db_one('SELECT g.* FROM accounts a JOIN gocardless_customers g ON g.id = a.gocardless_customer_id WHERE a.id = ?', [$gcIds['kettle']]);
    eq('pending_submission', $row['mandate_status']);
    eq('completed', db_value('SELECT status FROM gocardless_setup_links WHERE id = ?', [$link['id']]));
    eq(null, gc_open_setup_link($gcIds['kettle']));
});

test('link for an existing GoCardless customer uses their record', function () use (&$gcIds) {
    $account = db_one('SELECT * FROM accounts WHERE id = ?', [$gcIds['north']]);
    gc_create_setup_link($account);
    $bodies = gc_mock_state()['last_bodies'];
    eq('CU0003', $bodies['/billing_requests']['billing_requests']['links']['customer']);
    ok(!isset($bodies['/billing_request_flows']['billing_request_flows']['prefilled_customer']), 'no prefill for existing customer');
});

test('read-only token explains it cannot create links', function () use (&$gcIds) {
    set_setting('gocardless_access_token', 'sandbox_mock_readonly');
    try {
        gc_create_setup_link(db_one('SELECT * FROM accounts WHERE id = ?', [$gcIds['kettle']]));
        throw new Exception('expected failure');
    } catch (GoCardlessException $e) {
        ok(str_contains($e->getMessage(), 'read-write'), $e->getMessage());
    }
    gc_refresh_account($gcIds['harbour']); // reading still works
    set_setting('gocardless_access_token', 'sandbox_mock_readwrite');
});

proc_terminate($gcMock);
@unlink($gcState);

echo "Demo data\n";
test('demo data loads', function () {
    require_once APP_ROOT . '/install/demo_data.php';
    db_exec('DELETE FROM accounts');
    ob_start();
    seed_demo_data();
    ob_end_clean();
    ok(db_value('SELECT COUNT(*) FROM accounts') >= 10);
    ok(db_value("SELECT COUNT(*) FROM services WHERE status = 'active'") > 10);
});

$pdo->exec("DROP DATABASE IF EXISTS `$testDb`");
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
