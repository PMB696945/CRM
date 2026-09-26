<?php
declare(strict_types=1);

/* Shared by the CLI installer (install/install.php) and the web installer (public/install.php). */

/** Create the base tables (if missing) and apply all migrations. */
function install_schema(): void
{
    $schema = file_get_contents(APP_ROOT . '/install/schema.sql');
    foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $schema)))) as $statement) {
        db()->exec($statement);
    }
    migrate();
}

function latest_schema_version(): int
{
    return max(array_keys(require APP_ROOT . '/install/migrations.php'));
}

function schema_version(): int
{
    try {
        return (int)(db_value("SELECT value FROM settings WHERE name = 'schema_version'") ?? 1);
    } catch (PDOException) {
        return 1; // settings table arrives in version 2
    }
}

/** Apply any pending migrations. Returns the versions applied. */
function migrate(): array
{
    $applied = [];
    $current = schema_version();
    foreach (require APP_ROOT . '/install/migrations.php' as $version => $migration) {
        if ($version <= $current) {
            continue;
        }
        $migration();
        set_setting('schema_version', (string)$version);
        $applied[] = $version;
    }
    return $applied;
}

function column_exists(string $table, string $column): bool
{
    return (bool)db_value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
}

function constraint_exists(string $table, string $name): bool
{
    return (bool)db_value('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?', [$table, $name]);
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
