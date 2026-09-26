<?php
declare(strict_types=1);

/* Key/value settings stored in the database (the "settings" table). */

function setting(string $name, mixed $default = null): mixed
{
    $all = settings_cache();
    return array_key_exists($name, $all) ? $all[$name] : $default;
}

function set_setting(string $name, ?string $value): void
{
    if ($value === null) {
        db_exec('DELETE FROM settings WHERE name = ?', [$name]);
    } else {
        db_exec('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
    }
    settings_cache(true);
}

function settings_cache(bool $reset = false): array
{
    static $cache = null;
    if ($reset) {
        $cache = null;
    }
    if ($cache === null) {
        try {
            $cache = array_column(db_all('SELECT name, value FROM settings'), 'value', 'name');
        } catch (PDOException) {
            $cache = []; // not installed / not yet migrated
        }
    }
    return $cache;
}
