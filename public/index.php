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
    entity($page) !== null => entity_controller($page),
    default               => not_found(),
};
