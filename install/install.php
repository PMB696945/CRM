<?php
declare(strict_types=1);

/*
 * Command-line installer.
 *
 *   php install/install.php --email=you@example.com --password=secret123 [--name="Your Name"] [--demo]
 *
 * Creates the tables (safe to re-run) and an admin user. --demo loads sample
 * customers, services, tickets and opportunities.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this installer from the command line.');
}

require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/installer.php';

$opts = getopt('', ['email:', 'password:', 'name::', 'demo']);

try {
    db();
} catch (PDOException $e) {
    fwrite(STDERR, "Could not connect to MySQL: {$e->getMessage()}\nCheck config.php.\n");
    exit(1);
}

install_schema();
echo "✔ Schema installed\n";

if (!empty($opts['email'])) {
    $password = (string)($opts['password'] ?? '');
    if (strlen($password) < 8) {
        fwrite(STDERR, "Password must be at least 8 characters.\n");
        exit(1);
    }
    $created = create_admin($opts['name'] ?? 'Administrator', $opts['email'], $password);
    echo ($created ? '✔ Created' : '✔ Updated') . " admin user {$opts['email']}\n";
} elseif (!is_installed()) {
    echo "! No users exist yet. Re-run with --email=... --password=... to create an admin.\n";
}

if (isset($opts['demo'])) {
    require __DIR__ . '/demo_data.php';
    seed_demo_data();
    echo "✔ Demo data loaded\n";
}

echo "Done.\n";
