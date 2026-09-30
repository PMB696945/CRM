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
function xero_api(string $method, string $url, array $query = [], bool $withTenant = true, ?array $json = null): mixed
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
        }
        [$status, $body, $responseHeaders] = xero_http($method, $url, $headers, $json === null ? null : json_encode($json));

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
    foreach (db_all("SELECT id, name, account_number, email FROM xero_contacts WHERE id NOT IN ($taken)") as $c) {
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

/* ------------------------------------------------- Accounts contact push --- */

/** Scopes when the CRM may also update contact details in Xero (contacts write access). */
function xero_write_scopes(string $scopes): string
{
    return trim(preg_replace('/(^|\s)accounting\.contacts\.read(\s|$)/', '$1accounting.contacts$2', ' ' . $scopes . ' '));
}

/** Whether the saved scopes allow updating contacts in Xero. */
function xero_can_write_contacts(): bool
{
    return (bool)preg_match('/(^|\s)accounting\.contacts(\s|$)/', (string)(setting('xero_scopes') ?: XERO_DEFAULT_SCOPES));
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
function xero_item_scopes(string $scopes): string
{
    $scopes = trim(preg_replace('/(^|\s)accounting\.settings\.read(?=\s|$)/', '', ' ' . $scopes . ' '));
    return preg_match('/(^|\s)accounting\.settings(\s|$)/', $scopes) ? $scopes : $scopes . ' accounting.settings';
}

function xero_can_write_items(): bool
{
    return (bool)preg_match('/(^|\s)accounting\.settings(\s|$)/', (string)(setting('xero_scopes') ?: XERO_DEFAULT_SCOPES));
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
    if ($t = setting('xero_item_tax_type')) {
        $sales['TaxType'] = $t;
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
    return count($accounts);
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
