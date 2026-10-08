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
test('ticket gets reference, SLA from priority, and a group from its category', function () use (&$acc, &$svc, &$ticket) {
    $ticket = create('tickets', ['account_id' => $acc, 'service_id' => $svc, 'subject' => 'No sync', 'category' => 'fault', 'priority' => 'P1', 'status' => 'open']);
    $row = find('tickets', $ticket);
    eq(sprintf('TCK-%06d', $ticket), $row['reference']);
    $hours = (strtotime($row['sla_due_at']) - strtotime($row['created_at'])) / 3600;
    ok(abs($hours - 4) < 0.02, "P1 SLA should be 4h, got $hours");
    eq('Faults', $row['group_id__label'], 'fault tickets go to the Faults group');
    eq(null, $row['assigned_to'], 'grouped tickets wait in the queue');
    db_exec("UPDATE ticket_groups SET categories = NULL WHERE name = 'General'");
    $own = create('tickets', ['account_id' => $acc, 'subject' => 'Ungrouped', 'category' => 'general', 'priority' => 'P3', 'status' => 'open']);
    eq(1, find('tickets', $own)['assigned_to'], 'without a group, the person who logs it gets it');
    eq(null, find('tickets', $own)['group_id']);
    db_exec("UPDATE ticket_groups SET categories = 'general' WHERE name = 'General'");
});
test('ticket groups: queue in arrival order, pick up once, only see your groups', function () use (&$acc) {
    $faults = (int)db_value("SELECT id FROM ticket_groups WHERE name = 'Faults'");
    $billing = (int)db_value("SELECT id FROM ticket_groups WHERE name = 'Billing'");
    db_exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Queue Quinn', 'quinn@example.com', 'x', 'support')");
    $quinn = (int)db()->lastInsertId();
    db_exec('INSERT INTO ticket_group_members (group_id, user_id) VALUES (?, ?)', [$faults, $quinn]);
    $old = create('tickets', ['account_id' => $acc, 'subject' => 'Older fault', 'category' => 'fault', 'priority' => 'P3', 'status' => 'open']);
    db_exec('UPDATE tickets SET created_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = ?', [$old]);
    $new = create('tickets', ['account_id' => $acc, 'subject' => 'Newer fault', 'category' => 'fault', 'priority' => 'P1', 'status' => 'open']);
    $bill = create('tickets', ['account_id' => $acc, 'subject' => 'Invoice query', 'category' => 'billing', 'priority' => 'P3', 'status' => 'open']);
    eq($billing, (int)find('tickets', $bill)['group_id']);

    $_SESSION['user_id'] = $quinn;
    current_user(true);
    user_group_ids(null, true);
    ok(!can('tickets.all'));
    $queue = array_map('intval', array_column(ticket_queue(), 'id'));
    eq($old, $queue[0], 'oldest first');
    ok(in_array($new, $queue, true) && !in_array($bill, $queue, true), 'only my groups');
    $visible = array_map('intval', array_column(list_rows('tickets', ['per_page' => 0])['rows'], 'id'));
    ok(in_array($old, $visible, true) && !in_array($bill, $visible, true), 'ticket lists are limited to my groups');
    ok(!can_see_ticket(find('tickets', $bill)) && can_see_ticket(find('tickets', $old)));
    ok(ticket_queue_count() >= 2);
    ok(ticket_pick_up($old));
    $t = find('tickets', $old);
    eq($quinn, (int)$t['assigned_to']); eq('in_progress', $t['status']);
    ok(!ticket_pick_up($old), 'cannot be picked up twice');
    ok(!ticket_pick_up($bill), 'cannot pick up another group\'s ticket');
    ok(str_contains((string)db_value('SELECT body FROM ticket_comments WHERE ticket_id = ? ORDER BY id DESC LIMIT 1', [$old]), 'picked up'));

    $_SESSION['user_id'] = 1;
    current_user(true);
    user_group_ids(null, true);
    ok(can('tickets.all'));
    ok(in_array($bill, array_map('intval', array_column(list_rows('tickets', ['per_page' => 0])['rows'], 'id')), true), 'admins see every group');
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
/** Press Reconnect: authorise again with the scopes now asked for, and approve them. */
function xero_mock_reconnect(): void
{
    $redirect = 'https://crm.example.com/crm/xero-callback.php';
    [, , $headers] = xero_http('GET', xero_authorize_url('state123', $redirect));
    parse_str(parse_url($headers['location'], PHP_URL_QUERY), $back);
    xero_exchange_code($back['code'], $redirect);
}

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
    eq(db_value("SELECT contact_id FROM xero_contacts WHERE name = 'Supplier 4'"), db_value("SELECT merged_to FROM xero_contacts WHERE name = 'Supplier 3'"), 'merged contacts remember where they went');
    ok(str_contains(implode(' ', mock_state()['calls']), 'includeArchived=true'), 'archived contacts are fetched too');
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

test('customers can be added from Xero contacts, with company details, people and the Xero link', function () {
    $names = array_column(xero_importable_contacts(), 'name');
    eq(['Brand New Bakery Ltd', 'Greenfield Farm Supplies', 'Jo Bloggs'], $names, 'Xero customers not yet in the CRM');
    ok(count(xero_importable_contacts(false)) > 100, 'all contacts on request');
    eq(['Brand New Bakery Ltd'], array_column(xero_importable_contacts(false, 'bakery'), 'name'), 'search');

    $ids = array_column(array_filter(xero_importable_contacts(), fn($c) => $c['name'] !== 'Greenfield Farm Supplies'), 'id');
    // Jo's account number is added in Xero after the last sync: adding fetches the contact afresh.
    global $mockState;
    $st = mock_state();
    $st['contact_changes'][db_value("SELECT contact_id FROM xero_contacts WHERE name = 'Jo Bloggs'")] = ['AccountNumber' => 'BLOG001'];
    file_put_contents($mockState, json_encode($st));
    $r = xero_create_customers($ids);
    eq([], $r['no_number'], 'both have account numbers in Xero');
    eq(2, count($r['created']));
    $a = db_one('SELECT * FROM accounts WHERE id = ?', [$r['created'][0]]);
    eq(['BNB01', 'Brand New Bakery Ltd', 'business', 'active', 'accounts@bakery.example', '0113 496 0123', '01234567', '3 Oven Street', 'Leeds', 'West Yorkshire', 'LS2 2BB', (int)$ids[0]],
        [$a['account_number'], $a['name'], $a['type'], $a['status'], $a['email'], $a['phone'], $a['company_number'], $a['address'], $a['city'], $a['county'], $a['postcode'], (int)$a['xero_contact_id']]);
    ok(str_contains((string)$a['notes'], 'GB 111 2222 33'), 'VAT number kept in the notes');
    $main = db_one('SELECT * FROM contacts WHERE id = ?', [$a['main_contact_id']]);
    eq(['Pat Baker', 'accounts@bakery.example', '07700 900123', 1, 1], [$main['name'], $main['email'], $main['mobile'] ?: $main['phone'], (int)$main['is_primary'], (int)$main['is_billing']]);
    eq(['Robin Flour'], array_column(db_all('SELECT name FROM contacts WHERE account_id = ? AND id <> ?', [$a['id'], $main['id']]), 'name'), 'other people added as contacts');
    $jo = db_one('SELECT * FROM accounts WHERE id = ?', [$r['created'][1]]);
    eq(['residential', 'BLOG001'], [$jo['type'], $jo['account_number']], 'a person is a residential customer, with the account number now in Xero');

    eq(['Greenfield Farm Supplies'], array_column(xero_importable_contacts(), 'name'), 'gone from the list once added');
    $again = xero_create_customers($ids);
    eq([[], 2], [$again['created'], count($again['skipped'])], 'never added twice');
});

test('customers already in the CRM can take their Xero account numbers', function () {
    $linked = db_all('SELECT a.id, a.account_number, a.xero_contact_id FROM accounts a WHERE a.xero_contact_id IS NOT NULL ORDER BY a.id LIMIT 3');
    ok(count($linked) === 3, 'three linked customers to try');
    [$one, $two, $three] = $linked;
    db_exec('UPDATE xero_contacts SET account_number = NULL WHERE id IN (?, ?, ?)', [$one['xero_contact_id'], $two['xero_contact_id'], $three['xero_contact_id']]);
    db_exec("UPDATE xero_contacts SET account_number = ' HARB01 ' WHERE id = ?", [$one['xero_contact_id']]);
    db_exec('UPDATE xero_contacts SET account_number = ? WHERE id = ?', [$three['account_number'], $two['xero_contact_id']]); // another customer's number
    $changes = array_column(xero_account_number_changes(), null, 'id');
    eq(['HARB01', null], [$changes[$one['id']]['to'], $changes[$one['id']]['problem']]);
    ok(str_starts_with((string)$changes[$two['id']]['problem'], 'already used by'), 'never takes another customer\'s number');
    eq(1, xero_adopt_account_numbers());
    eq('HARB01', db_value('SELECT account_number FROM accounts WHERE id = ?', [$one['id']]));
    eq($two['account_number'], db_value('SELECT account_number FROM accounts WHERE id = ?', [$two['id']]));
    eq(0, xero_adopt_account_numbers(), 'nothing left to change');
    // The number is on another Xero contact of the same name (archived), so Xero won't put it on the linked one.
    db_exec('UPDATE xero_contacts SET account_number = NULL WHERE id = ?', [$two['xero_contact_id']]);
    $twoName = db_value('SELECT name FROM xero_contacts WHERE id = ?', [$two['xero_contact_id']]);
    db_exec("INSERT INTO xero_contacts (contact_id, name, account_number, status) VALUES ('e0000000-0000-0000-0000-000000000001', ?, 'OLD002', 'ARCHIVED')", [$twoName]);
    $row = array_column(xero_account_number_changes(), null, 'id')[$two['id']];
    eq(['OLD002', true, null], [$row['to'], $row['source']['archived'], $row['problem']]);
    eq(1, xero_adopt_account_numbers());
    eq('OLD002', db_value('SELECT account_number FROM accounts WHERE id = ?', [$two['id']]));
    db_exec('UPDATE accounts SET account_number = ? WHERE id = ?', [$two['account_number'], $two['id']]);
    db_exec("DELETE FROM xero_contacts WHERE contact_id = 'e0000000-0000-0000-0000-000000000001'");
    // Merged in Xero: the old contact (a different name, archived) keeps the number and points at the one kept.
    $twoContact = db_value('SELECT contact_id FROM xero_contacts WHERE id = ?', [$two['xero_contact_id']]);
    db_exec("INSERT INTO xero_contacts (contact_id, name, account_number, status, merged_to) VALUES ('e0000000-0000-0000-0000-000000000002', 'Old Trading Name Ltd', NULL, 'ARCHIVED', ?)", [$twoContact]);
    db_exec("INSERT INTO xero_contacts (contact_id, name, account_number, status, merged_to) VALUES ('e0000000-0000-0000-0000-000000000003', 'Even Older Name', 'MRG003', 'ARCHIVED', 'e0000000-0000-0000-0000-000000000002')");
    $row = array_column(xero_account_number_changes(), null, 'id')[$two['id']];
    eq(['MRG003', true], [$row['to'], $row['source']['merged']], 'found through a chain of merges');
    eq(1, xero_adopt_account_numbers());
    eq('MRG003', db_value('SELECT account_number FROM accounts WHERE id = ?', [$two['id']]));
    db_exec('UPDATE accounts SET account_number = ? WHERE id = ?', [$two['account_number'], $two['id']]);
    db_exec("DELETE FROM xero_contacts WHERE contact_id IN ('e0000000-0000-0000-0000-000000000002', 'e0000000-0000-0000-0000-000000000003')");
    // One given a number in Xero since the last sync is found when refreshing.
    global $mockState;
    $st = mock_state();
    $st['contact_changes'][db_value('SELECT contact_id FROM xero_contacts WHERE id = ?', [$three['xero_contact_id']])] = ['AccountNumber' => 'COPP001'];
    file_put_contents($mockState, json_encode($st));
    eq(0, xero_adopt_account_numbers(), 'not seen without refreshing');
    eq(1, xero_adopt_account_numbers(true));
    eq('COPP001', db_value('SELECT account_number FROM accounts WHERE id = ?', [$three['id']]));
    db_exec('UPDATE accounts SET account_number = ? WHERE id = ?', [$three['account_number'], $three['id']]);
    unset($st['contact_changes']);
    file_put_contents($mockState, json_encode($st));
    db_exec('UPDATE accounts SET account_number = ? WHERE id = ?', [$one['account_number'], $one['id']]);
    db_exec('UPDATE xero_contacts SET account_number = NULL WHERE id IN (?, ?)', [$one['xero_contact_id'], $two['xero_contact_id']]);
});

test('only roles allowed to open Xero get links to it; others still see the balance', function () {
    as_role('staff');
    ok(can('finance.view') && !can('xero.open'), 'staff see balances but not Xero itself');
    eq('Harbour', xero_link('https://go.xero.com/x', 'Harbour'), 'just the text');
    foreach (['finance', 'manager', 'admin'] as $role) {
        as_role($role);
        ok(str_contains(xero_link('https://go.xero.com/x', 'Harbour'), 'href="https://go.xero.com/x"'), "$role can open Xero");
    }
    as_role('super_admin');
});

test('customer details changed in Xero come into the CRM, without undoing CRM edits', function () {
    global $mockState;
    $xid = (int)db_value("SELECT id FROM xero_contacts WHERE contact_id = 'c1000000-0000-0000-0000-000000000001'");
    $id = (int)db_value('SELECT id FROM accounts WHERE xero_contact_id = ?', [$xid]);
    ok($id > 0, 'a linked customer');
    $orig = db_one('SELECT name, email, phone, billing_contact_id, main_contact_id FROM accounts WHERE id = ?', [$id]);
    $origContact = db_one('SELECT id, email FROM contacts WHERE id = ?', [$orig['billing_contact_id'] ?: ($orig['main_contact_id'] ?: 0)]);

    // On request: differences listed, ticked customers updated.
    db_exec("UPDATE accounts SET name = 'Harbour View Dental (old name)' WHERE id = ?", [$id]);
    $diff = array_column(xero_customer_differences(), null, 'id')[$id] ?? null;
    eq(['Harbour View Dental (old name)', 'Harbour View Dental Ltd'], [$diff['changes']['name']['from'], $diff['changes']['name']['to']]);
    eq(1, xero_update_customers_from_xero([$id]));
    $a = db_one('SELECT * FROM accounts WHERE id = ?', [$id]);
    eq('Harbour View Dental Ltd', $a['name']);
    eq(strtolower((string)db_value('SELECT email FROM xero_contacts WHERE id = ?', [$xid])),
        db_value('SELECT email FROM contacts WHERE id = ?', [$a['billing_contact_id'] ?: $a['main_contact_id']]), 'accounts contact email from Xero');
    ok(!isset(array_column(xero_customer_differences(), null, 'id')[$id]), 'matches Xero now');

    // Automatically on sync: only what changed in Xero is copied; an edit made in the CRM stays.
    set_setting('xero_update_customers', '1');
    db_exec("UPDATE accounts SET phone = '01632 960000' WHERE id = ?", [$id]);
    $st = mock_state();
    $st['contact_changes']['c1000000-0000-0000-0000-000000000001'] = ['Name' => 'Harbour View Dental Group Ltd', 'EmailAddress' => 'finance@harbour.example.co.uk',
        'Phones' => [['PhoneType' => 'DEFAULT', 'PhoneNumber' => '0113 000 0000']]];
    file_put_contents($mockState, json_encode($st));
    xero_sync(); // first sync sees the new phone too: Xero changed it
    $a = db_one('SELECT * FROM accounts WHERE id = ?', [$id]);
    eq(['Harbour View Dental Group Ltd', 'finance@harbour.example.co.uk', '0113 000 0000'], [$a['name'], $a['email'], $a['phone']]);
    db_exec("UPDATE accounts SET phone = '01632 960000' WHERE id = ?", [$id]);
    $s = xero_sync(); // nothing changed in Xero this time
    eq([0, '01632 960000'], [$s['updated'], db_value('SELECT phone FROM accounts WHERE id = ?', [$id])], 'CRM edit kept');

    set_setting('xero_update_customers', null);
    unset($st['contact_changes']['c1000000-0000-0000-0000-000000000001']);
    file_put_contents($mockState, json_encode($st));
    xero_sync();
    db_exec('UPDATE accounts SET name = ?, email = ?, phone = ? WHERE id = ?', [$orig['name'], $orig['email'], $orig['phone'], $id]);
    if ($origContact) {
        db_exec('UPDATE contacts SET email = ? WHERE id = ?', [$origContact['email'], $origContact['id']]);
    } else {
        db_exec('UPDATE accounts SET main_contact_id = NULL, billing_contact_id = NULL WHERE id = ?', [$id]);
        db_exec("DELETE FROM contacts WHERE account_id = ? AND name = 'Accounts'", [$id]);
    }
});

test('suppliers are brought in from Xero: same name linked, others added with details, archived skipped', function () {
    eq(1, (int)db_value("SELECT COUNT(*) FROM suppliers WHERE name = 'Giacom'"), 'Giacom is the first supplier');
    eq(['created' => 1, 'linked' => 1, 'updated' => 1], xero_import_suppliers());
    $giacom = db_one("SELECT s.*, x.name AS xname FROM suppliers s JOIN xero_contacts x ON x.id = s.xero_contact_id WHERE s.name = 'Giacom'");
    eq(['Giacom Limited', 'billing@giacom.example'], [$giacom['xname'], $giacom['email']], 'linked by name, blank email filled');
    $s1 = db_one("SELECT * FROM suppliers WHERE name = 'Supplier 1'");
    eq(['accounts@supplier1.example', 'Sam Vendor', '0161 496 0000', '1 Mill Lane', 'Unit 4', 'Bolton', 'Lancs', 'BL1 1AA', '30 days after the bill date', 'www.supplier1.example'],
        [$s1['accounts_email'], $s1['contact_name'], $s1['phone'], $s1['address'], $s1['address2'], $s1['city'], $s1['county'], $s1['postcode'], $s1['payment_terms'], $s1['website']]);
    ok(!db_value("SELECT 1 FROM suppliers WHERE name = 'Supplier 3'"), 'archived supplier skipped');
    ok(!db_value("SELECT 1 FROM suppliers WHERE name = 'Supplier 4'"), 'contacts not marked as suppliers skipped');
    db_exec("UPDATE suppliers SET phone = '0800 000' WHERE id = ?", [$s1['id']]);
    eq(['created' => 0, 'linked' => 0, 'updated' => 0], xero_import_suppliers(), 'safe to re-run; details typed in the CRM are kept');
    eq('0800 000', db_value('SELECT phone FROM suppliers WHERE id = ?', [$s1['id']]));
});

test('bill lines are nominal coded: product, then supplier default, then the bills default; codes are checked', function () {
    // Account codes may hold symbols, up to Xero's 10 characters; tax types are short codes.
    eq(null, xero_code_problem('1/500/5000', 'account'));
    ok(xero_code_problem('1/500/50000', 'account') !== null, 'longer than Xero allows');
    eq(null, xero_code_problem('INPUT2', 'tax'));
    ok(xero_code_problem('1/500/5000', 'tax') !== null, 'not a tax type');

    $sid = create('suppliers', ['name' => 'Coding Test Telecom', 'purchase_account_code' => '320']);
    $pid = create('products', ['sku' => 'CODE-T1', 'name' => 'Desk phone', 'category' => array_key_first(SERVICE_TYPES), 'monthly_price' => '100',
        'term_months' => '12', 'purchase_account_code' => '630']);
    create('supplier_products', ['supplier_id' => $sid, 'product_id' => $pid, 'supplier_sku' => 'YEA-T54W', 'description' => 'Yealink T54W', 'cost_price' => '80', 'billing_frequency' => 'monthly']);
    db_exec("INSERT INTO supplier_invoices (supplier_id, status, invoice_number, net, vat, total, line_items, file_name, stored_name, size) VALUES (?, 'approved', 'CT-1', 100, 20, 120, ?, 'x.pdf', 'x.pdf', 1)",
        [$sid, json_encode([['description' => 'YEA-T54W Yealink T54W handset', 'quantity' => 1, 'net_amount' => 80], ['description' => 'Courier', 'quantity' => 1, 'net_amount' => 20]])]);
    $bill = xero_bill_payload(db_one('SELECT * FROM supplier_invoices WHERE id = ?', [(int)db()->lastInsertId()]));
    eq(['630', '320'], array_column($bill['LineItems'], 'AccountCode'));
});

test('bills carry each line\'s own VAT: product rates, rates read off the invoice, reverse charge', function () {
    eq(['20', '5', '0', '0', 'RC', 'RC', 'exempt', '17.5', null, '20', null], array_map('invoice_vat_rate', ['20%', 'VAT 5%', '0.00%', 'Zero', 'Reverse charge', 'R/C', 'Exempt', '17.5%', 'Router', '20', '2.00']));
    ok(invoice_is_reverse_charge('Customer to account for VAT to HMRC'), 'domestic reverse charge wording');
    ok(!invoice_is_reverse_charge('VAT @ 20%'), 'ordinary invoice');

    $sid = create('suppliers', ['name' => 'Mixed VAT Wholesale']);
    $pid = create('products', ['sku' => 'VAT-T1', 'name' => 'Training course', 'category' => array_key_first(SERVICE_TYPES), 'monthly_price' => '100',
        'term_months' => '12', 'purchase_tax_type' => 'EXEMPTEXPENSES']);
    create('supplier_products', ['supplier_id' => $sid, 'product_id' => $pid, 'description' => 'Training course', 'cost_price' => '50', 'billing_frequency' => 'monthly']);
    $mk = function (array $lines, int $reverse = 0) use ($sid) {
        db_exec("INSERT INTO supplier_invoices (supplier_id, status, invoice_number, net, vat, total, reverse_charge, line_items, file_name, stored_name, size) VALUES (?, 'approved', 'MV', ?, 0, 0, ?, ?, 'x.pdf', 'x.pdf', 1)",
            [$sid, array_sum(array_column($lines, 'net_amount')), $reverse, json_encode($lines)]);
        return db_one('SELECT * FROM supplier_invoices WHERE id = ?', [(int)db()->lastInsertId()]);
    };
    $line = fn($d, $n, $r) => ['description' => $d, 'quantity' => 1, 'net_amount' => $n, 'vat_rate' => $r];

    // Standard, zero-rated and the product's own (exempt) rate on one bill.
    $bill = xero_bill_payload($mk([$line('Handsets', 100, '20'), $line('Postage stamps', 10, '0'), $line('Training course', 50, null)]));
    eq(['INPUT2', 'ZERORATEDINPUT', 'EXEMPTEXPENSES'], array_column($bill['LineItems'], 'TaxType'));

    // A reverse charge line needs its tax type chosen first.
    $rc = $mk([$line('Wholesale minutes', 200, 'RC'), $line('Handsets', 100, '20')]);
    try { xero_bill_payload($rc); throw new Exception('expected'); } catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'reverse charge'), $e->getMessage()); }
    set_setting('xero_bill_rate_map', json_encode(['RC' => 'DRCHARGE20']));
    eq(['DRCHARGE20', 'INPUT2'], array_column(xero_bill_payload($rc)['LineItems'], 'TaxType'));
    eq('RRINPUT', xero_bill_rate_map()['5'], 'other rates keep their defaults');

    // A whole invoice under the reverse charge, with no rates per line; and a supplier's default VAT.
    eq(['DRCHARGE20'], array_column(xero_bill_payload($mk([$line('Data services', 300, null)], 1))['LineItems'], 'TaxType'));
    db_exec("UPDATE suppliers SET purchase_tax_type = 'ZERORATEDINPUT' WHERE id = ?", [$sid]);
    eq(['ZERORATEDINPUT', 'INPUT2'], array_column(xero_bill_payload($mk([$line('Overseas licence', 40, null), $line('Handsets', 100, '20')]))['LineItems'], 'TaxType'));
    set_setting('xero_bill_rate_map', null);
});

test('approved supplier invoices go to Xero as bills, with the uploaded invoice attached', function () {
    as_role('super_admin');
    $sup = (int)db_value("SELECT id FROM suppliers WHERE name = 'Supplier 1'");
    db_exec('INSERT INTO purchase_orders (supplier_id, status, total, created_by) VALUES (?, ?, 0, 1)', [$sup, 'sent']);
    $poId = (int)db()->lastInsertId();
    db_exec('UPDATE purchase_orders SET reference = ? WHERE id = ?', [sprintf('PO-%06d', $poId), $poId]);
    po_save_lines($poId, [['supplier_product_id' => null, 'sku' => 'SW-1', 'description' => 'Switch', 'quantity' => 2, 'unit_cost' => 18.25]]);
    $file = storage_path('invoices') . '/xtest.pdf';
    file_put_contents($file, '%PDF-1.4 bill');
    db_exec("INSERT INTO supplier_invoices (supplier_id, po_id, status, invoice_number, invoice_date, due_date, net, vat, total, currency, file_name, stored_name, mime, size)
        VALUES (?, ?, 'approved', 'S1-77', '2026-10-01', '2026-10-31', 36.51, 7.30, 43.81, 'GBP', 'S1 invoice 77.pdf', 'xtest.pdf', 'application/pdf', 13)", [$sup, $poId]);
    $id = (int)db()->lastInsertId();

    eq('offline_access accounting.contacts.read accounting.invoices accounting.attachments', xero_bill_scopes(XERO_DEFAULT_SCOPES));
    eq('offline_access accounting.contacts.read accounting.transactions accounting.attachments', xero_bill_scopes('offline_access accounting.contacts.read accounting.transactions.read'));
    ok(!xero_can_write_bills());
    try { xero_post_bill($id); throw new Exception('expected'); } catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'Reconnect')); }
    set_setting('xero_scopes', xero_bill_scopes(XERO_DEFAULT_SCOPES));
    xero_mock_reconnect();
    set_setting('xero_push_bills', '1');
    ok(xero_bills_enabled());

    $xid = xero_post_bill($id);
    $st = mock_state();
    $bill = $st['bills'][$xid];
    $xc = db_value('SELECT x.contact_id FROM suppliers s JOIN xero_contacts x ON x.id = s.xero_contact_id WHERE s.id = ?', [$sup]);
    eq(['ACCPAY', $xc, 'S1-77', sprintf('PO-%06d', $poId), '2026-10-01', '2026-10-31', 'DRAFT', 'Exclusive'],
        [$bill['Type'], $bill['Contact']['ContactID'], $bill['InvoiceNumber'], $bill['Reference'], $bill['Date'], $bill['DueDate'], $bill['Status'], $bill['LineAmountTypes']]);
    eq([['SW-1 Switch', 2, 18.25, '310', 'INPUT2'], ['Rounding', 1, 0.01, '310', 'INPUT2']],
        array_map(fn($l) => [$l['Description'], $l['Quantity'], $l['UnitAmount'], $l['AccountCode'], $l['TaxType']], $bill['LineItems']), 'PO lines plus the penny difference');
    $att = end($st['attachments']);
    eq([$xid, 'S1 invoice 77.pdf', 'application/pdf', hash('sha256', '%PDF-1.4 bill')], [$att['invoice'], $att['name'], $att['type'], $att['sha']]);
    $row = db_one('SELECT * FROM supplier_invoices WHERE id = ?', [$id]);
    eq([$xid, 1, null], [$row['xero_invoice_id'], (int)$row['xero_attached'], $row['xero_error']]);

    // Sending again updates the same bill and doesn't attach a second copy.
    $count = count($st['attachments']);
    eq($xid, xero_post_bill($id));
    eq($count, count(mock_state()['attachments']));
    eq($xid, mock_state()['bills'][$xid]['InvoiceID']);

    // Without a PO: one line for the net amount; only a gross total: sent VAT-inclusive.
    db_exec('UPDATE supplier_invoices SET po_id = NULL, xero_invoice_id = NULL, xero_attached = 0 WHERE id = ?', [$id]);
    $p = xero_bill_payload(db_one('SELECT * FROM supplier_invoices WHERE id = ?', [$id]));
    eq([['Invoice S1-77', 1, 36.51]], array_map(fn($l) => [$l['Description'], $l['Quantity'], $l['UnitAmount']], $p['LineItems']));
    db_exec('UPDATE supplier_invoices SET net = NULL, vat = NULL WHERE id = ?', [$id]);
    $p = xero_bill_payload(db_one('SELECT * FROM supplier_invoices WHERE id = ?', [$id]));
    eq(['Inclusive', 43.81], [$p['LineAmountTypes'], $p['LineItems'][0]['UnitAmount']]);

    // Xero's validation errors are reported and kept on the invoice.
    db_exec('UPDATE supplier_invoices SET xero_invoice_id = ? WHERE id = ?', [$xid, $id]);
    $st = mock_state(); $st['bills'][$xid]['Status'] = 'PAID'; file_put_contents($GLOBALS['mockState'], json_encode($st));
    try { xero_post_bill($id); throw new Exception('expected'); } catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'Paid bills cannot be changed'), $e->getMessage()); }
    ok(str_contains((string)db_value('SELECT xero_error FROM supplier_invoices WHERE id = ?', [$id]), 'Paid bills'));
    set_setting('xero_push_bills', null);
    set_setting('xero_scopes', null);
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
    xero_mock_reconnect();
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

$abState = sys_get_temp_dir() . '/crm_abillity_' . getmypid() . '.json';
$abPort = 19000 + getmypid() % 1000;
$abProc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$abPort", APP_ROOT . '/tests/abillity_mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p8, null, ['MOCK_STATE' => $abState] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $abPort); $i++) {
    usleep(100000);
}
function ab_state(): array { global $abState; return json_decode((string)@file_get_contents($abState), true) ?: []; }
function ab_set(array $changes): void { global $abState; file_put_contents($abState, json_encode($changes + ab_state())); }

test('aBILLity: customers go to aBILLity and Xero together; products and services follow, with the real start date once live', function () use ($abPort) {
    as_role('super_admin');
    $cfg = &config_ref();
    $cfg['abillity_url'] = "http://127.0.0.1:$abPort/api";
    ok(!abillity_configured());
    try { abillity_api('GET', 'common/frequencytype', null, [], ['system' => 'TESTSYS', 'username' => 'api-user', 'password' => 'wrong']); throw new Exception('expected failure'); }
    catch (AbillityException $e) { ok(str_contains($e->getMessage(), '(401)') && str_contains($e->getMessage(), 'Admin → aBILLity'), $e->getMessage()); }
    foreach (['abillity_system' => 'TESTSYS', 'abillity_username' => 'api-user', 'abillity_password' => 'api-pass', 'abillity_auto' => '1'] as $k => $v) {
        set_setting($k, $v);
    }
    ok(abillity_configured() && abillity_auto());
    set_setting('xero_scopes', xero_write_scopes(XERO_DEFAULT_SCOPES));
    xero_mock_reconnect();

    // A prospect isn't set up for billing; a customer is, in aBILLity and Xero together.
    $acct = create('accounts', ['name' => 'Brook Farm Supplies Ltd', 'account_number' => 'BROO001', 'type' => 'business', 'status' => 'prospect',
        'address' => 'Brook Farm', 'address2' => 'Mill Lane', 'city' => 'Ledbury', 'county' => 'Herefordshire', 'postcode' => 'HR8 1AA', 'phone' => '01531 000000', 'company_number' => '01234567']);
    $contact = create('contacts', ['account_id' => $acct, 'name' => 'Bella Brook', 'email' => 'accounts@brookfarm.example', 'phone' => '01531 000001', 'is_billing' => '1']);
    db_exec('UPDATE accounts SET billing_contact_id = ?, main_contact_id = ? WHERE id = ?', [$contact, $contact, $acct]);
    eq(['', true], abillity_after_save('accounts', $acct, ['Status' => []], true));
    eq([], ab_state()['companies'] ?? [], 'prospects aren\'t sent');
    db_exec("UPDATE accounts SET status = 'active' WHERE id = ?", [$acct]);
    [$msg, $okSave] = abillity_after_save('accounts', $acct, ['Status' => []], false);
    ok($okSave && str_contains($msg, 'Sent to aBILLity'), $msg);
    $a = db_one('SELECT * FROM accounts WHERE id = ?', [$acct]);
    ok($a['abillity_company_id'] && $a['abillity_site_id'] && !$a['abillity_pending'] && !$a['abillity_error'], 'linked: ' . $a['abillity_error']);
    $st = ab_state();
    $site = $st['sites'][$a['abillity_site_id']];
    eq(['Brook Farm Supplies Ltd', 'BROO001', 'BROO001', 'Brook Farm, Mill Lane', 'Ledbury', 'Herefordshire', 'HR8 1AA', true],
        [$site['SiteName'], $site['ShortName'], $site['AccountRef'], $site['Address'], $site['Town'], $site['County'], $site['PostCode'], $site['MainSite']]);
    ok($st['companies'][$a['abillity_company_id']]['IsCustomer'] && !$st['companies'][$a['abillity_company_id']]['IsProspect'], 'a customer, not a prospect');
    $abContact = (int)db_value('SELECT abillity_contact_id FROM contacts WHERE id = ?', [$contact]);
    eq(['Bella', 'Brook', 'accounts@brookfarm.example', true], [$st['contacts'][$abContact]['Christian'], $st['contacts'][$abContact]['Surname'], $st['contacts'][$abContact]['Email'], $st['contacts'][$abContact]['MainContact']]);
    eq(['BillingContactId' => $abContact, 'Email' => 'accounts@brookfarm.example'], $st['billing'][$a['abillity_site_id']], 'invoices go to the accounts contact');
    // ...and in Xero, with the same account number.
    ok($a['xero_contact_id'] > 0, 'linked to Xero');
    $x = end(mock_state()['created']);
    eq(['Brook Farm Supplies Ltd', 'BROO001', 'accounts@brookfarm.example', 'Bella', 'Brook', '01234567', 'Ledbury', 'HR8 1AA'],
        [$x['Name'], $x['AccountNumber'], $x['EmailAddress'], $x['FirstName'], $x['LastName'], $x['CompanyNumber'], $x['Addresses'][0]['City'], $x['Addresses'][0]['PostalCode']]);

    // Changes are sent again, without making another company or Xero contact.
    db_exec("UPDATE accounts SET name = 'Brook Farm Supplies Limited' WHERE id = ?", [$acct]);
    db_exec("UPDATE contacts SET email = 'invoices@brookfarm.example' WHERE id = ?", [$contact]);
    abillity_push_customer($acct);
    $st = ab_state();
    eq(1, count($st['companies']));
    eq('Brook Farm Supplies Limited', $st['sites'][$a['abillity_site_id']]['SiteName']);
    eq('invoices@brookfarm.example', $st['contacts'][$abContact]['Email'], 'the same contact updated');
    eq(1, count(mock_state()['created']), 'not created in Xero twice');

    // A customer already in aBILLity (by account reference) is linked, not duplicated.
    $st = ab_state();
    $st['companies'][9001] = ['Id' => 9001, 'Name' => 'Old Mill Bakery', 'IsCustomer' => true];
    $st['sites'][9002] = ['Id' => 9002, 'CompanyId' => 9001, 'ShortName' => 'Old Mill', 'AccountRef' => 'OLDM001', 'MainSite' => true];
    file_put_contents($GLOBALS['abState'], json_encode($st));
    $old = create('accounts', ['name' => 'Old Mill Bakery', 'account_number' => 'OLDM001', 'type' => 'business', 'status' => 'active']);
    eq(9002, abillity_push_customer($old));
    eq([9001, 9002], array_map('intval', array_values(db_one('SELECT abillity_company_id, abillity_site_id FROM accounts WHERE id = ?', [$old]))));
    eq(2, count(ab_state()['companies']));

    // A user who can't set the account reference: everything else is sent, and it says what to do.
    ab_set(['no_cp' => true]);
    $nocp = create('accounts', ['name' => 'Cedar Joinery', 'account_number' => 'CEDA001', 'type' => 'business', 'status' => 'active']);
    abillity_push_customer($nocp);
    ok(str_contains((string)db_value('SELECT abillity_error FROM accounts WHERE id = ?', [$nocp]), 'CEDA001'), 'told to add the reference by hand');
    ab_set(['no_cp' => false]);

    // Products become service charge types; one already there with that name is linked.
    $broadband = create('products', ['sku' => 'AB-FTTP', 'name' => 'Business Fibre 500', 'category' => 'broadband', 'monthly_price' => '120', 'cost_price' => '75',
        'billing_frequency' => 'quarterly', 'term_months' => '24', 'sales_account_code' => '200']);
    $typeId = abillity_push_product($broadband);
    $t = ab_state()['types'][$typeId];
    eq(['Business Fibre 500', 4, 120, 75, true, '200'], [$t['RecurringChargeType'], $t['FrequencyTypeId'], $t['DefaultSalePrice'], $t['DefaultCost'], $t['Rental'], $t['Nominal']], 'price per quarter');
    $install = create('products', ['sku' => 'AB-INST', 'name' => 'Engineer installation', 'category' => 'other', 'monthly_price' => '150', 'billing_frequency' => 'one_off', 'term_months' => '0']);
    $iid = abillity_push_product($install);
    $t = ab_state()['types'][$iid];
    eq([3, false], [$t['FrequencyTypeId'], $t['Rental']], 'one-off, not a rental');
    $weekly = create('products', ['sku' => 'AB-WK', 'name' => 'Weekly thing', 'category' => 'other', 'monthly_price' => '12', 'billing_frequency' => 'weekly', 'term_months' => '1']);
    $wid = abillity_push_product($weekly);
    $t = ab_state()['types'][$wid];
    eq([2, 52.0], [$t['FrequencyTypeId'], (float)$t['DefaultSalePrice']], 'weekly sent as monthly');
    $st = ab_state(); $st['types'][7777] = ['Id' => 7777, 'RecurringChargeType' => 'SIP Trunk']; file_put_contents($GLOBALS['abState'], json_encode($st));
    $sip = create('products', ['sku' => 'AB-SIP', 'name' => 'SIP Trunk', 'category' => 'voip', 'monthly_price' => '8', 'term_months' => '12']);
    eq(7777, abillity_push_product($sip), 'linked to the existing charge type');
    eq(8, (int)ab_state()['types'][7777]['DefaultSalePrice'], 'and brought up to date');

    // A service goes in when added, with a provisional start date while it's pending.
    $svc = create('services', ['account_id' => $acct, 'product_id' => $broadband, 'service_type' => 'broadband', 'identifier' => 'TBC – Business Fibre 500',
        'status' => 'pending', 'monthly_price' => '40', 'setup_fee' => '99', 'term_months' => '24']);
    [$msg, $okSave] = abillity_after_save('services', $svc, [], true);
    ok($okSave, $msg);
    $s = db_one('SELECT * FROM services WHERE id = ?', [$svc]);
    ok($s['abillity_charge_id'] && $s['abillity_setup_charge_id'] && $s['abillity_provisional'] && !$s['abillity_pending'], 'sent: ' . $s['abillity_error']);
    $provisional = date('Y-m-d', strtotime('+30 days'));
    eq($provisional, $s['abillity_first_payment']);
    $charges = ab_state()['charges'];
    $c = $charges[$s['abillity_charge_id']];
    eq([$a['abillity_site_id'], $typeId, 4, 120, 1, true, $provisional . 'T00:00:00', 'Business Fibre 500 – TBC – Business Fibre 500'],
        [$c['SiteId'], $c['ChargeId'], $c['FrequencyTypeId'], (int)$c['SalesPrice'], $c['Quantity'], $c['Rental'], $c['FirstPayment'], $c['Description']], '£40 a month billed quarterly');
    ok(str_starts_with($c['Notes'], "CRM service #$svc;") && str_contains($c['Notes'], '24 month term'));
    $setup = $charges[$s['abillity_setup_charge_id']];
    eq([3, 99, false], [$setup['FrequencyTypeId'], (int)$setup['SalesPrice'], $setup['Rental']], 'setup fee as a one-off');

    // Live: the number and the real start date are sent.
    db_exec("UPDATE services SET status = 'active', identifier = '01531 222333', start_date = '2026-09-14' WHERE id = ?", [$svc]);
    abillity_after_save('services', $svc, ['Status' => []], false);
    $s = db_one('SELECT * FROM services WHERE id = ?', [$svc]);
    eq(['2026-09-14', 0], [$s['abillity_first_payment'], (int)$s['abillity_provisional']]);
    $charges = ab_state()['charges'];
    eq(['2026-09-14T00:00:00', '01531 222333'], [$charges[$s['abillity_charge_id']]['FirstPayment'], $charges[$s['abillity_charge_id']]['SerialNo']]);
    eq('2026-09-14T00:00:00', $charges[$s['abillity_setup_charge_id']]['FirstPayment'], 'setup billed when it goes live too');
    eq(2, count($charges), 'updated, not added again');

    // Ceased: billing ends.
    db_exec("UPDATE services SET status = 'ceased' WHERE id = ?", [$svc]);
    abillity_push_service($svc);
    eq(date('Y-m-d') . 'T00:00:00', ab_state()['charges'][$s['abillity_charge_id']]['LastPayment']);

    // Cancelled before it went live: flagged, since aBILLity has a charge from the provisional date.
    $early = create('services', ['account_id' => $acct, 'product_id' => $sip, 'service_type' => 'voip', 'identifier' => 'SIP-1', 'status' => 'pending', 'monthly_price' => '8']);
    abillity_push_service($early);
    db_exec("UPDATE services SET status = 'ceased' WHERE id = ?", [$early]);
    abillity_queue_service($early);
    ok(str_contains((string)db_value('SELECT abillity_error FROM services WHERE id = ?', [$early]), 'remove service charge'), 'told to remove it');

    // Sent, but its ID can't be found afterwards: never sent again (that would bill twice), and it says so.
    ab_set(['hide_charges' => true]);
    $lost = create('services', ['account_id' => $acct, 'service_type' => 'mobile', 'identifier' => '07700 900199', 'status' => 'active', 'monthly_price' => '9', 'start_date' => '2026-09-01']);
    abillity_push_service($lost);
    $before = count(ab_state()['charges']);
    abillity_push_service($lost);
    eq($before, count(ab_state()['charges']), 'not added twice');
    $l = db_one('SELECT abillity_charge_id, abillity_pending, abillity_error FROM services WHERE id = ?', [$lost]);
    ok((int)$l['abillity_charge_id'] === 0 && !$l['abillity_pending'] && str_contains((string)$l['abillity_error'], 'by hand'), json_encode($l));
    ab_set(['hide_charges' => false]);
    // Active with no start date entered: billed from today, not a provisional date.
    $nodate = create('services', ['account_id' => $acct, 'service_type' => 'mobile', 'identifier' => '07700 900198', 'status' => 'active', 'monthly_price' => '9']);
    abillity_push_service($nodate);
    eq([date('Y-m-d'), 0], array_values(array_map(fn($v) => is_numeric($v) ? (int)$v : $v, db_one('SELECT abillity_first_payment, abillity_provisional FROM services WHERE id = ?', [$nodate]))));

    // Services already on the CRM before aBILLity was connected are billed there already: not sent automatically.
    $older = create('services', ['account_id' => $acct, 'service_type' => 'mobile', 'identifier' => '07700 900100', 'status' => 'active', 'monthly_price' => '10', 'start_date' => '2025-01-01']);
    db_exec("UPDATE services SET created_at = '2025-01-01' WHERE id = ?", [$older]);
    set_setting('abillity_connected_at', '2026-01-01 00:00:00');
    abillity_queue_service($older);
    eq([null, 0], array_values(array_map(fn($v) => $v === null ? null : (int)$v, db_one('SELECT abillity_charge_id, abillity_pending FROM services WHERE id = ?', [$older]))), 'left alone');
    abillity_push_service($older);
    ok(db_value('SELECT abillity_charge_id FROM services WHERE id = ?', [$older]) > 0, 'but can be sent with the button');

    // Not sending straight away: queued, then sent by the cron job. Problems are kept and listed.
    set_setting('abillity_auto', '0');
    $later = create('services', ['account_id' => $old, 'service_type' => 'mobile', 'identifier' => '07700 900123', 'status' => 'active', 'monthly_price' => '15', 'start_date' => '2026-09-01']);
    abillity_queue_service($later);
    eq(1, (int)db_value('SELECT abillity_pending FROM services WHERE id = ?', [$later]));
    $bad = create('services', ['account_id' => $old, 'service_type' => 'mobile', 'identifier' => '07700 900124', 'status' => 'active', 'monthly_price' => '15', 'start_date' => '2026-09-01']);
    db_exec('UPDATE services SET product_id = ? WHERE id = ?', [$sip, $bad]);
    db_exec('UPDATE products SET abillity_charge_type_id = 424242 WHERE id = ?', [$sip]);
    abillity_queue_service($bad);
    $r = abillity_push_pending();
    ok($r['sent'] >= 1);
    ok(db_value('SELECT abillity_charge_id FROM services WHERE id = ?', [$later]) > 0, 'sent by the sync');
    ok(isset($r['failed']['07700 900124']) && str_contains($r['failed']['07700 900124'], 'ChargeId'), json_encode($r['failed']));
    eq(1, (int)db_value('SELECT abillity_pending FROM services WHERE id = ?', [$bad]), 'kept to try again');
    ok(in_array($bad, array_map('intval', array_column(abillity_problems()['services'], 'id')), true), 'listed under Admin → aBILLity');

    foreach (['abillity_system', 'abillity_username', 'abillity_password', 'abillity_auto', 'abillity_connected_at', 'xero_scopes'] as $k) {
        set_setting($k, null);
    }
    db_exec('UPDATE accounts SET xero_contact_id = NULL WHERE id IN (?, ?, ?)', [$acct, $old, $nocp]);
});

test('products are sent to Xero as items: one, several, validation problems, updates', function () {
    ok(!xero_can_write_items(), 'not allowed by default');
    try { xero_push_products([1]); throw new Exception('expected failure'); } catch (XeroException $e) { ok(str_contains($e->getMessage(), 'Reconnect')); }
    eq('offline_access accounting.contacts.read accounting.invoices.read accounting.settings', xero_item_scopes(XERO_DEFAULT_SCOPES));
    eq('offline_access accounting.settings', xero_item_scopes('offline_access accounting.settings.read'));
    set_setting('xero_scopes', xero_item_scopes(XERO_DEFAULT_SCOPES));
    // Ticked but not reconnected yet: Xero hasn't granted it, so the CRM says so instead of getting a 401.
    ok(!xero_can_write_items(), 'not usable until Xero grants it');
    ok(in_array('accounting.settings', xero_scopes_pending(), true), 'listed as waiting for Reconnect');
    xero_mock_reconnect();
    eq([], xero_scopes_pending());
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
proc_terminate($abProc);
@unlink($abState);

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

test('customers that do not match can be linked by hand; likely matches listed first', function () use (&$gcIds) {
    $acct = create('accounts', ['name' => 'Bright Smile Dental', 'type' => 'business', 'status' => 'active']);
    // A GoCardless customer set up under a personal email and a different name.
    db_exec("INSERT INTO gocardless_customers (customer_id, name, email, mandate_status) VALUES ('CUX1', 'Dr Priya Shah', 'priya.shah@gmail.example', 'active')");
    $gcId = (int)db()->lastInsertId();
    eq(0, gc_auto_link(), 'no automatic match');
    $list = gc_unlinked_customers(db_one('SELECT * FROM accounts WHERE id = ?', [$acct]));
    ok(in_array('CUX1', array_column($list, 'customer_id'), true));
    ok(!in_array((int)db_value('SELECT gocardless_customer_id FROM accounts WHERE id = ?', [$gcIds['harbour']]), array_map('intval', array_column($list, 'id')), true), 'already-linked customers left out');
    // Adding her email to a contact makes it a likely match (and auto-links on the next sync).
    create('contacts', ['account_id' => $acct, 'name' => 'Priya Shah', 'email' => 'Priya.Shah@gmail.example']);
    $list = gc_unlinked_customers(db_one('SELECT * FROM accounts WHERE id = ?', [$acct]));
    eq(['CUX1', true], [$list[0]['customer_id'], $list[0]['likely']]);
    gc_link_account($acct, $gcId);
    eq($gcId, (int)db_value('SELECT gocardless_customer_id FROM accounts WHERE id = ?', [$acct]));
    try { gc_link_account($gcIds['north'], $gcId); throw new Exception('expected'); } catch (GoCardlessException $e) { ok(str_contains($e->getMessage(), 'already linked to Bright Smile Dental')); }
    db_exec('DELETE FROM accounts WHERE id = ?', [$acct]);
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
test('merges fields split across runs, escapes XML, leaves other braces alone, builds table', function () {
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
    ok(str_contains($out, '{signature:signer1:Customer+Signature}'), 'single-brace text untouched');
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
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $smtpPort); $i++) {
    usleep(100000);
}
$cfg = &config_ref();
$cfg['storage_path'] = sys_get_temp_dir() . '/crm_storage_' . getmypid();
$anthState = sys_get_temp_dir() . '/crm_anth_' . getmypid() . '.json';
$anthPort = 17000 + getmypid() % 1000;
$anthProc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$anthPort", APP_ROOT . '/tests/anthropic_mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p9, null, ['MOCK_STATE' => $anthState] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $anthPort); $i++) {
    usleep(100000);
}
function sent_mails(): array { global $smtpDir; $files = glob("$smtpDir/*.eml") ?: []; sort($files); return array_map('file_get_contents', $files); }
function mail_body(string $raw): string { preg_match_all('/Content-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $raw, $m); return implode("\n", array_map(fn($b) => base64_decode(preg_replace('/\s+/', '', $b)), $m[1])); }

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

test('quote is emailed with a working link, accepted, and the contract is emailed to sign online', function () use (&$flow, &$dealerIds) {
    // Templates: broadband + general
    // Without a Contract Summary template, an agreement for a customer can't be prepared.
    $noSummaryAcct = db_one('SELECT * FROM accounts ORDER BY id LIMIT 1');
    try { contract_generate($noSummaryAcct, [], 'x', 'services', 'A', 'a@example.com'); throw new Exception('expected failure'); }
    catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'Contract Summary template'), $e->getMessage()); }
    $stored = bin2hex(random_bytes(6)) . '.docx';
    docx_example_summary_template(storage_path('templates') . '/' . $stored);
    db_exec("INSERT INTO contract_templates (name, service_type, file_name, stored_name) VALUES ('Contract Summary', 'contract_summary', 'cs.docx', ?)", [$stored]);
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

    $before = count(sent_mails());
    $contract = quote_accept($quote, 'Eve Echo', '203.0.113.9', false, 'Eve@Echo.example');
    usleep(300000);
    eq('accepted', db_value('SELECT status FROM quotes WHERE id = ?', [$qid]));
    ok($contract !== null, 'contract created');
    eq('sent', $contract['status'], 'emailed to sign automatically');
    eq('Eve Echo', $contract['signer_name'], 'the person who accepted signs');
    eq('eve@echo.example', $contract['signer_email'], 'at the email they gave');
    ok(preg_match('/^[a-f0-9]{48}$/', (string)$contract['sign_token']) === 1, 'signing token made');
    $signMail = array_values(array_filter(array_slice(sent_mails(), $before), fn($r) => str_contains($r, 'X-Rcpt: eve@echo.example')));
    ok(str_contains(implode('', array_map('mail_body', $signMail)), 'https://crm.example.co.uk/crm/sign.php?t=' . $contract['sign_token']), 'signing link emailed to the signer');
    $signRaw = implode('', $signMail);
    ok(str_contains($signRaw, 'Contract Summary.docx') && str_contains($signRaw, 'Broadband agreement.docx') && str_contains($signRaw, 'General terms.docx'),
        'the Contract Summary and agreement are attached to the signing email, before anything is signed');
    $q = db_one('SELECT * FROM quotes WHERE id = ?', [$qid]);
    ok(str_contains((string)$q['response_statement'], 'not yet a contract'), 'accepting the quote is a request to go ahead, not the contract');
    $events = array_column(contract_events((int)$contract['id']), 'event');
    eq(['created', 'sent'], $events);
    ok(str_contains((string)db_value("SELECT detail FROM contract_events WHERE contract_id = ? AND event = 'sent'", [$contract['id']]), 'Contract Summary (SHA-256 '), 'what was supplied is fingerprinted');
    eq($contract['id'], contract_by_sign_token($contract['sign_token'])['id']);
    eq(null, contract_by_sign_token(str_repeat('0', 48)));
    $docs = contract_documents($contract);
    eq(['Contract Summary', 'Broadband agreement', 'General terms'], array_column($docs, 'title'), 'the Contract Summary first, then one document per template (mobile uses General)');
    eq('summary', $docs[0]['kind']);
    $z = new ZipArchive(); $z->open(storage_path('contracts') . '/' . $docs[0]['file']); $sx = $z->getFromName('word/document.xml'); $z->close();
    ok(str_contains($sx, 'FTTP 900') && str_contains($sx, '5G SIM') && str_contains($sx, 'Echo Logistics Ltd'), 'the summary covers every service');
    $z = new ZipArchive(); $z->open(storage_path('contracts') . '/' . $docs[1]['file']); $xml = $z->getFromName('word/document.xml'); $z->close();
    ok(str_contains($xml, 'Echo Logistics Ltd') && str_contains($xml, 'FTTP 900') && !str_contains($xml, '5G SIM'), 'broadband doc has only broadband lines');
    ok(str_contains($xml, 'HU1 1AA'), 'address merged');
    $html = docx_to_html(storage_path('contracts') . '/' . $docs[1]['file']);
    ok(str_contains($html, 'Echo Logistics Ltd') && str_contains($html, '<table') && !str_contains($html, '{{'), 'agreement previews as HTML');
    $flow = ['quote' => $qid, 'contract' => (int)$contract['id'], 'account' => $acct];
    ok(str_contains(implode('', array_map('mail_body', array_slice(sent_mails(), $before))), 'accepted'), 'staff notified');
});

test('signing online: email code, typed signature, certificate, copies emailed, pending services created', function () use (&$flow) {
    $c = db_one('SELECT * FROM contracts WHERE id = ?', [$flow['contract']]);
    // The order is enforced: Contract Summary confirmed, then the email code, then signing.
    try { esign_sign($c, 'Eve Echo', '', '203.0.113.9', 'Test'); throw new Exception('expected failure'); }
    catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'Contract Summary'), 'must confirm the Contract Summary first'); }
    try { esign_send_code($c); throw new Exception('expected failure'); }
    catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'Contract Summary'), 'no code before the Contract Summary'); }
    esign_viewed($c, '203.0.113.9', 'Test browser');
    esign_viewed($c, '203.0.113.9', 'Test browser');
    esign_downloaded($c, contract_summary_document($c), '203.0.113.9', 'Test browser');
    esign_confirm_summary($c, '203.0.113.9', 'Test browser');
    $c = db_one('SELECT * FROM contracts WHERE id = ?', [$c['id']]);
    ok($c['summary_ack_at'] && esign_summary_confirmed($c), 'receipt of the Contract Summary recorded');
    try { esign_sign($c, 'Eve Echo', '', '203.0.113.9', 'Test'); throw new Exception('expected failure'); }
    catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'code'), 'must confirm email next'); }
    $before = count(sent_mails());
    esign_send_code($c);
    usleep(300000);
    $raw = array_slice(sent_mails(), $before)[0] ?? '';
    ok(str_contains($raw, 'X-Rcpt: eve@echo.example'), 'code sent to the signer');
    ok(preg_match('/letter-spacing:6px[^>]*>(\d{6})</', mail_body($raw), $m) === 1, 'email contains the code');
    $c = db_one('SELECT * FROM contracts WHERE id = ?', [$c['id']]);
    ok($c['viewed_at'] && $c['code_hash'] && !str_contains($c['code_hash'], $m[1]), 'code stored hashed');
    try { esign_send_code($c); throw new Exception('expected failure'); }
    catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'just sent'), 'can\'t ask again straight away'); }
    $wrong = $m[1] === '000000' ? '111111' : '000000';
    eq(false, esign_verify_code($c, $wrong));
    $c = db_one('SELECT * FROM contracts WHERE id = ?', [$c['id']]);
    eq(1, (int)$c['code_attempts']);
    eq(true, esign_verify_code($c, substr($m[1], 0, 3) . ' ' . substr($m[1], 3)));
    $c = db_one('SELECT * FROM contracts WHERE id = ?', [$c['id']]);
    ok(esign_is_verified($c) && $c['verified_at'] && !$c['code_hash'], 'verified, code used up');

    $before = count(sent_mails());
    $c = esign_sign($c, 'Eve Echo', 'Director', '203.0.113.9', 'Mozilla/5.0 Test');
    usleep(500000);
    eq('signed', $c['status']);
    eq(['Eve Echo', 'Director', '203.0.113.9'], [$c['signed_name'], $c['signed_position'], $c['signed_ip']]);
    ok(str_contains((string)$c['signed_statement'], 'Echo Logistics Ltd'), 'statement recorded');
    $hashes = json_decode((string)$c['document_hashes'], true);
    eq(hash_file('sha256', storage_path('contracts') . '/' . contract_documents($c)[1]['file']), $hashes['Broadband agreement'], 'document fingerprint recorded');
    ok(isset($hashes['Contract Summary']), 'the Contract Summary is fingerprinted too');
    eq(['created', 'sent', 'opened', 'downloaded', 'summary_confirmed', 'code_sent', 'code_wrong', 'code_confirmed', 'signed', 'copies_sent'],
        array_column(contract_events((int)$c['id']), 'event'), 'every step in order (a reload isn\'t counted twice)');
    $pdf = (string)file_get_contents(storage_path('contracts') . '/' . $c['signed_file']);
    ok(str_starts_with($pdf, '%PDF'), 'certificate PDF stored');
    ok(str_contains($pdf, '203.0.113.9') && str_contains($pdf, substr($hashes['General terms'], 0, 32)), 'certificate shows IP and fingerprints');
    ok(str_contains($pdf, 'Receipt of the Contract Summary confirmed') && str_contains($pdf, 'TIMELINE') && strpos($pdf, 'Receipt of the Contract Summary') < strpos($pdf, 'Email address confirmed with the code'),
        'certificate shows the timeline, Contract Summary before signing');
    $copies = array_slice(sent_mails(), $before);
    $toSigner = array_values(array_filter($copies, fn($r) => str_contains($r, 'X-Rcpt: eve@echo.example')));
    ok($toSigner && str_contains($toSigner[0], 'signature certificate.pdf') && str_contains($toSigner[0], '.docx'), 'signer gets the certificate and documents');
    ok((bool)array_filter($copies, fn($r) => str_contains($r, 'X-Rcpt: sales@example.co.uk') && str_contains($r, 'certificate.pdf')), 'we get a copy');
    try { esign_sign($c, 'Eve Echo', '', '1.1.1.1', 'x'); throw new Exception('expected failure'); }
    catch (IntegrationException $e) { ok(true); }
    eq(3, count(contract_services($c)), 'services added as pending on signing: 1 broadband + 2 mobile');
    eq(0, contract_create_services($c), 'not added twice');
    eq(3, (int)db_value("SELECT COUNT(*) FROM services WHERE account_id = ? AND status = 'pending'", [$flow['account']]));
});

/** A small PNG: RGBA (colour type 6) with a transparent left half, or palette-based (type 3). */
function test_png(int $w, int $h, int $type = 6): string
{
    $chunk = fn($t, $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\0"; // no filter
        for ($x = 0; $x < $w; $x++) {
            $raw .= $type === 6 ? "\x46\x5F\xFF" . ($x < $w / 2 ? "\x00" : "\xFF") : chr($x < $w / 2 ? 0 : 1);
        }
    }
    return "\x89PNG\r\n\x1A\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, $type, 0, 0, 0))
        . ($type === 3 ? $chunk('PLTE', "\xFF\xFF\xFF\x46\x5F\xFF") . $chunk('tRNS', "\x00") : '')
        . $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');
}

test('branding: a logo (PNG with transparency or JPEG) and colour on quote PDFs and customer pages', function () {
    $img = SimplePdf::prepareImage(test_png(40, 10));
    eq([40, 10, true], [$img['w'], $img['h'], $img['smask'] !== null], 'transparent PNG gets a soft mask');
    $alpha = gzuncompress($img['smask']['data']);
    eq(["\x00", "\xFF"], [$alpha[0], $alpha[39]]);
    eq("\x46\x5F\xFF", substr(gzuncompress($img['data']), 0, 3));
    $pal = SimplePdf::prepareImage(test_png(8, 2, 3));
    eq(["\x00", "\xFF\xFF\xFF"], [gzuncompress($pal['smask']['data'])[0], substr(gzuncompress($pal['data']), 0, 3)], 'palette PNG with a transparent colour');
    eq(null, SimplePdf::prepareImage('not an image'));

    $tmp = tempnam(sys_get_temp_dir(), 'logo');
    file_put_contents($tmp, 'GIF89a nonsense');
    ok(str_contains((string)brand_save_logo(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => 15]), 'PNG or JPG'));
    file_put_contents($tmp, test_png(120, 30));
    eq(null, brand_save_logo(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize($tmp)]));
    eq('logo.png', setting('brand_logo'));
    ok(str_starts_with((string)brand_logo_data_uri(), 'data:image/png;base64,'));
    set_setting('brand_colour', '#0A7C3E');
    eq([10, 124, 62], brand_colour());

    $q = db_one('SELECT * FROM quotes ORDER BY id DESC LIMIT 1');
    $pdf = quote_pdf($q);
    ok(str_contains($pdf, '/Subtype /Image') && str_contains($pdf, '/SMask') && str_contains($pdf, '/Im1 Do'), 'logo drawn on the quote');
    ok(str_contains($pdf, '0.04 0.49 0.24 rg'), 'brand colour used');

    foreach (glob(storage_path('branding') . '/logo.*') as $f) { unlink($f); }
    set_setting('brand_logo', null);
    set_setting('brand_colour', null);
    @unlink($tmp);
});

test('our address prints tidily however it was typed in Settings', function () {
    $saved = [setting('company_address'), setting('company_name')];
    set_setting('company_address', "Hadley House \r\n9 & 10 Croft Street\r\n \r\nCheltenham\r\nGloucestershire \r\nGL53 0ED");
    eq('Hadley House, 9 & 10 Croft Street, Cheltenham, Gloucestershire, GL53 0ED', company_address_line());
    eq(['Netcomm', 'Hadley House', '9 & 10 Croft Street', 'Cheltenham', 'Gloucestershire', 'GL53 0ED'], explode("\n", (function () {
        set_setting('company_name', 'Netcomm');
        return po_default_delivery(null);
    })()));
    set_setting('company_address', $saved[0]);
    set_setting('company_name', $saved[1]);
});

test('one-off quote lines (e.g. installation) have no term and don\'t become services', function () use (&$flow) {
    eq('One-off (no term)', term_label(0));
    ok(array_key_first(term_options()) === 0, 'offered first in term lists');
    [$lines, $errors] = quote_parse_lines(['line_description' => ['Hosted seat', 'Installation'], 'line_product_id' => ['', ''], 'line_service_type' => ['voip', 'other'],
        'line_quantity' => ['2', '1'], 'line_monthly_price' => ['10', '0'], 'line_setup_fee' => ['0', '150'], 'line_term_months' => ['36', '0']]);
    eq([], $errors);
    $t = quote_totals($lines);
    eq([20.0, 150.0, 870.0, 36], [$t['monthly'], $t['setup'], $t['tcv'], $t['term']], 'installation counted once; term from the ongoing line');
    $qid = (int)db_value('SELECT quote_id FROM contracts WHERE id = ?', [$flow['contract']]);
    $before = (int)db_value('SELECT COUNT(*) FROM quote_lines WHERE quote_id = ?', [$qid]);
    db_exec("INSERT INTO quote_lines (quote_id, service_type, description, quantity, monthly_price, setup_fee, term_months, sort) VALUES (?, 'other', 'Installation', 1, 0, 150, 0, 99)", [$qid]);
    $c = db_one('SELECT * FROM contracts WHERE id = ?', [$flow['contract']]);
    $made = array_column(contract_services($c), 'id');
    db_exec('DELETE FROM services WHERE id IN (' . implode(',', array_map('intval', $made)) . ')');
    eq(3, contract_create_services($c), 'still just the 3 ongoing services');
    db_exec('DELETE FROM quote_lines WHERE quote_id = ? AND sort = 99', [$qid]);
    ok($before > 0);

    // A one-off product: no term, nothing per month, its cost isn't spread over time.
    $pid = create('products', ['sku' => 'INSTALL-1', 'name' => 'On-site installation', 'category' => 'other', 'billing_frequency' => 'one_off',
        'monthly_price' => '150', 'cost_price' => '60', 'term_months' => '24']);
    $p = db_one('SELECT * FROM products WHERE id = ?', [$pid]);
    eq([0, 'One-off'], [(int)$p['term_months'], BILLING_FREQUENCIES[$p['billing_frequency']]]);
    eq(0.0, (float)list_rows('products', ['filters' => [], 'q' => 'INSTALL-1'])['rows'][0]['_monthly']);
    eq(0.0, monthly_equivalent(150, 'one_off'));
    eq(60.0, supplier_cost_for_product(['cost_price' => 60, 'billing_frequency' => 'one_off'], $p));
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

test('Contract Summary: for every customer, or only those Ofcom protects; editable wording', function () use (&$flow) {
    $acct = db_one('SELECT * FROM accounts WHERE id = ?', [$flow['account']]);
    ok(customer_is_protected($acct), 'size not set: treated as protected');
    ok(customer_is_protected(['type' => 'business', 'customer_size' => 'micro']));
    ok(!customer_is_protected(['type' => 'business', 'customer_size' => 'larger']));
    ok(customer_is_protected(['type' => 'residential', 'customer_size' => 'larger']), 'a residential customer is a consumer');
    $larger = ['customer_size' => 'larger', 'type' => 'business'] + $acct;
    ok(contract_summary_required($larger, 'services'), 'by default, everyone gets one');
    ok(!contract_summary_required($acct, 'msa'), 'not for a dealer MSA');
    set_setting('contract_summary_for', 'protected');
    ok(!contract_summary_required($larger, 'services') && contract_summary_required($acct, 'services'));
    $tpl = db_one("SELECT * FROM contract_templates WHERE service_type = 'broadband'");
    $c = contract_generate($larger, [[$tpl, []]], 'Big firm', 'services', 'B', 'b@example.com');
    eq(null, contract_summary_document($c));
    ok(esign_summary_confirmed($c), 'nothing to confirm without one');
    set_setting('contract_summary_for', null);
    set_setting('esign_sign_statement', 'Signed for {customer}, as approved by our solicitor.');
    eq('Signed for ' . $acct['name'] . ', as approved by our solicitor.', esign_statement($c, $acct['name']));
    set_setting('esign_sign_statement', null);
    ok(str_contains(quote_acceptance_statement($acct), 'not yet a contract'));
    set_setting('contracts_auto_on_accept', '0');
    eq('I accept this quote on behalf of ' . $acct['name'] . '.', quote_acceptance_statement($acct), 'no agreement follows: accepting the quote is the agreement');
    set_setting('contracts_auto_on_accept', null);
});

test('e-sign: failed email marks the contract failed; wrong codes lock out; decline; reminders', function () use (&$flow) {
    $acct = db_one('SELECT * FROM accounts WHERE id = ?', [$flow['account']]);
    $tpl = db_one("SELECT * FROM contract_templates WHERE service_type = 'broadband'");
    $c = contract_generate($acct, [[$tpl, []]], 'Test', 'services', 'Erin', 'erin@echo.example');
    $port = setting('smtp_port');
    set_setting('smtp_port', '1');
    try { contract_send($c); throw new Exception('expected failure'); }
    catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'signing email'), $e->getMessage()); }
    set_setting('smtp_port', $port);
    eq('failed', db_value('SELECT status FROM contracts WHERE id = ?', [$c['id']]));

    $c = contract_send(db_one('SELECT * FROM contracts WHERE id = ?', [$c['id']]));
    eq('sent', $c['status']);
    $token = $c['sign_token'];
    eq($token, contract_send($c)['sign_token'], 'resending keeps the same link');
    esign_confirm_summary($c, '198.51.100.1', 'Test');
    $c = db_one('SELECT * FROM contracts WHERE id = ?', [$c['id']]);
    esign_send_code($c);
    $c = db_one('SELECT * FROM contracts WHERE id = ?', [$c['id']]);
    for ($i = 0; $i < ESIGN_CODE_ATTEMPTS; $i++) {
        eq(false, esign_verify_code($c, 'abc'));
        $c = db_one('SELECT * FROM contracts WHERE id = ?', [$c['id']]);
    }
    try { esign_verify_code($c, '123456'); throw new Exception('expected failure'); }
    catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'Too many'), $e->getMessage()); }
    db_exec('UPDATE contracts SET code_expires_at = NOW() - INTERVAL 1 MINUTE, code_attempts = 0 WHERE id = ?', [$c['id']]);
    try { esign_verify_code(db_one('SELECT * FROM contracts WHERE id = ?', [$c['id']]), '123456'); throw new Exception('expected failure'); }
    catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'expired'), $e->getMessage()); }

    // Reminders: due after the set number of days, up to 3.
    set_setting('esign_remind_days', '3');
    db_exec('UPDATE contracts SET sent_at = NOW() - INTERVAL 4 DAY WHERE id = ?', [$c['id']]);
    ok(esign_send_reminders() >= 1);
    $c = db_one('SELECT * FROM contracts WHERE id = ?', [$c['id']]);
    eq(1, (int)$c['reminders_sent']);
    eq(0, (int)db_value('SELECT COUNT(*) FROM contracts WHERE id = ? AND last_reminded_at < NOW() - INTERVAL 3 DAY', [$c['id']]), 'not reminded again straight away');
    set_setting('esign_remind_days', '0');
    eq(0, esign_send_reminders(), 'reminders off');

    esign_decline($c, 'Price too high');
    $c = db_one('SELECT * FROM contracts WHERE id = ?', [$c['id']]);
    eq(['rejected', 'Price too high'], [$c['status'], $c['declined_reason']]);
    try { esign_send_code($c); throw new Exception('expected failure'); }
    catch (IntegrationException $e) { ok(true); }
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
    eq(['customers.edit', 'orders.check', 'orders.place', 'tickets.all', 'onboarding.edit', 'documents.manage', 'suppliers.view', 'suppliers.edit', 'purchasing.edit', 'xero.open', 'costs.view', 'costs.edit', 'audit.view'], role_permissions()['manager'], 'unknown permissions dropped; newer permissions keep defaults');
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
    ok(!can('services.edit') && can('finance.view') && can('costs.edit') && !can('products.edit'), 'finance can change cost prices only');
    as_role('super_admin');
    eq([], users_with_permission('not.a.permission'));
    ok(in_array('test@example.com', array_column(users_with_permission('approvals.decide'), 'email'), true));
});

test('custom roles get their own permissions and survive a reset of the built-in roles', function () {
    set_setting('custom_roles', json_encode(['c_provisioning' => ['label' => 'Provisioning', 'description' => 'Orders and installs']]));
    set_setting('role_permissions', json_encode(['c_provisioning' => ['services.edit', 'tickets.edit'], '_known' => all_permissions()]));
    eq('Provisioning', roles()['c_provisioning']);
    eq('Provisioning', role_label('c_provisioning'));
    eq('Orders and installs', role_description('c_provisioning'));
    eq(['services.edit', 'tickets.edit'], role_permissions()['c_provisioning']);
    $staff = role_permissions()['staff']; $want = DEFAULT_ROLE_PERMISSIONS['staff']; sort($staff); sort($want);
    eq($want, $staff, 'built-in roles not in the saved grid keep defaults');
    as_role('c_provisioning');
    ok(can('services.edit') && !can('customers.edit') && !can('audit.view'));
    as_role('super_admin');
    set_setting('custom_roles', null);
    set_setting('role_permissions', null);
    eq([], role_permissions()['c_provisioning'] ?? [], 'deleted role has no permissions');
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


echo "Giacom\n";
$gState = sys_get_temp_dir() . '/crm_giacom_' . getmypid() . '.json';
$gPort = 13000 + getmypid() % 1000;
$gProc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$gPort", APP_ROOT . '/tests/giacom_mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p4, null, ['MOCK_STATE' => $gState] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $gPort); $i++) {
    usleep(100000);
}
config_ref()['giacom_url'] = "http://127.0.0.1:$gPort/";
function g_state(): array { global $gState; return json_decode(file_get_contents($gState), true); }
$g = [];
test('Giacom: request XML is well formed and escaped; errors are explained', function () {
    $xml = giacom_request_xml('provide', ['order' => ['client-ref' => 'A&B <1>', 'attributes' => ['care-level' => 'standard']]], '1.0', ['username' => 'u', 'password' => 'p']);
    $doc = simplexml_load_string($xml);
    eq('provide', (string)$doc['call']);
    eq('A&B <1>', (string)$doc->xpath('//a[@name="client-ref"]')[0]);
    eq(['auth' => ['username' => 'u', 'password' => 'p'], 'order' => ['client-ref' => 'A&B <1>', 'attributes' => ['care-level' => 'standard']]], giacom_xml_to_array($doc));
    ok(!giacom_configured());
    try { giacom_call('check_api_service_status'); throw new Exception('expected failure'); } catch (GiacomException $e) { ok(str_contains($e->getMessage(), 'isn\'t set up')); }
    try { giacom_call('check_api_service_status', [], '1.0', ['username' => 'bad', 'password' => 'x']); throw new Exception('expected failure'); }
    catch (GiacomException $e) { eq('Giacom said: Un-authorised user: bad', $e->getMessage()); }
    set_setting('giacom_username', 'crm-api');
    set_setting('giacom_password', 'secret-pw');
    ok(str_starts_with((string)db_value("SELECT value FROM settings WHERE name = 'giacom_password'"), 'enc:v1:'), 'password encrypted');
    eq('OK', giacom_call('check_api_service_status')['check'][0]['status']);
});
test('Giacom: address search and availability check are saved and summarised', function () use (&$g) {
    $g['acc'] = create('accounts', ['name' => 'Canal Street Clinic', 'type' => 'business', 'status' => 'active', 'postcode' => 'M1 3HE', 'main_name' => 'Rita Reception', 'main_phone' => '0161 496 0000', 'main_email' => 'rita@canal.example', 'billing_same' => '1']);
    try { giacom_address_search('not a postcode'); throw new Exception('expected failure'); } catch (GiacomException) {}
    eq([], giacom_address_search('ZZ99 0ZZ'));
    $addresses = giacom_address_search('m1 3he');
    eq('M13HE', g_state()['last']['address_search']['postcode']);
    eq('12 Canal Street, Manchester, M1 3HE', $addresses[0]['label']);
    eq('Unit 2 Bramble <Dental> & Co, 10 Canal Street, Manchester, M1 3HE', $addresses[1]['label']);
    // The same addresses fill in customer and site forms.
    eq(['address' => 'Unit 2, 10 Canal Street', 'address2' => '', 'city' => 'Manchester', 'county' => '', 'postcode' => 'M1 3HE', 'organisation' => 'Bramble <Dental> & Co'],
        giacom_address_fields($addresses[1]));
    eq(['address' => 'Hadley House', 'address2' => 'Croft Street, Charlton Kings', 'city' => 'Cheltenham', 'county' => 'Gloucestershire', 'postcode' => 'GL53 0ED', 'organisation' => ''],
        giacom_address_fields(['building' => 'Hadley House', 'street' => 'Croft Street', 'locality' => 'Charlton Kings', 'city' => 'CHELTENHAM', 'county' => 'Gloucestershire', 'postcode' => 'GL530ED']));
    eq('Flat 3, 22A High Street', giacom_address_fields(['sub-premise' => 'Flat 3', 'building' => '22A', 'street' => 'High Street', 'postcode' => 'GL530ED'])['address']);
    $_GET = ['postcode' => 'M1 3HE'];
    ob_start();
    address_lookup_controller();
    $json = json_decode(ob_get_clean(), true);
    $_GET = [];
    eq(['12 Canal Street, Manchester, M1 3HE', '12 Canal Street'], [$json['addresses'][0]['label'], $json['addresses'][0]['fields']['address']]);
    $g['check'] = giacom_check($addresses[1], null, $g['acc'], null);
    $c = db_one('SELECT * FROM giacom_checks WHERE id = ?', [$g['check']]);
    $r = json_decode($c['result'], true);
    eq(5, $r['quick_result']); ok(str_contains($r['quick_text'], 'new provide'));
    eq('MANCHESTER CENTRAL', $r['exchange']['name']); eq('Enabled', $r['exchange']['state']);
    eq(8, count($r['products']));
    eq(['BT Wholesale', 'CityFibre', 'BT Wholesale', 'BT Wholesale', 'BT Wholesale', 'Vodafone', 'Sky', 'TalkTalk'], array_column($r['products'], 'supplier'));
    eq(['FTTC', 'FTTP', 'SOGEA', 'FTTP', 'FTTP', 'FTTP', 'SOGEA', 'MPF'], array_column($r['products'], 'tech_label'));
    eq('Standard', $r['products'][1]['install_type']);
    eq('CITYFIBRE', $r['products'][1]['supplier_code']);
    eq('77001234', g_state()['last']['availability']['uprn'], 'UPRN looked up so CityFibre can be offered');
    eq('77001234', json_decode(db_value('SELECT address FROM giacom_checks WHERE id = ?', [$g['check']]), true)['uprn']);
    eq('Sky', giacom_supplier_name('', 'SKY_FTTP_500')); eq('Vodafone', giacom_supplier_name('', 'Vodafone_ADSL2')); eq('TalkTalk', giacom_supplier_name('', 'TTB_MPF'));
    eq('SOADSL', giacom_tech_label('adsl', 'BT_21CN_SOADSL')); eq('SOGEA', giacom_tech_label('sogea', '')); eq('FTTC', giacom_tech_label('VDSL', 'BT_21CN_FTTC'));
    eq(['34350', 'Business FTTC 80/20', 'fttc'], [$r['products'][0]['product_id'], $r['products'][0]['name'], $r['products'][0]['technology']]);
    eq(['standard', 'enhanced'], $r['products'][0]['care_levels']);
    eq('68400.00', $r['products'][0]['estimate']['down']);
    eq(10, (int)$r['products'][0]['leadtime']['days']);
    eq(date('Y-m-d', strtotime('+30 days 00:00 UTC')), $r['products'][3]['leadtime']['first_date'], 'lead time found elsewhere in the response');
    ok(isset($r['raw']['availability']), 'full response kept for troubleshooting');
    eq('M1 3HE', g_state()['last']['availability']['postcode'], 'postcode sent with a space');
    eq('M1 3HE', giacom_postcode('m13he')); eq('SW1A 1AA', giacom_postcode('sw1a1aa'));
    eq('80 Mbps', giacom_mbps($r['products'][0]['speed']));
    // Real-response shapes: package speed from the subtype, kbit/s ranges, bits/s figures, suppliers.
    $by = array_column($r['products'], null, 'product_id');
    eq([80.0, 20.0, 'BT Wholesale', 'FTTP', 'fttp'], [$by['59310']['down_mbps'], $by['59310']['up_mbps'], $by['59310']['supplier'], $by['59310']['tech_label'], $by['59310']['technology']]);
    eq([40.0, 'Vodafone', 'FTTP'], [$by['53733']['down_mbps'], $by['53733']['supplier'], $by['53733']['tech_label']]);
    eq([80.0, 'Sky', 'SOGEA', 36], [$by['72299']['down_mbps'], $by['72299']['supplier'], $by['72299']['tech_label'], $by['72299']['contract_months']]);
    eq([9.2, 'TalkTalk', 'MPF'], [$by['55453']['down_mbps'], $by['55453']['supplier'], $by['55453']['tech_label']]);
    eq(500.0, $by['34370']['down_mbps'], 'plain bits/s service speed');
    eq(72.0, giacom_product_speed(['likely-max-range' => '72000000'])['down']);
    eq(36.0, giacom_product_speed(['service-speed' => '20000.00 - 36000.00'])['down']);
    eq(['new_line' => 'PREMIUM', 'existing_line' => 'STANDARD'], $r['min_visit']);
    eq(['ONT0064647241', 'Working', true], [$r['ont']['onts'][0]['reference'], $r['ont']['onts'][0]['ports'][0]['status'], $r['ont']['new_ont']]);
    eq('Vodafone', giacom_supplier_name('', 'VF_FTTP')); eq('Other', giacom_supplier_name('', 'SKYLARK_X'));
    eq('68 Mbps', giacom_mbps($r['products'][0]['estimate']['down'], 'kbps'));
    eq('A00012345679', g_state()['last']['availability']['address-reference']);
    eq('Y', g_state()['last']['availability']['detailed']);
    eq(1, (int)db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'giacom_check' AND account_id = ?", [$g['acc']]));
});
test('Giacom: placing an order sends the right details and adds a pending service', function () use (&$g) {
    $check = db_one('SELECT * FROM giacom_checks WHERE id = ?', [$g['check']]);
    $product = json_decode($check['result'], true)['products'][0];
    $o = ['order_type' => 'provide', 'cli' => '', 'crd' => date('Y-m-d', strtotime('+20 days')), 'bb_username' => 'acc10001-1', 'bb_password' => 'Pa55word!',
        'realm' => 'isp.example', 'care_level' => 'enhanced', 'site_visit_reason' => 'NO_SITE_VISIT', 'access_line_id' => '', 'client_ref' => 'PO 77', 'force_new_ont' => '',
        'title' => '', 'forename' => 'Rita', 'surname' => 'Reception', 'telephone' => '0161 496 0000', 'email' => 'rita@canal.example', 'crm_product_id' => ''];
    try { giacom_place_order($check, $product, $o); throw new Exception('expected failure'); }
    catch (GiacomException $e) { ok(str_contains($e->getMessage(), '[Site Contact] Forename not present'), 'Giacom requires a site contact: ' . $e->getMessage()); }
    $o += ['site_title' => 'Mr', 'site_forename' => 'Sam', 'site_surname' => 'Site', 'site_telephone' => '07700 900 111', 'site_email' => 'sam@canal.example',
        'site_passphrase' => 'blue door', 'site_notes' => 'Ring the bell at the side entrance', 'hazard_notes' => ''];
    $g['order'] = giacom_place_order($check, $product, $o);
    eq(['title' => 'Mr', 'forename' => 'Sam', 'surname' => 'Site', 'telephone' => '07700900111', 'email' => 'sam@canal.example', 'pass-phrase' => 'blue door',
        'site-notes' => 'Ring the bell at the side entrance'], g_state()['last']['provide']['site-contact'], 'site contact sent alongside the customer');
    $sent = g_state()['last']['provide'];
    eq('34350', $sent['order']['prod-id']); eq('A00012345679', $sent['order']['address-reference']);
    eq('enhanced', $sent['order']['attributes']['care-level']); eq('Pa55word!', $sent['order']['attributes']['password']);
    ok(str_starts_with($sent['order']['client-ref'], 'ACC-') && str_ends_with($sent['order']['client-ref'], ' PO 77'));
    eq('M1 3HE', $sent['customer']['postcode'], 'Giacom needs the space in the postcode');
    eq('acc10001-1@isp.example', $sent['order']['username'], 'Giacom finds the realm from the username'); eq('isp.example', $sent['order']['attributes']['realm']);
    eq('Canal Street Clinic', $sent['customer']['company']); eq('01614960000', $sent['customer']['telephone']); eq('Unit 2', $sent['customer']['sub-premise']);
    $order = db_one('SELECT * FROM giacom_orders WHERE id = ?', [$g['order']]);
    eq('700100', $order['giacom_order_id']); eq('9700100', $order['giacom_service_id']); eq('Placed', $order['status']);
    $svc = db_one('SELECT * FROM services WHERE id = ?', [$order['service_id']]);
    eq('pending', $svc['status']); eq('Giacom', $svc['carrier']); eq('broadband', $svc['service_type']); eq('acc10001-1@isp.example', $svc['identifier']);
    ok(str_contains($svc['notes'], '700100'));
    ok(!str_contains((string)db_value("SELECT changes FROM audit_log WHERE action = 'giacom_order' ORDER BY id DESC LIMIT 1"), 'Pa55word'), 'password not in the audit trail');
    ok(!str_contains((string)$order['details'], 'Pa55word'), 'password not stored');
    // Giacom's own validation errors come back readably and nothing is saved.
    try { giacom_place_order($check, $product, ['surname' => ''] + $o); throw new Exception('expected failure'); }
    catch (GiacomException $e) { eq('Giacom said: Customer surname is required', $e->getMessage()); }
    eq(1, (int)db_value('SELECT COUNT(*) FROM giacom_orders WHERE account_id = ?', [$g['acc']]));
    try { giacom_place_order($check, $product, ['order_type' => 'migrate'] + $o); throw new Exception('expected failure'); }
    catch (GiacomException $e) { ok(str_contains($e->getMessage(), 'CLI or access line ID')); }
});
test('Giacom: status updates are picked up; completion makes the service live', function () use (&$g, &$gPort) {
    $order = db_one('SELECT * FROM giacom_orders WHERE id = ?', [$g['order']]);
    $order = giacom_refresh_order($order);
    eq('Awaiting Processing', $order['status']);
    ok((int)db_value('SELECT COUNT(*) FROM giacom_order_events WHERE order_id = ?', [$g['order']]) >= 1, 'history saved');
    http_request('POST', "http://127.0.0.1:$gPort/__status/700100/In%20Progress%20(Supplier%20Committed)");
    http_request('POST', "http://127.0.0.1:$gPort/__status/700100/Completed");
    $r = giacom_sync();
    eq(2, $r['status_changes'], json_encode(g_state()['events']));
    $order = db_one('SELECT * FROM giacom_orders WHERE id = ?', [$g['order']]);
    eq('Completed', $order['status']); ok($order['completed_at'] !== null);
    $svc = db_one('SELECT * FROM services WHERE id = ?', [$order['service_id']]);
    eq('active', $svc['status']); eq(date('Y-m-d'), $svc['start_date']);
    ok(setting('giacom_events_since') !== null);
    eq(['events' => 0, 'status_changes' => 0], giacom_sync(), 'events already seen are not applied again');
    eq('Completed', db_value('SELECT status FROM giacom_orders WHERE id = ?', [$g['order']]));
    eq(3, (int)db_value("SELECT COUNT(*) FROM audit_log WHERE action = 'giacom_status' AND account_id = ?", [$g['acc']]), 'placed → awaiting → in progress → completed');
    eq(['Dr', 'Priya', 'Shah'], giacom_split_name('Dr. Priya Shah'));
    eq('joebloggs-Finn@surfdsluk', giacom_full_username('joebloggs', '-Finn', 'surfdsluk'));
    eq('joebloggs-Finn@surfdsluk', giacom_full_username('joebloggs', '-Finn', '@surfdsluk'));
    eq('joebloggs-Finn@surfdsluk', giacom_full_username('joebloggs', '', '-Finn@surfdsluk'));
    eq('joebloggs-Finn@surfdsluk', giacom_full_username('joebloggs-Finn', '-Finn', 'surfdsluk'), 'suffix not added twice');
    eq('joebloggs-Finn@surfdsluk', giacom_full_username('joebloggs@wrong', '-Finn', 'surfdsluk'));
    eq('off001-1@isp.example', giacom_full_username('off001-1', '', 'isp.example'));
    eq(['-Finn', 'surfdsluk'], giacom_split_realm('-Finn@surfdsluk'));
    eq('-Finn@surfdsluk', giacom_realm_value('-Finn', '@surfdsluk'));
    eq('surfdsluk', giacom_realm_value('', 'surfdsluk'));
    $r = json_decode(db_value('SELECT result FROM giacom_checks WHERE id = ?', [$g['check']]), true);
    eq(['isp.example', '-public@GreatDSL'], $r['products'][3]['realms'], 'realms offered per product');
    $acct = db_one('SELECT * FROM accounts WHERE id = ?', [$g['acc']]);
    ok(!str_contains(giacom_suggest_username($acct), '@'), 'suggested username has no realm');
    $check = db_one('SELECT * FROM giacom_checks WHERE id = ?', [$g['check']]);
    $product = json_decode($check['result'], true)['products'][1];
    $slots = giacom_appointments($check, $product);
    eq(null, $slots['error']);
    eq([date('Y-m-d', strtotime('+8 days')), 'AM', 'APT8'], array_values($slots['appointments'][0]), 'earliest appointment first');
    $sent = g_state()['last']['available_appointments'];
    eq(['FTTP', 'CITYFIBRE', 'NO_SITE_VISIT', '77001234'], [$sent['technology-type'], $sent['supplier'], $sent['site-visit-reason'], $sent['uprn']]);
    eq(date('Y-m-d', strtotime('+13 days')), giacom_appointments($check, $product, 'STANDARD')['appointments'][0]['date'], 'dates depend on the visit type');
    $order = db_one('SELECT * FROM giacom_orders WHERE id = ?', [$g['order']]);
    giacom_book_appointment($order, $slots['appointments'][1]);
    eq(['700100', date('Y-m-d', strtotime('+9 days')), 'PM', 'APT9'], [g_state()['last']['amend_order']['order-id'], g_state()['last']['amend_order']['appointment-date'], g_state()['last']['amend_order']['appointment-slot'], g_state()['last']['amend_order']['appointment-ref']]);
    $order = db_one('SELECT * FROM giacom_orders WHERE id = ?', [$g['order']]);
    eq(date('Y-m-d', strtotime('+9 days')), $order['crd']);
    eq('PM', json_decode($order['details'], true)['appointment']['slot']);
    eq('APT9', giacom_appointment_key($slots['appointments'][1]) === date('Y-m-d', strtotime('+9 days')) . '|PM|APT9' ? 'APT9' : 'x');
    eq(['', 'Mary Jane', 'Smith'], giacom_split_name('Mary Jane Smith'));
    eq(['', 'Cher', ''], giacom_split_name('Cher'));
    ok(giacom_is_complete('Completed') && !giacom_is_complete('Awaiting completion date') && giacom_is_cancelled('Order Cancelled'));
});
test('Giacom: cancelling an order ceases its pending service', function () use (&$g) {
    $check = db_one('SELECT * FROM giacom_checks WHERE id = ?', [$g['check']]);
    $product = json_decode($check['result'], true)['products'][3];
    $o = ['order_type' => 'migrate', 'cli' => '01614960001', 'crd' => date('Y-m-d', strtotime('+20 days')), 'bb_username' => 'acc10001-2', 'bb_password' => 'secret12', 'bb_suffix' => '-public',
        'realm' => 'GreatDSL', 'care_level' => '', 'site_visit_reason' => 'NO_SITE_VISIT', 'access_line_id' => '', 'client_ref' => '', 'force_new_ont' => 'N',
        'title' => 'Ms', 'forename' => 'Rita', 'surname' => 'Reception', 'telephone' => '01614960000', 'email' => '', 'crm_product_id' => '',
        'site_forename' => 'Rita', 'site_surname' => 'Reception', 'site_telephone' => '01614960000'];
    $id = giacom_place_order($check, $product, $o);
    eq('01614960001', g_state()['last']['migrate']['order']['cli']);
    eq('acc10001-2-public@GreatDSL', g_state()['last']['migrate']['order']['username']);
    eq('-public@GreatDSL', g_state()['last']['migrate']['order']['attributes']['realm'], 'realm sent as Giacom lists it');
    // Asking for dates: Giacom wants the line type (SOGEA as new or existing) and the visit.
    eq(['SOGEA', 'SOGEA_NEW'], giacom_appointment_service(['technology' => 'sogea', 'tech_label' => 'SOGEA'], 'provide'));
    eq(['SOGEA', 'SOGEA_EXISTING'], giacom_appointment_service(['technology' => '', 'name' => 'SKY SOGEA 80/20'], 'migrate'));
    eq(['FTTP', null], giacom_appointment_service(['technology' => '', 'tech_label' => 'FTTP'], 'migrate'));
    $sogea = array_values(array_filter(json_decode($check['result'], true)['products'], fn($p) => ($p['tech_label'] ?? '') === 'SOGEA'))[0];
    $r = giacom_appointments($check, $sogea, 'PREMIUM', 'migrate');
    ok($r['appointments'] && !$r['error'], 'SOGEA take-over dates: ' . (string)$r['error']);
    eq(['SOGEA', 'SOGEA_EXISTING', 'PREMIUM'], [end(g_state()['appointment_requests'])['technology-type'], end(g_state()['appointment_requests'])['order-type'], end(g_state()['appointment_requests'])['site-visit-reason']]);
    // A placed order asks with its own product (supplier, line type) and the visit it was ordered with.
    $placed = db_one('SELECT * FROM giacom_orders WHERE id = ?', [$id]);
    eq((string)$placed['product_id'], (string)giacom_order_product($placed, $check)['product_id']);
    ok(!giacom_appointments($check, giacom_order_product($placed, $check), giacom_order_visit($placed, $check), (string)$placed['order_type'])['error']);

    // Engineer visit: never less than Giacom's minimum for the order type.
    eq('PREMIUM', giacom_visit_at_least('NO_SITE_VISIT', 'PREMIUM'));
    eq('PREMIUM', giacom_visit_at_least('STANDARD', 'PREMIUM'));
    eq('PREMIUM', giacom_visit_at_least('PREMIUM', 'STANDARD'), 'more than the minimum is fine');
    eq('NO_SITE_VISIT', giacom_visit_at_least('NO_SITE_VISIT', null), 'no minimum given');
    eq('STANDARD', giacom_min_visit(['min_visit' => ['new_line' => 'PREMIUM', 'existing_line' => 'STANDARD']], 'migrate'));
    // FTTP: a new service gets a new ONT, a take-over keeps the existing one, unless chosen otherwise; FTTC has no ONT.
    ok(giacom_is_fttp($product) && !giacom_is_fttp(json_decode($check['result'], true)['products'][0]));
    eq(['Y', 'N'], [giacom_default_ont('provide'), giacom_default_ont('migrate')]);
    giacom_place_order($check, $product, ['force_new_ont' => '', 'bb_username' => 'acc10001-3'] + $o);
    eq('N', g_state()['last']['migrate']['order']['attributes']['force-new-ont'], 'take-over: existing ONT');
    giacom_place_order($check, $product, ['force_new_ont' => '', 'order_type' => 'provide', 'cli' => '', 'bb_username' => 'acc10001-4'] + $o);
    eq('Y', g_state()['last']['provide']['order']['attributes']['force-new-ont'], 'new service: new ONT');
    try { giacom_place_order($check, $product, ['realm' => '', 'bb_suffix' => ''] + $o); throw new Exception('expected failure'); } catch (GiacomException $e) { ok(str_contains($e->getMessage(), 'realm')); }
    eq('N', g_state()['last']['migrate']['order']['attributes']['force-new-ont']);
    $order = db_one('SELECT * FROM giacom_orders WHERE id = ?', [$id]);
    // Giacom refuses: its reasons are shown, and nothing changes here.
    $st = g_state(); $st['refuse_cancel'] = true; file_put_contents($GLOBALS['gState'], json_encode($st));
    try { giacom_abort_order($order, 'Test Order'); throw new Exception('expected failure'); }
    catch (GiacomException $e) { ok(str_contains($e->getMessage(), 'too far progressed') && str_contains($e->getMessage(), 'Engineer appointment already confirmed'), $e->getMessage()); }
    eq('Placed', db_value('SELECT status FROM giacom_orders WHERE id = ?', [$id]), 'not marked as cancelling');
    eq('pending', db_value('SELECT status FROM services WHERE id = ?', [$order['service_id']]), 'service not ceased');
    ok(str_contains((string)db_value("SELECT value FROM giacom_order_events WHERE order_id = ? ORDER BY id DESC LIMIT 1", [$id]), 'Cancellation refused'), 'refusal noted on the order');
    $st = g_state(); $st['refuse_cancel'] = false; file_put_contents($GLOBALS['gState'], json_encode($st));
    ok(str_contains(giacom_abort_order($order, 'Customer changed their mind'), 'Cancellation accepted'));
    eq('Cancelled', db_value('SELECT status FROM giacom_orders WHERE id = ?', [$id]), 'status from Giacom after the cancel');
    eq('ceased', db_value('SELECT status FROM services WHERE id = ?', [$order['service_id']]));
    eq('Customer changed their mind', g_state()['last']['order_abort']['reason']);
    ok(!giacom_is_cancelled('Cancellation requested') && giacom_is_cancelled('Cancelled'), 'only a confirmed cancellation ceases the service');
    // Repair for orders the old code treated as cancelled although Giacom refused (shown as "Cancel error: <reason>").
    db_exec("UPDATE giacom_orders SET status = 'Cancellation requested' WHERE id = ?", [$id]);
    db_exec("UPDATE services SET status = 'ceased' WHERE id = ?", [$order['service_id']]);
    db_exec("INSERT INTO giacom_order_events (order_id, event_date, name, value) VALUES (?, NOW() - INTERVAL 1 DAY, 'cancel', 'error: Test Order')", [$id]);
    (require migrations_file())[28]();
    eq('Placed', db_value('SELECT status FROM giacom_orders WHERE id = ?', [$id]), 'order open again');
    eq('pending', db_value('SELECT status FROM services WHERE id = ?', [$order['service_id']]), 'service back to pending');
});
proc_terminate($gProc);
@unlink($gState);
foreach (['giacom_username', 'giacom_password', 'giacom_events_since', 'giacom_last_sync_at'] as $k) { set_setting($k, null); }


echo "Ticket pick-up alerts\n";
test('tickets waiting too long alert admins once; per-group limits', function () {
    $acc = create('accounts', ['name' => 'Alert Co', 'type' => 'business', 'status' => 'active']);
    $faults = (int)db_value("SELECT id FROM ticket_groups WHERE name = 'Faults'");
    $billing = (int)db_value("SELECT id FROM ticket_groups WHERE name = 'Billing'");
    db_exec('UPDATE tickets SET pickup_alerted_at = NOW() WHERE pickup_alerted_at IS NULL'); // earlier tests' tickets
    set_setting('ticket_pickup_minutes', '30');
    db_exec('UPDATE ticket_groups SET pickup_minutes = NULL, alert_email = ? WHERE id = ?', ['faults-lead@example.com', $faults]);
    db_exec('UPDATE ticket_groups SET pickup_minutes = 0 WHERE id = ?', [$billing]);
    $late = create('tickets', ['account_id' => $acc, 'subject' => 'Late fault', 'category' => 'fault', 'priority' => 'P2', 'status' => 'open']);
    $fresh = create('tickets', ['account_id' => $acc, 'subject' => 'Fresh fault', 'category' => 'fault', 'priority' => 'P2', 'status' => 'open']);
    $bill = create('tickets', ['account_id' => $acc, 'subject' => 'Old billing', 'category' => 'billing', 'priority' => 'P3', 'status' => 'open']);
    db_exec('UPDATE tickets SET created_at = DATE_SUB(NOW(), INTERVAL 45 MINUTE) WHERE id IN (?, ?)', [$late, $bill]);
    $overdue = array_map(fn($t) => (int)$t['id'], tickets_overdue_pickup());
    eq([$late], $overdue, 'only past the limit, and not in groups with alerts off');
    ok(can('tickets.alerts'));
    $before = count(sent_mails());
    eq(1, ticket_pickup_alerts());
    usleep(300000);
    $mails = array_slice(sent_mails(), $before);
    $to = array_map(fn($m) => preg_match('/X-Rcpt: (\S+)/', $m, $x) ? $x[1] : '', $mails);
    ok(in_array('test@example.com', $to, true), 'admin emailed');
    ok(in_array('faults-lead@example.com', $to, true), 'group alert address emailed');
    ok(str_contains(mail_body($mails[0]), 'Late fault'));
    ok(db_value('SELECT pickup_alerted_at FROM tickets WHERE id = ?', [$late]) !== null);
    ok(str_contains((string)db_value('SELECT body FROM ticket_comments WHERE ticket_id = ? ORDER BY id DESC LIMIT 1', [$late]), 'admins alerted'));
    eq(0, ticket_pickup_alerts(), 'each ticket is alerted once');
    eq([$late], array_map(fn($t) => (int)$t['id'], tickets_overdue_pickup()), 'still shown as waiting too long until picked up');
    ticket_pick_up($late);
    eq([], tickets_overdue_pickup(), 'picked up: no longer overdue');
    db_exec('UPDATE ticket_groups SET pickup_minutes = 10 WHERE id = ?', [$billing]);
    eq([$bill], array_map(fn($t) => (int)$t['id'], tickets_overdue_pickup(true)), 'group limit overrides the default');
    eq(10, ticket_pickup_minutes(db_one('SELECT * FROM ticket_groups WHERE id = ?', [$billing])));
    eq(30, ticket_pickup_minutes(null));
    set_setting('ticket_pickup_checked_at', (string)time());
    ticket_pickup_alerts_throttled();
    eq(null, db_value('SELECT pickup_alerted_at FROM tickets WHERE id = ?', [$bill]), 'throttled: at most one check a minute');
    set_setting('ticket_pickup_checked_at', null);
    ticket_pickup_alerts_throttled();
    ok(db_value('SELECT pickup_alerted_at FROM tickets WHERE id = ?', [$bill]) !== null);
});


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
test('new users get a welcome email with a temporary password, and must choose their own', function () {
    $pw = temporary_password();
    ok(preg_match('/^[A-Za-z2-9]{4}-[A-Za-z2-9]{4}-[A-Za-z2-9]{4}$/', $pw) === 1 && password_problem($pw) === null, 'readable and passes the rules');
    db_exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Welcome Tester', 'welcome@example.com', 'x', 'staff')");
    $id = (int)db()->lastInsertId();
    $before = count(sent_mails());
    $sent = send_temporary_password($id, true);
    ok($sent['emailed'], 'emailed');
    $mail = mail_body(sent_mails()[$before] ?? '');
    ok(str_contains($mail, $sent['password']) && str_contains($mail, 'welcome@example.com') && str_contains($mail, 'page=login'), 'email has the sign-in details and link');
    $u = db_one('SELECT * FROM users WHERE id = ?', [$id]);
    eq(1, (int)$u['must_change_password']);
    ok(strtotime($u['temp_password_expires_at']) > time() + 6 * 86400, 'expires in a week');

    db_exec('DELETE FROM login_attempts');
    eq('ok', attempt_login('welcome@example.com', $sent['password'])['status']);
    eq(1, (int)current_user(true)['must_change_password'], 'has to choose a password first');
    logout();
    db_exec('UPDATE users SET temp_password_expires_at = NOW() - INTERVAL 1 DAY WHERE id = ?', [$id]);
    eq('temp_expired', attempt_login('welcome@example.com', $sent['password'])['status'], 'an old temporary password stops working');
    db_exec('DELETE FROM users WHERE id = ?', [$id]);
});

test('the CRM can be limited to approved IP addresses, with people allowed anywhere', function () {
    ok(ip_matches('81.2.69.160', '81.2.69.160') && ip_matches('81.2.69.77', '81.2.69.0/24') && !ip_matches('81.2.70.1', '81.2.69.0/24'));
    ok(ip_matches('::ffff:81.2.69.5', '81.2.69.0/28') && ip_matches('2001:db8::1', '2001:db8::/32') && !ip_matches('2001:db9::1', '2001:db8::/32'));
    ok(ip_matches('10.1.2.3', '10.0.0.0/8') && !ip_matches('11.1.2.3', '10.0.0.0/8') && ip_matches('172.16.5.4', '172.16.0.0/12'));
    eq(null, ip_entry_problem('81.2.69.0/24'));
    ok(ip_entry_problem('81.2.69/24') !== null && ip_entry_problem('81.2.69.0/33') !== null, 'bad entries are explained');

    db_exec("INSERT INTO users (name, email, password_hash, role) VALUES ('IP Tester', 'iptest@example.com', ?, 'staff')", [password_hash('Correct-horse-9', PASSWORD_DEFAULT)]);
    $id = (int)db()->lastInsertId();
    db_exec('DELETE FROM login_attempts');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    set_setting('allowed_ips', "81.2.69.0/24  # office\n203.0.113.9");
    set_setting('ip_restrict', '1');
    ok(ip_restriction_on());
    eq('ip', attempt_login('iptest@example.com', 'Correct-horse-9')['status'], 'refused from elsewhere');
    $_SERVER['REMOTE_ADDR'] = '81.2.69.40';
    eq('ok', attempt_login('iptest@example.com', 'Correct-horse-9')['status'], 'allowed from the office');
    logout();
    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    db_exec('UPDATE users SET ip_anywhere = 1 WHERE id = ?', [$id]);
    eq('ok', attempt_login('iptest@example.com', 'Correct-horse-9')['status'], 'remote worker allowed anywhere');
    logout();
    set_setting('ip_restrict', null);
    set_setting('allowed_ips', null);
    unset($_SERVER['REMOTE_ADDR']);
    db_exec('DELETE FROM users WHERE id = ?', [$id]);
    db_exec('DELETE FROM login_attempts');
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

echo "Terms, documents and suppliers\n";
test('terms are set values: one-off, 30 days, 12, 24, 36, 60 months', function () {
    eq(['30 days', '36 months', 'One-off (no term)', '18 months'], [term_label(1), term_label(36), term_label(0), term_label(18)]);
    eq([0, 1, 12, 24, 36, 60], array_keys(TERM_OPTIONS));
    ok(isset(term_options(18)[18]) && !isset(term_options(36)[18]), 'an older value is kept as a choice');
    eq('term', entity('products')['fields']['term_months']['type']);
    [$data, $errors] = validate(entity('opportunities'), ['account_id' => '', 'title' => 'x', 'opp_type' => 'upsell', 'stage' => 'lead', 'term_months' => '1']);
    eq(1, $data['term_months']);
    [, $errors] = validate(entity('opportunities'), ['term_months' => 'twelve']);
    ok(isset($errors['term_months']));
    eq(['30 days', ''], [export_value(entity('products'), 'term_months', ['term_months' => 1]), export_value(entity('products'), 'term_months', ['term_months' => null])]);
});

function temp_upload(string $name, string $content): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, $content);
    return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
}

$docIds = [];
test('documents: library folders, customer files, checks on type and who can add or remove them', function () use (&$docIds) {
    as_role('super_admin');
    $folders = array_column(doc_folders(), 'id', 'name');
    ok(isset($folders['Sales & Marketing'], $folders['Spec sheets']), 'starter folders');
    $spec = document_store(temp_upload('FTTP spec.pdf', '%PDF-1.4 spec sheet'), ['folder_id' => $folders['Spec sheets']], '', 'Full fibre', false);
    $brochure = document_store(temp_upload('brochure.docx', 'PK brochure'), ['folder_id' => $folders['Sales & Marketing']], 'Company brochure', '', false);
    $acct = create('accounts', ['name' => 'Files & Co Ltd', 'type' => 'business', 'status' => 'active', 'email' => 'boss@files.example']);
    $own = document_store(temp_upload('signed order.pdf', '%PDF order'), ['account_id' => $acct], '', 'Signed order form', false);
    $d = db_one('SELECT * FROM documents WHERE id = ?', [$spec]);
    eq(['FTTP spec', 'application/pdf', 'Full fibre'], [$d['title'], $d['mime'], $d['description']]);
    eq('%PDF-1.4 spec sheet', file_get_contents(document_path($d)), 'stored privately');
    ok(str_starts_with(document_path($d), storage_path('documents')));
    eq(['Company brochure'], array_column(library_documents()['Sales & Marketing'], 'title'));
    eq(['signed order'], array_column(account_documents($acct), 'title'));
    try { document_store(temp_upload('virus.exe', 'MZ'), ['folder_id' => $folders['Spec sheets']], '', '', false); throw new Exception('expected refusal'); } catch (IntegrationException) {}
    try { document_store(['name' => 'big.pdf', 'tmp_name' => '/dev/null', 'error' => UPLOAD_ERR_OK, 'size' => DOC_MAX_BYTES + 1], ['folder_id' => $folders['Spec sheets']], '', '', false); throw new Exception('expected refusal'); } catch (IntegrationException) {}
    as_role('staff');
    ok(document_can('view', $d) && !document_can('add', $d) && !document_can('delete', $d), 'staff can use the library but not change it');
    $ownDoc = db_one('SELECT * FROM documents WHERE id = ?', [$own]);
    ok(document_can('add', ['account_id' => $acct]) && document_can('delete', $ownDoc), 'staff can add customer files and remove their own');
    db_exec('UPDATE documents SET uploaded_by = NULL WHERE id = ?', [$own]);
    ok(!document_can('delete', db_one('SELECT * FROM documents WHERE id = ?', [$own])), "but not someone else's without delete permission");
    as_role('manager');
    ok(document_can('add', $d) && document_can('delete', $d), 'managers manage the library');
    as_role('super_admin');
    $docIds = compact('spec', 'brochure', 'own', 'acct');
});

test('quotes can be emailed with library documents and customer files attached', function () use (&$docIds) {
    extract($docIds);
    db_exec('INSERT INTO quotes (account_id, title, created_by) VALUES (?, ?, 1)', [$acct, 'Fibre for Files & Co']);
    $qid = (int)db()->lastInsertId();
    db_exec('UPDATE quotes SET reference = ? WHERE id = ?', [sprintf('Q-%06d', $qid), $qid]);
    quote_save_lines($qid, [['product_id' => null, 'service_type' => 'broadband', 'description' => 'FTTP 500', 'quantity' => 1, 'monthly_price' => 45, 'setup_fee' => 0, 'term_months' => 36]]);
    $quote = db_one('SELECT * FROM quotes WHERE id = ?', [$qid]);
    $other = create('accounts', ['name' => 'Someone Else Ltd', 'type' => 'business', 'status' => 'active']);
    $theirs = document_store(temp_upload('private.pdf', '%PDF private'), ['account_id' => $other], '', '', false);
    $docs = quote_set_documents($quote, [$spec, $brochure, $own, $theirs, 999999]);
    eq(['Company brochure', 'FTTP spec', 'signed order'], array_column(quote_documents($qid), 'title'), "another customer's files can't be attached");
    $before = count(sent_mails());
    quote_send($quote, 'boss@files.example', 'Bea Boss', $docs);
    usleep(300000);
    $mails = sent_mails();
    eq($before + 1, count($mails));
    $raw = end($mails);
    ok(str_contains($raw, 'multipart/mixed'), 'sent as multipart/mixed');
    ok(str_contains($raw, 'filename="FTTP spec.pdf"') && str_contains($raw, 'filename="brochure.docx"') && str_contains($raw, 'filename="signed order.pdf"'), 'all three attached');
    ok(str_contains($raw, base64_encode('%PDF-1.4 spec sheet')), 'file content attached');
    ok(str_contains(mail_body($raw), 'Company brochure'), 'email lists the attachments');
    ok(str_contains((string)db_value("SELECT body FROM activities WHERE account_id = ? AND type = 'email' ORDER BY id DESC LIMIT 1", [$acct]), 'FTTP spec'));
    db_exec('UPDATE documents SET size = ? WHERE id = ?', [QUOTE_ATTACH_MAX_BYTES, $brochure]);
    try { quote_set_documents($quote, [$spec, $brochure]); throw new Exception('expected refusal'); } catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'too big')); }
    eq(3, count(quote_documents($qid)), 'selection unchanged after a refusal');
    // Deleting a document removes the file and takes it off quotes.
    document_delete(db_one('SELECT * FROM documents WHERE id = ?', [$spec]));
    eq(2, count(quote_documents($qid)));
    ok(!is_file(storage_path('documents') . '/' . basename((string)db_value('SELECT stored_name FROM documents WHERE id = ?', [$spec]))));
    // Mail without attachments stays multipart/alternative.
    [$headers] = mail_build('a@example.com', 'A', 'Hi', '<p>Hi</p>');
    ok((bool)preg_grep('#multipart/alternative#', $headers));
});

test('accepting a quote emails the customer a confirmation with a PDF and the acceptance record', function () use (&$docIds) {
    $acct = $docIds['acct'];
    db_exec('INSERT INTO quotes (account_id, title, created_by) VALUES (?, ?, 1)', [$acct, 'Phones (with "quotes") & £ signs']);
    $qid = (int)db()->lastInsertId();
    db_exec('UPDATE quotes SET reference = ? WHERE id = ?', [sprintf('Q-%06d', $qid), $qid]);
    quote_save_lines($qid, [['product_id' => null, 'service_type' => 'voip', 'description' => 'Hosted seat – unlimited (UK)', 'quantity' => 3, 'monthly_price' => 12.5, 'setup_fee' => 20, 'term_months' => 1]]);
    quote_send(db_one('SELECT * FROM quotes WHERE id = ?', [$qid]), 'boss@files.example', 'Bea Boss');
    $before = count(sent_mails());
    quote_accept(db_one('SELECT * FROM quotes WHERE id = ?', [$qid]), 'Bea Boss', '203.0.113.9', false, 'Bea@Files.example', 'Mozilla/5.0 (iPhone) Safari');
    usleep(300000);
    $q = db_one('SELECT * FROM quotes WHERE id = ?', [$qid]);
    eq(['online', '203.0.113.9', 'Mozilla/5.0 (iPhone) Safari', 'bea@files.example'], [$q['response_method'], $q['response_ip'], $q['response_user_agent'], $q['response_email']]);
    eq(64, strlen((string)$q['response_fingerprint']));
    ok(str_contains((string)$q['response_statement'], 'on behalf of Files & Co Ltd'));
    ok($q['confirmation_sent_at'] !== null);
    $mails = array_slice(sent_mails(), $before);
    $conf = array_values(array_filter($mails, fn($m) => str_contains($m, 'X-Rcpt: bea@files.example')));
    eq(1, count($conf), 'confirmation sent to whoever accepted');
    ok(str_contains($conf[0], 'filename="Quote ' . $q['reference'] . ' accepted.pdf"') && str_contains($conf[0], 'application/pdf'));
    ok(str_contains(mail_body($conf[0]), 'go ahead') && str_contains(mail_body($conf[0]), 'contract is made when you sign'), 'says it isn\'t a contract yet');
    // The PDF.
    $pdf = quote_pdf($q);
    ok(str_starts_with($pdf, '%PDF-1.4') && str_ends_with(rtrim($pdf), '%%EOF'));
    foreach (['Accepted by', 'Bea Boss', '203.0.113.9', 'Mozilla/5.0 \(iPhone\) Safari', 'Hosted seat ' . "\x96" . ' unlimited \(UK\)', "\xA337.50", '30 days', $q['response_fingerprint']] as $needle) {
        ok(str_contains($pdf, $needle), "PDF contains $needle");
    }
    preg_match('/startxref\n(\d+)/', $pdf, $m);
    ok(str_starts_with(substr($pdf, (int)$m[1]), 'xref'), 'cross-reference table where it says');
    // Filed against the customer, once.
    eq(1, (int)db_value('SELECT COUNT(*) FROM documents WHERE account_id = ? AND file_name = ?', [$acct, "Quote {$q['reference']} accepted.pdf"]));
    quote_send_confirmation($q);
    eq(1, (int)db_value('SELECT COUNT(*) FROM documents WHERE account_id = ? AND file_name = ?', [$acct, "Quote {$q['reference']} accepted.pdf"]), 'resending replaces the filed copy');
    // Recorded by staff, without the email.
    db_exec('INSERT INTO quotes (account_id, title, created_by, status) VALUES (?, ?, 1, ?)', [$acct, 'Verbal yes', 'sent']);
    $q2 = (int)db()->lastInsertId();
    db_exec('UPDATE quotes SET reference = ? WHERE id = ?', [sprintf('Q-%06d', $q2), $q2]);
    $before = count(sent_mails());
    quote_accept(db_one('SELECT * FROM quotes WHERE id = ?', [$q2]), 'Bea Boss', '', true, 'bea@files.example', '', false);
    usleep(200000);
    $q2row = db_one('SELECT * FROM quotes WHERE id = ?', [$q2]);
    eq(['staff', null, null, 1], [$q2row['response_method'], $q2row['response_ip'], $q2row['confirmation_sent_at'], (int)$q2row['response_recorded_by']]);
    eq(0, count(array_filter(array_slice(sent_mails(), $before), fn($m) => str_contains($m, 'Confirmation: quote'))), 'no confirmation when unticked');
    ok(str_contains(quote_pdf($q2row), 'on the customer\'s behalf'));
});

test('accepted quotes become orders that wait for the agreement; signing alerts the team and adds services; customer updated at each step', function () use (&$docIds) {
    as_role('super_admin');
    $acct = $docIds['acct'];
    $team = (int)db_value("SELECT id FROM ticket_groups WHERE name = 'Onboarding'");
    ok($team > 0, 'Onboarding team created');
    eq((string)$team, (string)setting('order_group_id'));
    db_exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Olive Onboard', 'olive@netcomm.example', 'x', 'staff')");
    $olive = (int)db()->lastInsertId();
    db_exec('INSERT INTO ticket_group_members (group_id, user_id) VALUES (?, ?)', [$team, $olive]);

    db_exec('INSERT INTO quotes (account_id, title, created_by, status, recipient_name, recipient_email) VALUES (?, ?, 1, ?, ?, ?)', [$acct, 'New office lines', 'sent', 'Bea Boss', 'bea@files.example']);
    $qid = (int)db()->lastInsertId();
    db_exec('UPDATE quotes SET reference = ? WHERE id = ?', [sprintf('Q-%06d', $qid), $qid]);
    quote_save_lines($qid, [['product_id' => null, 'service_type' => 'broadband', 'description' => 'FTTP 900', 'quantity' => 1, 'monthly_price' => 60, 'setup_fee' => 99, 'term_months' => 36]]);
    $before = count(sent_mails());
    quote_accept(db_one('SELECT * FROM quotes WHERE id = ?', [$qid]), 'Bea Boss', '198.51.100.7', false, 'bea@files.example', 'Firefox');
    usleep(300000);
    $order = db_one('SELECT * FROM customer_orders WHERE quote_id = ?', [$qid]);
    ok($order !== null, 'order created');
    eq([sprintf('ORD-%06d', $order['id']), 'accepted', null, 'bea@files.example', 60.0, 99.0],
        [$order['reference'], $order['status'], $order['assigned_to'], $order['contact_email'], (float)$order['monthly_total'], (float)$order['setup_total']]);
    eq($order['id'], order_create_from_quote(db_one('SELECT * FROM quotes WHERE id = ?', [$qid]))['id'], 'only one order per quote');
    $mails = array_slice(sent_mails(), $before);
    eq([], array_values(array_filter($mails, fn($m) => str_contains($m, 'X-Rcpt: olive@netcomm.example'))), 'onboarding team not alerted until the agreement is signed');
    $conf = array_values(array_filter($mails, fn($m) => str_contains($m, 'X-Rcpt: bea@files.example')));
    ok(str_contains(mail_body($conf[0]), 'Track your order') && str_contains(mail_body($conf[0]), $order['reference']), 'confirmation includes the order tracking link');
    ok(orders_waiting_count() >= 1);

    // The agreement's progress shows between "accepted" and "processing"; the order can't go further until it's signed.
    $contract = order_contract($order);
    eq('sent', $contract['status']);
    eq($contract['id'], order_unsigned_contract($order)['id']);
    eq(['Quotation accepted', 'Agreement sent', 'Agreement signed', 'Order processing', 'Order confirmed', 'Order completed'], array_column(order_progress($order, $contract), 'label'));
    eq(['current', 'done', 'waiting'], array_slice(array_column(order_progress($order, $contract), 'state'), 0, 3));
    try { order_set_status($order, 'processing', '', false); throw new Exception('expected'); }
    catch (IntegrationException $e) { ok(str_contains($e->getMessage(), "hasn't been signed"), $e->getMessage()); }
    try { order_raise_purchase_orders($order); throw new Exception('expected'); }
    catch (IntegrationException $e) { ok(str_contains($e->getMessage(), "hasn't been signed"), 'no purchase orders before signing'); }
    eq('accepted', db_value('SELECT status FROM customer_orders WHERE id = ?', [$order['id']]));

    // Signing (here by hand) moves it on, adds the services and alerts the team.
    $before = count(sent_mails());
    $signed = contract_mark_signed($contract, null, 'marked as signed by a test');
    usleep(300000);
    eq('signed', $signed['status']);
    eq(null, order_unsigned_contract($order));
    eq('done', order_progress($order, $signed)[2]['state']);
    $last = array_slice(order_events((int)$order['id'], true), -1)[0];
    eq(['contract_signed', 'Agreement signed'], [$last['status'], order_status_label($last['status'])]);
    ok(str_contains((string)$last['message'], 'signed'), 'the customer sees it on their tracking page');
    $teamMail = array_values(array_filter(array_slice(sent_mails(), $before), fn($m) => str_contains($m, 'X-Rcpt: olive@netcomm.example')));
    eq(1, count($teamMail), 'onboarding team alerted once signed');
    ok(str_contains(mail_body($teamMail[0]), $order['reference']) && str_contains(mail_body($teamMail[0]), 'agreement has been signed'));
    $services = contract_services($signed);
    eq(1, count($services), 'pending service added on signing');
    eq('pending', $services[0]['status']);
    eq(0, contract_create_services($signed), 'not added twice');

    // Picked up once only.
    ok(order_pick_up($order, $olive));
    ok(!order_pick_up(db_one('SELECT * FROM customer_orders WHERE id = ?', [$order['id']]), 1), 'second pick-up refused');
    $order = db_one('SELECT * FROM customer_orders WHERE id = ?', [$order['id']]);
    eq($olive, (int)$order['assigned_to']);

    // Steps, each emailed to the customer.
    eq('processing', order_next_step('accepted'));
    foreach (['processing' => 'Order processing', 'confirmed' => 'Order confirmed', 'completed' => 'Order completed'] as $step => $label) {
        $before = count(sent_mails());
        $to = order_set_status(db_one('SELECT * FROM customer_orders WHERE id = ?', [$order['id']]), $step, order_default_message($step) . " ($step)", true, "internal $step");
        usleep(250000);
        eq('bea@files.example', $to);
        $m = array_slice(sent_mails(), $before);
        eq(1, count($m));
        ok(str_contains($m[0], 'Subject: =?UTF-8?B?') || str_contains($m[0], "Subject: Your order {$order['reference']}: $label"));
        $body = mail_body($m[0]);
        ok(str_contains($body, "($step)") && str_contains($body, 'Track your order') && !str_contains($body, "internal $step"), "$step email has the message but not the internal note");
    }
    $order = db_one('SELECT * FROM customer_orders WHERE id = ?', [$order['id']]);
    eq('completed', $order['status']);
    ok($order['confirmed_at'] !== null && $order['completed_at'] !== null);
    try { order_set_status($order, 'processing', '', false); throw new Exception('expected'); } catch (IntegrationException $e) { ok(str_contains($e->getMessage(), 'completed')); }
    $public = order_events((int)$order['id'], true);
    eq(['accepted', 'contract_sent', 'contract_signed', 'processing', 'confirmed', 'completed'], array_column($public, 'status'), 'customer sees the steps, including the agreement');
    eq(7, count(order_events((int)$order['id'])), 'staff also see the pick-up');
    // Without notifying the customer.
    db_exec("UPDATE customer_orders SET status = 'processing', completed_at = NULL WHERE id = ?", [$order['id']]);
    $before = count(sent_mails());
    eq(null, order_set_status(db_one('SELECT * FROM customer_orders WHERE id = ?', [$order['id']]), 'cancelled', 'Changed our mind', false));
    usleep(200000);
    eq($before, count(sent_mails()), 'no email when not asked');
    ok(!order_is_open(db_one('SELECT * FROM customer_orders WHERE id = ?', [$order['id']])));
    db_exec('DELETE FROM ticket_group_members WHERE user_id = ?', [$olive]);
    db_exec('UPDATE users SET active = 0 WHERE id = ?', [$olive]);
});

test('cancelling the agreement on an order releases it and alerts the onboarding team (or whoever has it)', function () use (&$docIds) {
    as_role('super_admin');
    $acct = $docIds['acct'];
    $team = (int)setting('order_group_id');
    db_exec("INSERT INTO users (name, email, password_hash, role) VALUES ('Ollie Onboard', 'ollie@netcomm.example', 'x', 'staff')");
    $ollie = (int)db()->lastInsertId();
    db_exec('INSERT INTO ticket_group_members (group_id, user_id) VALUES (?, ?)', [$team, $ollie]);
    $teamMails = fn(int $from) => array_values(array_filter(array_slice(sent_mails(), $from), fn($m) => str_contains($m, 'X-Rcpt: ollie@netcomm.example')));
    $newOrder = function () use ($acct): array {
        db_exec('INSERT INTO quotes (account_id, title, created_by, status, recipient_name, recipient_email) VALUES (?, ?, 1, ?, ?, ?)', [$acct, 'Extra lines', 'sent', 'Bea Boss', 'bea@files.example']);
        $qid = (int)db()->lastInsertId();
        db_exec('UPDATE quotes SET reference = ? WHERE id = ?', [sprintf('Q-%06d', $qid), $qid]);
        quote_save_lines($qid, [['product_id' => null, 'service_type' => 'broadband', 'description' => 'FTTP 500', 'quantity' => 1, 'monthly_price' => 40, 'setup_fee' => 0, 'term_months' => 24]]);
        quote_accept(db_one('SELECT * FROM quotes WHERE id = ?', [$qid]), 'Bea Boss', '198.51.100.7', false, 'bea@files.example', 'Firefox');
        return db_one('SELECT * FROM customer_orders WHERE quote_id = ?', [$qid]);
    };

    // Unassigned: the team is told it can go ahead.
    $order = $newOrder();
    $contract = order_unsigned_contract($order);
    ok($contract !== null, 'held by its agreement');
    usleep(300000);
    $before = count(sent_mails());
    contract_cancel($contract);
    usleep(300000);
    eq(null, order_unsigned_contract($order), 'no longer held');
    $m = $teamMails($before);
    eq(1, count($m), 'onboarding team alerted');
    ok(str_contains(mail_body($m[0]), "Agreement {$contract['reference']} was cancelled") && str_contains(mail_body($m[0]), $order['reference']));
    ok(str_contains((string)array_slice(order_events((int)$order['id']), -1)[0]['note'], 'no longer waiting'), 'noted on the order');
    order_set_status($order, 'processing', '', false);
    eq('processing', db_value('SELECT status FROM customer_orders WHERE id = ?', [$order['id']]));

    // Picked up: the person who has it is told instead.
    $order = $newOrder();
    order_pick_up($order, 1);
    $contract = order_unsigned_contract($order);
    usleep(300000);
    $before = count(sent_mails());
    contract_cancel($contract);
    usleep(300000);
    eq([], $teamMails($before), 'team not alerted when someone has it');
    $admin = db_value('SELECT email FROM users WHERE id = 1');
    ok((bool)array_filter(array_slice(sent_mails(), $before), fn($r) => str_contains($r, "X-Rcpt: $admin") && str_contains(mail_body($r), 'can go ahead')), 'owner told');

    // Replaced by another agreement: still held, nobody alerted yet.
    $order = $newOrder();
    $first = order_unsigned_contract($order);
    db_exec("UPDATE contracts SET status = 'draft' WHERE id = ?", [$first['id']]);
    db_exec("INSERT INTO contracts (account_id, quote_id, kind, title, status, signer_name, signer_email, reference) VALUES (?, ?, 'services', 'Replacement', 'draft', 'Bea', 'bea@files.example', 'CON-REPL')", [$order['account_id'], $order['quote_id']]);
    usleep(300000);
    $before = count(sent_mails());
    contract_cancel($first);
    usleep(300000);
    eq([], $teamMails($before), 'not alerted while a replacement agreement is unsigned');
    eq('CON-REPL', order_unsigned_contract($order)['reference']);

    db_exec('DELETE FROM ticket_group_members WHERE user_id = ?', [$ollie]);
    db_exec('UPDATE users SET active = 0 WHERE id = ?', [$ollie]);
});

test('supplier prices: the preferred supplier sets the product cost, per the product billing cycle', function () {
    as_role('super_admin');
    $supplier = create('suppliers', ['name' => 'Kit Distribution Ltd', 'category' => 'hardware', 'active' => '1', 'email' => 'orders@kit.example']);
    $product = create('products', ['sku' => 'T-RENTAL', 'name' => 'Router rental', 'category' => 'hardware', 'billing_frequency' => 'monthly', 'monthly_price' => '10', 'term_months' => '12', 'active' => '1']);
    $sp = create('supplier_products', ['supplier_id' => $supplier, 'supplier_sku' => 'RT-100', 'description' => 'Router', 'product_id' => $product,
        'cost_price' => '12.00', 'billing_frequency' => 'quarterly', 'preferred' => '1', 'active' => '1']);
    eq(4.0, (float)db_value('SELECT cost_price FROM products WHERE id = ?', [$product]), '£12 a quarter = £4 a month');
    ok(db_value('SELECT price_updated_at FROM supplier_products WHERE id = ?', [$sp]) !== null);
    $other = create('suppliers', ['name' => 'Cheaper Kit', 'active' => '1']);
    $sp2 = create('supplier_products', ['supplier_id' => $other, 'description' => 'Router', 'product_id' => $product, 'cost_price' => '3.50', 'billing_frequency' => 'monthly', 'preferred' => '1', 'active' => '1']);
    eq([0, 1], [(int)db_value('SELECT preferred FROM supplier_products WHERE id = ?', [$sp]), (int)db_value('SELECT preferred FROM supplier_products WHERE id = ?', [$sp2])], 'one preferred supplier per product');
    eq(3.5, (float)db_value('SELECT cost_price FROM products WHERE id = ?', [$product]));
    eq(['Cheaper Kit', 'Kit Distribution Ltd'], array_column(product_supplier_prices($product), 'supplier_name'), 'preferred first');
    ok(str_contains((string)db_value("SELECT summary FROM audit_log WHERE entity = 'products' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$product]), 'Cheaper Kit'));
    as_role('sales');
    ok(!can('suppliers.view') && !can('suppliers.edit'), 'sales can\'t see suppliers by default');
    as_role('finance');
    ok(can('suppliers.view') && can('suppliers.edit') && can('purchasing.edit'));
    as_role('staff');
    ok(can('suppliers.view') && !can('suppliers.edit') && !can('purchasing.edit'));
    as_role('super_admin');
});

function make_xlsx(string $path, array $rows): void
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $strings = [];
    $sheet = '';
    foreach ($rows as $r => $row) {
        $sheet .= '<row r="' . ($r + 1) . '">';
        foreach ($row as $c => $v) {
            $ref = chr(65 + $c) . ($r + 1);
            if (is_string($v)) {
                $strings[] = $v;
                $sheet .= '<c r="' . $ref . '" t="s"><v>' . (count($strings) - 1) . '</v></c>';
            } else {
                $sheet .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            }
        }
        $sheet .= '</row>';
    }
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Prices" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<sst>' . implode('', array_map(fn($s) => '<si><t>' . htmlspecialchars($s) . '</t></si>', $strings)) . '</sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData>' . $sheet . '</sheetData></worksheet>');
    $zip->close();
}

test('price files (CSV or Excel) update supplier prices after a preview', function () {
    $supplier = (int)db_value("SELECT id FROM suppliers WHERE name = 'Kit Distribution Ltd'");
    $product = (int)db_value("SELECT id FROM products WHERE sku = 'T-RENTAL'");
    db_exec('UPDATE supplier_products SET preferred = 0 WHERE product_id = ?', [$product]);
    db_exec("UPDATE supplier_products SET preferred = 1 WHERE supplier_sku = 'RT-100'");
    create('supplier_products', ['supplier_id' => $supplier, 'supplier_sku' => 'OLD-1', 'description' => 'Discontinued', 'cost_price' => '1', 'billing_frequency' => 'monthly', 'active' => '1']);
    create('supplier_products', ['supplier_id' => $supplier, 'supplier_sku' => 'SW-8', 'description' => 'Switch', 'cost_price' => '20', 'billing_frequency' => 'monthly', 'active' => '1']);
    $csv = sys_get_temp_dir() . '/prices_' . getmypid() . '.csv';
    file_put_contents($csv, "\xEF\xBB\xBFKit Distribution price list,,,\nProduct Code;Description;Trade Price;Setup Charge\nRT-100;Router 100;£15.00;£25\nSW-8;Switch;20.00;\nAP-2;Access point;\"1,234.50\";0\n;Section heading;;\nBAD-1;No price;call;\nRT-100;Duplicate;9;\n");
    $rows = price_file_rows($csv, 'prices.csv');
    ok(str_starts_with($rows[0][0], 'Kit Distribution') && count($rows[1]) === 4, 'semicolon-separated file read (a title line is kept for the controller to skip)');
    $rows = array_slice($rows, 1);
    $map = price_file_guess($rows[0]);
    eq(['setup_cost' => 3, 'supplier_sku' => 0, 'cost_price' => 2, 'description' => 1], $map, 'columns guessed from the headers');
    $plan = price_import_plan($supplier, $rows, $map, ['add_new' => true]);
    eq([1, 1, 1], [count($plan['changed']), count($plan['new']), $plan['same']], 'RT-100 changed, AP-2 new, SW-8 unchanged');
    eq([12.0, 15.0, 25.0], [$plan['changed'][0]['old_cost'], $plan['changed'][0]['cost_price'], $plan['changed'][0]['setup_cost']]);
    eq(1234.5, $plan['new'][0]['cost_price']);
    eq(2, count($plan['errors']), 'bad price and duplicate reported');
    eq(['OLD-1'], array_column($plan['missing'], 'supplier_sku'));
    $summary = price_import_apply($supplier, $plan, ['frequency' => 'monthly', 'retire_missing' => true]);
    eq([1, 1, 1], [$summary['updated'], $summary['added'], $summary['retired']]);
    eq(15.0, (float)db_value("SELECT cost_price FROM supplier_products WHERE supplier_sku = 'RT-100'"));
    eq(0, (int)db_value("SELECT active FROM supplier_products WHERE supplier_sku = 'OLD-1'"));
    eq(5.0, (float)db_value('SELECT cost_price FROM products WHERE id = ?', [$product]), 'preferred supplier price change updates the product cost (15 a quarter)');
    eq(['Router rental'], array_column($summary['products'], 0));
    ok(str_contains((string)db_value("SELECT changes FROM audit_log WHERE action = 'price_import' ORDER BY id DESC LIMIT 1"), 'RT-100'));
    // Without adding new products, unknown codes are ignored.
    eq(0, count(price_import_plan($supplier, [['Code', 'Price'], ['NEW-9', '5']], ['supplier_sku' => 0, 'cost_price' => 1])['new']));
    try { price_import_plan($supplier, $rows, ['supplier_sku' => 0]); throw new Exception('expected'); } catch (IntegrationException) {}
    // Excel.
    $xlsx = sys_get_temp_dir() . '/prices_' . getmypid() . '.xlsx';
    make_xlsx($xlsx, [['SKU', 'Name', 'Monthly cost'], ['SW-8', 'Switch 8 port', 18.25], ['RT-100', 'Router', 15]]);
    $rows = price_file_rows($xlsx, 'prices.xlsx');
    eq([['SKU', 'Name', 'Monthly cost'], ['SW-8', 'Switch 8 port', '18.25'], ['RT-100', 'Router', '15']], $rows);
    $plan = price_import_plan($supplier, $rows, price_file_guess($rows[0]));
    eq(['SW-8'], array_column($plan['changed'], 'sku'));
    price_import_apply($supplier, $plan, ['update_descriptions' => true]);
    eq(['18.25', 'Switch 8 port'], array_values(db_one("SELECT cost_price, description FROM supplier_products WHERE supplier_sku = 'SW-8'")));
    try { price_file_rows($csv, 'prices.pdf'); throw new Exception('expected'); } catch (IntegrationException) {}
});

test('purchase orders: lines, totals, emailing the supplier and receiving', function () {
    $supplier = db_one("SELECT * FROM suppliers WHERE name = 'Kit Distribution Ltd'");
    $sw = (int)db_value("SELECT id FROM supplier_products WHERE supplier_sku = 'SW-8'");
    [$lines, $errors] = po_parse_lines(['line_supplier_product_id' => [(string)$sw, '', ''], 'line_sku' => ['SW-8', '', ''], 'line_description' => ['Switch', 'Cable', ''],
        'line_quantity' => ['2', '10', ''], 'line_unit_cost' => ['18.25', '£1.50', '']], (int)$supplier['id']);
    eq([], $errors);
    eq([$sw, null], array_column($lines, 'supplier_product_id'));
    eq(51.5, po_total($lines));
    [, $errors] = po_parse_lines(['line_supplier_product_id' => [''], 'line_description' => ['X'], 'line_quantity' => ['0'], 'line_unit_cost' => ['1']], (int)$supplier['id']);
    ok((bool)$errors, 'quantity checked');
    [$l2] = po_parse_lines(['line_supplier_product_id' => [(string)$sw], 'line_description' => ['X'], 'line_quantity' => ['1'], 'line_unit_cost' => ['1']], (int)db_value("SELECT id FROM suppliers WHERE name = 'Cheaper Kit'"));
    eq(null, $l2[0]['supplier_product_id'], "another supplier's product isn't linked");
    db_exec('INSERT INTO purchase_orders (supplier_id, deliver_to, created_by) VALUES (?, ?, 1)', [$supplier['id'], "Unit 1\nLeeds"]);
    $id = (int)db()->lastInsertId();
    db_exec('UPDATE purchase_orders SET reference = ? WHERE id = ?', [sprintf('PO-%06d', $id), $id]);
    po_save_lines($id, $lines);
    eq(51.5, (float)db_value('SELECT total FROM purchase_orders WHERE id = ?', [$id]));
    $po = db_one('SELECT * FROM purchase_orders WHERE id = ?', [$id]);
    po_send($po, 'orders@kit.example', 'Olly Orders');
    usleep(300000);
    $mails = sent_mails();
    $raw = end($mails);
    ok(str_contains($raw, 'X-Rcpt: orders@kit.example'));
    $body = mail_body($raw);
    ok(str_contains($body, $po['reference']) && str_contains($body, 'Switch') && str_contains($body, '£51.50') && str_contains($body, 'Leeds'));
    $po = db_one('SELECT * FROM purchase_orders WHERE id = ?', [$id]);
    eq('sent', $po['status']);
    ok($po['sent_at'] !== null && $po['order_date'] === date('Y-m-d'));
    eq(company('name'), explode("\n", po_default_delivery(null))[0], 'delivery defaults to head office');
    ok(str_contains(po_default_delivery(db_one("SELECT * FROM accounts WHERE name = 'Files & Co Ltd'")), 'Files & Co Ltd'));
    try { db_exec('DELETE FROM suppliers WHERE id = ?', [$supplier['id']]); throw new Exception('expected'); } catch (PDOException $e) { eq(1451, (int)$e->errorInfo[1], 'suppliers with orders are kept'); }
});

test('orders raise a purchase order per supplier, emailed and linked; portal/API suppliers are left out', function () use (&$docIds) {
    as_role('super_admin');
    $acct = $docIds['acct'];
    $kit = (int)db_value("SELECT id FROM suppliers WHERE name = 'Kit Distribution Ltd'");
    $giacom = (int)db_value("SELECT id FROM suppliers WHERE name = 'Giacom'");
    eq('api', db_value('SELECT ordering FROM suppliers WHERE id = ?', [$giacom]), 'Giacom is ordered through the integration');
    $other = create('suppliers', ['name' => 'Phones Direct', 'active' => '1', 'email' => 'po@phones.example', 'ordering' => 'email']);
    $mk = fn($sku, $name, $cat) => create('products', ['sku' => $sku, 'name' => $name, 'category' => $cat, 'billing_frequency' => 'monthly', 'monthly_price' => '30', 'term_months' => '36', 'active' => '1']);
    $router = $mk('T-RTR2', 'Router (rented)', 'hardware');
    $handset = $mk('T-HS', 'Desk phone', 'hardware');
    $fibre = $mk('T-FIB', 'Fibre 900', 'broadband');
    create('supplier_products', ['supplier_id' => $kit, 'supplier_sku' => 'RT-9', 'description' => 'Router 9', 'product_id' => $router, 'cost_price' => '6.00', 'setup_cost' => '40', 'billing_frequency' => 'monthly', 'preferred' => '1', 'active' => '1']);
    create('supplier_products', ['supplier_id' => $other, 'supplier_sku' => 'YL-T54', 'description' => 'Yealink T54W', 'product_id' => $handset, 'cost_price' => '95.00', 'billing_frequency' => 'yearly', 'preferred' => '1', 'active' => '1']);
    create('supplier_products', ['supplier_id' => $giacom, 'supplier_sku' => 'FTTP900', 'description' => 'FTTP 900', 'product_id' => $fibre, 'cost_price' => '32.00', 'billing_frequency' => 'monthly', 'preferred' => '1', 'active' => '1']);

    db_exec('INSERT INTO quotes (account_id, title, created_by, status, recipient_name, recipient_email) VALUES (?, ?, 1, ?, ?, ?)', [$acct, 'Office kit', 'sent', 'Bea Boss', 'bea@files.example']);
    $qid = (int)db()->lastInsertId();
    db_exec('UPDATE quotes SET reference = ? WHERE id = ?', [sprintf('Q-%06d', $qid), $qid]);
    quote_save_lines($qid, [
        ['product_id' => $router, 'service_type' => 'hardware', 'description' => 'Router', 'quantity' => 2, 'monthly_price' => 10, 'setup_fee' => 0, 'term_months' => 36],
        ['product_id' => $handset, 'service_type' => 'hardware', 'description' => 'Desk phones', 'quantity' => 5, 'monthly_price' => 8, 'setup_fee' => 0, 'term_months' => 36],
        ['product_id' => $fibre, 'service_type' => 'broadband', 'description' => 'Fibre', 'quantity' => 1, 'monthly_price' => 60, 'setup_fee' => 0, 'term_months' => 36],
        ['product_id' => null, 'service_type' => 'other', 'description' => 'Cabling', 'quantity' => 1, 'monthly_price' => 0, 'setup_fee' => 150, 'term_months' => 1],
    ]);
    quote_accept(db_one('SELECT * FROM quotes WHERE id = ?', [$qid]), 'Bea Boss', '198.51.100.7', false, 'bea@files.example', 'Firefox', false);
    $order = db_one('SELECT * FROM customer_orders WHERE quote_id = ?', [$qid]);
    $plan = order_po_plan($order);
    eq([$kit, $other], array_keys($plan['suppliers']));
    eq([['Router 9 (monthly)', 2, 6.0], ['Setup / one-off: Router 9', 2, 40.0]], array_map(fn($l) => [$l['description'], $l['quantity'], $l['unit_cost']], $plan['suppliers'][$kit]['lines']));
    eq(['Fibre', 'Cabling'], array_column($plan['skipped'], 0), 'Giacom (integration) and the custom line are left out');
    ok(str_contains($plan['skipped'][0][1], 'integration'));

    $before = count(sent_mails());
    $r = order_raise_purchase_orders($order, null, true);
    usleep(300000);
    eq(2, count($r));
    eq(['orders@kit.example', 'po@phones.example'], array_column($r, 'emailed_to'));
    $pos = order_purchase_orders((int)$order['id']);
    eq(['sent', 'sent'], array_column($pos, 'status'));
    eq(92.0, (float)$pos[0]['total'], '2 × £6 + 2 × £40');
    $mails = array_slice(sent_mails(), $before);
    ok(str_contains(mail_body($mails[0]), $pos[0]['reference']) && str_contains(mail_body($mails[0]), 'Files & Co Ltd'), 'supplier email names the PO and the customer');
    ok(str_contains((string)db_value('SELECT note FROM customer_order_events WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$order['id']]), $pos[0]['reference']), 'noted on the order');
    $again = order_po_plan($order);
    eq([], $again['suppliers'], 'already ordered');
    ok(str_contains(implode(' ', array_column($again['skipped'], 1)), 'Already on ' . $pos[0]['reference']));
    $GLOBALS['invoiceTest'] = ['po' => $pos[0], 'kit' => $kit, 'other' => $other];
});

function make_test_invoice_pdf(array $lines): string
{
    $pdf = new SimplePdf(50, 'Invoice');
    $pdf->addPage();
    foreach ($lines as $l) {
        $pdf->text(50, $pdf->y, $l, 10);
        $pdf->y += 16;
    }
    $path = tempnam(sys_get_temp_dir(), 'inv') . '.pdf';
    file_put_contents($path, $pdf->output());
    return $path;
}

test('PDF text: plain fonts, and compressed streams with embedded fonts (ToUnicode)', function () {
    $text = pdf_extract_text(file_get_contents(make_test_invoice_pdf(['Kit Distribution Ltd', 'Invoice No: KD-1', 'Total £1,234.50'])));
    eq("Kit Distribution Ltd\nInvoice No: KD-1\nTotal £1,234.50", $text);
    // Hand-made PDF like accounting software writes: Type0 font, two-byte codes, Flate-compressed, kerning moves.
    $cmap = "/CIDInit /ProcSet findresource begin 1 begincodespacerange <0000> <FFFF> endcodespacerange\n2 beginbfrange\n<0024> <003D> <0041>\n<0044> <005D> <0061>\nendbfrange\n2 beginbfchar\n<0003> <0020>\n<0011> <002E>\nendbfchar\nendcmap";
    $code = fn($s) => implode('', array_map(fn($c) => sprintf('%04X', ctype_upper($c) ? ord($c) - 29 : (ctype_lower($c) ? ord($c) - 29 : ($c === ' ' ? 3 : 17))), str_split($s)));
    $content = "BT /F1 10 Tf 1 0 0 1 50 700 Tm <" . $code('T') . "> Tj 6.1 0 Td <" . $code('otal due') . "> Tj ET\nBT /F1 10 Tf 1 0 0 1 50 680 Tm [<" . $code('Ref') . "> -300 <" . $code('ABC') . ">] TJ ET";
    $z = gzcompress($content);
    $zc = gzcompress($cmap);
    $raw = "%PDF-1.7\n1 0 obj << /Type /Font /Subtype /Type0 /BaseFont /X /Encoding /Identity-H /DescendantFonts [2 0 R] /ToUnicode 3 0 R >> endobj\n"
        . "2 0 obj << /Type /Font /Subtype /CIDFontType2 /DW 1000 /W [55 [610]] >> endobj\n"
        . "3 0 obj << /Length " . strlen($zc) . " /Filter /FlateDecode >> stream\n$zc\nendstream endobj\n"
        . "4 0 obj << /Type /Page /Resources << /Font << /F1 1 0 R >> >> /Contents 5 0 R >> endobj\n"
        . "5 0 obj << /Length " . strlen($z) . " /Filter /FlateDecode >> stream\n$z\nendstream endobj\n%%EOF";
    eq("Total due\nRef ABC", pdf_extract_text($raw), 'kerning move not taken as a space; TJ gap is');

    // Like Xero's invoices: glyphs listed with width 0, each piece placed separately, and a hyphen drawn
    // for capitals that maps to a private-use character.
    $cmap = "/CIDInit /ProcSet findresource begin 1 begincodespacerange <0000> <FFFF> endcodespacerange\n1 beginbfrange\n<0030> <007A> <0030>\nendbfrange\n1 beginbfchar\n<0001> <E088>\nendbfchar\nendcmap";
    $hex = fn($s) => implode('', array_map(fn($c) => sprintf('%04X', $c === '~' ? 1 : ord($c)), str_split($s)));
    $content = "BT /F1 10 Tf 1 0 0 1 50 700 Tm <" . $hex('Dat') . "> Tj ET BT /F1 10 Tf 1 0 0 1 64.6 700 Tm <" . $hex('e') . "> Tj ET\n"
        . "BT /F1 10 Tf 1 0 0 1 50 680 Tm <" . $hex('PO~000123') . "> Tj ET";
    $z = gzcompress($content);
    $zc = gzcompress($cmap);
    $raw = "%PDF-1.7\n1 0 obj << /Type /Font /Subtype /Type0 /BaseFont /X /Encoding /Identity-H /DescendantFonts [2 0 R] /ToUnicode 3 0 R >> endobj\n"
        . "2 0 obj << /Type /Font /Subtype /CIDFontType2 /W [68 [700] 97 [550 0 0 0 550] 116 [0]] >> endobj\n"
        . "3 0 obj << /Length " . strlen($zc) . " /Filter /FlateDecode >> stream\n$zc\nendstream endobj\n"
        . "4 0 obj << /Type /Page /Resources << /Font << /F1 1 0 R >> >> /Contents 5 0 R >> endobj\n"
        . "5 0 obj << /Length " . strlen($z) . " /Filter /FlateDecode >> stream\n$z\nendstream endobj\n%%EOF";
    eq("Date\nPO-000123", pdf_extract_text($raw), 'zero widths and private-use hyphens');
});

test('purchase orders can use our products & tariffs; new ones join the supplier price list', function () {
    $sid = create('suppliers', ['name' => 'Catalogue Test Supplies']);
    $pid = create('products', ['sku' => 'CAT-PO-1', 'name' => 'Yealink W73P DECT', 'category' => array_key_first(SERVICE_TYPES), 'billing_frequency' => 'monthly',
        'monthly_price' => '120', 'cost_price' => '80', 'term_months' => '12']);
    [$lines, $errors] = po_parse_lines(['line_item' => ["p:$pid", ''], 'line_sku' => ['W73P', ''], 'line_description' => ['Yealink W73P DECT', 'Courier'],
        'line_quantity' => ['2', '1'], 'line_unit_cost' => ['78.50', '9.99']], $sid);
    eq([], $errors);
    eq([null, $pid], [$lines[0]['supplier_product_id'], $lines[0]['product_id']]);
    $added = po_link_new_products($sid, $lines);
    eq(['Yealink W73P DECT'], $added);
    $sp = db_one('SELECT * FROM supplier_products WHERE id = ?', [$lines[0]['supplier_product_id']]);
    eq([$sid, $pid, 'W73P', 78.5, 1], [(int)$sp['supplier_id'], (int)$sp['product_id'], $sp['supplier_sku'], (float)$sp['cost_price'], (int)$sp['preferred']]);
    eq(null, $lines[1]['supplier_product_id'], 'free-typed lines stay as they are');
    // Picking it again uses the price list entry rather than adding another.
    [$again] = po_parse_lines(['line_item' => ["p:$pid"], 'line_sku' => [''], 'line_description' => ['Yealink W73P DECT'], 'line_quantity' => ['1'], 'line_unit_cost' => ['78.50']], $sid);
    eq([], po_link_new_products($sid, $again));
    eq((int)$sp['id'], $again[0]['supplier_product_id']);
    eq(1, (int)db_value('SELECT COUNT(*) FROM supplier_products WHERE product_id = ?', [$pid]));
    ok(!array_filter(list_rows('products', ['preset' => 'no_supplier', 'per_page' => 0])['rows'], fn($r) => (int)$r['id'] === $pid), 'no longer listed as without a supplier');
});

test('one company record: a customer can also be a supplier and a dealer, with shared details', function () {
    $acc = fn($id) => db_one('SELECT * FROM accounts WHERE id = ?', [$id]);
    $sup = fn($id) => db_one('SELECT * FROM suppliers WHERE account_id = ?', [$id]);
    $save = function (int $id, array $changes) use ($acc) {
        [$data, $errors] = validate(entity('accounts'), $changes + $acc($id) + account_contact_values($acc($id)));
        eq([], $errors);
        update_row('accounts', $id, $data);
    };

    // Ticking "also a supplier" makes a linked supplier record from the customer's details.
    $id = create('accounts', ['name' => 'Dual Role Comms Ltd', 'type' => 'business', 'status' => 'active', 'phone' => '0113 000 0001',
        'address' => '1 Dual Street', 'city' => 'Leeds', 'postcode' => 'ls1 1aa', 'email' => 'hello@dual.example', 'is_supplier' => '1']);
    $s = $sup($id);
    eq(['Dual Role Comms Ltd', '0113 000 0001', '1 Dual Street', 'LS1 1AA', 'hello@dual.example', 'email'], [$s['name'], $s['phone'], $s['address'], $s['postcode'], $s['email'], $s['ordering']]);
    eq(1, account_contact_values($acc($id))['is_supplier']);
    ok((bool)array_filter(list_rows('accounts', ['preset' => 'suppliers', 'per_page' => 0])['rows'], fn($r) => (int)$r['id'] === $id), 'in the "also suppliers" list');

    // Shared details follow edits on either side; supplier-only details stay their own.
    $save($id, ['name' => 'Dual Role Communications Ltd', 'city' => 'Bradford']);
    eq(['Dual Role Communications Ltd', 'Bradford'], [$sup($id)['name'], $sup($id)['city']]);
    [$data, $errors] = validate(entity('suppliers'), ['phone' => '0113 999 9999', 'email' => 'orders@dual.example'] + $sup($id));
    eq([], $errors);
    update_row('suppliers', (int)$sup($id)['id'], $data);
    eq(['0113 999 9999', 'hello@dual.example'], [$acc($id)['phone'], $acc($id)['email']]);

    // A second supplier can't claim the same customer.
    [$data] = validate(entity('suppliers'), ['name' => 'Someone Else', 'account_id' => $id]);
    ok(isset(validate_rules('suppliers', $data, null)['account_id']), 'one supplier per customer');

    // Unticking unlinks; the supplier record (and its history) is kept.
    $supplierId = (int)$sup($id)['id'];
    $save($id, ['is_supplier' => '0']);
    eq(null, $sup($id));
    eq(null, db_value('SELECT account_id FROM suppliers WHERE id = ?', [$supplierId]));
    // Ticking again finds that supplier by name rather than making a duplicate.
    $save($id, ['is_supplier' => '1']);
    eq($supplierId, (int)$sup($id)['id']);

    // From the supplier side: a supplier that is also a dealer gets a customer record, filled from the supplier.
    $sid = create('suppliers', ['name' => 'Reseller Supplies Ltd', 'phone' => '0161 000 0002', 'address' => '5 Trade Park', 'postcode' => 'M1 1AA']);
    $aid = supplier_make_account(db_one('SELECT * FROM suppliers WHERE id = ?', [$sid]), true);
    $a = $acc($aid);
    eq(['Reseller Supplies Ltd', '0161 000 0002', '5 Trade Park', 1, 'active'], [$a['name'], $a['phone'], $a['address'], (int)$a['is_dealer'], $a['status']]);
    eq($sid, (int)$sup($aid)['id']);
    ok(str_starts_with($a['account_number'], 'ACC-'), 'gets an account number');

    // Linking an existing customer to an existing supplier only fills blanks.
    $cid = create('accounts', ['name' => 'Linked Later Ltd', 'type' => 'business', 'status' => 'active', 'phone' => '0200 000 0000']);
    $sid2 = create('suppliers', ['name' => 'Linked Later Limited', 'phone' => '0300 000 0000', 'city' => 'York']);
    [$data] = validate(entity('suppliers'), ['account_id' => $cid] + db_one('SELECT * FROM suppliers WHERE id = ?', [$sid2]));
    update_row('suppliers', $sid2, $data);
    eq(['Linked Later Ltd', '0200 000 0000', 'York'], [$acc($cid)['name'], $acc($cid)['phone'], $acc($cid)['city']]);
    eq(['Linked Later Limited', '0300 000 0000'], [$sup($cid)['name'], $sup($cid)['phone']]);
});

test('invoice reader: tables, unlabelled dates and totals laid out in columns', function () {
    // Laid out like a real distributor invoice: date under the number, "Total Due" above the table, right-aligned columns.
    $pdf = new SimplePdf();
    $pdf->addPage();
    $t = fn($x, $y, $s, $align = 'left') => $pdf->text($x, $y, $s, 10, false, [0, 0, 0], $align);
    $t(30, 40, 'NetXL Distribution'); $t(560, 40, 'INVOICE #DUK-12076093', 'right');
    $t(560, 56, '29 September 2026', 'right');
    $t(410, 120, 'Total Due'); $t(560, 120, '£0.00', 'right');
    $t(30, 160, 'Description'); $t(343, 160, 'Qty'); $t(433, 160, 'Price'); $t(553, 160, 'Total', 'right');
    $t(30, 180, 'Draytek Vigor V167 Modem'); $t(343, 180, '3'); $t(433, 180, '£81.00'); $t(553, 180, '£243.00', 'right');
    $t(30, 196, 'Shipping - Next Working Day'); $t(343, 196, '1'); $t(433, 196, '£6.99'); $t(553, 196, '£6.99', 'right');
    $t(410, 230, 'Subtotal'); $t(553, 230, '£249.99', 'right');
    $t(410, 246, 'VAT (20%)'); $t(553, 246, '£50.00', 'right');
    $t(410, 262, 'Invoice Total'); $t(553, 262, '£299.99', 'right');
    $rows = pdf_extract_rows($pdf->output());
    $f = invoice_read_builtin($rows, pdf_rows_text($rows));
    eq(['DUK-12076093', '2026-09-29', 249.99, 50.0, 299.99], [$f['invoice_number'], $f['invoice_date'], $f['net_total'], $f['vat_total'], $f['gross_total']]);
    eq([['description' => 'Draytek Vigor V167 Modem', 'quantity' => 3.0, 'unit_price' => 81.0, 'net_amount' => 243.0, 'vat_rate' => null],
        ['description' => 'Shipping - Next Working Day', 'quantity' => 1.0, 'unit_price' => 6.99, 'net_amount' => 6.99, 'vat_rate' => null]], $f['lines']);

    // Headed boxes: values under their labels; "Unit Price" is a price, and a table amount is never a PO number.
    $pdf = new SimplePdf();
    $pdf->addPage();
    $t = fn($x, $y, $s, $align = 'left') => $pdf->text($x, $y, $s, 10, false, [0, 0, 0], $align);
    $t(30, 100, 'Invoice Number'); $t(160, 100, 'Invoice Date'); $t(260, 100, 'Due Date'); $t(360, 100, 'Reference');
    $t(30, 114, 'INV-004512'); $t(160, 114, '2 October 2026'); $t(260, 114, '1 November 2026'); $t(360, 114, 'PO-000003');
    $t(30, 150, 'Description'); $t(326, 150, 'Quantity', 'right'); $t(415, 150, 'Unit Price', 'right'); $t(450, 150, 'VAT', 'right'); $t(565, 150, 'Amount GBP', 'right');
    $t(30, 166, 'Yealink T54W desk phone'); $t(326, 166, '5.00', 'right'); $t(415, 166, '95.00', 'right'); $t(450, 166, '20%', 'right'); $t(565, 166, '475.00', 'right');
    $t(440, 200, 'Subtotal'); $t(565, 200, '475.00', 'right');
    $t(440, 216, 'TOTAL VAT 20%'); $t(565, 216, '95.00', 'right');
    $t(440, 232, 'TOTAL GBP'); $t(565, 232, '570.00', 'right');
    $rows = pdf_extract_rows($pdf->output());
    $f = invoice_read_builtin($rows, pdf_rows_text($rows));
    eq(['INV-004512', '2026-10-02', '2026-11-01', ['PO-000003'], 475.0, 95.0, 570.0],
        [$f['invoice_number'], $f['invoice_date'], $f['due_date'], $f['purchase_order_numbers'], $f['net_total'], $f['vat_total'], $f['gross_total']]);
    eq([['description' => 'Yealink T54W desk phone', 'quantity' => 5.0, 'unit_price' => 95.0, 'net_amount' => 475.0, 'vat_rate' => '20']], $f['lines']);
});

test('supplier invoices are read and matched to their purchase order, with warnings when they differ', function () {
    ['po' => $po, 'kit' => $kit, 'other' => $other] = $GLOBALS['invoiceTest'];
    $upload = fn(array $lines) => invoice_upload(['name' => 'invoice.pdf', 'tmp_name' => make_test_invoice_pdf($lines), 'error' => UPLOAD_ERR_OK], null, null, false);
    $row = fn($id) => db_one('SELECT * FROM supplier_invoices WHERE id = ?', [$id]);
    $problems = fn($id) => json_decode((string)$row($id)['problems'], true) ?: [];
    set_setting('invoice_reader', 'builtin');

    // A good one: matched by PO number, supplier and amount.
    $good = $upload(['Kit Distribution Ltd', 'VAT Reg No: GB 123 4567 89', 'Invoice No: KD-4001', 'Invoice Date: 03/10/2026', 'Your order ref: ' . $po['reference'],
        'Subtotal £92.00', 'VAT @ 20% £18.40', 'Total due £110.40']);
    $g = $row($good);
    eq(['KD-4001', '2026-10-03', 92.0, 18.4, 110.4, $kit, (int)$po['id'], 'matched', 'builtin'],
        [$g['invoice_number'], $g['invoice_date'], (float)$g['net'], (float)$g['vat'], (float)$g['total'], (int)$g['supplier_id'], (int)$g['po_id'], $g['status'], $g['reader']]);
    eq([], $problems($good));

    // Wrong amount, and the same PO billed again: warned, and the team is emailed.
    $before = count(sent_mails());
    $over = $upload(['Kit Distribution Ltd', 'Invoice No: KD-4002', 'PO number: ' . $po['reference'], 'Net total £120.00', 'VAT £24.00', 'Total £144.00']);
    usleep(300000);
    eq('needs_review', $row($over)['status']);
    $p = implode(' ', $problems($over));
    ok(str_contains($p, 'over by £28.00'), $p);
    ok(str_contains($p, 'already been invoiced'), $p);
    ok((bool)array_filter(array_slice(sent_mails(), $before), fn($m) => str_contains($m, 'Invoice to check')), 'warning emailed');

    // No PO number; duplicate invoice number; unknown PO; wrong supplier.
    $noPo = $upload(['Kit Distribution Ltd', 'Invoice No: KD-4003', 'Total £10.00']);
    ok(str_contains(implode(' ', $problems($noPo)), 'no purchase order number'));
    $dup = $upload(['Kit Distribution Ltd', 'Invoice No: KD-4001', 'Order ref: ' . $po['reference'], 'Subtotal £92.00', 'Total £110.40']);
    ok(str_contains(implode(' ', $problems($dup)), 'duplicate'));
    $unknown = $upload(['Kit Distribution Ltd', 'Invoice No: KD-4004', 'PO: PO-999999', 'Total £10.00']);
    ok(str_contains(implode(' ', $problems($unknown)), "doesn't match any of ours"));
    $wrong = $upload(['Phones Direct', 'Invoice No: PD-1', 'Your order ref: ' . $po['reference'], 'Subtotal £92.00', 'Total £110.40']);
    ok(str_contains(implode(' ', $problems($wrong)), 'was raised with Kit Distribution Ltd'));
    $png = tempnam(sys_get_temp_dir(), 'png');
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    // A scan (no text) with the built-in reader asks for Claude or manual entry.
    $img = invoice_upload(['name' => 'photo.jpg', 'tmp_name' => $png, 'error' => UPLOAD_ERR_OK], (int)$po['id'], null, false);
    ok(str_contains(implode(' ', $problems($img)), 'Photos can only be read with Claude'));

    // Claude reads it instead (stand-in API checks the request).
    global $anthPort, $anthState;
    config_ref()['anthropic_url'] = "http://127.0.0.1:$anthPort";
    set_setting('invoice_reader', 'claude');
    set_setting('anthropic_api_key', 'sk-ant-test');
    eq('claude', invoice_reader());
    file_put_contents("$anthState.po", $po['reference']);
    $c = invoice_upload(['name' => 'scan.png', 'tmp_name' => $png, 'error' => UPLOAD_ERR_OK], null, null, false);
    $state = json_decode(file_get_contents($anthState), true);
    eq([], $state['problems'], 'request shape');
    eq(['claude-opus-5-5', 'low', 'image'], [$state['last']['model'], $state['last']['output_config']['effort'], $state['last']['messages'][0]['content'][0]['type']]);
    $cr = $row($c);
    eq(['KD-5001', 'claude', $kit, (int)$po['id'], 36.5], [$cr['invoice_number'], $cr['reader'], (int)$cr['supplier_id'], (int)$cr['po_id'], (float)$cr['net']]);
    ok(str_contains(implode(' ', $problems($c)), 'under by £55.50'), implode(' ', $problems($c)));
    @unlink("$anthState.po");
    eq('Switch', json_decode($cr['line_items'], true)[0]['description']);
    // Brief outages are retried: two "overloaded" replies, then success.
    config_ref()['anthropic_no_sleep'] = true;
    $retry = fn(string $fail) => (file_put_contents("$anthState.fail", $fail) || true) && (file_put_contents($anthState, json_encode(['requests' => 0])) || true);
    $retry('2 529');
    $r1 = invoice_upload(['name' => 'scan2.png', 'tmp_name' => $png, 'error' => UPLOAD_ERR_OK], null, null, false);
    eq(['claude', 3], [$row($r1)['reader'], json_decode(file_get_contents($anthState), true)['requests']], 'third attempt succeeded');
    $retry('1 429');
    $r2 = invoice_upload(['name' => 'scan3.png', 'tmp_name' => $png, 'error' => UPLOAD_ERR_OK], null, null, false);
    eq(['claude', 2], [$row($r2)['reader'], json_decode(file_get_contents($anthState), true)['requests']], 'rate limit retried');
    $retry('5 529');
    $r3 = invoice_upload(['name' => 'scan4.png', 'tmp_name' => $png, 'error' => UPLOAD_ERR_OK], null, null, false);
    eq(3, json_decode(file_get_contents($anthState), true)['requests'], 'gives up after 3 attempts');
    ok(str_contains(implode(' ', $problems($r3)), "Claude couldn't read the invoice (529)"));
    @unlink("$anthState.fail");
    $retry('0 529');
    set_setting('anthropic_api_key', 'wrong');
    $bad = invoice_upload(['name' => 'inv.pdf', 'tmp_name' => make_test_invoice_pdf(['Kit Distribution Ltd', 'Invoice No: KD-4009', 'Total £5.00']), 'error' => UPLOAD_ERR_OK], null, null, false);
    eq(['builtin', 'KD-4009'], [$row($bad)['reader'], $row($bad)['invoice_number']], 'falls back to the built-in reader if Claude fails');
    ok(str_contains(implode(' ', $problems($bad)), "Claude couldn't read the invoice"));
    eq(1, json_decode(file_get_contents($anthState), true)['requests'], 'a bad key (400) is not retried');
    db_exec('DELETE FROM supplier_invoices WHERE id IN (?, ?, ?)', [$r1, $r2, $r3]);
    ok(anthropic_retry_delay(1, '7') === 7.0 && anthropic_retry_delay(1, '600') === 20.0 && anthropic_retry_delay(3, null) <= 5.0);
    set_setting('invoice_reader', 'builtin');
    set_setting('anthropic_api_key', null);

    // Correcting by hand re-matches; approving records who.
    db_exec('UPDATE supplier_invoices SET net = 92, vat = 18.40, total = 110.40, invoice_number = ? WHERE id = ?', ['KD-4002b', $over]);
    db_exec('DELETE FROM supplier_invoices WHERE id IN (?, ?, ?)', [$dup, $wrong, $c]);
    db_exec('UPDATE supplier_invoices SET status = ? WHERE id = ?', ['disputed', $good]);
    eq([], invoice_match($over), 'now matches once the first invoice is disputed');
    eq('matched', $row($over)['status']);
    eq(['KD-1', 9.5], [invoice_read_text("Invoice no: KD-1\nTotal: 9.50")['invoice_number'], invoice_read_text("Invoice no: KD-1\nTotal: 9.50")['gross_total']]);
    eq(['2026-03-04', '2026-10-03', null], [invoice_date('04/03/2026'), invoice_date('3rd October 2026'), invoice_date('')]);
    eq([1234.5, 1234.5, null], [invoice_amount('£1,234.50'), invoice_amount('1234,50'), invoice_amount('n/a')]);
});

proc_terminate($sinkProc);
proc_terminate($anthProc);
@unlink($anthState);
array_map('unlink', glob("$smtpDir/*") ?: []);
exec('rm -rf ' . escapeshellarg($cfg['storage_path']));
@rmdir($smtpDir);

echo "Billing diary\n";
test('billing diary: every service change is recorded with its effect on billing, to tick off', function () {
    as_role('super_admin');
    $acct = create('accounts', ['name' => 'Diary Test Ltd', 'type' => 'business', 'status' => 'active']);
    $product = create('products', ['sku' => 'DIARY-FTTP', 'name' => 'Diary Fibre', 'category' => 'broadband', 'monthly_price' => '90', 'billing_frequency' => 'quarterly', 'term_months' => '24']);
    $types = fn() => array_column(db_all('SELECT change_type FROM service_changes WHERE account_id = ? ORDER BY id', [$acct]), 'change_type');
    $last = fn() => db_one('SELECT * FROM service_changes WHERE account_id = ? ORDER BY id DESC LIMIT 1', [$acct]);
    $before = fn($id) => db_one('SELECT * FROM services WHERE id = ?', [$id]);

    // Added while pending: noted, but not billed yet.
    $id = create('services', ['account_id' => $acct, 'product_id' => $product, 'service_type' => 'broadband', 'identifier' => 'TBC', 'status' => 'pending', 'setup_fee' => '99']);
    service_changed($id, null);
    $e = $last();
    eq(['added', null, null], [$e['change_type'], $e['monthly_change'], $e['one_off']]);
    ok(str_contains($e['summary'], 'Diary Fibre') && str_contains($e['summary'], 'billed once live'));

    // Goes live with its number: billing starts (product price per month, as it has none of its own) plus the setup fee.
    $b = $before($id);
    db_exec("UPDATE services SET status = 'active', identifier = 'BB-12345', start_date = '2026-10-03' WHERE id = ?", [$id]);
    service_changed($id, $b);
    eq(['added', 'live', 'number'], $types());
    $live = db_one("SELECT * FROM service_changes WHERE account_id = ? AND change_type = 'live'", [$acct]);
    eq([30.0, 99.0, '2026-10-03'], [(float)$live['monthly_change'], (float)$live['one_off'], $live['effective_date']]);
    eq(['TBC', 'BB-12345'], [db_value("SELECT from_value FROM service_changes WHERE account_id = ? AND change_type = 'number'", [$acct]), db_value("SELECT to_value FROM service_changes WHERE account_id = ? AND change_type = 'number'", [$acct])]);

    // Repriced: the difference.
    $b = $before($id);
    db_exec("UPDATE services SET monthly_price = 35 WHERE id = ?", [$id]);
    service_changed($id, $b);
    $e = $last();
    eq(['price', 5.0, '£30.00/mo', '£35.00/mo'], [$e['change_type'], (float)$e['monthly_change'], $e['from_value'], $e['to_value']]);

    // Nothing billing-related changed: nothing recorded.
    $b = $before($id);
    db_exec("UPDATE services SET notes = 'just a note' WHERE id = ?", [$id]);
    eq(0, service_diary_record($id, $b));

    // Ceased: billing stops.
    $b = $before($id);
    db_exec("UPDATE services SET status = 'ceased' WHERE id = ?", [$id]);
    service_changed($id, $b);
    eq(['ceased', -35.0], [$last()['change_type'], (float)$last()['monthly_change']]);

    // Cancelled before going live, and deleted.
    $p = create('services', ['account_id' => $acct, 'service_type' => 'mobile', 'identifier' => '07700 900555', 'status' => 'pending', 'monthly_price' => '12']);
    service_changed($p, null);
    $b = $before($p);
    db_exec("UPDATE services SET status = 'ceased' WHERE id = ?", [$p]);
    service_changed($p, $b);
    eq(['cancelled', null], [$last()['change_type'], $last()['monthly_change']]);
    $gone = create('services', ['account_id' => $acct, 'service_type' => 'mobile', 'identifier' => '07700 900556', 'status' => 'active', 'monthly_price' => '10']);
    service_changed($gone, null);
    service_diary_removed($before($gone));
    db_exec('DELETE FROM services WHERE id = ?', [$gone]);
    eq(['removed', -10.0, null, '07700 900556'], [$last()['change_type'], (float)$last()['monthly_change'], $last()['service_id'], $last()['identifier']], 'kept after the service is deleted');

    // The month: totals, what's left to check, and ticking off.
    $month = date('Y-m');
    $rows = billing_diary_rows($month, ['account_id' => $acct]);
    eq(9, count($rows)); // added, live, number, price, ceased; added, cancelled; added, removed
    $sum = array_sum(array_map(fn($r) => (float)$r['monthly_change'], $rows));
    eq(30.0 + 5 - 35 + 10 - 10, $sum, 'net change in monthly billing');
    $summary = billing_diary_summary($month);
    ok($summary['unchecked'] >= 9 && $summary['by_type']['live'] >= 1);
    db_exec('UPDATE service_changes SET checked_at = NOW(), checked_by = 1 WHERE id = ?', [$rows[0]['id']]);
    eq(8, count(billing_diary_rows($month, ['account_id' => $acct, 'checked' => 'no'])));
    eq(1, count(billing_diary_rows($month, ['account_id' => $acct, 'checked' => 'yes'])));
    eq(1, count(billing_diary_rows($month, ['account_id' => $acct, 'type' => 'price'])));
    eq([], billing_diary_rows(date('Y-m', strtotime('-2 months')), ['account_id' => $acct]), 'other months are separate');
    eq(date('Y-m'), billing_diary_month('2026-13'));
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
