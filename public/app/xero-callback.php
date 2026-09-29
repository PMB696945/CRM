<?php
declare(strict_types=1);

// Xero sends the user back here after they approve the connection.
require (require dirname(__DIR__) . '/app_root.php') . '/src/bootstrap.php';
require APP_ROOT . '/src/controllers.php';

security_headers();
start_session();
require_login();
require_admin();

$expected = $_SESSION['xero_oauth_state'] ?? '';
$redirectUri = $_SESSION['xero_redirect_uri'] ?? xero_redirect_uri();
unset($_SESSION['xero_oauth_state'], $_SESSION['xero_redirect_uri']);

$state = query('state');
if ($expected === '' || !hash_equals($expected, $state)) {
    flash('The Xero connection attempt expired or was not started from this CRM. Please try again.', 'error');
    redirect(url('xero'));
}
if (query('error') !== '') {
    flash('Xero did not connect: ' . (query('error_description') ?: query('error')), 'error');
    redirect(url('xero'));
}

try {
    xero_exchange_code(query('code'), $redirectUri);
    $orgs = xero_connections();
    if (!$orgs) {
        throw new XeroException('No Xero organisation was authorised.');
    }
    set_setting('xero_tenants', json_encode(array_map(fn($o) => ['id' => $o['tenantId'], 'name' => $o['tenantName'] ?? $o['tenantId']], $orgs)));
    set_setting('xero_tenant_id', $orgs[0]['tenantId']);
    set_setting('xero_tenant_name', $orgs[0]['tenantName'] ?? $orgs[0]['tenantId']);

    $summary = xero_sync();
    flash(sprintf('Connected to %s and synced %d contacts (%d unpaid invoices, %d customers linked automatically).',
        setting('xero_tenant_name'), $summary['contacts'], $summary['invoices'], $summary['linked']));
} catch (XeroException | PDOException $e) {
    flash('Xero connection problem: ' . $e->getMessage(), 'error');
}
redirect(url('xero'));
