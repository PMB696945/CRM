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
