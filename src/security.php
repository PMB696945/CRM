<?php
declare(strict_types=1);

/*
 * Security helpers: encryption of stored secrets, sign-in throttling, audit
 * log, TOTP two-factor codes, and HTTP security headers.
 */

/* ------------------------------------------------------------ Encryption --- */

/** Settings that hold credentials; stored encrypted in the database. */
const SECRET_SETTINGS = [
    'xero_client_secret', 'xero_access_token', 'xero_refresh_token',
    'gocardless_access_token', 'signable_api_key', 'signable_webhook_secret', 'smtp_password', 'mailchimp_api_key', 'mandrill_api_key', 'giacom_password',
];

/**
 * The 32-byte encryption key: config.php 'app_key' if set, otherwise the
 * app.key file next to config.php (created on first use, not web-accessible).
 */
function app_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    if ($configured = config('app_key')) {
        return $key = hash('sha256', (string)$configured, true);
    }
    $file = config('app_key_file') ?: APP_ROOT . '/app.key';
    if (!is_file($file)) {
        $new = base64_encode(random_bytes(32));
        if (@file_put_contents($file, $new . "\n", LOCK_EX) === false) {
            throw new RuntimeException("Couldn't create the encryption key file $file. Make sure the CRM folder is writable, or set 'app_key' in config.php.");
        }
        @chmod($file, 0600);
    }
    return $key = hash('sha256', trim((string)file_get_contents($file)), true);
}

function encrypt_secret(string $plain): string
{
    $iv = random_bytes(12);
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
}

/** Decrypt a value; plain (legacy) values pass through. Returns null if it can't be decrypted. */
function decrypt_secret(?string $value): ?string
{
    if ($value === null || !str_starts_with($value, 'enc:v1:')) {
        return $value;
    }
    $raw = base64_decode(substr($value, 7), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? null : $plain;
}

/* ------------------------------------------------------- Sign-in throttle --- */

const LOGIN_MAX_PER_EMAIL = 5;   // failed attempts per email…
const LOGIN_MAX_PER_IP = 20;     // …or per IP address…
const LOGIN_WINDOW_MINUTES = 15; // …within this many minutes locks sign-in for that long

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Minutes until sign-in is allowed again for this email/IP (0 = allowed). */
function login_locked_minutes(string $email): int
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_MINUTES * 60);
    $email = strtolower(trim($email));
    $byEmail = db_one('SELECT COUNT(*) AS n, MAX(attempted_at) AS last FROM login_attempts WHERE email = ? AND success = 0 AND attempted_at > ?', [$email, $since]);
    $byIp = db_one('SELECT COUNT(*) AS n, MAX(attempted_at) AS last FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at > ?', [client_ip(), $since]);
    $last = null;
    if ((int)$byEmail['n'] >= LOGIN_MAX_PER_EMAIL) {
        $last = $byEmail['last'];
    }
    if ((int)$byIp['n'] >= LOGIN_MAX_PER_IP) {
        $last = max((string)$last, (string)$byIp['last']);
    }
    if (!$last) {
        return 0;
    }
    return max(1, (int)ceil((strtotime($last) + LOGIN_WINDOW_MINUTES * 60 - time()) / 60));
}

function record_login_attempt(string $email, bool $success): void
{
    db_exec('INSERT INTO login_attempts (email, ip, success) VALUES (?, ?, ?)', [mb_substr(strtolower(trim($email)), 0, 190), client_ip(), $success ? 1 : 0]);
    if ($success) {
        // A successful sign-in clears that email's failures.
        db_exec('DELETE FROM login_attempts WHERE email = ? AND success = 0', [strtolower(trim($email))]);
    }
    if (random_int(1, 50) === 1) {
        db_exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 30 DAY');
    }
}

/* -------------------------------------------------------------- Audit log --- */

/** Tables whose rows belong to a customer, so audit entries can be shown on the customer's page. */
const AUDIT_ACCOUNT_TABLES = ['contacts', 'sites', 'services', 'tickets', 'opportunities', 'activities', 'quotes', 'contracts', 'approval_requests'];

/**
 * Record who did what. $changes is [field => ['from' => old, 'to' => new]] (or any
 * small array of details). The log is append-only: nothing in the CRM edits or deletes it.
 */
function audit(string $action, string $summary = '', ?string $entity = null, ?int $entityId = null, ?int $userId = null, ?array $changes = null, ?int $accountId = null): void
{
    $GLOBALS['audit_written'] = true;
    try {
        if ($accountId === null && $entityId) {
            if ($entity === 'accounts') {
                $accountId = $entityId;
            } elseif (in_array($entity, AUDIT_ACCOUNT_TABLES, true)) {
                $accountId = ($v = db_value("SELECT account_id FROM $entity WHERE id = ?", [$entityId])) ? (int)$v : null;
            }
        }
        $json = $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null;
        if ($json !== null && strlen($json) > 60000) {
            $json = json_encode(['note' => 'Too many changes to record in full.']);
        }
        db_exec('INSERT INTO audit_log (user_id, action, entity, entity_id, account_id, summary, changes, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $userId ?? (current_user()['id'] ?? null), $action, $entity, $entityId, $accountId, mb_substr($summary, 0, 500), $json, client_ip(),
        ]);
    } catch (PDOException $e) {
        error_log('Audit log write failed: ' . $e->getMessage()); // never block the action itself
    }
}

/* ------------------------------------------------------------------ TOTP --- */

function base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $c) {
        $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

/** RFC 6238 code (6 digits, 30-second steps, SHA-1) for a time step. */
function totp_code(string $secretBase32, int $step): string
{
    $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $step), base32_decode($secretBase32), true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Check a code, allowing one step of clock drift either way. Returns the
 * matched time step (to stop the same code being used twice) or null.
 */
function totp_verify(string $secretBase32, string $code, int $lastUsedStep = 0, ?int $now = null): ?int
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return null;
    }
    $step = intdiv($now ?? time(), 30);
    foreach ([0, -1, 1] as $drift) {
        $s = $step + $drift;
        if ($s > $lastUsedStep && hash_equals(totp_code($secretBase32, $s), $code)) {
            return $s;
        }
    }
    return null;
}

function totp_uri(string $secretBase32, string $email): string
{
    $issuer = company('name', config('app_name'));
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email) . '?' . http_build_query(['secret' => $secretBase32, 'issuer' => $issuer, 'digits' => 6, 'period' => 30]);
}

/** Ten one-time recovery codes like "4F7K-9QX2". Returns [plain codes, hashed JSON]. */
function make_recovery_codes(): array
{
    $codes = [];
    for ($i = 0; $i < 10; $i++) {
        $raw = strtoupper(substr(base32_encode(random_bytes(5)), 0, 8));
        $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }
    return [$codes, json_encode(array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $codes))];
}

/** Use a recovery code: returns the remaining hashes JSON, or null if it didn't match. */
function use_recovery_code(string $hashesJson, string $code): ?string
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    $code = substr($code, 0, 4) . '-' . substr($code, 4, 4);
    $hashes = json_decode($hashesJson ?: '[]', true) ?: [];
    foreach ($hashes as $i => $hash) {
        if (password_verify($code, $hash)) {
            unset($hashes[$i]);
            return json_encode(array_values($hashes));
        }
    }
    return null;
}

/* ------------------------------------------------------ Security headers --- */

function csp_nonce(): string
{
    static $nonce = null;
    return $nonce ??= base64_encode(random_bytes(16));
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** Send security headers; redirect to HTTPS when that's switched on. */
function security_headers(): void
{
    if (setting('force_https') === '1' && !is_https() && PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') {
        header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . csp_nonce() . "'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; "
        . "form-action 'self' https://login.xero.com https://*.xero.com");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
