<?php
declare(strict_types=1);

/*
 * GoCardless integration: shows whether each customer has a Direct Debit
 * mandate and, if not, creates a personal mandate setup link (a Billing
 * Request Flow, GoCardless's hosted page) to send to the customer.
 *
 * Uses an access token created in the GoCardless dashboard. A read-only token
 * is enough to check mandates; creating setup links needs read-write access.
 */

const GC_API_VERSION = '2015-07-06';

/** Mandate statuses, best first. Used to pick a customer's "current" mandate. */
const GC_MANDATE_RANK = [
    'active' => 1, 'submitted' => 2, 'pending_submission' => 3, 'pending_customer_approval' => 4,
    'suspended_by_payer' => 5, 'failed' => 6, 'blocked' => 7, 'expired' => 8, 'cancelled' => 9, 'consumed' => 10,
];

final class GoCardlessException extends IntegrationException
{
}

function gc_configured(): bool
{
    return (bool)setting('gocardless_access_token');
}

function gc_sandbox(): bool
{
    return setting('gocardless_environment') === 'sandbox';
}

function gc_base_url(): string
{
    return config('gocardless_url') ?: (gc_sandbox() ? 'https://api-sandbox.gocardless.com' : 'https://api.gocardless.com');
}

function gc_customer_url(string $customerId): string
{
    return (gc_sandbox() ? 'https://manage-sandbox.gocardless.com' : 'https://manage.gocardless.com') . '/customers/' . rawurlencode($customerId);
}

/** Summarise a mandate status as active / pending / inactive / none. */
function gc_mandate_state(?string $status): string
{
    return match ($status) {
        'active' => 'active',
        'submitted', 'pending_submission', 'pending_customer_approval' => 'pending',
        null, '' => 'none',
        default => 'inactive',
    };
}

function gc_mandate_label(?string $status): string
{
    return match (gc_mandate_state($status)) {
        'active'   => 'Active',
        'pending'  => 'Setting up',
        'inactive' => humanize($status),
        default    => 'No mandate',
    };
}

/** Badge HTML for a mandate status. */
function gc_mandate_badge(?string $status): string
{
    $class = ['active' => 'badge-active', 'pending' => 'badge-negotiation', 'inactive' => 'badge-p1', 'none' => 'badge-p1'][gc_mandate_state($status)];
    return '<span class="badge ' . $class . '">' . h(gc_mandate_label($status)) . '</span>';
}

/** Authorised request to the GoCardless API. Returns the decoded JSON body. */
function gc_request(string $method, string $path, array $query = [], ?array $body = null): array
{
    $token = setting('gocardless_access_token');
    if (!$token) {
        throw new GoCardlessException('GoCardless is not set up. Add an access token on the GoCardless page.');
    }
    $url = gc_base_url() . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    $headers = [
        'Authorization: Bearer ' . $token,
        'GoCardless-Version: ' . GC_API_VERSION,
        'Accept: application/json',
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        // Makes a retried POST safe: GoCardless won't create the resource twice.
        $headers[] = 'Idempotency-Key: ' . bin2hex(random_bytes(16));
    }

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            [$status, $response, $responseHeaders] = http_request($method, $url, $headers, $body === null ? null : json_encode($body));
        } catch (IntegrationException $e) {
            throw new GoCardlessException('Could not reach GoCardless: ' . $e->getMessage());
        }
        if ($status >= 200 && $status < 300) {
            return is_array($response) ? $response : [];
        }
        if ($status === 429 && $attempt < 3) {
            sleep(min(60, max(1, (int)($responseHeaders['retry-after'] ?? 5))));
            continue;
        }
        $error = is_array($response) ? ($response['error'] ?? []) : [];
        $message = $error['message'] ?? (is_string($response) ? substr(strip_tags($response), 0, 200) : 'Unknown error');
        foreach ($error['errors'] ?? [] as $detail) {
            if (!empty($detail['field']) && !empty($detail['message'])) {
                $message .= " ({$detail['field']} {$detail['message']})";
            }
        }
        $message = match ($status) {
            401 => 'GoCardless rejected the access token. Check it is correct and for the right environment (live or sandbox).',
            403 => 'The GoCardless access token doesn\'t have permission for this. Creating setup links needs a read-write token. (' . $message . ')',
            default => "GoCardless error ($status): $message",
        };
        throw new GoCardlessException($message);
    }
    throw new GoCardlessException('GoCardless request failed after retries.');
}

/** Fetch every page of a list endpoint (cursor pagination). */
function gc_list_all(string $path, string $key, array $query = []): array
{
    $all = [];
    $after = null;
    for ($i = 0; $i < 1000; $i++) {
        $page = gc_request('GET', $path, $query + ['limit' => 500] + ($after ? ['after' => $after] : []));
        array_push($all, ...($page[$key] ?? []));
        $after = $page['meta']['cursors']['after'] ?? null;
        if (!$after) {
            break;
        }
    }
    return $all;
}

/** Name of the organisation the token belongs to (also a good connection test). */
function gc_creditor_name(): string
{
    $creditors = gc_request('GET', '/creditors', ['limit' => 1])['creditors'] ?? [];
    return $creditors[0]['name'] ?? 'GoCardless account';
}

function gc_customer_name(array $c): string
{
    return trim((string)($c['company_name'] ?? '')) ?: (trim(($c['given_name'] ?? '') . ' ' . ($c['family_name'] ?? '')) ?: ($c['email'] ?? $c['id']));
}

/** Pick each customer's best mandate: [customerId => mandate]. */
function gc_best_mandates(array $mandates): array
{
    $best = [];
    foreach ($mandates as $m) {
        $customer = $m['links']['customer'] ?? null;
        if (!$customer) {
            continue;
        }
        $rank = GC_MANDATE_RANK[$m['status'] ?? ''] ?? 99;
        $current = $best[$customer] ?? null;
        $currentRank = $current ? (GC_MANDATE_RANK[$current['status']] ?? 99) : 100;
        if ($rank < $currentRank || ($rank === $currentRank && ($m['created_at'] ?? '') > ($current['created_at'] ?? ''))) {
            $best[$customer] = $m;
        }
    }
    return $best;
}

/** Insert or update a customer row (and its mandate). Returns the local id. */
function gc_store_customer(array $customer, ?array $mandate): int
{
    db_exec('INSERT INTO gocardless_customers (customer_id, name, email, crm_reference, mandate_id, mandate_status, mandate_reference, mandate_scheme, next_charge_date, synced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE name = VALUES(name), email = VALUES(email), crm_reference = VALUES(crm_reference),
            mandate_id = VALUES(mandate_id), mandate_status = VALUES(mandate_status), mandate_reference = VALUES(mandate_reference),
            mandate_scheme = VALUES(mandate_scheme), next_charge_date = VALUES(next_charge_date), synced_at = VALUES(synced_at)', [
        $customer['id'],
        mb_substr(gc_customer_name($customer), 0, 255),
        $customer['email'] ?? null,
        $customer['metadata']['crm_account'] ?? null,
        $mandate['id'] ?? null,
        $mandate['status'] ?? null,
        $mandate['reference'] ?? null,
        $mandate['scheme'] ?? null,
        $mandate['next_possible_charge_date'] ?? null,
    ]);
    return (int)db_value('SELECT id FROM gocardless_customers WHERE customer_id = ?', [$customer['id']]);
}

/**
 * Check open setup links: when a customer has completed one, link the CRM
 * customer to the GoCardless customer it created. Returns number completed.
 */
function gc_check_setup_links(?int $accountId = null): int
{
    $sql = "SELECT * FROM gocardless_setup_links WHERE status = 'open'" . ($accountId ? ' AND account_id = ?' : '');
    $completed = 0;
    foreach (db_all($sql, $accountId ? [$accountId] : []) as $link) {
        $br = gc_request('GET', '/billing_requests/' . rawurlencode($link['billing_request_id']))['billing_requests'] ?? [];
        $customerId = $br['links']['customer'] ?? null;
        $mandateId = $br['links']['mandate_request_mandate'] ?? null;
        $status = $br['status'] ?? '';

        if ($customerId && ($mandateId || $status === 'fulfilled')) {
            $customer = gc_request('GET', '/customers/' . rawurlencode($customerId))['customers'] ?? ['id' => $customerId];
            $mandates = gc_list_all('/mandates', 'mandates', ['customer' => $customerId]);
            $localId = gc_store_customer($customer, gc_best_mandates($mandates)[$customerId] ?? null);
            db_exec('UPDATE accounts SET gocardless_customer_id = ? WHERE id = ? AND gocardless_customer_id IS NULL', [$localId, $link['account_id']]);
            db_exec("UPDATE gocardless_setup_links SET status = 'completed' WHERE id = ?", [$link['id']]);
            $completed++;
        } elseif ($status === 'cancelled') {
            db_exec("UPDATE gocardless_setup_links SET status = 'cancelled' WHERE id = ?", [$link['id']]);
        } elseif ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
            db_exec("UPDATE gocardless_setup_links SET status = 'expired' WHERE id = ?", [$link['id']]);
        }
    }
    return $completed;
}

/** Link unlinked CRM customers by CRM reference (metadata), then email, then name. */
function gc_auto_link(): int
{
    $external = [];
    $taken = 'SELECT gocardless_customer_id FROM accounts WHERE gocardless_customer_id IS NOT NULL';
    foreach (db_all("SELECT id, name, email, crm_reference FROM gocardless_customers WHERE id NOT IN ($taken)") as $c) {
        $external[(int)$c['id']] = [
            'account_number' => [strtolower(trim((string)$c['crm_reference']))],
            'email'          => [email_match_key($c['email'])],
            'name'           => [company_match_key($c['name'])],
        ];
    }
    $crm = [];
    foreach (db_all('SELECT id, name, account_number, email FROM accounts WHERE gocardless_customer_id IS NULL') as $a) {
        $crm[(int)$a['id']] = [
            'account_number' => [strtolower(trim((string)$a['account_number']))],
            'email'          => [email_match_key($a['email'])],
            'name'           => [company_match_key($a['name'])],
        ];
    }
    // Contacts' email addresses also identify their customer.
    foreach (db_all('SELECT c.account_id, c.email FROM contacts c JOIN accounts a ON a.id = c.account_id WHERE a.gocardless_customer_id IS NULL AND c.email IS NOT NULL') as $c) {
        $crm[(int)$c['account_id']]['email'][] = email_match_key($c['email']);
    }
    $pairs = match_unambiguous($external, $crm, ['account_number', 'email', 'name']);
    foreach ($pairs as $accountId => $customerId) {
        db_exec('UPDATE accounts SET gocardless_customer_id = ? WHERE id = ? AND gocardless_customer_id IS NULL', [$customerId, $accountId]);
    }
    return count($pairs);
}

/** Full sync of customers and mandates. Returns a summary. */
function gc_sync(): array
{
    if (!gc_configured()) {
        throw new GoCardlessException('GoCardless is not set up.');
    }
    if (!(int)db_value("SELECT GET_LOCK('telecomcrm_gc_sync', 0)")) {
        throw new GoCardlessException('A GoCardless sync is already running. Try again in a minute.');
    }
    try {
        $started = microtime(true);
        $customers = gc_list_all('/customers', 'customers');
        $mandates = gc_list_all('/mandates', 'mandates');
        $best = gc_best_mandates($mandates);

        db()->beginTransaction();
        foreach ($customers as $c) {
            gc_store_customer($c, $best[$c['id']] ?? null);
        }
        db()->commit();

        $completed = gc_check_setup_links();
        $linked = gc_auto_link();
        $summary = [
            'customers' => count($customers),
            'mandates'  => count($mandates),
            'active'    => count(array_filter($best, fn($m) => $m['status'] === 'active')),
            'linked'    => $linked + $completed,
            'seconds'   => round(microtime(true) - $started, 1),
        ];
        set_setting('gocardless_last_sync_at', date('Y-m-d H:i:s'));
        set_setting('gocardless_last_sync_error', null);
        set_setting('gocardless_last_sync_summary', json_encode($summary));
        return $summary;
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        set_setting('gocardless_last_sync_error', date('Y-m-d H:i:s') . ' — ' . $e->getMessage());
        throw $e;
    } finally {
        db_value("SELECT RELEASE_LOCK('telecomcrm_gc_sync')");
    }
}

/** Refresh one CRM customer's mandate status straight from GoCardless. */
function gc_refresh_account(int $accountId): void
{
    gc_check_setup_links($accountId);
    $customerId = db_value('SELECT g.customer_id FROM accounts a JOIN gocardless_customers g ON g.id = a.gocardless_customer_id WHERE a.id = ?', [$accountId]);
    if ($customerId) {
        $customer = gc_request('GET', '/customers/' . rawurlencode($customerId))['customers'] ?? ['id' => $customerId];
        $mandates = gc_list_all('/mandates', 'mandates', ['customer' => $customerId]);
        gc_store_customer($customer, gc_best_mandates($mandates)[$customerId] ?? null);
    }
}

/** The customer's current, unexpired setup link, if any. */
function gc_open_setup_link(int $accountId): ?array
{
    return db_one("SELECT * FROM gocardless_setup_links WHERE account_id = ? AND status = 'open'
        AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 1", [$accountId]);
}

/** Best contact for Direct Debit: billing contact, then primary, then any. */
function gc_billing_contact(int $accountId): ?array
{
    return db_one('SELECT * FROM contacts WHERE account_id = ? ORDER BY is_billing DESC, is_primary DESC, id LIMIT 1', [$accountId]);
}

/** Customer details to prefill on GoCardless's hosted page. */
function gc_prefill(array $account): array
{
    $contact = gc_billing_contact((int)$account['id']);
    $person = $contact['name'] ?? ($account['type'] === 'residential' ? $account['name'] : '');
    $person = trim(preg_replace('/^(mr|mrs|ms|miss|dr)\.?\s+/i', '', (string)$person));
    $parts = $person === '' ? [] : preg_split('/\s+/', $person);
    $family = count($parts) > 1 ? array_pop($parts) : '';
    return array_filter([
        'company_name'  => $account['type'] === 'business' ? $account['name'] : null,
        'given_name'    => $parts ? implode(' ', $parts) : null,
        'family_name'   => $family ?: null,
        'email'         => $contact['email'] ?? null ?: $account['email'],
        'address_line1' => $account['address'],
        'city'          => $account['city'],
        'postal_code'   => $account['postcode'],
        'country_code'  => 'GB',
    ], fn($v) => $v !== null && $v !== '');
}

/** Create a personal mandate setup link for a CRM customer. */
function gc_create_setup_link(array $account): array
{
    $linkedCustomer = $account['gocardless_customer_id']
        ? db_value('SELECT customer_id FROM gocardless_customers WHERE id = ?', [$account['gocardless_customer_id']])
        : null;
    $scheme = setting('gocardless_scheme') ?: 'bacs';

    $request = [
        'mandate_request' => array_filter(['scheme' => $scheme, 'currency' => $scheme === 'bacs' ? 'GBP' : null]),
        'metadata'        => ['crm_account' => mb_substr((string)$account['account_number'], 0, 50)],
    ];
    if ($linkedCustomer) {
        $request['links'] = ['customer' => $linkedCustomer];
    }
    $br = gc_request('POST', '/billing_requests', [], ['billing_requests' => $request])['billing_requests'] ?? [];
    if (empty($br['id'])) {
        throw new GoCardlessException('GoCardless did not return a billing request.');
    }

    $flow = ['links' => ['billing_request' => $br['id']]];
    if ($returnUrl = setting('gocardless_return_url')) {
        $flow['redirect_uri'] = $returnUrl;
        $flow['exit_uri'] = $returnUrl;
    }
    if (!$linkedCustomer) {
        $flow['prefilled_customer'] = gc_prefill($account);
    }
    $flowResponse = gc_request('POST', '/billing_request_flows', [], ['billing_request_flows' => $flow])['billing_request_flows'] ?? [];
    if (empty($flowResponse['authorisation_url'])) {
        throw new GoCardlessException('GoCardless did not return a setup link.');
    }

    $expires = !empty($flowResponse['expires_at']) ? date('Y-m-d H:i:s', strtotime($flowResponse['expires_at'])) : null;
    db_exec("UPDATE gocardless_setup_links SET status = 'replaced' WHERE account_id = ? AND status = 'open'", [$account['id']]);
    db_exec('INSERT INTO gocardless_setup_links (account_id, billing_request_id, flow_id, url, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?)',
        [$account['id'], $br['id'], $flowResponse['id'] ?? null, $flowResponse['authorisation_url'], $expires, current_user()['id'] ?? null]);
    return gc_open_setup_link((int)$account['id']);
}

/** mailto: link with the setup link, addressed to the billing contact. */
function gc_setup_mailto(array $account, string $url): string
{
    $contact = gc_billing_contact((int)$account['id']);
    $to = $contact['email'] ?? $account['email'] ?? '';
    $first = $contact ? explode(' ', preg_replace('/^(mr|mrs|ms|miss|dr)\.?\s+/i', '', $contact['name']))[0] : '';
    $body = "Hi" . ($first ? " $first" : '') . ",\n\n"
        . "Please use the secure link below to set up your Direct Debit with us. It only takes a couple of minutes and is protected by the Direct Debit Guarantee.\n\n"
        . "$url\n\n"
        . "Your account number is {$account['account_number']}.\n\nMany thanks,\n" . (current_user()['name'] ?? '');
    return 'mailto:' . rawurlencode($to) . '?subject=' . rawurlencode('Set up your Direct Debit') . '&body=' . rawurlencode($body);
}
