<?php
declare(strict_types=1);

/*
 * Sync every connected integration (Xero balances, GoCardless mandates).
 * Schedule as a cron job, e.g. hourly:
 *   php /home/youraccount/crm/cron/sync.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run from the command line / cron.');
}

require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/installer.php';

migrate();
$failed = false;

if (xero_connected()) {
    try {
        $s = xero_sync();
        echo date('c') . " Xero OK: {$s['contacts']} contacts, {$s['invoices']} unpaid invoices, {$s['linked']} newly linked\n";
    } catch (Throwable $e) {
        fwrite(STDERR, date('c') . ' Xero sync failed: ' . $e->getMessage() . "\n");
        $failed = true;
    }
}

if (gc_configured()) {
    try {
        $s = gc_sync();
        echo date('c') . " GoCardless OK: {$s['customers']} customers, {$s['active']} active mandates, {$s['linked']} newly linked\n";
    } catch (Throwable $e) {
        fwrite(STDERR, date('c') . ' GoCardless sync failed: ' . $e->getMessage() . "\n");
        $failed = true;
    }
}

exit($failed ? 1 : 0);
