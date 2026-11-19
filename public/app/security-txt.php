<?php
declare(strict_types=1);

// /.well-known/security.txt (RFC 9116): how to report a security problem. Rewritten here by public/.htaccess.
require (require dirname(__DIR__) . '/app_root.php') . '/src/bootstrap.php';

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
$body = is_file(APP_ROOT . '/config.php') ? security_txt() : null;
if ($body === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found\n");
}
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=86400');
echo $body;
