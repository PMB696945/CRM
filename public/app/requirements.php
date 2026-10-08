<?php
/*
 * Runs first on every page. Written for very old PHP too, so an unsuitable
 * server shows a clear explanation instead of a blank "500 Internal Server
 * Error". Also turns any later crash into a readable error page and log entry.
 *
 * Keep this file free of modern PHP syntax.
 */

ini_set('display_errors', '0');
$crmLog = dirname(__FILE__) . '/crm-error.log';
if (is_writable(dirname(__FILE__)) || is_writable($crmLog)) {
    ini_set('error_log', $crmLog);
}

function crm_fail_page($title, $html)
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:680px;margin:3rem auto;padding:0 1rem;line-height:1.5;color:#1d2939">'
        . '<h1 style="font-size:1.5rem">' . htmlspecialchars($title) . '</h1>' . $html . '</body>';
    exit;
}

// ---- Server requirements ----------------------------------------------------
$crmProblems = array();
if (PHP_VERSION_ID < 80100) {
    $crmProblems[] = '<b>PHP 8.1 or newer</b> is required, but this server is running <b>PHP ' . htmlspecialchars(PHP_VERSION) . '</b>. '
        . 'In cPanel, open <i>MultiPHP Manager</i> (or <i>Select PHP Version</i>) and choose PHP 8.1, 8.2 or 8.3 for this domain.';
}
$crmExtensions = array('pdo_mysql' => 'MySQL database access', 'curl' => 'Xero and GoCardless', 'mbstring' => 'text handling', 'json' => 'data handling', 'session' => 'sign-in');
foreach ($crmExtensions as $ext => $why) {
    if (!extension_loaded($ext)) {
        $crmProblems[] = 'The PHP extension <b>' . $ext . '</b> (' . $why . ') is not enabled. '
            . 'In cPanel, open <i>Select PHP Version</i> → <i>Extensions</i> and tick <b>' . $ext . '</b>.';
    }
}
if ($crmProblems) {
    crm_fail_page('This server needs a small change', '<p>The CRM can\'t run yet:</p><ul><li>' . implode('</li><li>', $crmProblems) . '</li></ul>'
        . '<p>After changing it, reload this page.</p>');
}

// ---- Readable errors instead of a blank 500 ---------------------------------
function crm_error_page($message, $file, $line)
{
    $ref = strtoupper(substr(md5(uniqid('', true)), 0, 8));
    error_log('CRM error [' . $ref . ']: ' . $message . ' in ' . $file . ':' . $line);

    // Technical details only for a signed-in admin; everyone else gets a reference.
    $isAdmin = false;
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent() && isset($_COOKIE['telecomcrm'])) {
        session_name('telecomcrm');
        @session_start();
    }
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['is_admin']) && !empty($_SESSION['user_id'])) {
        $isAdmin = true;
    }
    if (!$isAdmin) {
        // Neutral wording: dealers on the portal see this too.
        crm_fail_page('Something went wrong', '<p>Sorry, there was an unexpected error. Please try again in a moment.</p>'
            . '<p>If it keeps happening, contact us and quote this reference: <b>' . $ref . '</b></p>');
    }
    crm_fail_page('Something went wrong', '<p>The CRM hit an error it couldn\'t recover from:</p>'
        . '<pre style="white-space:pre-wrap;background:#f2f4f7;padding:1rem;border-radius:8px">' . htmlspecialchars($message)
        . "\n\n" . htmlspecialchars(basename(dirname($file)) . '/' . basename($file) . ' line ' . $line) . '</pre>'
        . '<p>Reference <b>' . $ref . '</b>, also saved in <code>public/app/crm-error.log</code>. Only admins see these details.</p>');
}

set_exception_handler(function ($e) {
    crm_error_page(get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        crm_error_page($e['message'], $e['file'], $e['line']);
    }
});
