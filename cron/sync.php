<?php
declare(strict_types=1);

/*
 * Sync every connected integration (Xero balances, GoCardless mandates, signing reminders,
 * Mailchimp unsubscribes) and carry on sending queued service alert / marketing emails.
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

// Agreements still waiting to be signed get a reminder every few days (up to three).
if (mail_configured()) {
    try {
        $n = esign_send_reminders();
        echo date('c') . " Signing reminders: $n sent\n";
    } catch (Throwable $e) {
        fwrite(STDERR, date('c') . ' Signing reminders failed: ' . $e->getMessage() . "\n");
        $failed = true;
    }
}

if (mailchimp_configured()) {
    try {
        $n = mailchimp_sync_unsubscribes();
        echo date('c') . " Mailchimp OK: $n contacts unsubscribed\n";
    } catch (Throwable $e) {
        fwrite(STDERR, date('c') . ' Mailchimp check failed: ' . $e->getMessage() . "\n");
        $failed = true;
    }
}

if (giacom_configured()) {
    try {
        $r = giacom_sync();
        echo date('c') . " Giacom OK: {$r['events']} order updates, {$r['status_changes']} status changes\n";
    } catch (Throwable $e) {
        fwrite(STDERR, date('c') . ' Giacom check failed: ' . $e->getMessage() . "\n");
        $failed = true;
    }
}

try {
    if ($n = ticket_pickup_alerts()) {
        echo date('c') . " Tickets: alerted admins about $n ticket(s) not picked up\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' Ticket pick-up check failed: ' . $e->getMessage() . "\n");
    $failed = true;
}

// Carry on sending service alerts / marketing emails that are part-way through.
try {
    $n = campaigns_process_queue();
    if ($n) {
        echo date('c') . " Emails OK: $n campaign emails sent\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' Sending campaign emails failed: ' . $e->getMessage() . "\n");
    $failed = true;
}

exit($failed ? 1 : 0);
