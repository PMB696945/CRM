<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

function config(?string $key = null): mixed
{
    static $config = null;
    if ($config === null) {
        $file = is_file(APP_ROOT . '/config.php') ? APP_ROOT . '/config.php' : APP_ROOT . '/config.sample.php';
        $config = require $file;
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

date_default_timezone_set(config('timezone') ?: 'UTC');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/entities.php';
require_once __DIR__ . '/repository.php';
