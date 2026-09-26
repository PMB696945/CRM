<?php
declare(strict_types=1);

/** HTML-escape a value for output. */
function h(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an app URL: url('accounts', ['action' => 'view', 'id' => 3]). */
function url(string $page = 'dashboard', array $params = []): string
{
    $params = array_filter(['page' => $page] + $params, fn($v) => $v !== null && $v !== '');
    return 'index.php?' . http_build_query($params);
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function flash(?string $message = null, string $type = 'success'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function money(mixed $amount): string
{
    return config('currency') . number_format((float)$amount, 2);
}

function fmt_date(?string $date): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date('d M Y', $ts) : '';
}

function fmt_datetime(?string $date): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date('d M Y H:i', $ts) : '';
}

/** Turn an enum key like "awaiting_carrier" into "Awaiting carrier". */
function humanize(?string $value): string
{
    return ucfirst(str_replace('_', ' ', (string)$value));
}

function badge(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $class = 'badge badge-' . preg_replace('/[^a-z0-9_-]/i', '', strtolower($value));
    return '<span class="' . $class . '">' . h(humanize($value)) . '</span>';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session has expired. Please go back, refresh the page and try again.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Read a scalar string from the query string. */
function query(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function query_int(string $key): ?int
{
    $value = query($key);
    return ctype_digit($value) ? (int)$value : null;
}

function render(string $template, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require APP_ROOT . '/templates/' . $template . '.php';
}

/** Render a page inside the main layout. */
function page(string $template, array $vars = [], string $title = ''): void
{
    ob_start();
    render($template, $vars);
    $content = ob_get_clean();
    render('layout', ['content' => $content, 'title' => $title]);
}

function not_found(string $message = 'Page not found.'): never
{
    http_response_code(404);
    page('error', ['message' => $message], 'Not found');
    exit;
}

function forbidden(): never
{
    http_response_code(403);
    page('error', ['message' => 'You do not have permission to do that.'], 'Forbidden');
    exit;
}

/** Days from today until a date (negative when in the past). */
function days_until(?string $date): ?int
{
    if (!$date) {
        return null;
    }
    $today = new DateTimeImmutable('today');
    $target = new DateTimeImmutable($date);
    return (int)$today->diff($target)->format('%r%a');
}
