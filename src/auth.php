<?php
declare(strict_types=1);

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_name('telecomcrm');
    session_start();
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $id = $_SESSION['user_id'] ?? null;
        $user = $id ? db_one('SELECT id, name, email, role FROM users WHERE id = ? AND active = 1', [$id]) : null;
    }
    return $user;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? null) === 'admin';
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect(url('login'));
    }
    return $user;
}

function require_admin(): void
{
    if (!is_admin()) {
        forbidden();
    }
}

function attempt_login(string $email, string $password): bool
{
    $user = db_one('SELECT id, password_hash FROM users WHERE email = ? AND active = 1', [strtolower(trim($email))]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    return true;
}

function logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}
