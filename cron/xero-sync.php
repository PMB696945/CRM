<?php
declare(strict_types=1);

/*
 * Sync customer balances from Xero. Schedule it as a cron job, e.g. hourly:
 *   php /home/youraccount/crm/cron/xero-sync.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run from the command line / cron.');
}

require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/installer.php';

migrate();

if (!xero_connected()) {
    fwrite(STDERR, "Xero is not connected. Connect it from the Xero page in the CRM first.\n");
    exit(1);
}

try {
    $s = xero_sync();
    echo date('c') . " Xero sync OK: {$s['contacts']} contacts, {$s['invoices']} unpaid invoices, {$s['linked']} newly linked ({$s['seconds']}s)\n";
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' Xero sync failed: ' . $e->getMessage() . "\n");
    exit(1);
}
