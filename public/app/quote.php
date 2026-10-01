<?php
declare(strict_types=1);

// Public page where a customer views a quote and accepts or declines it.
require (require dirname(__DIR__) . '/app_root.php') . '/src/bootstrap.php';
require APP_ROOT . '/src/controllers.php';

security_headers();
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
start_session();

$token = query('t');
$quote = quote_by_token($token);
$error = null;
$done = null;

if ($quote && in_array($quote['status'], ['draft', 'cancelled'], true)) {
    $quote = null; // link withdrawn
}
if ($quote) {
    $quote = quote_expire_if_due($quote);
    if ($quote['status'] === 'sent' && !$quote['viewed_at']) {
        db_exec('UPDATE quotes SET viewed_at = NOW() WHERE id = ?', [$quote['id']]);
        log_activity((int)$quote['account_id'], 'note', "Quote {$quote['reference']} viewed by the customer");
    }
    if (is_post()) {
        verify_csrf();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($quote['status'] !== 'sent') {
            $error = 'This quote can no longer be changed.';
        } elseif (($_POST['response'] ?? '') === 'accept') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            if (mb_strlen($name) < 2 || empty($_POST['agree'])) {
                $error = 'Please type your full name and tick the box to accept.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address' . (signable_configured() ? ' – we\'ll send the contract there for you to sign.' : '.');
            } else {
                quote_accept($quote, mb_substr($name, 0, 150), $ip, false, $email, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
                $done = 'accepted';
            }
        } elseif (($_POST['response'] ?? '') === 'decline') {
            quote_decline($quote, mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 2000), $ip);
            $done = 'declined';
        }
        $quote = db_one('SELECT * FROM quotes WHERE id = ?', [$quote['id']]);
    }
}

// The customer can download the quote (with their acceptance record, once accepted).
if ($quote && query('pdf') === '1' && !is_post()) {
    $pdf = quote_pdf($quote);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Quote ' . $quote['reference'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
if (!$quote) {
    http_response_code(404);
}
render('public_quote', [
    'quote'   => $quote,
    'account' => $quote ? db_one('SELECT * FROM accounts WHERE id = ?', [$quote['account_id']]) : null,
    'lines'   => $quote ? quote_lines((int)$quote['id']) : [],
    'error'   => $error,
    'done'    => $done,
    'token'   => $token,
]);
