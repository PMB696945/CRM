<?php
declare(strict_types=1);

/*
 * Outgoing email: PHP's mail() (the host's mail server) or SMTP with
 * STARTTLS/SSL and login. No external libraries needed.
 */

function company(string $key, string $default = ''): string
{
    return (string)(setting('company_' . $key) ?: $default);
}

/** Our address as tidy lines (no stray spaces, carriage returns or blank lines), as typed in Settings. */
function company_address_lines(): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/u', company('address'))), fn($l) => $l !== ''));
}

/** Our address on one line: "Hadley House, 9 & 10 Croft Street, Cheltenham". */
function company_address_line(): string
{
    return implode(', ', array_map(fn($l) => rtrim($l, ', '), company_address_lines()));
}

function mail_configured(): bool
{
    return (bool)setting('mail_from_email');
}

/** Encode a header value (e.g. subject or name) for non-ASCII text. */
function mail_encode_header(string $value): string
{
    return preg_match('/[^\x20-\x7e]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
}

function mail_address(string $email, string $name = ''): string
{
    $name = trim(str_replace(['"', "\r", "\n"], '', $name));
    return $name === '' ? "<$email>" : mail_encode_header($name) . " <$email>";
}

/**
 * Build the full MIME message (headers + body) as used by both transports.
 * $attachments: [['name' => file name, 'path' => file on disk, 'mime' => type], ...]
 */
function mail_build(string $to, string $toName, string $subject, string $html, ?string $text = null, array $extraHeaders = [], array $attachments = []): array
{
    $from = (string)setting('mail_from_email');
    $fromName = (string)(setting('mail_from_name') ?: company('name', config('app_name')));
    $text ??= trim(html_entity_decode(strip_tags(preg_replace(['#<br\s*/?>#i', '#</(p|div|h\d|tr|li)>#i'], "\n", $html)), ENT_QUOTES, 'UTF-8'));
    $boundary = 'b' . bin2hex(random_bytes(12));
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);

    $headers = [
        'Date: ' . date('r'),
        'From: ' . mail_address($from, $fromName),
        'To: ' . mail_address($to, $toName),
        'Subject: ' . mail_encode_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $mixed = $attachments ? 'm' . bin2hex(random_bytes(12)) : null;
    if ($mixed) {
        $headers[6] = 'Content-Type: multipart/mixed; boundary="' . $mixed . '"';
    }
    if ($replyTo = setting('mail_reply_to')) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    foreach ($extraHeaders as $name => $value) {
        $headers[] = $name . ': ' . str_replace(["\r", "\n"], '', (string)$value);
    }
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text))
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . "--$boundary--\r\n";
    if ($mixed) {
        $parts = "--$mixed\r\nContent-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n" . $body;
        foreach ($attachments as $a) {
            $name = str_replace(['"', "\r", "\n"], '', (string)$a['name']);
            $encoded = preg_match('/[^\x20-\x7e]/', $name) ? "filename*=UTF-8''" . rawurlencode($name) : 'filename="' . $name . '"';
            $parts .= "--$mixed\r\nContent-Type: " . (($a['mime'] ?? '') ?: 'application/octet-stream') . "\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; $encoded\r\n\r\n" . chunk_split(base64_encode((string)file_get_contents($a['path'])));
        }
        $body = $parts . "--$mixed--\r\n";
    }
    return [$headers, $body];
}

/** Send an email. Throws IntegrationException with a readable reason on failure. */
function send_mail(string $to, string $toName, string $subject, string $html, ?string $text = null, array $headers = [], array $attachments = []): void
{
    if (!mail_configured()) {
        throw new IntegrationException('Email isn\'t set up yet. An admin can add it under Settings → Email.');
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new IntegrationException("\"$to\" isn't a valid email address.");
    }
    if (setting('mail_transport') === 'mandrill') {
        mandrill_send($to, $toName, $subject, $html, $text, $headers, $attachments);
        return;
    }
    [$headers, $body] = mail_build($to, $toName, $subject, $html, $text, $headers, $attachments);

    if (setting('mail_transport') === 'smtp') {
        smtp_send((string)setting('mail_from_email'), $to, implode("\r\n", $headers) . "\r\n\r\n" . $body);
        return;
    }
    // mail() takes To and Subject separately.
    $extra = array_values(array_filter($headers, fn($h) => !str_starts_with($h, 'To:') && !str_starts_with($h, 'Subject:')));
    $ok = @mail(mail_address($to, $toName), mail_encode_header($subject), $body, implode("\r\n", $extra), '-f' . setting('mail_from_email'));
    if (!$ok) {
        throw new IntegrationException('The server\'s mail() function refused the message. Try SMTP under Settings → Email.');
    }
}

/** Send through Mailchimp Transactional (formerly Mandrill). */
function mandrill_send(string $to, string $toName, string $subject, string $html, ?string $text, array $headers, array $attachments = []): void
{
    $key = (string)setting('mandrill_api_key');
    if ($key === '') {
        throw new IntegrationException('Add your Mailchimp Transactional API key under Settings → Email.');
    }
    if ($replyTo = setting('mail_reply_to')) {
        $headers['Reply-To'] = $replyTo;
    }
    $payload = ['key' => $key, 'message' => array_filter([
        'html'       => $html,
        'text'       => $text,
        'subject'    => $subject,
        'from_email' => (string)setting('mail_from_email'),
        'from_name'  => (string)(setting('mail_from_name') ?: company('name', config('app_name'))),
        'to'         => [['email' => $to, 'name' => $toName, 'type' => 'to']],
        'headers'    => $headers ?: null,
        'attachments' => $attachments ? array_map(fn($a) => ['type' => ($a['mime'] ?? '') ?: 'application/octet-stream', 'name' => (string)$a['name'],
            'content' => base64_encode((string)file_get_contents($a['path']))], $attachments) : null,
        'track_opens' => true,
        'track_clicks' => false,
    ], fn($v) => $v !== null)];
    [$status, $body] = http_request('POST', config('mandrill_url') ?? 'https://mandrillapp.com/api/1.0/messages/send.json',
        ['Content-Type: application/json'], json_encode($payload));
    if ($status !== 200 || !is_array($body) || !isset($body[0]['status'])) {
        $message = is_array($body) ? ($body['message'] ?? json_encode($body)) : substr((string)$body, 0, 200);
        throw new IntegrationException("Mailchimp Transactional refused the message ($status): $message");
    }
    if (!in_array($body[0]['status'], ['sent', 'queued', 'scheduled'], true)) {
        throw new IntegrationException('Mailchimp Transactional did not send to ' . $to . ': ' . ($body[0]['reject_reason'] ?? $body[0]['status']));
    }
}

/** Minimal SMTP client (RFC 5321) with STARTTLS/SSL and AUTH LOGIN. */
function smtp_send(string $from, string $to, string $message): void
{
    $host = (string)setting('smtp_host');
    $port = (int)(setting('smtp_port') ?: 587);
    $security = (string)(setting('smtp_encryption') ?: 'tls');
    if ($host === '') {
        throw new IntegrationException('SMTP server isn\'t set. Check Settings → Email.');
    }

    $remote = ($security === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($remote, $errno, $errstr, 15);
    if (!$socket) {
        throw new IntegrationException("Couldn't connect to the SMTP server $host:$port ($errstr).");
    }
    stream_set_timeout($socket, 30);

    $read = function () use ($socket): array {
        $lines = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $lines .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return [(int)substr($lines, 0, 3), trim($lines)];
    };
    $cmd = function (string $command, array $expect, string $hideAs = '') use ($socket, $read): string {
        fwrite($socket, $command . "\r\n");
        [$code, $reply] = $read();
        if (!in_array($code, $expect, true)) {
            throw new IntegrationException('SMTP server rejected "' . ($hideAs ?: strtok($command, ' ')) . "\": $reply");
        }
        return $reply;
    };

    try {
        [$code, $greeting] = $read();
        if ($code !== 220) {
            throw new IntegrationException("SMTP server said: $greeting");
        }
        $helo = gethostname() ?: 'localhost';
        $ehlo = $cmd("EHLO $helo", [250]);
        if ($security === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new IntegrationException('Couldn\'t start a secure (TLS) connection with the SMTP server.');
            }
            $ehlo = $cmd("EHLO $helo", [250]);
        }
        if (($user = (string)setting('smtp_username')) !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($user), [334], 'username');
            $cmd(base64_encode((string)setting('smtp_password')), [235], 'password');
        }
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        // Dot-stuffing: lines starting with "." get an extra ".".
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $message));
        fwrite($socket, str_replace("\n", "\r\n", $data) . "\r\n");
        $cmd('.', [250], 'message');
        $cmd('QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

/** Wrap content in a simple, email-client-friendly branded layout. */
function email_layout(string $title, string $bodyHtml): string
{
    $company = h(company('name', config('app_name')));
    $footer = h(implode(' · ', array_filter([company('name'), company('phone'), company('email')])));
    return '<!doctype html><html><body style="margin:0;background:#f2f4f7;font-family:Arial,Helvetica,sans-serif;color:#1d2939">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7;padding:24px 0"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;border:1px solid #e4e7ec">'
        . '<tr><td style="padding:24px 32px;border-bottom:1px solid #e4e7ec;font-size:18px;font-weight:bold;color:#465fff">' . $company . '</td></tr>'
        . '<tr><td style="padding:28px 32px;font-size:15px;line-height:1.55">'
        . '<h1 style="font-size:20px;margin:0 0 16px;color:#101828">' . h($title) . '</h1>' . $bodyHtml . '</td></tr>'
        . '<tr><td style="padding:16px 32px;border-top:1px solid #e4e7ec;font-size:12px;color:#667085">' . $footer . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function email_button(string $url, string $label): string
{
    return '<p style="margin:24px 0"><a href="' . h($url) . '" style="background:#465fff;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;display:inline-block">' . h($label) . '</a></p>';
}

/** Public base URL of the CRM, e.g. https://example.com/crm (no trailing slash). */
function app_url(): string
{
    if ($fixed = setting('app_url')) {
        return rtrim($fixed, '/');
    }
    return detected_app_url();
}

/**
 * When the "CRM web address" setting doesn't match the address the CRM is being
 * used at, links in emails (quotes, contracts) go to the wrong place. Returns a
 * warning, or null when they match (or there's no request to compare with).
 */
function app_url_mismatch(): ?string
{
    $set = rtrim((string)setting('app_url'), '/');
    if ($set === '' || PHP_SAPI === 'cli') {
        return null;
    }
    $here = detected_app_url();
    $norm = fn($u) => strtolower(preg_replace('#^https?://#i', '', rtrim($u, '/')));
    if ($norm($set) === $norm($here)) {
        return null;
    }
    return "Links in emails use the CRM web address $set (Settings), but you're using the CRM at $here. "
        . 'If customers can\'t open links, change or clear that setting.';
}
