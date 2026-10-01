<?php
declare(strict_types=1);

/** Errors talking to an external service (Xero, GoCardless), safe to show to staff. */
class IntegrationException extends RuntimeException
{
}

/**
 * Minimal HTTP client. Returns [status, decoded JSON body (or raw string), lower-cased headers].
 */
function http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60): array
{
    $responseHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new IntegrationException(curl_error($ch));
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $decoded = json_decode((string)$raw, true);
    return [$status, $decoded ?? $raw, $responseHeaders];
}
