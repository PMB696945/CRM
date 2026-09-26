<?php
declare(strict_types=1);

require (require __DIR__ . '/app_root.php') . '/src/bootstrap.php';
require APP_ROOT . '/src/controllers.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

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

match (true) {
    $page === 'dashboard' => dashboard_controller(),
    $page === 'pipeline'  => pipeline_controller(),
    $page === 'search'    => search_controller(),
    $page === 'refs'      => refs_controller(),
    $page === 'users'     => users_controller(),
    $page === 'profile'   => profile_controller(),
    $page === 'xero'      => xero_controller(),
    entity($page) !== null => entity_controller($page),
    default               => not_found(),
};
