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

$opts = getopt('', ['email:', 'password:', 'name::', 'demo']);

try {
    db();
} catch (PDOException $e) {
    fwrite(STDERR, "Could not connect to MySQL: {$e->getMessage()}\nCheck config.php.\n");
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/schema.sql');
foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $schema)))) as $statement) {
    db()->exec($statement);
}
echo "✔ Schema installed\n";

if (!empty($opts['email'])) {
    $password = (string)($opts['password'] ?? '');
    if (strlen($password) < 8) {
        fwrite(STDERR, "Password must be at least 8 characters.\n");
        exit(1);
    }
    $email = strtolower($opts['email']);
    $name = $opts['name'] ?? 'Administrator';
    $existing = db_value('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        db_exec("UPDATE users SET password_hash = ?, role = 'admin', active = 1 WHERE id = ?", [password_hash($password, PASSWORD_DEFAULT), $existing]);
        echo "✔ Updated admin user $email\n";
    } else {
        db_exec("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')", [$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        echo "✔ Created admin user $email\n";
    }
} elseif (!db_value('SELECT COUNT(*) FROM users')) {
    echo "! No users exist yet. Re-run with --email=... --password=... to create an admin.\n";
}

if (isset($opts['demo'])) {
    require __DIR__ . '/demo_data.php';
    seed_demo_data();
    echo "✔ Demo data loaded\n";
}

echo "Done.\n";
