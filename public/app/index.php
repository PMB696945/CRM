<?php
declare(strict_types=1);

require (require dirname(__DIR__) . '/app_root.php') . '/src/bootstrap.php';
require APP_ROOT . '/src/controllers.php';

security_headers();

if (!is_file(APP_ROOT . '/config.php')) {
    redirect('install.php');
}

// Apply database upgrades after an update (e.g. new features' tables).
require_once APP_ROOT . '/src/installer.php';
if (schema_version() < latest_schema_version()) {
    if (!is_installed()) {
        redirect('install.php');
    }
    try {
        migrate();
    } catch (PDOException $e) {
        http_response_code(503);
        exit('<!doctype html><meta charset="utf-8"><title>Database update needed</title><body style="font-family:system-ui;max-width:640px;margin:3rem auto;padding:0 1rem">'
            . '<h1>Database update needed</h1><p>This version of the CRM needs to update the database, but the database user '
            . 'doesn\'t have permission. In your hosting control panel, give the database user <b>CREATE</b>, <b>ALTER</b>, '
            . '<b>INDEX</b> and <b>REFERENCES</b> privileges (or All Privileges), then reload this page.</p>'
            . '<p style="color:#666">Details: ' . h($e->getMessage()) . '</p>');
    }
}

start_session();

$page = query('page', 'dashboard');

if ($page === 'login') {
    login_controller();
    exit;
}
if ($page === 'logout') {
    if (is_post()) {
        verify_csrf();
        logout();
    }
    redirect(url('login'));
}

require_login();

// After the page has been sent, check for tickets waiting too long in a queue
// (at most once a minute), so alerts go out even without a frequent cron job.
register_shutdown_function(function (): void {
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }
    ticket_pickup_alerts_throttled();
});

// Safety net for the audit trail: record any change (POST) that wasn't logged in more detail.
register_shutdown_function(function () use ($page): void {
    if (is_post() && empty($GLOBALS['audit_written']) && http_response_code() < 400 && !in_array($page, ['refs', 'logout'], true)) {
        $action = query('action');
        audit('action', 'Submitted ' . humanize($page) . ($action !== '' ? ' → ' . humanize($action) : '') . (query_int('id') ? ' #' . query_int('id') : ''),
            entity($page) ? $page : null, query_int('id'));
    }
});

// Two-factor sign-in required but not set up yet: only the profile page is available.
if (must_set_up_2fa() && !in_array($page, ['profile', 'logout'], true)) {
    flash('Your administrator requires two-factor sign-in. Please set it up to continue.', 'error');
    redirect(url('profile'));
}

match (true) {
    $page === 'dashboard' => dashboard_controller(),
    $page === 'pipeline'  => pipeline_controller(),
    $page === 'search'    => search_controller(),
    $page === 'refs'      => refs_controller(),
    $page === 'users'     => users_controller(),
    $page === 'profile'   => profile_controller(),
    $page === 'xero'      => xero_controller(),
    $page === 'gocardless' => gocardless_controller(),
    $page === 'quotes'    => quotes_controller(),
    $page === 'contracts' => contracts_controller(),
    $page === 'contract_templates' => contract_templates_controller(),
    $page === 'settings'  => settings_controller(),
    $page === 'signable'  => signable_controller(),
    $page === 'audit'     => audit_controller(),
    $page === 'roles'     => roles_controller(),
    $page === 'approvals' => approvals_controller(),
    $page === 'campaigns' => campaigns_controller(),
    $page === 'mailchimp' => mailchimp_controller(),
    $page === 'giacom'    => giacom_controller(),
    $page === 'queue'     => queue_controller(),
    $page === 'ticket_groups' => ticket_groups_controller(),
    $page === 'documents' => documents_controller(),
    $page === 'error_log' => error_log_controller(),
    $page === 'purchase_orders' => purchase_orders_controller(),
    $page === 'price_import' => price_import_controller(),
    $page === 'giacom_settings' => (function () { $_GET['action'] = 'settings'; giacom_controller(); })(),
    entity($page) !== null => entity_controller($page),
    default               => not_found(),
};
