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
    return detected_app_url() . '/xero-callback.php';
}

/** The address this request reached the CRM at, e.g. https://example.com/crm (no trailing slash). */
function detected_app_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $dir = str_ends_with($path, '/') ? rtrim($path, '/') : rtrim(dirname($path), '/\\');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
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

final class XeroException extends IntegrationException
{
}

function xero_http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    try {
        return http_request($method, $url, $headers, $body);
    } catch (IntegrationException $e) {
        throw new XeroException('Could not reach Xero: ' . $e->getMessage());
    }
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
    $granted = xero_token_scopes((string)$tokens['access_token'])
        ?? (is_string($tokens['scope'] ?? null) ? preg_split('/\s+/', trim($tokens['scope'])) : null);
    if ($granted !== null) {
        set_setting('xero_granted_scopes', implode(' ', $granted));
    }
    set_setting('xero_expires_at', (string)(time() + (int)($tokens['expires_in'] ?? 1800)));
    if (!empty($tokens['refresh_token'])) {
        set_setting('xero_refresh_token', $tokens['refresh_token']);
    }
}

/** The scopes Xero actually granted, from the access token (a JWT with a "scope" claim). Null if unreadable. */
function xero_token_scopes(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    $claims = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/') . str_repeat('=', (4 - strlen($parts[1]) % 4) % 4)), true);
    $scope = $claims['scope'] ?? null;
    if (is_string($scope)) {
        $scope = preg_split('/\s+/', trim($scope));
    }
    return is_array($scope) ? array_values(array_filter(array_map('strval', $scope))) : null;
}

/** Scopes the CRM can use: those asked for that Xero also granted (when known from the token). */
function xero_scopes_in_effect(): string
{
    $asked = (string)(setting('xero_scopes') ?: XERO_DEFAULT_SCOPES);
    $granted = setting('xero_granted_scopes');
    if ($granted === null || $granted === '') {
        return $asked;
    }
    return implode(' ', array_intersect(preg_split('/\s+/', trim($asked)), preg_split('/\s+/', trim($granted))));
}

/** Scopes asked for that the current connection hasn't been granted (press Reconnect to approve them). */
function xero_scopes_pending(): array
{
    $granted = setting('xero_granted_scopes');
    if (!xero_connected() || $granted === null || $granted === '') {
        return [];
    }
    return array_values(array_diff(preg_split('/\s+/', trim((string)(setting('xero_scopes') ?: XERO_DEFAULT_SCOPES))), preg_split('/\s+/', trim($granted))));
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
function xero_api(string $method, string $url, array $query = [], bool $withTenant = true, ?array $json = null, ?string $raw = null, string $rawType = ''): mixed
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
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
        } elseif ($raw !== null) {
            $headers[] = 'Content-Type: ' . ($rawType ?: 'application/octet-stream');
        }
        [$status, $body, $responseHeaders] = xero_http($method, $url, $headers, $json !== null ? json_encode($json) : $raw);

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
        // Validation problems are listed per element.
        $validation = [];
        foreach (is_array($body) ? ($body['Elements'] ?? []) : [] as $el) {
            foreach ($el['ValidationErrors'] ?? [] as $ve) {
                $validation[] = $ve['Message'] ?? '';
            }
        }
        if ($validation) {
            $detail = implode(' ', array_unique(array_filter($validation)));
        }
        if ($status === 401 || $status === 403) {
            $pending = xero_scopes_pending();
            $detail .= $pending
                ? ' — Xero hasn\'t approved ' . implode(', ', $pending) . ' for this connection yet. Under Admin → Xero press Reconnect and approve it.'
                : ' — the connection isn\'t allowed to do this. Under Admin → Xero press Reconnect and approve every permission'
                    . ' (the Xero user who approves needs the Adviser or Standard role with access to settings).';
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
    foreach (['xero_access_token', 'xero_refresh_token', 'xero_expires_at', 'xero_tenant_id', 'xero_tenant_name', 'xero_tenants', 'xero_granted_scopes'] as $key) {
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
    $external = [];
    $taken = 'SELECT xero_contact_id FROM accounts WHERE xero_contact_id IS NOT NULL';
    foreach (db_all("SELECT id, name, account_number, email FROM xero_contacts WHERE id NOT IN ($taken) AND (status IS NULL OR status <> 'ARCHIVED')") as $c) {
        $external[(int)$c['id']] = [
            'account_number' => [strtolower(trim((string)$c['account_number']))],
            'email'          => [email_match_key($c['email'])],
            'name'           => [company_match_key($c['name'])],
        ];
    }
    $crm = [];
    foreach (db_all('SELECT id, name, account_number, email FROM accounts WHERE xero_contact_id IS NULL') as $a) {
        $crm[(int)$a['id']] = [
            'account_number' => [strtolower(trim((string)$a['account_number']))],
            'email'          => [email_match_key($a['email'])],
            'name'           => [company_match_key($a['name'])],
        ];
    }
    $pairs = match_unambiguous($external, $crm, ['account_number', 'email', 'name']);
    foreach ($pairs as $accountId => $contactId) {
        db_exec('UPDATE accounts SET xero_contact_id = ? WHERE id = ? AND xero_contact_id IS NULL', [$contactId, $accountId]);
    }
    return count($pairs);
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
        // Archived contacts too: an older or duplicate contact may hold the customer's account number.
        $contacts = xero_fetch_all('Contacts', 'Contacts', ['includeArchived' => 'true']);
        $invoices = xero_fetch_all('Invoices', 'Invoices', ['where' => 'Type=="ACCREC"', 'Statuses' => 'AUTHORISED', 'summaryOnly' => 'true']);
        $creditNotes = xero_fetch_all('CreditNotes', 'CreditNotes', ['where' => 'Type=="ACCRECCREDIT" AND Status=="AUTHORISED"']);
        $balances = xero_calculate_balances($invoices, $creditNotes, date('Y-m-d'));
        $now = date('Y-m-d H:i:s');

        db()->beginTransaction();
        $upsert = db()->prepare('INSERT INTO xero_contacts (contact_id, name, account_number, email, status, is_supplier, is_customer, details, synced_at, merged_to)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE name = VALUES(name), account_number = VALUES(account_number),
                email = VALUES(email), status = VALUES(status), is_supplier = VALUES(is_supplier), is_customer = VALUES(is_customer),
                details = COALESCE(VALUES(details), details), synced_at = VALUES(synced_at), merged_to = VALUES(merged_to)');
        $seen = [];
        foreach ($contacts as $c) {
            if (empty($c['ContactID'])) {
                continue;
            }
            $upsert->execute([$c['ContactID'], mb_substr($c['Name'] ?? '(no name)', 0, 255), $c['AccountNumber'] ?? null,
                $c['EmailAddress'] ?? null, $c['ContactStatus'] ?? null, !empty($c['IsSupplier']) ? 1 : 0, !empty($c['IsCustomer']) ? 1 : 0,
                xero_contact_details($c), $now, ($c['MergedToContactID'] ?? '') && $c['MergedToContactID'] !== '00000000-0000-0000-0000-000000000000' ? $c['MergedToContactID'] : null]);
            $seen[$c['ContactID']] = true;
        }
        // Contacts with balances that weren't in the list (e.g. archived) still need a row.
        foreach ($balances as $id => $b) {
            if (!isset($seen[$id])) {
                $upsert->execute([$id, mb_substr($b['name'] ?? '(unknown contact)', 0, 255), null, null, null, 0, 1, null, $now, null]);
            }
        }
        db_exec('UPDATE xero_contacts SET outstanding = 0, overdue = 0, open_invoices = 0, oldest_due_date = NULL');
        $update = db()->prepare('UPDATE xero_contacts SET outstanding = ?, overdue = ?, open_invoices = ?, oldest_due_date = ? WHERE contact_id = ?');
        foreach ($balances as $id => $b) {
            $update->execute([$b['outstanding'], $b['overdue'], $b['open_invoices'], $b['oldest_due_date'], $id]);
        }
        $linked = xero_auto_link();
        db()->commit();
        if (setting('xero_use_account_numbers') === '1') {
            xero_adopt_account_numbers();
        }
        $suppliers = setting('xero_import_suppliers') === '1' ? xero_import_suppliers() : null;

        $summary = [
            'contacts'  => count($contacts),
            'invoices'  => count($invoices),
            'linked'    => $linked,
            'suppliers' => $suppliers ? $suppliers['created'] + $suppliers['linked'] : null,
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

/** The parts of a Xero contact a supplier record can use (phones, address, website, person), as JSON. */
function xero_contact_details(array $c): ?string
{
    $phone = null;
    $mobile = null;
    foreach ($c['Phones'] ?? [] as $p) {
        $number = trim(implode(' ', array_filter([trim((string)($p['PhoneAreaCode'] ?? '')), trim((string)($p['PhoneNumber'] ?? ''))])));
        if ($number === '') {
            continue;
        }
        if (($p['PhoneType'] ?? '') === 'MOBILE') {
            $mobile ??= $number;
        } elseif (in_array($p['PhoneType'] ?? '', ['DEFAULT', 'DDI'], true)) {
            $phone ??= $number;
        }
    }
    $address = null;
    // Prefer the street address, else the postal one.
    foreach (['STREET', 'POBOX'] as $type) {
        foreach ($c['Addresses'] ?? [] as $a) {
            if (($a['AddressType'] ?? '') === $type && trim(($a['AddressLine1'] ?? '') . ($a['PostalCode'] ?? '')) !== '') {
                $address = [
                    'address' => trim((string)($a['AddressLine1'] ?? '')), 'address2' => trim(implode(', ', array_filter([$a['AddressLine2'] ?? '', $a['AddressLine3'] ?? '']))),
                    'city' => trim((string)($a['City'] ?? '')), 'county' => trim((string)($a['Region'] ?? '')), 'postcode' => strtoupper(trim((string)($a['PostalCode'] ?? ''))),
                ];
                break 2;
            }
        }
    }
    $terms = $c['PaymentTerms']['Bills'] ?? null;
    $people = [];
    foreach ($c['ContactPersons'] ?? [] as $p) {
        $name = trim(($p['FirstName'] ?? '') . ' ' . ($p['LastName'] ?? ''));
        if ($name !== '' || !empty($p['EmailAddress'])) {
            $people[] = array_filter(['name' => $name, 'email' => trim((string)($p['EmailAddress'] ?? ''))]);
        }
    }
    $details = array_filter([
        'contact_name' => trim(($c['FirstName'] ?? '') . ' ' . ($c['LastName'] ?? '')),
        'phone' => $phone ?? $mobile,
        'mobile' => $phone !== null ? $mobile : null,
        'company_number' => trim((string)($c['CompanyNumber'] ?? '')),
        'vat_number' => trim((string)($c['TaxNumber'] ?? '')),
        'people' => $people,
        'website' => trim((string)($c['Website'] ?? '')),
        'payment_terms' => $terms && isset($terms['Day']) ? xero_payment_terms_label((int)$terms['Day'], (string)($terms['Type'] ?? '')) : '',
        'address' => $address,
    ]);
    return $details ? json_encode($details) : null;
}

/* ------------------------------------------------- Customers from Xero --- */

/** Xero contacts not yet linked to a CRM customer (not archived), for importing. */
function xero_importable_contacts(bool $customersOnly = true, string $q = ''): array
{
    $where = ["(x.status IS NULL OR x.status <> 'ARCHIVED')", 'NOT EXISTS (SELECT 1 FROM accounts a WHERE a.xero_contact_id = x.id)'];
    $params = [];
    if ($customersOnly) {
        $where[] = 'x.is_customer = 1';
    }
    if ($q !== '') {
        $where[] = '(x.name LIKE ? OR x.email LIKE ? OR x.account_number LIKE ?)';
        array_push($params, "%$q%", "%$q%", "%$q%");
    }
    return db_all('SELECT x.* FROM xero_contacts x WHERE ' . implode(' AND ', $where) . ' ORDER BY x.name', $params);
}

/** Re-read one contact from Xero and update its stored copy. Returns the updated row. */
function xero_refresh_contact(array $x): array
{
    $c = xero_api('GET', xero_urls()['api'] . '/Contacts/' . rawurlencode($x['contact_id']))['Contacts'][0] ?? null;
    if (!$c) {
        return $x;
    }
    db_exec('UPDATE xero_contacts SET name = ?, account_number = ?, email = ?, status = ?, is_supplier = ?, is_customer = ?, details = COALESCE(?, details), synced_at = NOW() WHERE id = ?', [
        mb_substr($c['Name'] ?? $x['name'], 0, 255), $c['AccountNumber'] ?? null, $c['EmailAddress'] ?? null, $c['ContactStatus'] ?? $x['status'],
        !empty($c['IsSupplier']) ? 1 : 0, !empty($c['IsCustomer']) ? 1 : (int)$x['is_customer'], xero_contact_details($c), $x['id']]);
    return db_one('SELECT * FROM xero_contacts WHERE id = ?', [$x['id']]);
}

/**
 * Create CRM customers from Xero contacts, linked to them, with their company details,
 * main/accounts contact and other people. Returns ['created' => [account ids], 'skipped' => [names]].
 */
function xero_create_customers(array $xeroIds): array
{
    $out = ['created' => [], 'skipped' => [], 'no_number' => []];
    foreach (array_unique(array_map('intval', $xeroIds)) as $xid) {
        $x = db_one('SELECT * FROM xero_contacts WHERE id = ?', [$xid]);
        if (!$x) {
            continue;
        }
        // Fetch the contact afresh, so details changed in Xero since the last sync (e.g. its account number) are used.
        try {
            $x = xero_refresh_contact($x);
        } catch (IntegrationException) {
            // Use what the last sync brought in.
        }
        if (db_value('SELECT 1 FROM accounts WHERE xero_contact_id = ?', [$xid])) {
            $out['skipped'][] = $x['name'] . ' (already a customer)';
            continue;
        }
        $d = json_decode((string)$x['details'], true) ?: [];
        $person = trim((string)($d['contact_name'] ?? ''));
        $addr = $d['address'] ?? [];
        // Their Xero account number becomes the CRM one, unless it's taken.
        $number = trim((string)$x['account_number']);
        if ($number === '') {
            // Xero keeps account numbers unique, so it may be on a contact merged into this one (or a duplicate).
            $number = xero_number_held_elsewhere($x)['number'] ?? '';
        }
        if ($number === '') {
            $out['no_number'][] = $x['name'];
        } elseif (mb_strlen($number) > 20) {
            $out['no_number'][] = "{$x['name']} (their Xero account number $number is longer than 20 characters)";
            $number = '';
        } elseif ($taken = db_value('SELECT name FROM accounts WHERE account_number = ?', [$number])) {
            $out['no_number'][] = "{$x['name']} ($number is already used by $taken)";
            $number = '';
        }
        $data = [
            'account_number' => $number ?: null,
            'name' => mb_substr($x['name'], 0, 150),
            // A contact named after one person (e.g. "Jo Bloggs") is a household; anything else a business.
            'type' => $person !== '' && company_match_key($person) === company_match_key($x['name']) ? 'residential' : 'business',
            'status' => 'active',
            'email' => $x['email'] ? strtolower($x['email']) : null,
            'phone' => $d['phone'] ?? null,
            'company_number' => mb_substr((string)($d['company_number'] ?? ''), 0, 20) ?: null,
            'address' => $addr['address'] ?? null, 'address2' => ($addr['address2'] ?? '') ?: null, 'city' => ($addr['city'] ?? '') ?: null,
            'county' => ($addr['county'] ?? '') ?: null, 'postcode' => ($addr['postcode'] ?? '') ?: null,
            'xero_contact_id' => $xid,
            'notes' => trim(implode("\n", array_filter([
                !empty($d['vat_number']) ? 'VAT number: ' . $d['vat_number'] : '',
                !empty($d['website']) ? 'Website: ' . $d['website'] : '',
            ]))) ?: null,
            // The Xero contact's person and email become the main contact, who also gets the invoices.
            'main_name' => $person !== '' ? $person : ($x['email'] ? 'Accounts' : ''),
            'main_job_title' => null,
            'main_phone' => ($d['mobile'] ?? null) ?: ($d['phone'] ?? null),
            'main_email' => $x['email'] ? strtolower($x['email']) : null,
            'billing_same' => 1,
        ];
        $id = insert_row('accounts', $data);
        foreach ($d['people'] ?? [] as $p) {
            if (!empty($p['email']) && strcasecmp($p['email'], (string)$x['email']) === 0) {
                continue;
            }
            db_exec('INSERT INTO contacts (account_id, name, email) VALUES (?, ?, ?)', [$id, mb_substr($p['name'] ?? '', 0, 150) ?: $p['email'], $p['email'] ?? null]);
        }
        // Already a supplier in the CRM (same Xero contact): one company record.
        db_exec('UPDATE suppliers SET account_id = ? WHERE xero_contact_id = ? AND account_id IS NULL AND NOT EXISTS (SELECT 1 FROM (SELECT account_id FROM suppliers WHERE account_id = ?) t)', [$id, $xid, $id]);
        audit('create', "Customer {$x['name']} added from Xero", 'accounts', $id, null, null, $id);
        $out['created'][] = $id;
    }
    return $out;
}

/**
 * The account number for a Xero contact that has none of its own, held on another contact: one merged into it
 * in Xero (followed through chains of merges), else the only other contact of the same name that has one
 * (Xero keeps account numbers unique, so it can't be typed onto this one). Returns ['number', 'name', 'contact_id', 'archived', 'merged'] or null.
 */
function xero_number_held_elsewhere(array $contact): ?array
{
    // Contacts merged into this one, and into those, and so on.
    $ids = [$contact['contact_id']];
    $found = [];
    for ($depth = 0; $depth < 5 && $ids; $depth++) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $merged = db_all("SELECT contact_id, name, status, TRIM(account_number) AS n FROM xero_contacts WHERE merged_to IN ($marks)", $ids);
        foreach ($merged as $m) {
            if ($m['n'] !== '' && $m['n'] !== null) {
                $found[$m['n']] = ['number' => $m['n'], 'name' => $m['name'], 'contact_id' => $m['contact_id'], 'archived' => $m['status'] === 'ARCHIVED', 'merged' => true];
            }
        }
        $ids = array_column($merged, 'contact_id');
    }
    if (count($found) === 1) {
        return reset($found);
    }
    if ($found) {
        return null; // several merged contacts with different numbers: can't tell which
    }
    $key = company_match_key($contact['name']);
    $holders = array_values(array_filter(db_all("SELECT contact_id, name, status, TRIM(account_number) AS n FROM xero_contacts
        WHERE contact_id <> ? AND account_number IS NOT NULL AND TRIM(account_number) <> ''", [$contact['contact_id']]), fn($h) => company_match_key($h['name']) === $key));
    return count($holders) === 1
        ? ['number' => $holders[0]['n'], 'name' => $holders[0]['name'], 'contact_id' => $holders[0]['contact_id'], 'archived' => $holders[0]['status'] === 'ARCHIVED', 'merged' => false]
        : null;
}

/**
 * Linked customers whose CRM account number differs from their Xero contact's account number:
 * [['id', 'name', 'from', 'to', 'problem']]. A problem (too long, or used by another customer) means it's left alone.
 */
function xero_account_number_changes(): array
{
    $rows = db_all("SELECT a.id, a.name, a.account_number AS `from`, TRIM(x.account_number) AS `to`, NULL AS source FROM accounts a JOIN xero_contacts x ON x.id = a.xero_contact_id
        WHERE x.account_number IS NOT NULL AND TRIM(x.account_number) <> '' AND TRIM(x.account_number) <> a.account_number ORDER BY a.name");
    // Linked to a Xero contact with no account number, while a contact merged into it (or a duplicate of
    // the same name) holds it: Xero won't let the number be used twice, so that's theirs.
    foreach (db_all("SELECT a.id, a.name, a.account_number, x.contact_id, x.name AS xname FROM accounts a JOIN xero_contacts x ON x.id = a.xero_contact_id
        WHERE x.account_number IS NULL OR TRIM(x.account_number) = ''") as $a) {
        $held = xero_number_held_elsewhere(['contact_id' => $a['contact_id'], 'name' => $a['xname']]);
        if ($held && $held['number'] !== $a['account_number']) {
            $rows[] = ['id' => $a['id'], 'name' => $a['name'], 'from' => $a['account_number'], 'to' => $held['number'], 'source' => $held];
        }
    }
    usort($rows, fn($x, $y) => strcasecmp($x['name'], $y['name']));
    $wanted = array_count_values(array_map('strtolower', array_column($rows, 'to')));
    foreach ($rows as &$r) {
        $r['problem'] = match (true) {
            mb_strlen($r['to']) > 20 => 'longer than 20 characters',
            $wanted[strtolower($r['to'])] > 1 => 'more than one customer has it in Xero',
            (bool)db_value('SELECT name FROM accounts WHERE account_number = ? AND id <> ?', [$r['to'], $r['id']]) =>
                'already used by ' . db_value('SELECT name FROM accounts WHERE account_number = ? AND id <> ?', [$r['to'], $r['id']]),
            default => null,
        };
    }
    return $rows;
}

/** Where every customer stands for Xero account numbers, to explain what can and can't change. */
function xero_account_number_overview(): array
{
    $changes = xero_account_number_changes();
    $noNumber = db_all("SELECT a.id, a.name, a.account_number FROM accounts a JOIN xero_contacts x ON x.id = a.xero_contact_id
        WHERE x.account_number IS NULL OR TRIM(x.account_number) = '' ORDER BY a.name");
    $foundElsewhere = array_column(array_filter($changes, fn($r) => $r['source']), 'id');
    $noNumber = array_values(array_filter($noNumber, fn($a) => !in_array($a['id'], $foundElsewhere)));
    return [
        'linked' => (int)db_value('SELECT COUNT(*) FROM accounts WHERE xero_contact_id IS NOT NULL'),
        'same' => (int)db_value('SELECT COUNT(*) FROM accounts a JOIN xero_contacts x ON x.id = a.xero_contact_id WHERE TRIM(x.account_number) = a.account_number'),
        'changes' => $changes,
        'will_change' => count(array_filter($changes, fn($r) => $r['problem'] === null)),
        'no_number' => $noNumber,
        'unlinked' => db_all("SELECT id, name, account_number FROM accounts WHERE xero_contact_id IS NULL AND status <> 'churned' ORDER BY name"),
    ];
}

/** Give linked customers their Xero account numbers (where that's possible). Returns how many changed. */
function xero_adopt_account_numbers(bool $refresh = false): int
{
    if ($refresh) {
        // A full sync brings in every contact's current account number (100 contacts per request).
        try {
            xero_sync();
        } catch (IntegrationException) {
            // Carry on with what's stored, re-reading a few below.
        }
        // Then re-read individually the longest-unchecked of those still without one, so repeated presses work through them all.
        foreach (db_all("SELECT x.* FROM xero_contacts x JOIN accounts a ON a.xero_contact_id = x.id
            WHERE (x.account_number IS NULL OR TRIM(x.account_number) = '') AND (x.synced_at IS NULL OR x.synced_at < NOW() - INTERVAL 10 MINUTE)
            ORDER BY x.synced_at LIMIT 30") as $x) {
            try {
                xero_refresh_contact($x);
            } catch (IntegrationException) {
                break;
            }
        }
    }
    $n = 0;
    foreach (xero_account_number_changes() as $r) {
        if ($r['problem'] !== null || db_value('SELECT 1 FROM accounts WHERE account_number = ? AND id <> ?', [$r['to'], $r['id']])) {
            continue;
        }
        db_exec('UPDATE accounts SET account_number = ? WHERE id = ?', [$r['to'], $r['id']]);
        audit('update', "Customer {$r['name']}: account number {$r['from']} → {$r['to']} (from Xero)", 'accounts', (int)$r['id'], null,
            ['Account no.' => ['from' => $r['from'], 'to' => $r['to']]], (int)$r['id']);
        $n++;
    }
    return $n;
}

function xero_payment_terms_label(int $day, string $type): string
{
    return match ($type) {
        'DAYSAFTERBILLDATE'    => "$day days after the bill date",
        'DAYSAFTERBILLMONTH'   => "$day days after the end of the bill month",
        'OFCURRENTMONTH'       => "Day $day of the bill month",
        'OFFOLLOWINGMONTH'     => "Day $day of the following month",
        default                => '',
    };
}

/**
 * Bring Xero contacts marked as suppliers into the CRM's suppliers: link ones
 * with the same name, create the rest. Blank details are filled from Xero;
 * anything already typed in the CRM is kept. Returns ['created' => n, 'linked' => n, 'updated' => n].
 */
function xero_import_suppliers(): array
{
    $out = ['created' => 0, 'linked' => 0, 'updated' => 0];
    $bySupplierName = [];
    foreach (db_all('SELECT id, name FROM suppliers WHERE xero_contact_id IS NULL') as $s) {
        $bySupplierName[company_match_key($s['name'])][] = (int)$s['id'];
    }
    $contacts = db_all("SELECT * FROM xero_contacts WHERE is_supplier = 1 AND (status IS NULL OR status <> 'ARCHIVED')");
    foreach ($contacts as $c) {
        $d = json_decode((string)$c['details'], true) ?: [];
        $fields = array_filter([
            'email' => $c['email'] ? strtolower((string)$c['email']) : null,
            'accounts_email' => $c['email'] ? strtolower((string)$c['email']) : null,
            'contact_name' => $d['contact_name'] ?? null, 'phone' => $d['phone'] ?? null, 'website' => $d['website'] ?? null,
            'payment_terms' => $d['payment_terms'] ?? null,
        ] + array_map(fn($v) => $v ?: null, $d['address'] ?? []), fn($v) => $v !== null && $v !== '');
        $supplier = db_one('SELECT * FROM suppliers WHERE xero_contact_id = ?', [$c['id']]);
        if (!$supplier) {
            $match = $bySupplierName[company_match_key($c['name'])] ?? [];
            if (count($match) === 1 && !db_value("SELECT 1 FROM suppliers WHERE id = ? AND xero_contact_id IS NOT NULL", [$match[0]])) {
                db_exec('UPDATE suppliers SET xero_contact_id = ? WHERE id = ?', [$c['id'], $match[0]]);
                $supplier = db_one('SELECT * FROM suppliers WHERE id = ?', [$match[0]]);
                unset($bySupplierName[company_match_key($c['name'])]);
                audit('xero_link', "Supplier {$supplier['name']} linked to Xero contact {$c['name']}", 'suppliers', (int)$supplier['id']);
                $out['linked']++;
            } else {
                $cols = ['name' => mb_substr($c['name'], 0, 150), 'xero_contact_id' => (int)$c['id']] + $fields;
                // Already a customer in the CRM (same Xero contact): one company record.
                $account = db_value('SELECT a.id FROM accounts a WHERE a.xero_contact_id = ? AND NOT EXISTS (SELECT 1 FROM suppliers s WHERE s.account_id = a.id)', [$c['id']]);
                if ($account) {
                    $cols['account_id'] = (int)$account;
                }
                db_exec('INSERT INTO suppliers (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($cols));
                $id = (int)db()->lastInsertId();
                audit('create', "Supplier {$c['name']} added from Xero", 'suppliers', $id);
                $out['created']++;
                continue;
            }
        }
        // Fill in blanks from Xero.
        $blank = array_filter($fields, fn($v, $k) => array_key_exists($k, $supplier) && ($supplier[$k] === null || $supplier[$k] === ''), ARRAY_FILTER_USE_BOTH);
        if ($blank) {
            db_exec('UPDATE suppliers SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($blank))) . ' WHERE id = ?', [...array_values($blank), $supplier['id']]);
            $out['updated']++;
        }
    }
    return $out;
}

/** Link to the contact in Xero's web app. */
function xero_contact_url(string $contactId): string
{
    return 'https://go.xero.com/Contacts/View/' . rawurlencode($contactId);
}

/* ------------------------------------------------- Accounts contact push --- */

/** Scopes when the CRM may also update contact details in Xero (contacts write access). */
function xero_write_scopes(string $scopes): string
{
    return trim(preg_replace('/(^|\s)accounting\.contacts\.read(\s|$)/', '$1accounting.contacts$2', ' ' . $scopes . ' '));
}

/** Whether the saved scopes allow updating contacts in Xero. */
function xero_can_write_contacts(): bool
{
    return (bool)preg_match('/(^|\s)accounting\.contacts(\s|$)/', xero_scopes_in_effect());
}

function xero_push_enabled(): bool
{
    return xero_connected() && setting('xero_push_contacts') === '1' && xero_can_write_contacts();
}

/**
 * Tell Xero where to send this customer's invoices and statements: sets the Xero
 * contact's email address and name to the CRM accounts contact (or main contact).
 */
function xero_push_billing_contact(int $accountId): string
{
    $a = db_one('SELECT a.name, a.main_contact_id, a.billing_contact_id, x.contact_id AS xero_id, x.id AS xero_row
        FROM accounts a LEFT JOIN xero_contacts x ON x.id = a.xero_contact_id WHERE a.id = ?', [$accountId]);
    if (!$a || !$a['xero_id']) {
        throw new XeroException('This customer isn\'t linked to a Xero contact yet. Link it by editing the customer.');
    }
    $contact = db_one('SELECT name, email FROM contacts WHERE id = ?', [$a['billing_contact_id'] ?: $a['main_contact_id'] ?: 0]);
    if (!$contact || !$contact['email']) {
        throw new XeroException('Add an accounts contact with an email address first.');
    }
    $parts = preg_split('/\s+/', trim($contact['name']), 2);
    xero_api('POST', xero_urls()['api'] . '/Contacts/' . rawurlencode($a['xero_id']), [], true, ['Contacts' => [[
        'ContactID'    => $a['xero_id'],
        'EmailAddress' => $contact['email'],
        'FirstName'    => mb_substr($parts[0] ?? '', 0, 255),
        'LastName'     => mb_substr($parts[1] ?? '', 0, 255),
    ]]]);
    db_exec('UPDATE xero_contacts SET email = ? WHERE id = ?', [$contact['email'], $a['xero_row']]);
    audit('xero_push', "Invoice email in Xero set to {$contact['email']} for {$a['name']}", 'accounts', $accountId);
    return $contact['email'];
}


/* ------------------------------------------------------ Products → items --- */

/** Scopes with accounting.settings (write) added, which Xero needs to create and update items. */
/** Scopes needed to create bills and attach files (granular scopes, or the older transactions scope). */
function xero_bill_scopes(string $scopes): string
{
    $scopes = ' ' . $scopes . ' ';
    $scopes = preg_replace('/(^|\s)accounting\.invoices\.read(?=\s|$)/', '$1accounting.invoices', $scopes);
    $scopes = preg_replace('/(^|\s)accounting\.transactions\.read(?=\s|$)/', '$1accounting.transactions', $scopes);
    $scopes = trim(preg_replace('/\s+/', ' ', $scopes));
    if (!preg_match('/(^|\s)accounting\.(invoices|transactions)(\s|$)/', $scopes)) {
        $scopes .= ' accounting.invoices';
    }
    return preg_match('/(^|\s)accounting\.attachments(\s|$)/', $scopes) ? $scopes : $scopes . ' accounting.attachments';
}

function xero_can_write_bills(): bool
{
    $s = xero_scopes_in_effect();
    return (bool)preg_match('/(^|\s)accounting\.(invoices|transactions)(\s|$)/', $s) && (bool)preg_match('/(^|\s)accounting\.attachments(\s|$)/', $s);
}

function xero_bills_enabled(): bool
{
    return xero_connected() && setting('xero_push_bills') === '1' && xero_can_write_bills();
}

/** Link to a bill in Xero. */
function xero_bill_url(string $invoiceId): string
{
    return 'https://go.xero.com/AccountsPayable/View.aspx?InvoiceID=' . rawurlencode($invoiceId);
}

/** Xero tax types used on bills for each VAT rate printed on a supplier invoice (UK defaults; changeable under Admin → Xero). */
const XERO_BILL_RATE_DEFAULTS = ['20' => 'INPUT2', '5' => 'RRINPUT', '0' => 'ZERORATEDINPUT', 'exempt' => 'EXEMPTEXPENSES', 'RC' => ''];

function xero_bill_rate_map(): array
{
    $saved = json_decode((string)setting('xero_bill_rate_map', ''), true) ?: [];
    $map = array_replace(XERO_BILL_RATE_DEFAULTS, array_intersect_key($saved, XERO_BILL_RATE_DEFAULTS));
    $map['20'] = (string)(setting('xero_bill_tax_type') ?: $map['20']);
    return $map;
}

/** The VAT % of a Xero tax type, if the rates have been loaded. */
function xero_tax_type_rate(string $type): ?float
{
    $values = json_decode((string)setting('xero_tax_rate_values', '[]'), true) ?: [];
    return isset($values[strtoupper($type)]) ? (float)$values[strtoupper($type)] : null;
}

/**
 * The Xero tax type for one bill line. In order: the product's purchase VAT (unless the invoice shows a
 * different rate for the line), the rate printed on the line, the supplier's default VAT, the reverse
 * charge if the invoice says it applies, then the bills default.
 */
function xero_bill_line_tax(?string $productType, ?string $lineRate, ?string $supplierType, bool $reverse): string
{
    $map = xero_bill_rate_map();
    $fromRate = function (string $rate) use ($map): string {
        $type = $map[$rate] ?? '';
        if ($type === '' && $rate === 'RC') {
            throw new IntegrationException('This invoice is under the reverse charge. Choose the Xero tax type to use for reverse charge bills under Admin → Xero → Supplier bills first.');
        }
        if ($type === '') {
            throw new IntegrationException("The invoice has a line at $rate% VAT, and there's no Xero tax type for that rate. Set the product's VAT on purchases or the supplier's Default VAT, or for 5%, 0% and exempt, set them under Admin → Xero → Supplier bills.");
        }
        return $type;
    };
    if ($productType) {
        // The product's own rate wins, unless the invoice prints a different rate for this line.
        $productRate = xero_tax_type_rate($productType);
        $differs = $lineRate !== null && ($lineRate === 'RC' || $lineRate === 'exempt' || ($productRate !== null && abs($productRate - (float)$lineRate) > 0.001));
        if (!$differs) {
            return strtoupper($productType);
        }
    }
    if ($lineRate !== null) {
        return strtoupper($fromRate($lineRate));
    }
    if ($supplierType) {
        return strtoupper($supplierType);
    }
    return strtoupper($reverse ? $fromRate('RC') : $map['20']);
}

/**
 * Our product an invoice line is for (its purchases nominal code and VAT): the supplier's price list entry
 * whose code or description appears in the line (longest first, so "Router two" beats "Router").
 */
function bill_line_product(int $supplierId, string $description): ?array
{
    static $cache = [];
    $cache[$supplierId] ??= db_all('SELECT sp.supplier_sku, sp.description, p.purchase_account_code, p.purchase_tax_type FROM supplier_products sp JOIN products p ON p.id = sp.product_id
        WHERE sp.supplier_id = ?', [$supplierId]);
    $text = mb_strtolower($description);
    $best = null;
    foreach ($cache[$supplierId] as $sp) {
        foreach ([$sp['supplier_sku'], $sp['description']] as $needle) {
            $needle = mb_strtolower(trim((string)$needle));
            if (mb_strlen($needle) >= 3 && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $text) && mb_strlen($needle) > ($best[0] ?? 0)) {
                $best = [mb_strlen($needle), $sp];
            }
        }
    }
    return $best[1] ?? null;
}

/** The bill for a supplier invoice, in Xero's format. */
function xero_bill_payload(array $inv): array
{
    $supplier = $inv['supplier_id'] ? db_one('SELECT * FROM suppliers WHERE id = ?', [$inv['supplier_id']]) : null;
    if (!$supplier) {
        throw new IntegrationException('Choose which supplier the invoice is from first.');
    }
    $po = $inv['po_id'] ? db_one('SELECT * FROM purchase_orders WHERE id = ?', [$inv['po_id']]) : null;
    $xc = $supplier['xero_contact_id'] ? db_value('SELECT contact_id FROM xero_contacts WHERE id = ?', [$supplier['xero_contact_id']]) : null;
    // Nominal codes: the product's, else the supplier's default, else the bills default.
    $account = (string)($supplier['purchase_account_code'] ?: (setting('xero_bill_account') ?: (setting('xero_item_purchase_account') ?: '310')));
    $reverse = !empty($inv['reverse_charge']);
    $supplierTax = $supplier['purchase_tax_type'] ?? null;
    $tax = xero_bill_line_tax(null, null, $supplierTax, $reverse); // for lines that aren't products
    $net = $inv['net'] !== null ? (float)$inv['net'] : null;
    $tolerance = invoice_tolerance();
    $lines = [];
    $amountTypes = 'Exclusive';

    // Lines: the purchase order's (when the invoice agrees with it), else what was read from the invoice, else one line.
    $poLines = $po ? po_lines((int)$po['id']) : [];
    if ($poLines && $net !== null && abs($net - po_total($poLines)) <= $tolerance) {
        // VAT rates printed on the invoice, by line description, to check against the products'.
        $readRates = [];
        foreach (json_decode((string)$inv['line_items'], true) ?: [] as $rl) {
            if (($rl['vat_rate'] ?? null) !== null) {
                $readRates[] = [mb_strtolower((string)($rl['description'] ?? '')), (string)$rl['vat_rate']];
            }
        }
        $distinct = array_unique(array_column($readRates, 1));
        foreach ($poLines as $l) {
            $p = $l['supplier_product_id'] ? db_one('SELECT p.purchase_account_code, p.purchase_tax_type FROM supplier_products sp JOIN products p ON p.id = sp.product_id WHERE sp.id = ?', [$l['supplier_product_id']]) : null;
            // The invoice line's rate: the one whose description mentions this line, or the only rate on the invoice.
            $rate = count($distinct) === 1 ? reset($distinct) : null;
            foreach ($readRates as [$d, $r]) {
                if ($d !== '' && (str_contains($d, mb_strtolower($l['description'])) || ($l['sku'] && str_contains($d, mb_strtolower($l['sku']))))) {
                    $rate = $r;
                    break;
                }
            }
            $lines[] = ['Description' => trim(($l['sku'] ? $l['sku'] . ' ' : '') . $l['description']), 'Quantity' => (float)$l['quantity'],
                'UnitAmount' => (float)$l['unit_cost'], 'AccountCode' => ($p['purchase_account_code'] ?? null) ?: $account,
                'TaxType' => xero_bill_line_tax($p['purchase_tax_type'] ?? null, $rate, $supplierTax, $reverse)];
        }
        // Pennies the supplier rounded differently.
        $diff = round($net - po_total($poLines), 2);
        if (abs($diff) >= 0.01) {
            $lines[] = ['Description' => 'Rounding', 'Quantity' => 1, 'UnitAmount' => $diff, 'AccountCode' => $account, 'TaxType' => $tax];
        }
    } else {
        $read = json_decode((string)$inv['line_items'], true) ?: [];
        $sum = array_sum(array_map(fn($l) => (float)($l['net_amount'] ?? 0), $read));
        if ($read && $net !== null && abs($sum - $net) <= 0.05) {
            foreach ($read as $l) {
                $qty = (float)($l['quantity'] ?? 0) ?: 1;
                $p = bill_line_product((int)$supplier['id'], (string)$l['description']);
                $lines[] = ['Description' => mb_substr((string)$l['description'], 0, 4000) ?: 'Item', 'Quantity' => $qty,
                    'UnitAmount' => round((float)$l['net_amount'] / $qty, 4), 'AccountCode' => ($p['purchase_account_code'] ?? null) ?: $account,
                    'TaxType' => xero_bill_line_tax($p['purchase_tax_type'] ?? null, $l['vat_rate'] ?? null, $supplierTax, $reverse)];
            }
        } elseif ($net !== null) {
            $lines[] = ['Description' => 'Invoice ' . ($inv['invoice_number'] ?: '') . ($po ? " ({$po['reference']})" : ''), 'Quantity' => 1, 'UnitAmount' => $net, 'AccountCode' => $account, 'TaxType' => $tax];
        } elseif ($inv['total'] !== null) {
            // Only a VAT-inclusive total is known: let Xero work the VAT out.
            $amountTypes = 'Inclusive';
            $lines[] = ['Description' => 'Invoice ' . ($inv['invoice_number'] ?: '') . ($po ? " ({$po['reference']})" : ''), 'Quantity' => 1, 'UnitAmount' => (float)$inv['total'], 'AccountCode' => $account, 'TaxType' => $tax];
        } else {
            throw new IntegrationException('The invoice has no amount yet. Enter it first.');
        }
    }
    $bill = array_filter([
        'Type' => 'ACCPAY',
        'Contact' => $xc ? ['ContactID' => $xc] : ['Name' => $supplier['name']],
        'InvoiceNumber' => $inv['invoice_number'] ?: null,
        'Reference' => $po['reference'] ?? ($inv['po_reference'] ?: null),
        'Date' => $inv['invoice_date'] ?: date('Y-m-d'),
        'DueDate' => $inv['due_date'] ?: null,
        'CurrencyCode' => $inv['currency'] ?: 'GBP',
        'LineAmountTypes' => $amountTypes,
        'Status' => in_array(setting('xero_bill_status'), ['DRAFT', 'SUBMITTED', 'AUTHORISED'], true) ? setting('xero_bill_status') : 'DRAFT',
        'LineItems' => $lines,
    ], fn($v) => $v !== null);
    if (!empty($inv['xero_invoice_id'])) {
        $bill['InvoiceID'] = $inv['xero_invoice_id'];
    }
    return $bill;
}

/**
 * Send a supplier invoice to Xero as a bill (or update the one already sent),
 * with the uploaded file attached. Returns the Xero InvoiceID.
 */
function xero_post_bill(int $id): string
{
    if (!xero_connected()) {
        throw new IntegrationException('Xero isn\'t connected.');
    }
    if (!xero_can_write_bills()) {
        throw new IntegrationException('Xero hasn\'t been given permission to create bills. Switch it on under Admin → Xero and press Reconnect.');
    }
    $inv = db_one('SELECT * FROM supplier_invoices WHERE id = ?', [$id]);
    try {
        $bill = xero_bill_payload($inv);
        $res = xero_api('POST', xero_urls()['api'] . '/Invoices', ['summarizeErrors' => 'false'], true, ['Invoices' => [$bill]]);
        $out = $res['Invoices'][0] ?? [];
        if (!empty($out['ValidationErrors']) || empty($out['InvoiceID'])) {
            throw new XeroException('Xero said: ' . implode(' ', array_column($out['ValidationErrors'] ?? [['Message' => 'no bill was created']], 'Message')));
        }
        $xid = $out['InvoiceID'];
        // Xero works the VAT out from each line's tax type: flag it if that disagrees with the invoice.
        $vatNote = null;
        if (isset($out['TotalTax']) && $inv['vat'] !== null && abs((float)$out['TotalTax'] - (float)$inv['vat']) > 0.05) {
            $vatNote = 'Sent, but Xero worked out VAT of ' . money((float)$out['TotalTax']) . ' where the invoice shows ' . money((float)$inv['vat'])
                . '. Check the VAT rates on the bill in Xero (and on the products), or change it in Xero.';
        }
        $attached = (int)$inv['xero_attached'];
        if (setting('xero_bill_attach', '1') === '1' && !$attached && is_file($path = invoice_path($inv))) {
            $name = preg_replace('/[^\w.\- ]+/', '_', $inv['file_name']) ?: 'invoice.pdf';
            xero_api('PUT', xero_urls()['api'] . '/Invoices/' . rawurlencode($xid) . '/Attachments/' . rawurlencode($name), [], true, null,
                (string)file_get_contents($path), (string)$inv['mime']);
            $attached = 1;
        }
        db_exec('UPDATE supplier_invoices SET xero_invoice_id = ?, xero_posted_at = NOW(), xero_error = ?, xero_attached = ? WHERE id = ?', [$xid, $vatNote, $attached, $id]);
        audit('xero_bill', 'Supplier invoice ' . ($inv['invoice_number'] ?: '#' . $id) . ($inv['xero_invoice_id'] ? ' updated in' : ' sent to') . ' Xero as a bill'
            . ($attached && !$inv['xero_attached'] ? ' with the invoice attached' : ''), 'supplier_invoices', $id);
        return $xid;
    } catch (IntegrationException $e) {
        db_exec('UPDATE supplier_invoices SET xero_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 500), $id]);
        throw $e;
    }
}

function xero_item_scopes(string $scopes): string
{
    $scopes = trim(preg_replace('/(^|\s)accounting\.settings\.read(?=\s|$)/', '', ' ' . $scopes . ' '));
    return preg_match('/(^|\s)accounting\.settings(\s|$)/', $scopes) ? $scopes : $scopes . ' accounting.settings';
}

function xero_can_write_items(): bool
{
    return (bool)preg_match('/(^|\s)accounting\.settings(\s|$)/', xero_scopes_in_effect());
}

/** Xero item for a product: Code = SKU, sale price, and cost price as the purchase price. */
function xero_item_payload(array $p): array
{
    $cycle = strtolower(BILLING_FREQUENCIES[$p['billing_frequency'] ?? 'monthly'] ?? 'monthly');
    $description = trim((string)$p['description']) !== '' ? trim((string)$p['description']) : $p['name'];
    $sales = ['UnitPrice' => (float)$p['monthly_price']];
    if ($a = ($p['sales_account_code'] ?? null) ?: setting('xero_item_sales_account')) {
        $sales['AccountCode'] = $a;
    }
    if ($t = ($p['sales_tax_type'] ?? null) ?: setting('xero_item_tax_type')) {
        $sales['TaxType'] = strtoupper($t);
    }
    $item = [
        'Code'        => (string)$p['sku'],
        'Name'        => mb_substr((string)$p['name'], 0, 50),
        'Description' => mb_substr($description . " (billed $cycle)", 0, 4000),
        'IsSold'      => true,
        'SalesDetails' => $sales,
    ];
    if ($p['cost_price'] !== null && $p['cost_price'] !== '') {
        $purchase = ['UnitPrice' => (float)$p['cost_price']];
        if ($a = ($p['purchase_account_code'] ?? null) ?: setting('xero_item_purchase_account')) {
            $purchase['AccountCode'] = $a;
        }
        if ($t = $p['purchase_tax_type'] ?? null) {
            $purchase['TaxType'] = strtoupper($t);
        }
        $item['IsPurchased'] = true;
        $item['PurchaseDescription'] = mb_substr($description, 0, 4000);
        $item['PurchaseDetails'] = $purchase;
    }
    if (!empty($p['xero_item_id'])) {
        $item['ItemID'] = $p['xero_item_id'];
    }
    return $item;
}

/**
 * Create or update products as items in Xero (matched on the SKU / item code).
 * Returns ['sent' => n, 'failed' => [sku => reason]].
 */
function xero_push_products(array $ids): array
{
    if (!xero_connected()) {
        throw new XeroException('Connect Xero first (Admin → Xero).');
    }
    if (!xero_can_write_items()) {
        throw new XeroException('Xero hasn\'t given the CRM permission to create items yet. Switch on "Send products to Xero" under Admin → Xero, then press Reconnect.');
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        throw new XeroException('Choose at least one product.');
    }
    $products = db_all('SELECT * FROM products WHERE id IN (' . implode(',', $ids) . ')');
    $result = ['sent' => 0, 'failed' => []];
    $fail = function (array $p, string $reason) use (&$result): void {
        $result['failed'][$p['sku']] = $reason;
        db_exec('UPDATE products SET xero_sync_error = ? WHERE id = ?', [mb_substr($reason, 0, 500), $p['id']]);
    };

    $valid = [];
    foreach ($products as $p) {
        if (mb_strlen($p['sku']) > 30) {
            $fail($p, 'The SKU is longer than the 30 characters Xero allows for an item code.');
        } else {
            $valid[] = $p;
        }
    }
    foreach (array_chunk($valid, 50) as $chunk) {
        $body = xero_api('POST', xero_urls()['api'] . '/Items', ['summarizeErrors' => 'false'], true,
            ['Items' => array_map('xero_item_payload', $chunk)]);
        $returned = $body['Items'] ?? [];
        foreach ($chunk as $i => $p) {
            $item = $returned[$i] ?? null;
            $errors = array_column($item['ValidationErrors'] ?? [], 'Message');
            if (!$item || $errors || ($item['StatusAttributeString'] ?? 'OK') === 'ERROR') {
                $fail($p, $errors ? implode(' ', $errors) : 'Xero didn\'t confirm this item.');
                continue;
            }
            db_exec('UPDATE products SET xero_item_id = ?, xero_synced_at = NOW(), xero_sync_error = NULL WHERE id = ?', [$item['ItemID'] ?? $p['xero_item_id'], $p['id']]);
            $result['sent']++;
        }
    }
    audit('xero_items', sprintf('Sent %d product%s to Xero as items%s', $result['sent'], $result['sent'] === 1 ? '' : 's',
        $result['failed'] ? ', ' . count($result['failed']) . ' failed' : ''), 'products', count($ids) === 1 ? $ids[0] : null, null,
        $result['failed'] ? array_map(fn($r) => ['from' => '', 'to' => $r], $result['failed']) : null);
    return $result;
}

/** A readable one-line result of xero_push_products(). */
function xero_push_products_message(array $r): string
{
    $msg = $r['sent'] ? sprintf('%d product%s sent to Xero.', $r['sent'], $r['sent'] === 1 ? '' : 's') : 'Nothing was sent to Xero.';
    foreach (array_slice($r['failed'], 0, 5, true) as $sku => $reason) {
        $msg .= " $sku: $reason";
    }
    if (count($r['failed']) > 5) {
        $msg .= ' …and ' . (count($r['failed']) - 5) . ' more (see the "Xero problems" tab).';
    }
    return $msg;
}

/* ------------------------------------------------------ Chart of accounts --- */

/** Load the active account codes from Xero (for the nominal code lists on products). */
function xero_fetch_accounts(): int
{
    $body = xero_api('GET', xero_urls()['api'] . '/Accounts', ['where' => 'Status=="ACTIVE"']);
    $accounts = [];
    foreach ($body['Accounts'] ?? [] as $a) {
        if (($a['Code'] ?? '') !== '') {
            $accounts[] = ['code' => (string)$a['Code'], 'name' => (string)($a['Name'] ?? ''), 'class' => (string)($a['Class'] ?? '')];
        }
    }
    set_setting('xero_accounts', json_encode($accounts));
    // VAT rates too, so tax types can be picked rather than typed.
    try {
        $rates = $values = [];
        foreach (xero_api('GET', xero_urls()['api'] . '/TaxRates', ['where' => 'Status=="ACTIVE"'])['TaxRates'] ?? [] as $t) {
            if (($t['TaxType'] ?? '') !== '') {
                $rates[$t['TaxType']] = trim(($t['Name'] ?? $t['TaxType']) . (isset($t['EffectiveRate']) ? ' (' . rtrim(rtrim(number_format((float)$t['EffectiveRate'], 2), '0'), '.') . '%)' : ''));
                $values[$t['TaxType']] = isset($t['EffectiveRate']) ? (float)$t['EffectiveRate'] : null;
            }
        }
        set_setting('xero_tax_rates', json_encode($rates));
        set_setting('xero_tax_rate_values', json_encode($values));
    } catch (IntegrationException) {
        // Account codes are the main thing; tax types can still be typed.
    }
    return count($accounts);
}

/** Cached Xero VAT rates: [tax type => "name (rate%)"]. */
function xero_tax_rates(): array
{
    return json_decode((string)setting('xero_tax_rates', '[]'), true) ?: [];
}

/**
 * Check a nominal code or tax type typed into a Xero setting. Returns a problem, or null if fine.
 * Account codes are up to 10 characters (letters, numbers and symbols such as / or -).
 */
function xero_code_problem(string $value, string $kind): ?string
{
    if ($value === '') {
        return null;
    }
    if ($kind === 'tax') {
        $rates = xero_tax_rates();
        if (!preg_match('/^[A-Za-z0-9_]{1,50}$/', $value)) {
            return "\"$value\" isn't a Xero tax type. Tax types are short codes such as INPUT2 (20% VAT on expenses) or OUTPUT2 (20% VAT on income)"
                . ($rates ? ': pick one from the list.' : '.');
        }
        if ($rates && !isset($rates[$value]) && !isset(array_change_key_case($rates, CASE_UPPER)[strtoupper($value)])) {
            return "Xero has no active tax type \"$value\". Pick one from the list.";
        }
        return null;
    }
    if (mb_strlen($value) > 10 || preg_match('/[\x00-\x1F<>"]/', $value)) {
        return "\"$value\" isn't a Xero account code: codes are up to 10 characters, as shown in Xero's Chart of accounts (e.g. 310).";
    }
    $codes = nominal_codes();
    if ($codes && !isset($codes[$value])) {
        return "Xero has no active account with the code \"$value\". Pick one from the list, or press Load nominal codes from Xero if you've just added it.";
    }
    return null;
}

/** Cached Xero account codes: [code => "code – name"], optionally for sales (REVENUE) or purchases (EXPENSE). */
function nominal_codes(?string $for = null): array
{
    $out = [];
    foreach (json_decode((string)setting('xero_accounts', '[]'), true) ?: [] as $a) {
        if ($for === 'sales' && $a['class'] !== 'REVENUE') {
            continue;
        }
        if ($for === 'purchases' && $a['class'] !== 'EXPENSE') {
            continue;
        }
        $out[$a['code']] = $a['code'] . ' – ' . $a['name'];
    }
    return $out;
}
