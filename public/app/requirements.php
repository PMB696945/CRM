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

// ---- cPanel's PHP version setting --------------------------------------------
// cPanel's MultiPHP Manager keeps the PHP version as a few lines in the site's .htaccess. Uploading a new
// copy of the CRM replaces that file, so the site drops back to the server's default PHP. While running on
// the right PHP, remember those lines; if an upload has removed them, put them back.

function crm_php_handler_saved_file()
{
    return dirname(__FILE__) . '/php-handler.json';
}

/** The .htaccess files cPanel may keep the PHP version in: the site's document root, and the CRM's own. */
function crm_php_handler_candidates()
{
    $files = array();
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $files[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/.htaccess';
    }
    $public = dirname(dirname(__FILE__));
    $files[] = $public . '/.htaccess';
    $files[] = dirname($public) . '/.htaccess';
    return array_values(array_unique($files));
}

/** cPanel's handler block in some .htaccess text, or null. */
function crm_php_handler_block($text)
{
    if (preg_match('/# php -- BEGIN cPanel-generated handler, do not edit.*?# php -- END cPanel-generated handler, do not edit[^\n]*\n?/s', (string)$text, $m)) {
        return $m[0];
    }
    return null;
}

/** Running on the right PHP: remember where cPanel's handler lines are, and what they say. */
function crm_php_handler_remember()
{
    $found = array();
    foreach (crm_php_handler_candidates() as $file) {
        $block = is_file($file) ? crm_php_handler_block(@file_get_contents($file)) : null;
        if ($block !== null) {
            $found[$file] = $block;
        }
    }
    if (!$found) {
        return;
    }
    $saved = crm_php_handler_saved_file();
    $json = json_encode($found);
    if (!is_file($saved) || @file_get_contents($saved) !== $json) {
        @file_put_contents($saved, $json);
    }
}

/** Running on the wrong PHP: put back cPanel's handler lines an upload removed. Returns true if any were restored. */
function crm_php_handler_restore()
{
    $saved = crm_php_handler_saved_file();
    $found = is_file($saved) ? json_decode((string)@file_get_contents($saved), true) : null;
    if (!is_array($found)) {
        return false;
    }
    $restored = false;
    foreach ($found as $file => $block) {
        if (!in_array($file, crm_php_handler_candidates(), true) || !is_string($block) || crm_php_handler_block($block) === null) {
            continue;
        }
        $current = is_file($file) ? (string)@file_get_contents($file) : '';
        if (crm_php_handler_block($current) !== null) {
            continue; // still there: the wrong PHP is for another reason
        }
        if (@file_put_contents($file, rtrim($block, "\n") . "\n\n" . $current) !== false) {
            $restored = true;
            error_log('CRM: put back cPanel\'s PHP version setting in ' . $file . ' (an upload had replaced it)');
        }
    }
    return $restored;
}

$crmPhpVersionId = getenv('CRM_TEST_PHP_VERSION_ID') ? (int)getenv('CRM_TEST_PHP_VERSION_ID') : PHP_VERSION_ID;
if ($crmPhpVersionId >= 80100) {
    crm_php_handler_remember();
} elseif (crm_php_handler_restore()) {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="2"><title>One moment</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:680px;margin:3rem auto;padding:0 1rem;line-height:1.5;color:#1d2939">'
        . '<h1 style="font-size:1.5rem">One moment&hellip;</h1><p>The files you uploaded replaced the setting that tells the server to use PHP 8. '
        . 'It has been put back. This page will reload by itself.</p></body>';
    exit;
}

// ---- Server requirements ----------------------------------------------------
$crmProblems = array();
if ($crmPhpVersionId < 80100) {
    $crmProblems[] = '<b>PHP 8.1 or newer</b> is required, but this server is running <b>PHP ' . htmlspecialchars(PHP_VERSION) . '</b>. '
        . 'In cPanel, open <i>MultiPHP Manager</i> (or <i>Select PHP Version</i>) and choose PHP 8.1, 8.2 or 8.3 for this domain. '
        . 'If it already says 8.1, choose another version and then 8.1 again: cPanel keeps the setting in the site\'s <code>.htaccess</code> file, '
        . 'and uploading the CRM\'s files replaces that file. Once the CRM has run on PHP 8.1, it puts the setting back by itself after future uploads.';
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
