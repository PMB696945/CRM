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

test('accounts contact can be sent to Xero once contacts write access is granted', function () {
    ok(!xero_can_write_contacts(), 'read-only by default');
    eq('offline_access accounting.contacts accounting.invoices.read', xero_write_scopes(XERO_DEFAULT_SCOPES));
    eq('offline_access accounting.contacts accounting.transactions.read', xero_write_scopes('offline_access accounting.contacts.read accounting.transactions.read'));
    set_setting('xero_scopes', xero_write_scopes(XERO_DEFAULT_SCOPES));
    set_setting('xero_push_contacts', '1');
    ok(xero_push_enabled());
    $id = (int)db_value("SELECT id FROM accounts WHERE name = 'Harbour View Dental'");
    $c = create('contacts', ['account_id' => $id, 'name' => 'Alex Ledger Smith', 'email' => 'ledger@harbour.example.co.uk', 'is_billing' => '1']);
    eq('ledger@harbour.example.co.uk', xero_push_billing_contact($id));
    $pushed = end(mock_state()['pushed']);
    eq(['ContactID' => 'c1000000-0000-0000-0000-000000000001', 'EmailAddress' => 'ledger@harbour.example.co.uk', 'FirstName' => 'Alex', 'LastName' => 'Ledger Smith'], $pushed);
    eq('ledger@harbour.example.co.uk', db_value('SELECT x.email FROM xero_contacts x JOIN accounts a ON a.xero_contact_id = x.id WHERE a.id = ?', [$id]));
    $unlinked = create('accounts', ['name' => 'Not In Xero', 'type' => 'business', 'status' => 'active']);
    try { xero_push_billing_contact($unlinked); throw new Exception('expected failure'); } catch (XeroException $e) { ok(str_contains($e->getMessage(), 'linked')); }
    set_setting('xero_scopes', null);
    set_setting('xero_push_contacts', null);
});

test('products are sent to Xero as items: one, several, validation problems, updates', function () {
    ok(!xero_can_write_items(), 'not allowed by default');
    try { xero_push_products([1]); throw new Exception('expected failure'); } catch (XeroException $e) { ok(str_contains($e->getMessage(), 'Reconnect')); }
    eq('offline_access accounting.contacts.read accounting.invoices.read accounting.settings', xero_item_scopes(XERO_DEFAULT_SCOPES));
    eq('offline_access accounting.settings', xero_item_scopes('offline_access accounting.settings.read'));
    set_setting('xero_scopes', xero_item_scopes(XERO_DEFAULT_SCOPES));
    set_setting('xero_item_sales_account', '200');
    set_setting('xero_item_tax_type', 'OUTPUT2');
    $a = create('products', ['sku' => 'T-FTTP-900', 'name' => 'FTTP 900', 'category' => 'broadband', 'monthly_price' => '600', 'cost_price' => '360', 'billing_frequency' => 'yearly', 'term_months' => '12']);
    $b = create('products', ['sku' => 'T-ROUTER', 'name' => 'Router', 'category' => 'hardware', 'monthly_price' => '5', 'term_months' => '0']);
    $bad = create('products', ['sku' => str_repeat('X', 31), 'name' => 'Too long code', 'category' => 'other', 'monthly_price' => '1', 'term_months' => '0']);
    $r = xero_push_products([$a, $b, $bad]);
    eq(2, $r['sent']);
    ok(str_contains($r['failed'][str_repeat('X', 31)], '30 characters'));
    $items = mock_state()['items'];
    $item = $items['T-FTTP-900'];
    eq(600, $item['SalesDetails']['UnitPrice']); eq('200', $item['SalesDetails']['AccountCode']); eq('OUTPUT2', $item['SalesDetails']['TaxType']);
    eq(360, $item['PurchaseDetails']['UnitPrice']); ok($item['IsPurchased']);
    ok(str_contains($item['Description'], 'billed yearly'));
    ok(!isset($items['T-ROUTER']['PurchaseDetails']), 'no cost price, no purchase details');
    eq('summarizeErrors=false', end(mock_state()['item_posts']));
    $p = db_one('SELECT * FROM products WHERE id = ?', [$a]);
    ok($p['xero_item_id'] !== null && $p['xero_synced_at'] !== null && $p['xero_sync_error'] === null);
    eq('sent', find('products', $a)['_xero']);
    ok(db_value('SELECT xero_sync_error FROM products WHERE id = ?', [$bad]) !== null);
    eq('error', find('products', $bad)['_xero']);
    // A later edit shows as changed; sending again updates the same item.
    sleep(1);
    [$data] = validate(entity('products'), ['monthly_price' => '650'] + find('products', $a));
    update_row('products', $a, $data);
    eq('changed', find('products', $a)['_xero']);
    xero_push_products([$a]);
    eq(650, mock_state()['items']['T-FTTP-900']['SalesDetails']['UnitPrice']);
    eq($p['xero_item_id'], mock_state()['items']['T-FTTP-900']['ItemID'], 'same Xero item updated');
    // Xero's own validation errors are recorded against the product.
    // Each product's own nominal codes win over the defaults.
    db_exec("UPDATE products SET sales_account_code = '205', purchase_account_code = '310' WHERE id = ?", [$a]);
    xero_push_products([$a]);
    eq('205', mock_state()['items']['T-FTTP-900']['SalesDetails']['AccountCode']);
    eq('310', mock_state()['items']['T-FTTP-900']['PurchaseDetails']['AccountCode']);
    eq(3, xero_fetch_accounts(), 'accounts without a code skipped');
    eq(['200' => '200 – Sales', '205' => '205 – Airtime sales'], nominal_codes('sales'));
    eq(['310'], array_keys(nominal_codes('purchases')));
    [$data] = validate(entity('products'), ['sales_account_code' => '999'] + find('products', $a));
    ok(isset(validate_rules('products', $data, $a)['sales_account_code']), 'unknown code rejected once codes are loaded');
    [$data] = validate(entity('products'), ['sales_account_code' => '205'] + find('products', $a));
    eq([], validate_rules('products', $data, $a));
    set_setting('xero_accounts', null);
    set_setting('xero_item_sales_account', '999');
    $r = xero_push_products([$b]);
    ok(str_contains($r['failed']['T-ROUTER'], 'not a valid code'));
    ok(str_contains(xero_push_products_message($r), 'T-ROUTER'));
    foreach (['xero_scopes', 'xero_item_sales_account', 'xero_item_tax_type'] as $k) { set_setting($k, null); }
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

echo "Dealers\n";
$dealerIds = [];
test('dealer rules: must be a dealer, no self or loops, can\'t un-dealer with customers', function () use (&$dealerIds) {
    $mk = fn($name, $extra = []) => create('accounts', ['name' => $name, 'type' => 'business', 'status' => 'active'] + $extra);
    $a = $mk('Alpha IT Ltd', ['is_dealer' => '1', 'dealer_commission_pct' => '10']);
    $b = $mk('Bravo Dental', ['parent_id' => $a, 'parent_relationship' => 'billed_via_dealer', 'msa_covered' => '1']);
    $plain = $mk('Plain Co');
    $dealerIds = ['a' => $a, 'b' => $b];

    [$data] = validate(entity('accounts'), ['name' => 'X', 'type' => 'business', 'status' => 'active', 'parent_id' => $plain, 'parent_relationship' => 'referral']);
    ok(isset(validate_rules('accounts', $data, null)['parent_id']), 'parent must be a dealer');

    [$data] = validate(entity('accounts'), ['name' => 'Alpha IT Ltd', 'type' => 'business', 'status' => 'active', 'is_dealer' => '1', 'parent_id' => $a, 'parent_relationship' => 'referral']);
    ok(isset(validate_rules('accounts', $data, $a)['parent_id']), 'not its own dealer');

    db_exec('UPDATE accounts SET is_dealer = 1 WHERE id = ?', [$b]);
    [$data] = validate(entity('accounts'), ['name' => 'Alpha IT Ltd', 'type' => 'business', 'status' => 'active', 'is_dealer' => '1', 'parent_id' => $b, 'parent_relationship' => 'referral']);
    ok(str_contains(validate_rules('accounts', $data, $a)['parent_id'] ?? '', 'loop'), 'loop detected');
    db_exec('UPDATE accounts SET is_dealer = 0 WHERE id = ?', [$b]);

    [$data] = validate(entity('accounts'), ['name' => 'Alpha IT Ltd', 'type' => 'business', 'status' => 'active']);
    ok(isset(validate_rules('accounts', $data, $a)['is_dealer']), 'dealer with customers can\'t be un-marked');

    [$data] = validate(entity('accounts'), ['name' => 'Y', 'type' => 'business', 'status' => 'active', 'parent_id' => $a]);
    ok(isset(validate_rules('accounts', $data, null)['parent_relationship']), 'relationship required');

    eq(1, list_rows('accounts', ['preset' => 'dealers'])['total']);
    eq(1, list_rows('accounts', ['filters' => ['parent_id' => $a]])['total']);
});
test('removing the dealer clears relationship and MSA flag', function () use (&$dealerIds) {
    $id = create('accounts', ['name' => 'Charlie', 'type' => 'business', 'status' => 'active', 'parent_id' => $dealerIds['a'], 'parent_relationship' => 'referral', 'msa_covered' => '1']);
    [$data] = validate(entity('accounts'), ['name' => 'Charlie', 'type' => 'business', 'status' => 'active', 'parent_id' => '']);
    update_row('accounts', $id, $data);
    $row = find('accounts', $id);
    eq(null, $row['parent_relationship']);
    eq(0, (int)$row['msa_covered']);
});

echo "Word templates\n";
test('merges fields split across runs, escapes XML, keeps Signable tags, builds table', function () {
    $xml = '<w:document><w:body>'
        . '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:rPr><w:b/></w:rPr><w:t>Agreement with {{cust</w:t></w:r><w:r><w:t>omer_name}} ({{account_number}})</w:t></w:r></w:p>'
        . '<w:p><w:r><w:t>{{services_table}}</w:t></w:r></w:p>'
        . '<w:p><w:r><w:t>{{customer_address}}</w:t></w:r></w:p>'
        . '<w:p><w:r><w:t>Sign: {signature:signer1:Customer+Signature} {{unknown_field}}</w:t></w:r></w:p>'
        . '<w:p><w:r><w:t>Untouched text</w:t></w:r></w:p></w:body></w:document>';
    $out = docx_merge_xml($xml, ['customer_name' => 'Smith & Sons <Ltd>', 'account_number' => 'ACC-1', 'customer_address' => "1 High St\nYork"],
        [['Service', 'Qty'], ['Broadband', '1'], ['Total', '']]);
    ok(str_contains($out, 'Agreement with Smith &amp; Sons &lt;Ltd&gt; (ACC-1)'), 'split placeholder merged + escaped');
    ok(str_contains($out, '<w:pStyle w:val="Heading1"/>') && str_contains($out, '<w:rPr><w:b/></w:rPr>'), 'paragraph and run formatting kept');
    ok(str_contains($out, '<w:tbl>') && str_contains($out, '>Broadband<'), 'services table inserted');
    ok(str_contains($out, '1 High St</w:t><w:br/><w:t xml:space="preserve">York'), 'multi-line value uses line breaks');
    ok(str_contains($out, '{signature:signer1:Customer+Signature}'), 'Signable tag untouched');
    ok(str_contains($out, '{{unknown_field}}'), 'unknown fields left as-is');
    ok(str_contains($out, '<w:p><w:r><w:t>Untouched text</w:t></w:r></w:p>'), 'other paragraphs unchanged');
    $dom = new DOMDocument();
    ok(@$dom->loadXML(str_replace('<w:document>', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">', $out)), 'result is valid XML');
});
test('example template round-trips through merge', function () {
    $tpl = sys_get_temp_dir() . '/crm_tpl_' . getmypid() . '.docx';
    $out = sys_get_temp_dir() . '/crm_out_' . getmypid() . '.docx';
    docx_example_template($tpl);
    ok(docx_validate($tpl) === null);
    $fields = docx_placeholders($tpl);
    ok(in_array('services_table', $fields, true) && in_array('customer_name', $fields, true));
    eq([], array_values(array_diff($fields, array_keys(contract_merge_field_help()))), 'example only uses known fields');
    docx_merge($tpl, $out, ['customer_name' => 'Zulu Ltd', 'our_company_name' => 'Netcomm'], [['A', 'B'], ['x', 'y']]);
    $zip = new ZipArchive();
    $zip->open($out);
    $doc = $zip->getFromName('word/document.xml');
    $zip->close();
    ok(str_contains($doc, 'Zulu Ltd') && str_contains($doc, 'Netcomm') && str_contains($doc, '<w:tbl>'));
    ok(!str_contains($doc, '{{customer_name}}'));
    @unlink($tpl); @unlink($out);
    ok(docx_validate(__FILE__) !== null, 'non-docx rejected');
});

echo "Quotes & contracts\n";
test('quote line parsing and totals', function () {
    [$lines, $errors] = quote_parse_lines(['line_description' => ['Fibre', '', 'SIMs'], 'line_product_id' => ['', '', ''], 'line_service_type' => ['broadband', '', 'mobile'],
        'line_quantity' => ['1', '', '3'], 'line_monthly_price' => ['50', '', '£10.50'], 'line_setup_fee' => ['99', '', '0'], 'line_term_months' => ['24', '', '12']]);
    eq([], $errors);
    eq(2, count($lines), 'blank row skipped');
    $t = quote_totals($lines);
    eq(81.5, $t['monthly']);
    eq(99.0, $t['setup']);
    eq(50 * 24 + 99 + 3 * 10.5 * 12, $t['tcv']);
    eq(24, $t['term']);
    [, $errors] = quote_parse_lines(['line_description' => ['X'], 'line_quantity' => ['0'], 'line_monthly_price' => ['-1'], 'line_setup_fee' => ['0'], 'line_term_months' => ['500']]);
    eq(3, count($errors));
});

$smtpDir = sys_get_temp_dir() . '/crm_smtp_' . getmypid();
$smtpPort = 16000 + getmypid() % 1000;
$sinkProc = proc_open(['python3', APP_ROOT . '/tests/smtp_sink.py', (string)$smtpPort, $smtpDir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p1);
$sgState = sys_get_temp_dir() . '/crm_sg_' . getmypid() . '.json';
$sgPort = 15000 + getmypid() % 1000;
$sgProc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$sgPort", APP_ROOT . '/tests/signable_mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p2, null, ['MOCK_STATE' => $sgState] + getenv());
for ($i = 0; $i < 50 && (!@fsockopen('127.0.0.1', $smtpPort) || !@fsockopen('127.0.0.1', $sgPort)); $i++) {
    usleep(100000);
}
$cfg = &config_ref();
$cfg['signable_url'] = "http://127.0.0.1:$sgPort/v1";
$cfg['storage_path'] = sys_get_temp_dir() . '/crm_storage_' . getmypid();
function sent_mails(): array { global $smtpDir; $files = glob("$smtpDir/*.eml") ?: []; sort($files); return array_map('file_get_contents', $files); }
function mail_body(string $raw): string { preg_match_all('/Content-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $raw, $m); return implode("\n", array_map(fn($b) => base64_decode(preg_replace('/\s+/', '', $b)), $m[1])); }
function sg_state(): array { global $sgState; return json_decode(file_get_contents($sgState), true); }

$flow = [];
test('SMTP: email sent with login, encoded subject and both text and HTML parts', function () use ($smtpPort) {
    foreach (['mail_from_email' => 'sales@example.co.uk', 'mail_from_name' => 'Netcomm Sales', 'mail_transport' => 'smtp', 'smtp_host' => '127.0.0.1',
        'smtp_port' => (string)$smtpPort, 'smtp_encryption' => 'none', 'smtp_username' => 'user', 'smtp_password' => 'pw', 'company_name' => 'Netcomm UK',
        'app_url' => 'https://crm.example.co.uk/crm'] as $k => $v) {
        set_setting($k, $v);
    }
    send_mail('test@example.com', 'Tëst Person', 'Quote – £100 ✓', '<p>Hello <b>there</b></p>');
    usleep(300000);
    $all = sent_mails();
    $raw = end($all);
    ok(str_contains($raw, 'X-Rcpt: test@example.com'));
    ok(str_contains($raw, 'Subject: =?UTF-8?B?'), 'UTF-8 subject encoded');
    ok(str_contains($raw, 'text/plain') && str_contains($raw, 'text/html'));
    ok(str_contains(mail_body($raw), 'Hello there'), 'plain-text version generated');
    try { send_mail('not-an-email', '', 's', 'b'); throw new Exception('expected failure'); } catch (IntegrationException) {}
});

test('quote is emailed with a working link, accepted, and the contract goes to Signable', function () use (&$flow, &$dealerIds) {
    set_setting('signable_api_key', 'signable-test-key');
    // Templates: broadband + general
    foreach (['broadband' => 'Broadband agreement', 'general' => 'General terms'] as $type => $name) {
        $stored = bin2hex(random_bytes(6)) . '.docx';
        docx_example_template(storage_path('templates') . '/' . $stored);
        db_exec('INSERT INTO contract_templates (name, service_type, file_name, stored_name) VALUES (?, ?, ?, ?)', [$name, $type, "$name.docx", $stored]);
    }
    $acct = create('accounts', ['name' => 'Echo Logistics Ltd', 'type' => 'business', 'status' => 'prospect', 'address' => '5 Dock Rd', 'city' => 'Hull', 'postcode' => 'HU1 1AA']);
    create('contacts', ['account_id' => $acct, 'name' => 'Erin Echo', 'email' => 'erin@echo.example', 'is_billing' => '1']);
    db_exec('INSERT INTO quotes (account_id, title, created_by) VALUES (?, ?, 1)', [$acct, 'Fibre and mobiles']);
    $qid = (int)db()->lastInsertId();
    db_exec("UPDATE quotes SET reference = 'Q-TEST1' WHERE id = ?", [$qid]);
    quote_save_lines($qid, [
        ['product_id' => null, 'service_type' => 'broadband', 'description' => 'FTTP 900', 'quantity' => 1, 'monthly_price' => 55, 'setup_fee' => 99, 'term_months' => 24],
        ['product_id' => null, 'service_type' => 'mobile', 'description' => '5G SIM', 'quantity' => 2, 'monthly_price' => 20, 'setup_fee' => 0, 'term_months' => 24],
    ]);
    $quote = db_one('SELECT * FROM quotes WHERE id = ?', [$qid]);
    $before = count(sent_mails());
    quote_send($quote, 'erin@echo.example', 'Erin Echo');
    usleep(300000);
    $mails = sent_mails();
    eq($before + 1, count($mails));
    $body = mail_body(end($mails));
    ok(preg_match('#https://crm\.example\.co\.uk/crm/quote\.php\?t=([a-f0-9]{48})#', $body, $m) === 1, 'email contains quote link');
    $quote = quote_by_token($m[1]);
    eq('sent', $quote['status']);
    ok($quote['valid_until'] > date('Y-m-d'), 'validity set');
    eq(null, quote_by_token(str_repeat('0', 48)));

    $contract = quote_accept($quote, 'Eve Echo', '203.0.113.9', false, 'Eve@Echo.example');
    eq('accepted', db_value('SELECT status FROM quotes WHERE id = ?', [$qid]));
    ok($contract !== null, 'contract created');
    eq('sent', $contract['status'], 'sent to Signable automatically');
    $docs = contract_documents($contract);
    eq(['Broadband agreement', 'General terms'], array_column($docs, 'title'), 'one document per template (mobile uses General)');
    $env = sg_state()['envelopes'][$contract['signable_fingerprint']];
    eq('Eve Echo', $env['parties'][0]['party_name'], 'the person who accepted signs');
    eq('eve@echo.example', $env['parties'][0]['party_email'], 'at the email they gave');
    $zip = sys_get_temp_dir() . '/crm_sent_' . getmypid() . '.docx';
    file_put_contents($zip, base64_decode($env['documents'][0]['document_file_content']));
    $z = new ZipArchive(); $z->open($zip); $xml = $z->getFromName('word/document.xml'); $z->close(); @unlink($zip);
    ok(str_contains($xml, 'Echo Logistics Ltd') && str_contains($xml, 'FTTP 900') && !str_contains($xml, '5G SIM'), 'broadband doc has only broadband lines');
    ok(str_contains($xml, '{signature:signer1:Customer+Signature}'), 'Signable tag present');
    ok(str_contains($xml, 'HU1 1AA'), 'address merged');
    eq(1, json_decode($env['meta'], true)['crm_contract_id'] === (int)$contract['id'] ? 1 : 0, 'contract id in envelope meta');
    $flow = ['quote' => $qid, 'contract' => (int)$contract['id'], 'fp' => $contract['signable_fingerprint'], 'account' => $acct];
    // Staff notification email
    ok(str_contains(implode('', array_map('mail_body', array_slice(sent_mails(), -1))), 'accepted'), 'staff notified');
});

test('signing is picked up, signed PDF saved, pending services created', function () use (&$flow, $sgPort) {
    $c = db_one('SELECT * FROM contracts WHERE id = ?', [$flow['contract']]);
    eq('sent', contract_sync($c)['status'], 'still sent before signing');
    http_request('POST', "http://127.0.0.1:$sgPort/__sign/{$flow['fp']}");
    $c = contract_sync($c);
    eq('signed', $c['status']);
    ok($c['signed_file'] && str_starts_with((string)file_get_contents(storage_path('contracts') . '/' . $c['signed_file']), '%PDF'), 'signed PDF stored');
    eq(3, contract_create_services($c), '1 broadband + 2 mobile');
    eq(3, (int)db_value("SELECT COUNT(*) FROM services WHERE account_id = ? AND status = 'pending'", [$flow['account']]));
});

test('MSA-covered customer gets one service schedule; missing templates are explained', function () use (&$dealerIds) {
    $b = db_one('SELECT * FROM accounts WHERE id = ?', [$dealerIds['b']]);
    $lines = [['service_type' => 'leased_line', 'description' => 'LL', 'quantity' => 1, 'monthly_price' => 300, 'setup_fee' => 0, 'term_months' => 36]];
    db_exec("DELETE FROM contract_templates WHERE service_type = 'general'");
    try { contract_templates_for($b + ['msa_covered' => 0], $lines); throw new Exception('expected failure'); }
    catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'Leased line'), $e->getMessage()); }
    $stored = bin2hex(random_bytes(6)) . '.docx';
    docx_example_template(storage_path('templates') . '/' . $stored);
    db_exec("INSERT INTO contract_templates (name, service_type, file_name, stored_name) VALUES ('Schedule', 'msa_schedule', 's.docx', ?)", [$stored]);
    $groups = contract_templates_for($b, $lines);
    eq('msa_schedule', $groups[0][0]['service_type']);
});

test('bad Signable key is reported; contract marked failed', function () use (&$flow) {
    set_setting('signable_api_key', 'wrong');
    $acct = db_one('SELECT * FROM accounts WHERE id = ?', [$flow['account']]);
    $tpl = db_one("SELECT * FROM contract_templates WHERE service_type = 'broadband'");
    $c = contract_generate($acct, [[$tpl, []]], 'Test', 'services', 'Erin', 'erin@echo.example');
    try { contract_send($c); throw new Exception('expected failure'); }
    catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'API key'), $e->getMessage()); }
    eq('failed', db_value('SELECT status FROM contracts WHERE id = ?', [$c['id']]));
    set_setting('signable_api_key', 'signable-test-key');
});


echo "Products\n";
test('billing cycles, cost price, margin and monthly equivalents', function () {
    as_role('super_admin');
    eq(50.0, monthly_equivalent(600, 'yearly'));
    eq(20.0, monthly_equivalent(60, 'quarterly'));
    eq(43.33, monthly_equivalent(10, 'weekly'));
    eq(10.0, monthly_equivalent(60, 'biannually'));
    $id = create('products', ['sku' => 'T-LL-100', 'name' => 'Leased line 100', 'category' => 'leased_line', 'monthly_price' => '1200', 'cost_price' => '900', 'billing_frequency' => 'quarterly', 'term_months' => '36', 'active' => '1']);
    $p = find('products', $id);
    eq('quarterly', $p['billing_frequency']); eq(400.0, (float)$p['_monthly']); eq(25.0, (float)$p['_margin']);
    eq('monthly', find('products', create('products', ['sku' => 'X-1', 'name' => 'X', 'category' => 'other', 'monthly_price' => '1', 'term_months' => '0']))['billing_frequency'], 'defaults to monthly');
    [, $errors] = validate(entity('products'), ['sku' => 'Y', 'name' => 'Y', 'category' => 'other', 'monthly_price' => '1', 'term_months' => '0', 'billing_frequency' => 'fortnightly']);
    ok(isset($errors['billing_frequency']));
    $acc = create('accounts', ['name' => 'Cycle Co', 'type' => 'business', 'status' => 'active']);
    $svc = create('services', ['account_id' => $acc, 'product_id' => $id, 'identifier' => 'LL-CYCLE', 'status' => 'active']);
    eq(400.0, (float)db_value('SELECT monthly_price FROM services WHERE id = ?', [$svc]), 'service gets the monthly equivalent');
    ok(str_contains(ref_options('products')[$id], 'quarterly'));
    as_role('support');
    ok(!field_enabled(entity('products')['fields']['cost_price']), 'cost price hidden without permission');
    ok(!in_array('cost_price', entity('products')['list'], true) && !isset(entity('products')['computed']['_margin']));
    as_role('sales');
    ok(field_enabled(entity('products')['fields']['cost_price']) && !empty(entity('products')['fields']['cost_price']['readonly']), 'sales see cost but can\'t change it');
    [$data] = validate(entity('products'), ['cost_price' => '1'] + find('products', $id));
    ok(!array_key_exists('cost_price', $data), 'cost price not taken from the form');
    as_role('manager');
    ok(!can('products.edit') && can('costs.edit'));
    $fields = entity('products')['fields'];
    ok(empty($fields['cost_price']['readonly']) && !empty($fields['monthly_price']['readonly']) && !empty($fields['sku']['readonly']), 'managers change only the cost');
    [$data] = validate(entity('products'), ['cost_price' => '950', 'monthly_price' => '1'] + find('products', $id));
    eq(['cost_price'], array_keys($data));
    update_row('products', $id, $data);
    $p = find('products', $id);
    db_exec('UPDATE products SET setup_fee = 99 WHERE id = ?', [$id]);
    update_row('products', $id, $data);
    $p = find('products', $id);
    eq(950.0, (float)$p['cost_price']); eq(1200.0, (float)$p['monthly_price']); eq(99.0, (float)$p['setup_fee'], 'other fields untouched');
    as_role('super_admin');
});

echo "Roles, customers, approvals and audit\n";
function as_role(string $role): void { db_exec('UPDATE users SET role = ? WHERE id = 1', [$role]); current_user(true); }
test('version 6 roles become super admin / staff', function () {
    ok(in_array(db_value('SELECT role FROM users WHERE id = 1'), ['admin', 'super_admin'], true));
    as_role('super_admin');
    ok(can('audit.view') && can('customers.delete') && is_super_admin() && is_admin());
});
test('role permissions: defaults, changes on the Roles page, super admin always all', function () {
    as_role('staff');
    ok(can('customers.edit') && !can('customers.close') && !can('customers.delete') && !can('audit.view') && !can('approvals.decide'));
    as_role('manager');
    ok(can('approvals.decide') && can('customers.close') && !can('customers.delete'));
    set_setting('role_permissions', json_encode(['manager' => ['customers.edit', 'audit.view', 'not.a.permission']]));
    ok(can('audit.view') && !can('approvals.decide'), 'saved matrix wins');
    eq(['customers.edit', 'costs.view', 'costs.edit', 'audit.view'], role_permissions()['manager'], 'unknown permissions dropped; newer permissions keep defaults');
    set_setting('role_permissions', json_encode(['manager' => ['customers.edit'], '_known' => all_permissions()]));
    eq(['customers.edit'], role_permissions()['manager'], 'once saved with the new permissions, the saved grid wins');
    set_setting('role_permissions', json_encode(['super_admin' => []]));
    as_role('super_admin');
    ok(can('approvals.decide'), 'super admin cannot be restricted');
    set_setting('role_permissions', null);
    as_role('read_only');
    ok(!can('customers.edit') && !can('export'));
    as_role('staff');
    ok(!array_key_exists('_balance', entity('accounts')['computed']) || can('finance.view'));
    as_role('finance');
    ok(!can('services.edit') && can('finance.view'));
    as_role('super_admin');
    eq([], users_with_permission('not.a.permission'));
    ok(in_array('test@example.com', array_column(users_with_permission('approvals.decide'), 'email'), true));
});

$acct = [];
test('customer form creates main and accounts contacts', function () use (&$acct) {
    $acct['a'] = create('accounts', ['name' => 'Bramble Dental', 'type' => 'business', 'status' => 'active', 'address' => '1 High St', 'postcode' => 'm1 2ab',
        'main_name' => 'Sam Lee', 'main_phone' => '0161 000 0000', 'main_email' => 'sam@bramble.example', 'billing_same' => '1']);
    $a = db_one('SELECT * FROM accounts WHERE id = ?', [$acct['a']]);
    ok($a["main_contact_id"] && $a["main_contact_id"] === $a["billing_contact_id"], "same contact for both: " . json_encode([$a["main_contact_id"], $a["billing_contact_id"]]));
    $c = db_one('SELECT * FROM contacts WHERE id = ?', [$a['main_contact_id']]);
    eq('Sam Lee', $c['name']); eq(1, (int)$c['is_primary']); eq(1, (int)$c['is_billing']);
    eq(1, (int)$c['service_alerts'], 'alerts on by default'); eq(0, (int)$c['marketing_email'], 'no marketing by default');

    // Separate accounts contact
    [$data, $errors] = validate(entity('accounts'), ['billing_same' => '0', 'billing_name' => 'Pat Accounts', 'billing_email' => 'ACCOUNTS@bramble.example'] + account_contact_values($a) + $a);
    eq([], $errors + validate_rules('accounts', $data, $acct['a']));
    update_row('accounts', $acct['a'], $data);
    $a = db_one('SELECT * FROM accounts WHERE id = ?', [$acct['a']]);
    ok($a['billing_contact_id'] && $a['billing_contact_id'] !== $a['main_contact_id'], 'separate: ' . json_encode([$a['main_contact_id'], $a['billing_contact_id']]));
    eq('accounts@bramble.example', db_value('SELECT email FROM contacts WHERE id = ?', [$a['billing_contact_id']]));
    eq(2, (int)db_value('SELECT COUNT(*) FROM contacts WHERE account_id = ?', [$acct['a']]));
    eq(1, (int)db_value('SELECT SUM(is_billing) FROM contacts WHERE account_id = ?', [$acct['a']]), 'one accounts contact');

    // Editing the main contact updates the same record rather than adding another
    $v = account_contact_values($a);
    eq(0, $v['billing_same']);
    [$data] = validate(entity('accounts'), ['main_phone' => '0161 999 9999'] + $v + $a);
    update_row('accounts', $acct['a'], $data);
    eq(2, (int)db_value('SELECT COUNT(*) FROM contacts WHERE account_id = ?', [$acct['a']]));
    eq('0161 999 9999', db_value('SELECT phone FROM contacts WHERE id = ?', [$a['main_contact_id']]));

    // Validation: accounts contact name without email
    [$data] = validate(entity('accounts'), ['billing_same' => '0', 'billing_name' => 'X', 'billing_email' => ''] + $v + $a);
    ok(isset(validate_rules('accounts', $data, $acct['a'])['billing_email']));
});
test('ticking "main contact" on a contact updates the customer', function () use (&$acct) {
    $id = create('contacts', ['account_id' => $acct['a'], 'name' => 'New Boss', 'email' => 'boss@bramble.example', 'is_primary' => '1']);
    eq($id, (int)db_value('SELECT main_contact_id FROM accounts WHERE id = ?', [$acct['a']]));
    eq(1, (int)db_value('SELECT SUM(is_primary) FROM contacts WHERE account_id = ?', [$acct['a']]));
    [$data] = validate(entity('contacts'), ['is_primary' => '0'] + find('contacts', $id));
    update_row('contacts', $id, $data);
    eq(null, db_value('SELECT main_contact_id FROM accounts WHERE id = ?', [$acct['a']]));
    [$data] = validate(entity('contacts'), ['is_primary' => '1'] + find('contacts', $id));
    update_row('contacts', $id, $data);
    $acct['boss'] = $id;
});
test('address book: sites with their own contact, services at a site', function () use (&$acct) {
    $other = create('accounts', ['name' => 'Other Co', 'type' => 'business', 'status' => 'active']);
    $otherContact = create('contacts', ['account_id' => $other, 'name' => 'Nope']);
    $siteContact = create('contacts', ['account_id' => $acct['a'], 'name' => 'Site Sue', 'email' => 'sue@bramble.example']);
    [, $errors] = validate(entity('sites'), ['account_id' => $acct['a'], 'name' => 'Leeds', 'contact_id' => $otherContact]);
    [$data] = validate(entity('sites'), ['account_id' => $acct['a'], 'name' => 'Leeds', 'contact_id' => $otherContact]);
    ok(isset(validate_scoped_refs(entity('sites'), $data)['contact_id']), 'site contact must belong to the customer');
    $acct['leeds'] = create('sites', ['account_id' => $acct['a'], 'name' => 'Leeds depot', 'address' => '5 Canal Rd', 'postcode' => 'ls1 4ap', 'contact_id' => $siteContact]);
    eq('LS1 4AP', db_value('SELECT postcode FROM sites WHERE id = ?', [$acct['leeds']]));
    $acct['svc_leeds'] = create('services', ['account_id' => $acct['a'], 'site_id' => $acct['leeds'], 'identifier' => 'BB-LEEDS-1', 'service_type' => 'broadband', 'carrier' => 'Openreach', 'status' => 'active', 'monthly_price' => '40']);
    $acct['svc_ho'] = create('services', ['account_id' => $acct['a'], 'identifier' => '07700900111', 'service_type' => 'mobile', 'carrier' => 'EE', 'status' => 'active', 'monthly_price' => '15']);
    [$data] = validate(entity('services'), ['account_id' => $other, 'site_id' => $acct['leeds'], 'identifier' => 'X', 'status' => 'active']);
    ok(isset(validate_scoped_refs(entity('services'), $data)['site_id']), 'site must belong to the customer');
    ok(isset(ref_options('sites', $acct['a'])[$acct['leeds']]));
    ok(!isset(ref_options('sites', $other)[$acct['leeds']]));
    eq(1, (int)find('sites', $acct['leeds'])['_services']);
});
test('marketing preferences need a source and an email; changes are timestamped', function () use (&$acct) {
    $row = find('contacts', $acct['boss']);
    [$data] = validate(entity('contacts'), ['marketing_email' => '1', 'marketing_topics' => ['newsletter', 'bogus']] + $row);
    ok(isset(validate_rules('contacts', $data, $acct['boss'])['marketing_source']));
    eq('newsletter', $data['marketing_topics'], 'unknown topics dropped');
    [$data] = validate(entity('contacts'), ['marketing_email' => '1', 'marketing_source' => 'verbal', 'marketing_topics' => ['newsletter']] + $row);
    eq([], validate_rules('contacts', $data, $acct['boss']));
    update_row('contacts', $acct['boss'], $data);
    ok(db_value('SELECT marketing_updated_at FROM contacts WHERE id = ?', [$acct['boss']]) !== null);
    [$data] = validate(entity('contacts'), ['marketing_email' => '1', 'marketing_source' => 'verbal', 'email' => ''] + $row);
    ok(isset(validate_rules('contacts', $data, $acct['boss'])['marketing_email']));
    eq('Newsletter', export_value(entity('contacts'), 'marketing_topics', find('contacts', $acct['boss'])));
});
test('staff cannot close a customer by editing it', function () use (&$acct) {
    as_role('staff');
    $a = find('accounts', $acct['a']);
    [$data] = validate(entity('accounts'), ['status' => 'churned'] + account_contact_values($a) + $a);
    ok(isset(validate_rules('accounts', $data, $acct['a'])['status']));
    as_role('manager');
    eq([], array_intersect_key(validate_rules('accounts', $data, $acct['a']), ['status' => 1]), 'managers can close directly');
    as_role('super_admin');
});
test('close request: approve closes the customer, ceases services and is audited', function () use (&$acct) {
    db_exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Staff Sam', 'staff@example.com', 'x', 'staff')");
    $staff = (int)db()->lastInsertId();
    db_exec('INSERT INTO approval_requests (type, account_id, account_label, reason, options, requested_by) VALUES (?, ?, ?, ?, ?, ?)',
        ['close_account', $acct['a'], 'Bramble Dental', 'Moved to another provider', json_encode(['cease_services' => true]), $staff]);
    $req = (int)db()->lastInsertId();
    eq($req, (int)pending_request_for($acct['a'])['id']);
    eq(1, pending_approvals_count());
    $msg = execute_account_action('close_account', db_one('SELECT * FROM accounts WHERE id = ?', [$acct['a']]), 'Moved to another provider', ['cease_services' => true], $req);
    ok(str_contains($msg, '2 services marked as ceased'), $msg);
    $a = db_one('SELECT * FROM accounts WHERE id = ?', [$acct['a']]);
    eq('churned', $a['status']); ok($a['closed_at'] !== null); eq('Moved to another provider', $a['closed_reason']);
    eq(0, (int)db_value("SELECT COUNT(*) FROM services WHERE account_id = ? AND status <> 'ceased'", [$acct['a']]));
    $log = db_one("SELECT * FROM audit_log WHERE action = 'account_close' ORDER BY id DESC LIMIT 1");
    eq($acct['a'], (int)$log['account_id']);
    eq(['status', 'active', 'churned'], audit_changes($log['changes'])[0]);
    // Re-opening clears the closure details
    $row = find('accounts', $acct['a']);
    [$data] = validate(entity('accounts'), ['status' => 'active'] + account_contact_values($row) + $row);
    update_row('accounts', $acct['a'], $data);
    eq(null, db_value('SELECT closed_reason FROM accounts WHERE id = ?', [$acct['a']]));
    db_exec("UPDATE approval_requests SET status = 'approved' WHERE id = ?", [$req]);
    db_exec("UPDATE services SET status = 'active' WHERE account_id = ?", [$acct['a']]);
});
test('delete via approval keeps the request and the audit trail', function () {
    $id = create('accounts', ['name' => 'Doomed Ltd', 'type' => 'business', 'status' => 'prospect', 'main_name' => 'Dee', 'billing_same' => '1']);
    db_exec('INSERT INTO approval_requests (type, account_id, account_label, reason, requested_by) VALUES (?, ?, ?, ?, 1)', ['delete_account', $id, 'Doomed Ltd', 'Duplicate']);
    $req = (int)db()->lastInsertId();
    execute_account_action('delete_account', db_one('SELECT * FROM accounts WHERE id = ?', [$id]), 'Duplicate', [], $req);
    eq(null, db_value('SELECT id FROM accounts WHERE id = ?', [$id]));
    eq(null, db_value('SELECT account_id FROM approval_requests WHERE id = ?', [$req]), 'request kept, customer link cleared');
    eq('Doomed Ltd', db_value('SELECT account_label FROM approval_requests WHERE id = ?', [$req]));
    ok((int)db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'delete' AND account_id = ?", [$id]) === 1, 'history of a deleted customer is kept');
});
test('audit entries link to the customer and record before/after values', function () use (&$acct) {
    audit('update', 'test', 'services', $acct['svc_leeds'], null, ['Status' => ['from' => 'Active', 'to' => 'Ceased']]);
    $log = db_one('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
    eq($acct['a'], (int)$log['account_id']);
    eq([['Status', 'Active', 'Ceased']], audit_changes($log['changes']));
    $snap = record_snapshot('accounts', entity('accounts'), $acct['a']);
    eq('Bramble Dental', $snap['Name']);
    eq('Pat Accounts', $snap['Accounts contact']);
    eq('Closed / churned', export_value(entity('accounts'), 'status', ['status' => 'churned']));
    ok(str_contains(audit_changes_text(json_encode(['Role' => ['removed' => 'a', 'added' => 'b']])), 'removed: a'));
});

echo "Service alerts & marketing\n";
test('service alert audience: by service type, postcode and who at the customer', function () use (&$acct) {
    db_exec('UPDATE contacts SET service_alerts = 1 WHERE account_id = ?', [$acct['a']]);
    $f = campaign_filters(['service_types' => ['broadband']], 'service_alert');
    $emails = array_column(array_filter(campaign_audience('service_alert', $f), fn($r) => $r['account_id'] === $acct['a']), 'email');
    sort($emails);
    eq(['boss@bramble.example', 'sue@bramble.example'], $emails, 'main contact + Leeds site contact');
    $f['who'] = 'main';
    eq(['boss@bramble.example'], array_column(array_filter(campaign_audience('service_alert', $f), fn($r) => $r['account_id'] === $acct['a']), 'email'));
    $f = campaign_filters(['postcodes' => 'LS1', 'who' => 'main_site'], 'service_alert');
    $aud = campaign_audience('service_alert', $f);
    eq([$acct['a']], array_values(array_unique(array_column($aud, 'account_id'))), 'only customers with a service at an LS1 site');
    eq(['BB-LEEDS-1'], array_column($aud[0]['services'], 'identifier'), 'only the affected service listed');
    $f = campaign_filters(['postcodes' => 'M1 2', 'service_types' => ['mobile']], 'service_alert');
    eq(['07700900111'], array_column(campaign_audience('service_alert', $f)[0]['services'] ?? [], 'identifier'), 'head office postcode used when no site');
    db_exec('UPDATE contacts SET service_alerts = 0 WHERE email = ?', ['sue@bramble.example']);
    $f = campaign_filters(['service_types' => ['broadband'], 'account_id' => $acct['a']], 'service_alert');
    eq(['boss@bramble.example'], array_column(campaign_audience('service_alert', $f), 'email'), 'opted-out contacts skipped');
    db_exec('UPDATE contacts SET service_alerts = 1 WHERE email = ?', ['sue@bramble.example']);
    $f = campaign_filters(['service_types' => ['sip_trunk'], 'account_id' => $acct['a']], 'service_alert');
    eq([], campaign_audience('service_alert', $f), 'no matching live services, no email');
});
test('marketing audience: opted in, topic, live product filter', function () use (&$acct) {
    $f = campaign_filters([], 'marketing');
    $emails = array_column(campaign_audience('marketing', $f), 'email');
    ok(in_array('boss@bramble.example', $emails, true));
    ok(!in_array('sue@bramble.example', $emails, true), 'not opted in');
    ok(in_array('boss@bramble.example', array_column(campaign_audience('marketing', campaign_filters(['topic' => 'newsletter'], 'marketing')), 'email'), true));
    ok(!in_array('boss@bramble.example', array_column(campaign_audience('marketing', campaign_filters(['topic' => 'events_webinars'], 'marketing')), 'email'), true), 'not interested in events');
    ok(in_array('boss@bramble.example', array_column(campaign_audience('marketing', campaign_filters(['service_types' => ['mobile']], 'marketing')), 'email'), true));
    ok(!in_array('boss@bramble.example', array_column(campaign_audience('marketing', campaign_filters(['service_types' => ['leased_line']], 'marketing')), 'email'), true));
});
test('merge fields, safe HTML, unsubscribe links and problems', function () {
    $c = ['kind' => 'marketing', 'channel' => 'email', 'subject' => 'Hi', 'body' => "Hello {{first_name}} at {{company}}\n\nSee https://example.com/offer. <script>x</script>\n\n{{services}}"];
    $html = campaign_html($c, ['name' => 'Sam Lee', 'account_name' => 'A & B', 'account_number' => 'ACC-1', 'contact_id' => 7, 'services' => [['identifier' => 'L1', 'service_type' => 'broadband', 'site_name' => 'Leeds']]]);
    ok(str_contains($html, 'Hello Sam at A &amp; B'));
    ok(str_contains($html, '<a href="https://example.com/offer">'), 'link');
    ok(!str_contains($html, '<script>'), 'escaped');
    ok(str_contains($html, '<li>L1 – Broadband at Leeds</li>'));
    ok(str_contains($html, 'unsubscribe.php?c=7&amp;w=marketing&amp;t=' . unsubscribe_token(7, 'marketing')));
    ok(unsubscribe_token(7, 'marketing') !== unsubscribe_token(7, 'alerts') && unsubscribe_token(7, 'marketing') !== unsubscribe_token(8, 'marketing'));
    eq([], campaign_content_problems($c));
    ok(count(campaign_content_problems(['channel' => 'mailchimp'] + $c)) === 1, '{{services}} not possible with Mailchimp');
    ok(count(campaign_content_problems(['body' => '{{nope}}'] + $c)) === 1);
    ok(str_contains(mailchimp_merge_tags('{{first_name}} {{company}}'), '*|FNAME|* *|COMPANY|*'));
});
test('service alert is sent by email in batches, with unsubscribe headers, and audited', function () use (&$acct) {
    set_setting('mail_transport', 'smtp');
    db_exec('INSERT INTO campaigns (kind, channel, subject, body, filters, created_by) VALUES (?, ?, ?, ?, ?, 1)',
        ['service_alert', 'email', 'Planned maintenance in Leeds', "Hi {{first_name}},\n\n{{services}}\n\nThanks", json_encode(campaign_filters(['postcodes' => 'LS1'], 'service_alert'))]);
    $id = (int)db()->lastInsertId();
    db_exec('UPDATE campaigns SET reference = ? WHERE id = ?', [sprintf('MSG-%05d', $id), $id]);
    set_setting('campaign_batch_size', '1');
    $before = count(sent_mails());
    $msg = campaign_start(db_one('SELECT * FROM campaigns WHERE id = ?', [$id]));
    ok(str_contains($msg, '1 still to go'), $msg);
    eq('sending', db_value('SELECT status FROM campaigns WHERE id = ?', [$id]));
    try { campaign_start(db_one('SELECT * FROM campaigns WHERE id = ?', [$id])); throw new Exception('sent twice'); } catch (IntegrationException) {}
    eq(0, campaigns_process_queue() - 1, 'cron sends the rest');
    usleep(300000);
    $c = db_one('SELECT * FROM campaigns WHERE id = ?', [$id]);
    eq('sent', $c['status']); eq(2, (int)$c['sent_count']); eq(2, (int)$c['recipients']);
    $mails = array_slice(sent_mails(), $before);
    eq(2, count($mails));
    ok(str_contains($mails[0], 'List-Unsubscribe: <https://crm.example.co.uk/crm/unsubscribe.php?c='));
    ok(str_contains($mails[0], 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'));
    ok(str_contains(mail_body($mails[0]), 'BB-LEEDS-1'));
    ok(str_contains(mail_body($mails[0]), 'Stop service alert emails'));
    eq(1, (int)db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'campaign_send' AND entity_id = ?", [$id]));
    set_setting('campaign_batch_size', null);
});
test('unsubscribing: link token, marketing and alerts separately, audited', function () use (&$acct) {
    ok(marketing_unsubscribe($acct['boss'], 'marketing', 'unsubscribe link'));
    $c = db_one('SELECT * FROM contacts WHERE id = ?', [$acct['boss']]);
    eq(0, (int)$c['marketing_email']); ok($c['unsubscribed_at'] !== null); eq('unsubscribed', $c['marketing_source']);
    eq(1, (int)$c['service_alerts'], 'still gets service alerts');
    ok(!in_array('boss@bramble.example', array_column(campaign_audience('marketing', campaign_filters([], 'marketing')), 'email'), true));
    marketing_unsubscribe($acct['boss'], 'alerts', 'unsubscribe link');
    eq(0, (int)db_value('SELECT service_alerts FROM contacts WHERE id = ?', [$acct['boss']]));
    eq(2, (int)db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'unsubscribe' AND entity_id = ?", [$acct['boss']]));
    // Opting back in clears the unsubscribe date
    $row = find('contacts', $acct['boss']);
    [$data] = validate(entity('contacts'), ['marketing_email' => '1', 'marketing_source' => 'email', 'service_alerts' => '1'] + $row);
    update_row('contacts', $acct['boss'], $data);
    eq(null, db_value('SELECT unsubscribed_at FROM contacts WHERE id = ?', [$acct['boss']]));
});

$mcState = sys_get_temp_dir() . '/crm_mc_' . getmypid() . '.json';
$mcPort = 14000 + getmypid() % 1000;
$mcProc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$mcPort", APP_ROOT . '/tests/mailchimp_mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p3, null, ['MOCK_STATE' => $mcState] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $mcPort); $i++) {
    usleep(100000);
}
$cfg = &config_ref();
$cfg['mailchimp_base'] = "http://127.0.0.1:$mcPort/3.0";
$cfg['mandrill_url'] = "http://127.0.0.1:$mcPort/api/1.0/messages/send.json";
function mc_state(): array { global $mcState; return json_decode(file_get_contents($mcState), true); }

test('Mailchimp: key check, audience list, bad key explained', function () {
    eq(['list123' => 'Customers (0 contacts)'], mailchimp_lists('mc-test-key-us21'));
    try { mailchimp_lists('wrong-key-us21'); throw new Exception('expected failure'); } catch (MailchimpException $e) { ok(str_contains($e->getMessage(), 'API Key Invalid'), $e->getMessage()); }
    unset(config_ref()['mailchimp_base']);
    try { mailchimp_base('no-datacentre'); throw new Exception('expected failure'); } catch (MailchimpException) {}
    eq('https://us21.api.mailchimp.com/3.0', mailchimp_base('abc-us21'));
    global $mcPort;
    config_ref()['mailchimp_base'] = "http://127.0.0.1:$mcPort/3.0";
});
test('Mailchimp: marketing campaign synced to the audience, segmented and sent', function () use (&$acct) {
    set_setting('mailchimp_api_key', 'mc-test-key-us21');
    set_setting('mailchimp_list_id', 'list123');
    ok(mailchimp_configured());
    db_exec('INSERT INTO campaigns (kind, channel, subject, body, filters, created_by) VALUES (?, ?, ?, ?, ?, 1)',
        ['marketing', 'mailchimp', 'Autumn offers', "Hi {{first_name}} at {{company}}", json_encode(campaign_filters(['account_id' => $acct['a']], 'marketing'))]);
    $id = (int)db()->lastInsertId();
    db_exec('UPDATE campaigns SET reference = ? WHERE id = ?', [sprintf('MSG-%05d', $id), $id]);
    $msg = campaign_start(db_one('SELECT * FROM campaigns WHERE id = ?', [$id]));
    ok(str_contains($msg, 'Sent to Mailchimp for 1 recipient'), $msg);
    $st = mc_state();
    ok(in_array('COMPANY', $st['merge_fields'], true) && in_array('ACCNO', $st['merge_fields'], true), 'merge fields created');
    $member = $st['members'][md5('boss@bramble.example')];
    eq('subscribed', $member['status']); eq('Bramble Dental', $member['merge_fields']['COMPANY']);
    $seg = array_values($st['segments'])[0];
    eq(['boss@bramble.example'], $seg['emails']);
    $mc = $st['campaigns']['cmp1'];
    eq('sent', $mc['status']);
    eq('Autumn offers', $mc['settings']['subject_line']);
    ok(str_contains($mc['html'], 'Hi *|FNAME|* at *|COMPANY|*'));
    $c = db_one('SELECT * FROM campaigns WHERE id = ?', [$id]);
    eq('sent', $c['status']); eq('cmp1', $c['mailchimp_id']); eq(1, (int)$c['sent_count']);
});
test('Mailchimp: unsubscribes flow back; unsubscribed members are not resubscribed', function () use (&$acct, &$mcState) {
    $st = mc_state();
    $st['members'][md5('boss@bramble.example')]['status'] = 'unsubscribed';
    file_put_contents($mcState, json_encode($st));
    eq(1, mailchimp_sync_unsubscribes());
    eq(0, (int)db_value('SELECT marketing_email FROM contacts WHERE id = ?', [$acct['boss']]));
    ok(setting('mailchimp_unsub_since') !== null);
    eq('unsubscribed', mailchimp_upsert_member('list123', ['email' => 'boss@bramble.example', 'name' => 'New Boss', 'account_name' => 'B', 'account_number' => 'A']));
});
test('Mailchimp Transactional sends one-to-one email and reports rejections', function () {
    set_setting('mail_transport', 'mandrill');
    set_setting('mandrill_api_key', 'md-test-key');
    send_mail('someone@example.com', 'Some One', 'Hello', '<p>Hi</p>', null, ['List-Unsubscribe' => '<https://x/unsub>']);
    $m = end(mc_state()['mandrill']);
    eq('someone@example.com', $m['to'][0]['email']); eq('Hello', $m['subject']); eq('sales@example.co.uk', $m['from_email']);
    eq('<https://x/unsub>', $m['headers']['List-Unsubscribe']);
    try { send_mail('x@rejected.example', 'X', 'S', '<p>b</p>'); throw new Exception('expected failure'); } catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'hard-bounce')); }
    set_setting('mandrill_api_key', 'bad');
    try { send_mail('a@example.com', 'A', 'S', '<p>b</p>'); throw new Exception('expected failure'); } catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'Invalid API key')); }
    set_setting('mail_transport', 'smtp');
});
proc_terminate($mcProc);
@unlink($mcState);
foreach (['mailchimp_api_key', 'mailchimp_list_id'] as $k) { set_setting($k, null); }

proc_terminate($sinkProc);
proc_terminate($sgProc);
@unlink($sgState);
array_map('unlink', glob("$smtpDir/*") ?: []);
exec('rm -rf ' . escapeshellarg($cfg['storage_path']));
@rmdir($smtpDir);

echo "Security\n";
test('secrets are encrypted at rest and decrypted transparently', function () {
    set_setting('smtp_password', 'hunter2-very-secret');
    $raw = db_value("SELECT value FROM settings WHERE name = 'smtp_password'");
    ok(str_starts_with($raw, 'enc:v1:') && !str_contains($raw, 'hunter2'), 'stored encrypted');
    eq('hunter2-very-secret', setting('smtp_password'));
    $tampered = substr($raw, 0, -4) . (substr($raw, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
    eq(null, decrypt_secret($tampered), 'tampering detected');
    eq('plain', decrypt_secret('plain'), 'legacy plain values still readable');
    db_exec("UPDATE settings SET value = 'legacy-plain' WHERE name = 'smtp_password'");
    set_setting('schema_version', '5');
    migrate();
    ok(str_starts_with((string)db_value("SELECT value FROM settings WHERE name = 'smtp_password'"), 'enc:v1:'), 'migration encrypts old values');
    eq('legacy-plain', setting('smtp_password'));
});
test('TOTP matches the RFC 6238 test vectors, allows drift, blocks replay', function () {
    $secret = base32_encode('12345678901234567890');
    eq('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
    eq('287082', totp_code($secret, intdiv(59, 30)));
    eq('081804', totp_code($secret, intdiv(1111111109, 30)));
    eq('005924', totp_code($secret, intdiv(1234567890, 30)));
    $now = 1234567890;
    $step = totp_verify($secret, '005924', 0, $now);
    eq(intdiv($now, 30), $step);
    ok(totp_verify($secret, totp_code($secret, intdiv($now, 30) - 1), 0, $now) !== null, 'previous code accepted (clock drift)');
    eq(null, totp_verify($secret, totp_code($secret, intdiv($now, 30) - 3), 0, $now), 'old code rejected');
    eq(null, totp_verify($secret, '005924', $step, $now), 'same code can\'t be used twice');
    eq(null, totp_verify($secret, 'abcdef', 0, $now));
    eq('12345678901234567890', base32_decode($secret));
});
test('recovery codes work once each', function () {
    [$codes, $hashes] = make_recovery_codes();
    eq(10, count($codes));
    $left = use_recovery_code($hashes, strtolower(str_replace('-', '', $codes[3])));
    ok($left !== null, 'accepted without dash, any case');
    eq(9, count(json_decode($left, true)));
    eq(null, use_recovery_code($left, $codes[3]), 'can\'t reuse');
});
test('sign-in locks after repeated failures and unlocks on success', function () {
    db_exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Lock Test', 'lock@example.com', ?, 'agent')", [password_hash('Correct-horse-9', PASSWORD_DEFAULT)]);
    db_exec('DELETE FROM login_attempts');
    for ($n = 0; $n < LOGIN_MAX_PER_EMAIL; $n++) {
        eq('invalid', attempt_login('lock@example.com', 'wrong')['status']);
    }
    $r = attempt_login('lock@example.com', 'Correct-horse-9');
    eq('locked', $r['status'], 'correct password refused while locked');
    ok($r['minutes'] >= 1 && $r['minutes'] <= LOGIN_WINDOW_MINUTES);
    db_exec('UPDATE login_attempts SET attempted_at = NOW() - INTERVAL 20 MINUTE');
    eq('ok', attempt_login('lock@example.com', 'Correct-horse-9')['status'], 'unlocked after the window');
    eq(0, (int)db_value("SELECT COUNT(*) FROM login_attempts WHERE email = 'lock@example.com' AND success = 0"), 'failures cleared');
    eq('invalid', attempt_login('nobody@example.com', 'x')['status'], 'unknown email just fails');
    ok((int)db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'login_failed'") >= 6, 'failures audited');
    ok((int)db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'login_locked'") >= 1, 'lockout audited');
});
test('two-factor sign-in: password then code', function () {
    $secret = base32_encode(random_bytes(20));
    db_exec("UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = 0 WHERE email = 'lock@example.com'", [encrypt_secret($secret)]);
    unset($_SESSION['user_id']);
    eq('2fa', attempt_login('lock@example.com', 'Correct-horse-9')['status']);
    ok(empty($_SESSION['user_id']), 'not signed in yet');
    eq('invalid', verify_second_factor('000000')['status']);
    eq('ok', verify_second_factor(totp_code($secret, intdiv(time(), 30)))['status']);
    eq((int)db_value("SELECT id FROM users WHERE email = 'lock@example.com'"), $_SESSION['user_id']);
    $_SESSION['pending_2fa'] = ['user_id' => $_SESSION['user_id'], 'email' => 'lock@example.com', 'at' => time() - 600];
    eq('expired', verify_second_factor(totp_code($secret, intdiv(time(), 30)))['status'], 'code step times out');
    $_SESSION['user_id'] = 1;
});
test('password rules', function () {
    ok(password_problem('short') !== null);
    ok(password_problem('password12') !== null, 'common word');
    ok(password_problem('aaaaaaaaaaaa') !== null, 'repeated');
    ok(password_problem('jsmith-rocks-99', 'jsmith@example.com') !== null, 'contains email name');
    eq(null, password_problem('orange-kettle-river'));
});

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
