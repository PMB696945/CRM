<?php
declare(strict_types=1);

/*
 * Xero integration: OAuth 2.0 connection and a one-way sync of customer
 * balances (Xero → CRM).
 *
 * Balances are calculated from unpaid sales invoices (AUTHORISED ACCREC) less
 * unallocated sales credit notes, converted to the organisation's base
 * currency. Overdue = unpaid invoices whose due date has passed.
 *
 * Credentials and tokens are stored in the settings table.
 */

const XERO_DEFAULT_SCOPES = 'offline_access accounting.contacts.read accounting.invoices.read';

function xero_urls(): array
{
    return (config('xero_urls') ?? []) + [
        'authorize'   => 'https://login.xero.com/identity/connect/authorize',
        'token'       => 'https://identity.xero.com/connect/token',
        'revoke'      => 'https://identity.xero.com/connect/revocation',
        'connections' => 'https://api.xero.com/connections',
        'api'         => 'https://api.xero.com/api.xro/2.0',
    ];
}

function xero_configured(): bool
{
    return setting('xero_client_id') && setting('xero_client_secret');
}

function xero_connected(): bool
{
    return xero_configured() && setting('xero_refresh_token') && setting('xero_tenant_id');
}

/** Absolute URL of the OAuth callback, based on the address the CRM is being used at. */
function xero_redirect_uri(): string
{
    if ($fixed = setting('xero_redirect_uri')) {
        return $fixed;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $dir = str_ends_with($path, '/') ? rtrim($path, '/') : rtrim(dirname($path), '/\\');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/xero-callback.php';
}

function xero_authorize_url(string $state, string $redirectUri): string
{
    return xero_urls()['authorize'] . '?' . http_build_query([
        'response_type' => 'code',
        'client_id'     => setting('xero_client_id'),
        'redirect_uri'  => $redirectUri,
        'scope'         => setting('xero_scopes') ?: XERO_DEFAULT_SCOPES,
        'state'         => $state,
    ], '', '&', PHP_QUERY_RFC3986);
}

/* ---------------------------------------------------------------- HTTP --- */

final class XeroException extends RuntimeException
{
}

/**
 * Minimal HTTP client. Returns [status, decoded JSON body (or raw string), headers].
 */
function xero_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $responseHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
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
        $error = curl_error($ch);
        throw new XeroException("Could not reach Xero: $error");
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $decoded = json_decode((string)$raw, true);
    return [$status, $decoded ?? $raw, $responseHeaders];
}

/** POST to the token endpoint (code exchange or refresh). */
function xero_token_request(array $params): array
{
    $auth = base64_encode(setting('xero_client_id') . ':' . setting('xero_client_secret'));
    [$status, $body] = xero_http('POST', xero_urls()['token'], [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ], http_build_query($params));

    if ($status !== 200 || !is_array($body) || empty($body['access_token'])) {
        $reason = is_array($body) ? ($body['error_description'] ?? $body['error'] ?? json_encode($body)) : substr((string)$body, 0, 200);
        throw new XeroException("Xero rejected the token request ($status): $reason");
    }
    xero_store_tokens($body);
    return $body;
}

function xero_store_tokens(array $tokens): void
{
    set_setting('xero_access_token', $tokens['access_token']);
    set_setting('xero_expires_at', (string)(time() + (int)($tokens['expires_in'] ?? 1800)));
    if (!empty($tokens['refresh_token'])) {
        set_setting('xero_refresh_token', $tokens['refresh_token']);
    }
}

function xero_exchange_code(string $code, string $redirectUri): void
{
    xero_token_request(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
}

function xero_access_token(bool $forceRefresh = false): string
{
    $token = setting('xero_access_token');
    if (!$forceRefresh && $token && (int)setting('xero_expires_at', 0) > time() + 60) {
        return $token;
    }
    $refresh = setting('xero_refresh_token');
    if (!$refresh) {
        throw new XeroException('Not connected to Xero.');
    }
    try {
        return xero_token_request(['grant_type' => 'refresh_token', 'refresh_token' => $refresh])['access_token'];
    } catch (XeroException $e) {
        // A refresh token that has expired (60 days unused) or been revoked can't be recovered.
        set_setting('xero_refresh_token', null);
        set_setting('xero_access_token', null);
        throw new XeroException('The Xero connection has expired or been revoked. Please reconnect. (' . $e->getMessage() . ')');
    }
}

/** Authorised GET/POST against the Xero API, with one retry for expired tokens and rate limits. */
function xero_api(string $method, string $url, array $query = [], bool $withTenant = true): mixed
{
    if ($query) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    $forceRefresh = false;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $headers = ['Authorization: Bearer ' . xero_access_token($forceRefresh), 'Accept: application/json'];
        if ($withTenant) {
            $headers[] = 'xero-tenant-id: ' . setting('xero_tenant_id');
        }
        [$status, $body, $responseHeaders] = xero_http($method, $url, $headers);

        if ($status >= 200 && $status < 300) {
            return $body;
        }
        if ($status === 401 && !$forceRefresh) {
            $forceRefresh = true;
            continue;
        }
        if ($status === 429 && $attempt < 3) {
            sleep(min(60, max(1, (int)($responseHeaders['retry-after'] ?? 5))));
            continue;
        }
        $detail = is_array($body) ? ($body['Detail'] ?? $body['Message'] ?? $body['Title'] ?? json_encode($body)) : substr(strip_tags((string)$body), 0, 200);
        if ($status === 403) {
            $detail .= ' — check the app has the scopes: ' . (setting('xero_scopes') ?: XERO_DEFAULT_SCOPES);
        }
        throw new XeroException("Xero API error ($status): $detail");
    }
    throw new XeroException('Xero API request failed after retries.');
}

/** Organisations the connection has access to: [['tenantId' => ..., 'tenantName' => ...], ...] */
function xero_connections(): array
{
    $list = xero_api('GET', xero_urls()['connections'], [], false);
    return array_values(array_filter($list ?: [], fn($c) => ($c['tenantType'] ?? 'ORGANISATION') === 'ORGANISATION'));
}

/** Fetch every page of an Accounting API collection. */
function xero_fetch_all(string $endpoint, string $key, array $query = []): array
{
    $all = [];
    for ($page = 1; $page <= 500; $page++) {
        $body = xero_api('GET', xero_urls()['api'] . '/' . $endpoint, $query + ['page' => $page]);
        $items = $body[$key] ?? [];
        array_push($all, ...$items);
        if (count($items) < 100) {
            break;
        }
    }
    return $all;
}

function xero_disconnect(): void
{
    $refresh = setting('xero_refresh_token');
    if ($refresh) {
        try {
            $auth = base64_encode(setting('xero_client_id') . ':' . setting('xero_client_secret'));
            xero_http('POST', xero_urls()['revoke'], [
                'Authorization: Basic ' . $auth,
                'Content-Type: application/x-www-form-urlencoded',
            ], http_build_query(['token' => $refresh]));
        } catch (XeroException) {
            // Revocation is best-effort; the local tokens are removed regardless.
        }
    }
    foreach (['xero_access_token', 'xero_refresh_token', 'xero_expires_at', 'xero_tenant_id', 'xero_tenant_name', 'xero_tenants'] as $key) {
        set_setting($key, null);
    }
}

/* ---------------------------------------------------------------- Sync --- */

/** Parse Xero's "/Date(1518685950940+0000)/" or ISO dates into Y-m-d. */
function xero_date(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    if (preg_match('#/Date\((-?\d+)([+-]\d{4})?\)/#', $value, $m)) {
        return gmdate('Y-m-d', intdiv((int)$m[1], 1000));
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** Normalise a company name for matching: "The Copper Kettle Café Ltd." → "copper kettle cafe". */
function xero_match_name(?string $name): string
{
    $name = strtolower(trim((string)$name));
    $name = strtr($name, ['&' => ' and ', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'ö' => 'o', 'ü' => 'u']);
    $name = preg_replace('/[^a-z0-9 ]+/', ' ', $name);
    $name = preg_replace('/\b(the|ltd|limited|llp|plc|inc|co|uk)\b/', ' ', $name);
    return trim(preg_replace('/\s+/', ' ', $name));
}

/**
 * Aggregate invoices and credit notes into per-contact balances.
 * Returns [contactId => ['outstanding', 'overdue', 'open_invoices', 'oldest_due_date', 'name']].
 */
function xero_calculate_balances(array $invoices, array $creditNotes, string $today): array
{
    $balances = [];
    $blank = ['outstanding' => 0.0, 'overdue' => 0.0, 'open_invoices' => 0, 'oldest_due_date' => null, 'name' => null];

    foreach ($invoices as $inv) {
        if (($inv['Type'] ?? '') !== 'ACCREC' || ($inv['Status'] ?? '') !== 'AUTHORISED') {
            continue;
        }
        $id = $inv['Contact']['ContactID'] ?? null;
        $due = (float)($inv['AmountDue'] ?? 0);
        if (!$id || $due <= 0) {
            continue;
        }
        $rate = (float)($inv['CurrencyRate'] ?? 1) ?: 1.0;
        $amount = $due / $rate; // CurrencyRate is foreign units per 1 unit of base currency
        $b = $balances[$id] ?? $blank;
        $b['name'] ??= $inv['Contact']['Name'] ?? null;
        $b['outstanding'] += $amount;
        $b['open_invoices']++;
        $dueDate = xero_date($inv['DueDateString'] ?? null) ?? xero_date($inv['DueDate'] ?? null);
        if ($dueDate !== null && $dueDate < $today) {
            $b['overdue'] += $amount;
            if ($b['oldest_due_date'] === null || $dueDate < $b['oldest_due_date']) {
                $b['oldest_due_date'] = $dueDate;
            }
        }
        $balances[$id] = $b;
    }

    foreach ($creditNotes as $cn) {
        if (($cn['Type'] ?? '') !== 'ACCRECCREDIT' || ($cn['Status'] ?? '') !== 'AUTHORISED') {
            continue;
        }
        $id = $cn['Contact']['ContactID'] ?? null;
        $remaining = (float)($cn['RemainingCredit'] ?? 0);
        if (!$id || $remaining <= 0) {
            continue;
        }
        $rate = (float)($cn['CurrencyRate'] ?? 1) ?: 1.0;
        $b = $balances[$id] ?? $blank;
        $b['name'] ??= $cn['Contact']['Name'] ?? null;
        $b['outstanding'] -= $remaining / $rate;
        $balances[$id] = $b;
    }

    foreach ($balances as &$b) {
        $b['outstanding'] = round($b['outstanding'], 2);
        $b['overdue'] = round(min($b['overdue'], max($b['outstanding'], 0)), 2);
    }
    return $balances;
}

/**
 * Link unlinked CRM customers to Xero contacts when exactly one contact matches,
 * by account number, then email, then company name. Returns number linked.
 */
function xero_auto_link(): int
{
    $contacts = db_all('SELECT id, name, account_number, email FROM xero_contacts');
    $taken = array_flip(array_filter(array_column(db_all('SELECT xero_contact_id FROM accounts WHERE xero_contact_id IS NOT NULL'), 'xero_contact_id')));
    $unlinked = db_all('SELECT id, name, account_number, email FROM accounts WHERE xero_contact_id IS NULL');

    $index = ['account_number' => [], 'email' => [], 'name' => []];
    foreach ($contacts as $c) {
        if (isset($taken[$c['id']])) {
            continue;
        }
        foreach (['account_number' => strtolower(trim((string)$c['account_number'])), 'email' => strtolower(trim((string)$c['email'])), 'name' => xero_match_name($c['name'])] as $key => $value) {
            if ($value !== '') {
                $index[$key][$value][] = (int)$c['id'];
            }
        }
    }

    $crmCounts = ['account_number' => [], 'email' => [], 'name' => []];
    foreach ($unlinked as $a) {
        foreach (xero_account_keys($a) as $key => $value) {
            $crmCounts[$key][$value] = ($crmCounts[$key][$value] ?? 0) + 1;
        }
    }

    $linked = 0;
    foreach ($unlinked as $a) {
        foreach (xero_account_keys($a) as $key => $value) {
            $matches = $index[$key][$value] ?? [];
            // Only link unambiguous one-to-one matches.
            if (count($matches) === 1 && $crmCounts[$key][$value] === 1 && !isset($taken[$matches[0]])) {
                db_exec('UPDATE accounts SET xero_contact_id = ? WHERE id = ? AND xero_contact_id IS NULL', [$matches[0], $a['id']]);
                $taken[$matches[0]] = true;
                $linked++;
                break;
            }
        }
    }
    return $linked;
}

function xero_account_keys(array $account): array
{
    return array_filter([
        'account_number' => strtolower(trim((string)$account['account_number'])),
        'email'          => strtolower(trim((string)$account['email'])),
        'name'           => xero_match_name($account['name']),
    ], fn($v) => $v !== '');
}

/** Pull contacts and balances from Xero. Returns a summary array. */
function xero_sync(): array
{
    if (!xero_connected()) {
        throw new XeroException('Xero is not connected.');
    }
    // Only one sync at a time (e.g. cron and a button press), so refresh tokens aren't used twice.
    if (!(int)db_value("SELECT GET_LOCK('telecomcrm_xero_sync', 0)")) {
        throw new XeroException('A Xero sync is already running. Try again in a minute.');
    }
    try {
        $started = microtime(true);
        $contacts = xero_fetch_all('Contacts', 'Contacts');
        $invoices = xero_fetch_all('Invoices', 'Invoices', ['where' => 'Type=="ACCREC"', 'Statuses' => 'AUTHORISED', 'summaryOnly' => 'true']);
        $creditNotes = xero_fetch_all('CreditNotes', 'CreditNotes', ['where' => 'Type=="ACCRECCREDIT" AND Status=="AUTHORISED"']);
        $balances = xero_calculate_balances($invoices, $creditNotes, date('Y-m-d'));
        $now = date('Y-m-d H:i:s');

        db()->beginTransaction();
        $upsert = db()->prepare('INSERT INTO xero_contacts (contact_id, name, account_number, email, status, synced_at)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name), account_number = VALUES(account_number),
                email = VALUES(email), status = VALUES(status), synced_at = VALUES(synced_at)');
        $seen = [];
        foreach ($contacts as $c) {
            if (empty($c['ContactID'])) {
                continue;
            }
            $upsert->execute([$c['ContactID'], mb_substr($c['Name'] ?? '(no name)', 0, 255), $c['AccountNumber'] ?? null,
                $c['EmailAddress'] ?? null, $c['ContactStatus'] ?? null, $now]);
            $seen[$c['ContactID']] = true;
        }
        // Contacts with balances that weren't in the list (e.g. archived) still need a row.
        foreach ($balances as $id => $b) {
            if (!isset($seen[$id])) {
                $upsert->execute([$id, mb_substr($b['name'] ?? '(unknown contact)', 0, 255), null, null, null, $now]);
            }
        }
        db_exec('UPDATE xero_contacts SET outstanding = 0, overdue = 0, open_invoices = 0, oldest_due_date = NULL');
        $update = db()->prepare('UPDATE xero_contacts SET outstanding = ?, overdue = ?, open_invoices = ?, oldest_due_date = ? WHERE contact_id = ?');
        foreach ($balances as $id => $b) {
            $update->execute([$b['outstanding'], $b['overdue'], $b['open_invoices'], $b['oldest_due_date'], $id]);
        }
        $linked = xero_auto_link();
        db()->commit();

        $summary = [
            'contacts'  => count($contacts),
            'invoices'  => count($invoices),
            'linked'    => $linked,
            'seconds'   => round(microtime(true) - $started, 1),
        ];
        set_setting('xero_last_sync_at', $now);
        set_setting('xero_last_sync_error', null);
        set_setting('xero_last_sync_summary', json_encode($summary));
        return $summary;
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        set_setting('xero_last_sync_error', date('Y-m-d H:i:s') . ' — ' . $e->getMessage());
        throw $e;
    } finally {
        db_value("SELECT RELEASE_LOCK('telecomcrm_xero_sync')");
    }
}

/** Link to the contact in Xero's web app. */
function xero_contact_url(string $contactId): string
{
    return 'https://go.xero.com/Contacts/View/' . rawurlencode($contactId);
}
