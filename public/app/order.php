<?php
declare(strict_types=1);

// Public page where a customer follows their order's progress.
require (require dirname(__DIR__) . '/app_root.php') . '/src/bootstrap.php';
require APP_ROOT . '/src/controllers.php';

security_headers();
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$token = query('t');
$order = preg_match('/^[a-f0-9]{48}$/', $token) ? db_one('SELECT * FROM customer_orders WHERE token = ?', [$token]) : null;
if (!$order) {
    http_response_code(404);
}
render('public_order', [
    'order'   => $order,
    'account' => $order ? db_one('SELECT name FROM accounts WHERE id = ?', [$order['account_id']]) : null,
    'events'  => $order ? order_events((int)$order['id'], true) : [],
    'progress' => $order ? order_progress($order, order_contract($order)) : [],
]);
