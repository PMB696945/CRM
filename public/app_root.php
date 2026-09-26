<?php
declare(strict_types=1);

/*
 * Locates the CRM application folder (the one containing src/), so the
 * contents of public/ can be uploaded straight into a host's public_html.
 * Looks in, in order:
 *   1. the CRM_APP_ROOT environment variable
 *   2. the parent folder          (standard layout: public/ inside the CRM folder)
 *   3. a "crm" folder alongside   (public_html/ + ~/crm/ outside the web root)
 *   4. a "crm" folder inside      (public_html/ + public_html/crm/)
 */
return (static function (): string {
    $candidates = array_values(array_unique(array_filter([
        getenv('CRM_APP_ROOT') ?: null,
        dirname(__DIR__),
        dirname(__DIR__) . '/crm',
        __DIR__ . '/crm',
    ])));
    foreach ($candidates as $dir) {
        if (@is_file($dir . '/src/bootstrap.php')) {
            return $dir;
        }
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $list = implode('', array_map(fn($d) => '<li><code>' . htmlspecialchars($d) . '/src/</code></li>', $candidates));
    exit(<<<HTML
        <!doctype html><meta charset="utf-8"><title>CRM files not found</title>
        <body style="font-family:system-ui,sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem;line-height:1.5">
        <h1>CRM application files not found</h1>
        <p>This page is running, but it can't find the rest of the CRM (the <code>src</code>, <code>templates</code>
        and <code>install</code> folders). It looked for them in:</p>
        <ul>$list</ul>
        <p>Upload the whole CRM folder (everything except <code>public</code>) to one of those places, and name the
        folder <code>crm</code>. The recommended place is next to <code>public_html</code>, where it can't be reached
        from the web.</p>
        </body>
        HTML);
})();
