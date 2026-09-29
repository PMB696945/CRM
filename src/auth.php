<?php
declare(strict_types=1);

const SESSION_MAX_HOURS = 12;          // sign in again at least this often
const TWO_FACTOR_PENDING_SECONDS = 300; // time allowed to enter the code after the password

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)(SESSION_MAX_HOURS * 3600));
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => is_https(),
    ]);
    session_name('telecomcrm');
    session_start();
    enforce_session_timeout();
}

/** Sign out idle sessions (default 60 minutes, set in Settings) and very old ones. */
function enforce_session_timeout(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $idle = max(5, (int)(setting('session_idle_minutes') ?: 60)) * 60;
    $now = time();
    $expired = ($now - (int)($_SESSION['last_activity'] ?? $now)) > $idle
        || ($now - (int)($_SESSION['login_time'] ?? $now)) > SESSION_MAX_HOURS * 3600;
    if ($expired) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['flash'] = ['message' => 'You were signed out after a period of inactivity. Please sign in again.', 'type' => 'error'];
        return;
    }
    $_SESSION['last_activity'] = $now;
}

function current_user(bool $refresh = false): ?array
{
    static $user = false;
    if ($user === false || $refresh) {
        $id = $_SESSION['user_id'] ?? null;
        $user = $id ? db_one('SELECT id, name, email, role, totp_enabled FROM users WHERE id = ? AND active = 1', [$id]) : null;
        if ($id) {
            $_SESSION['is_admin'] = ($user['role'] ?? null) === 'admin';
        }
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

/** True when this user must set up two-factor sign-in before using the CRM. */
function must_set_up_2fa(): bool
{
    $user = current_user();
    return $user && !$user['totp_enabled'] && setting('require_2fa') === '1';
}

/**
 * Check email + password. Returns ['status' => ok|2fa|invalid|locked, 'minutes' => int].
 * On "2fa" the user must still enter a code (see verify_second_factor()).
 */
function attempt_login(string $email, string $password): array
{
    $email = strtolower(trim($email));
    if ($minutes = login_locked_minutes($email)) {
        audit('login_locked', "Sign-in blocked for $email (too many attempts)");
        return ['status' => 'locked', 'minutes' => $minutes];
    }
    $user = db_one('SELECT id, password_hash, totp_enabled FROM users WHERE email = ? AND active = 1', [$email]);
    // For unknown emails, spend the same time hashing so timing doesn't reveal which emails exist.
    $valid = $user ? password_verify($password, $user['password_hash']) : (password_hash($password, PASSWORD_DEFAULT) && false);
    if (!$valid) {
        record_login_attempt($email, false);
        audit('login_failed', "Failed sign-in for $email");
        return ['status' => 'invalid', 'minutes' => 0];
    }
    if ($user['totp_enabled']) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['pending_2fa'] = ['user_id' => (int)$user['id'], 'email' => $email, 'at' => time()];
        return ['status' => '2fa', 'minutes' => 0];
    }
    complete_login((int)$user['id'], $email);
    return ['status' => 'ok', 'minutes' => 0];
}

/** Second step: a 6-digit authenticator code or a recovery code. */
function verify_second_factor(string $code): array
{
    $pending = $_SESSION['pending_2fa'] ?? null;
    if (!$pending || time() - $pending['at'] > TWO_FACTOR_PENDING_SECONDS) {
        unset($_SESSION['pending_2fa']);
        return ['status' => 'expired', 'minutes' => 0];
    }
    if ($minutes = login_locked_minutes($pending['email'])) {
        return ['status' => 'locked', 'minutes' => $minutes];
    }
    $user = db_one('SELECT id, totp_secret, totp_last_step, recovery_codes FROM users WHERE id = ? AND active = 1', [$pending['user_id']]);
    $secret = $user ? decrypt_secret($user['totp_secret']) : null;
    if ($user && $secret && ($step = totp_verify($secret, $code, (int)$user['totp_last_step'])) !== null) {
        db_exec('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $user['id']]);
    } elseif ($user && ($remaining = use_recovery_code((string)$user['recovery_codes'], $code)) !== null) {
        db_exec('UPDATE users SET recovery_codes = ? WHERE id = ?', [$remaining, $user['id']]);
        audit('2fa_recovery_used', 'Signed in with a recovery code (' . count(json_decode($remaining, true)) . ' left)', 'users', (int)$user['id'], (int)$user['id']);
    } else {
        record_login_attempt($pending['email'], false);
        audit('login_failed', "Wrong two-factor code for {$pending['email']}", 'users', $pending['user_id'], $pending['user_id']);
        return ['status' => 'invalid', 'minutes' => 0];
    }
    unset($_SESSION['pending_2fa']);
    complete_login((int)$user['id'], $pending['email']);
    return ['status' => 'ok', 'minutes' => 0];
}

function complete_login(int $userId, string $email): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $userId;
    $_SESSION['login_time'] = $_SESSION['last_activity'] = time();
    record_login_attempt($email, true);
    db_exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$userId]);
    $_SESSION['is_admin'] = db_value('SELECT role FROM users WHERE id = ?', [$userId]) === 'admin';
    audit('login', 'Signed in', 'users', $userId, $userId);
}

function logout(): void
{
    if (!empty($_SESSION['user_id'])) {
        audit('logout', 'Signed out', 'users', (int)$_SESSION['user_id'], (int)$_SESSION['user_id']);
    }
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}
