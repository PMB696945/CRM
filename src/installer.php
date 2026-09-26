<?php
declare(strict_types=1);

/* Shared by the CLI installer (install/install.php) and the web installer (public/install.php). */

function install_schema(): void
{
    $schema = file_get_contents(APP_ROOT . '/install/schema.sql');
    foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $schema)))) as $statement) {
        db()->exec($statement);
    }
}

/** Create an admin, or promote and reset the password of an existing user. Returns true if created. */
function create_admin(string $name, string $email, string $password): bool
{
    $email = strtolower(trim($email));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $existing = db_value('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        db_exec("UPDATE users SET password_hash = ?, role = 'admin', active = 1 WHERE id = ?", [$hash, $existing]);
        return false;
    }
    db_exec("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')", [$name, $email, $hash]);
    return true;
}

/** True once the tables exist and at least one user has been created. */
function is_installed(): bool
{
    try {
        return (int)db_value('SELECT COUNT(*) FROM users') > 0;
    } catch (PDOException) {
        return false;
    }
}

/** PHP source for config.php, based on config.sample.php with the DB details filled in. */
function build_config(array $db): string
{
    $source = file_get_contents(APP_ROOT . '/config.sample.php');
    foreach (['host', 'port', 'name', 'user', 'pass'] as $key) {
        $env = 'CRM_DB_' . strtoupper($key);
        $source = preg_replace(
            "/getenv\\('$env'\\) \\?: '[^']*'/",
            "getenv('$env') ?: " . var_export((string)$db[$key], true),
            $source
        );
    }
    return $source;
}
