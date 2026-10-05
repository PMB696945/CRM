<?php
declare(strict_types=1);

/*
 * aBILLity (Giacom's billing platform, by Union Street). The CRM is where customers, products and
 * services are set up; they're sent to aBILLity for billing:
 *   - customers  → a company with one site (AccountRef = our account number), its billing contact and invoice email.
 *                  Created in Xero at the same time.
 *   - products   → service charge types.
 *   - services   → service charges on the customer's site (plus a one-off charge for any setup fee). A pending
 *                  service goes in with a provisional start date, which is replaced with the real one when it goes live.
 * Anything that can't be sent is kept as pending, shown under Admin → aBILLity, and retried by the cron job.
 * API reference: https://api-billing.abillity.co.uk/help
 */

const ABILLITY_DEFAULT_URL = 'https://api-billing.abillity.co.uk/api';
// FrequencyTypeId values in aBILLity.
const ABILLITY_FREQUENCY = ['yearly' => 1, 'monthly' => 2, 'one_off' => 3, 'quarterly' => 4];
const ABILLITY_FREQUENCY_MONTHS = [1 => 12, 2 => 1, 3 => 0, 4 => 3];

final class AbillityException extends IntegrationException
{
}

function abillity_configured(): bool
{
    return (bool)(setting('abillity_system') && setting('abillity_username') && setting('abillity_password'));
}

/** Send automatically when things are added or changed (otherwise only with the buttons). */
function abillity_auto(): bool
{
    return abillity_configured() && setting('abillity_auto', '1') === '1';
}

function abillity_url(): string
{
    return rtrim((string)(config('abillity_url') ?: setting('abillity_url') ?: ABILLITY_DEFAULT_URL), '/');
}

/** Call the API. $auth overrides the saved login (to test new details before saving them). */
function abillity_api(string $method, string $path, ?array $json = null, array $query = [], ?array $auth = null): mixed
{
    $auth ??= ['system' => (string)setting('abillity_system'), 'username' => (string)setting('abillity_username'), 'password' => (string)setting('abillity_password')];
    $url = abillity_url() . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    $headers = ['Accept: application/json', 'SystemInformation: ' . $auth['system'], 'username: ' . $auth['username'], 'password: ' . $auth['password']];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    [$status, $body] = http_request($method, $url, $headers, $json !== null ? json_encode($json) : null, 45);
    if ($status >= 200 && $status < 300) {
        return $body;
    }
    throw new AbillityException(abillity_error_message($status, $body));
}

/** A readable message from an aBILLity error response. */
function abillity_error_message(int $status, mixed $body): string
{
    $detail = '';
    if (is_array($body)) {
        $parts = [];
        foreach ((array)($body['ModelState'] ?? []) as $field => $messages) {
            foreach ((array)$messages as $m) {
                $parts[] = (str_contains((string)$field, '.') ? substr((string)$field, strrpos((string)$field, '.') + 1) . ': ' : '') . $m;
            }
        }
        $detail = $parts ? implode(' ', $parts) : (string)($body['Message'] ?? $body['message'] ?? json_encode($body));
    } elseif (is_string($body)) {
        $detail = trim(mb_substr(strip_tags($body), 0, 300), " \"\n");
    }
    $hint = match (true) {
        $status === 401 => ' Check the aBILLity login under Admin → aBILLity, and that the user has permission for this.',
        $status === 404 && $detail === '' => ' (not found)',
        default => '',
    };
    return "aBILLity said ($status): " . ($detail !== '' ? $detail : 'no details') . $hint;
}

/** The new record's ID from a create response (the API returns {"Id": n} for most, but not all, methods). */
function abillity_new_id(mixed $body): ?int
{
    if (is_int($body) || (is_string($body) && ctype_digit(trim($body)))) {
        return (int)$body;
    }
    if (is_array($body)) {
        foreach (['Id', 'ID', 'id', 'CompanyId', 'CompanyID', 'ServiceChargeId', 'ChargeId'] as $k) {
            if (isset($body[$k]) && is_numeric($body[$k])) {
                return (int)$body[$k];
            }
        }
    }
    return null;
}

function abillity_date(?string $date): ?string
{
    return $date ? date('Y-m-d', strtotime($date)) . 'T00:00:00' : null;
}

/* ------------------------------------------------------------- Customers --- */

/** Queue a customer to be sent (and send it now if sending automatically). */
function abillity_queue_account(int $accountId): void
{
    if (!abillity_configured()) {
        return;
    }
    db_exec('UPDATE accounts SET abillity_pending = 1 WHERE id = ?', [$accountId]);
    if (abillity_auto()) {
        try {
            abillity_push_customer($accountId);
        } catch (IntegrationException) {
            // Kept as pending with the error shown; the cron job tries again.
        }
    }
}

/** Should this customer be set up for billing? (Customers, not prospects or closed accounts.) */
function abillity_account_billable(array $a): bool
{
    return in_array($a['status'], ['active', 'suspended'], true);
}

/**
 * Create or update the customer in aBILLity, and create them in Xero at the same time if they're not there yet.
 * Returns the aBILLity site ID.
 */
function abillity_push_customer(int $accountId): int
{
    $a = db_one('SELECT * FROM accounts WHERE id = ?', [$accountId]) ?? throw new AbillityException('Customer not found.');
    try {
        $notes = [];
        if (!$a['xero_contact_id'] && xero_connected()) {
            try {
                xero_create_customer($accountId);
            } catch (IntegrationException $e) {
                // Billing still goes ahead; the Xero problem is shown alongside.
                $notes[] = 'Not created in Xero: ' . $e->getMessage();
            }
        }
        [$companyId, $siteId] = abillity_find_or_create_customer($a);
        db_exec('UPDATE accounts SET abillity_company_id = ?, abillity_site_id = ? WHERE id = ?', [$companyId, $siteId, $accountId]);

        abillity_api('PATCH', "company/$companyId", ['Name' => mb_substr($a['name'], 0, 50), 'IsCustomer' => true, 'IsProspect' => false]);
        $site = array_filter([
            'SiteName' => mb_substr($a['name'], 0, 255), 'ShortName' => mb_substr($a['account_number'], 0, 255),
            'Address' => mb_substr(implode(', ', array_filter([$a['address'], $a['address2'] ?? null])), 0, 255),
            'Town' => mb_substr((string)$a['city'], 0, 50), 'County' => mb_substr((string)($a['county'] ?? ''), 0, 50),
            'PostCode' => mb_substr((string)$a['postcode'], 0, 10), 'Telephone' => mb_substr((string)$a['phone'], 0, 30),
        ], fn($v) => $v !== '') + ['MainSite' => true, 'AccountRef' => $a['account_number']];
        try {
            abillity_api('PATCH', "site/$siteId", $site);
        } catch (AbillityException $e) {
            // Only some aBILLity users may set the account reference; send the rest without it.
            if (!str_contains($e->getMessage(), 'AccountRef')) {
                throw $e;
            }
            unset($site['AccountRef']);
            abillity_api('PATCH', "site/$siteId", $site);
            $notes[] = 'Your aBILLity user can\'t set the account reference, so add ' . $a['account_number'] . ' in aBILLity by hand.';
        }
        abillity_push_billing_contact($a, $siteId);

        db_exec('UPDATE accounts SET abillity_pending = 0, abillity_synced_at = NOW(), abillity_error = ? WHERE id = ?',
            [$notes ? mb_substr(implode(' ', $notes), 0, 500) : null, $accountId]);
        return $siteId;
    } catch (IntegrationException $e) {
        db_exec('UPDATE accounts SET abillity_pending = 1, abillity_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 500), $accountId]);
        throw $e;
    }
}

/** [companyId, siteId] for a customer: already linked, found by account reference, or newly created. */
function abillity_find_or_create_customer(array $a): array
{
    if ($a['abillity_site_id'] && $a['abillity_company_id']) {
        return [(int)$a['abillity_company_id'], (int)$a['abillity_site_id']];
    }
    if ($a['abillity_company_id']) {
        $sites = abillity_api('GET', "company/{$a['abillity_company_id']}/Site");
        $site = abillity_pick_site(is_array($sites) ? $sites : [], $a);
        if ($site) {
            return [(int)$a['abillity_company_id'], (int)$site['Id']];
        }
    }
    foreach (['AccountRef' => $a['account_number'], 'SiteRef' => $a['account_number']] as $filter => $value) {
        if ($found = abillity_search_customer([$filter => $value])) {
            return $found;
        }
    }
    $created = abillity_api('POST', 'company', ['CompanyName' => mb_substr($a['name'], 0, 50), 'SiteShortName' => mb_substr($a['account_number'], 0, 255)]);
    $companyId = abillity_new_id($created);
    if ($companyId) {
        $sites = abillity_api('GET', "company/$companyId/Site");
        if ($site = abillity_pick_site(is_array($sites) ? $sites : [], $a)) {
            log_activity((int)$a['id'], 'note', "Customer created in aBILLity (company $companyId)");
            return [$companyId, (int)$site['Id']];
        }
    }
    // The create didn't say what it made: find it by the site short name (our account number), then the name.
    foreach (['SiteRef' => $a['account_number'], 'SiteName' => $a['name']] as $filter => $value) {
        if ($found = abillity_search_customer([$filter => $value], $a)) {
            log_activity((int)$a['id'], 'note', "Customer created in aBILLity (company {$found[0]})");
            return $found;
        }
    }
    throw new AbillityException('The customer was created in aBILLity, but it couldn\'t be found afterwards to link it. Find it in aBILLity and enter its company and site IDs on the customer here.');
}

/** Search aBILLity for one customer site; returns [companyId, siteId] or null. */
function abillity_search_customer(array $filter, ?array $a = null): ?array
{
    $r = abillity_api('GET', 'company/CompanySearch', null, $filter + ['CompanyType' => 1, 'Page_Size' => 20]);
    $rows = is_array($r) ? ($r['Data'] ?? []) : [];
    if ($a) {
        // A name search can match others: insist on our short name or exact name.
        $rows = array_values(array_filter($rows, fn($row) => strcasecmp((string)($row['SiteRef'] ?? ''), $a['account_number']) === 0
            || strcasecmp((string)($row['AccountRef'] ?? ''), $a['account_number']) === 0 || strcasecmp(trim((string)($row['CompanyName'] ?? '')), trim($a['name'])) === 0));
    }
    if (count($rows) !== 1 && count(array_unique(array_column($rows, 'CompanyId'))) !== 1) {
        return null;
    }
    return !empty($rows[0]['CompanyId']) && !empty($rows[0]['SiteId']) ? [(int)$rows[0]['CompanyId'], (int)$rows[0]['SiteId']] : null;
}

function abillity_pick_site(array $sites, array $a): ?array
{
    foreach ($sites as $s) {
        if (strcasecmp((string)($s['ShortName'] ?? ''), $a['account_number']) === 0 || strcasecmp((string)($s['AccountRef'] ?? ''), $a['account_number']) === 0) {
            return $s;
        }
    }
    foreach ($sites as $s) {
        if (!empty($s['MainSite'])) {
            return $s;
        }
    }
    return count($sites) === 1 ? $sites[0] : null;
}

/** The accounts contact (or main contact) as the site's billing contact, with their email for invoices. */
function abillity_push_billing_contact(array $a, int $siteId): void
{
    $c = db_one('SELECT * FROM contacts WHERE id = ?', [$a['billing_contact_id'] ?: $a['main_contact_id'] ?: 0]);
    if (!$c || !filter_var((string)$c['email'], FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $parts = preg_split('/\s+/', trim($c['name']), 2);
    $body = array_filter([
        'Christian' => $parts[0] ?? $c['name'], 'Surname' => $parts[1] ?? '', 'Email' => $c['email'],
        'DDI' => mb_substr((string)$c['phone'], 0, 34), 'Mobile' => mb_substr(preg_replace('/[^\d+]/', '', (string)$c['mobile']), 0, 17),
    ], fn($v) => $v !== '') + ['MainContact' => true];
    $contactId = (int)$c['abillity_contact_id'];
    if ($contactId) {
        try {
            abillity_api('PATCH', "sitecontact/$contactId", $body);
        } catch (AbillityException $e) {
            if (!str_contains($e->getMessage(), '(404)')) {
                throw $e;
            }
            $contactId = 0; // removed in aBILLity: add it again
        }
    }
    if (!$contactId) {
        $contactId = (int)abillity_new_id(abillity_api('POST', 'sitecontact', ['SiteID' => $siteId] + $body));
        if ($contactId) {
            db_exec('UPDATE contacts SET abillity_contact_id = ? WHERE id = ?', [$contactId, $c['id']]);
        }
    }
    abillity_api('PATCH', "site/$siteId/billinginformation", array_filter(['BillingContactId' => $contactId ?: null, 'Email' => $c['email']]));
}

/* -------------------------------------------------------------- Products --- */

/** aBILLity frequency for a product's billing cycle (cycles aBILLity doesn't have are billed monthly). */
function abillity_frequency(?string $billingFrequency): int
{
    return ABILLITY_FREQUENCY[$billingFrequency ?? 'monthly'] ?? ABILLITY_FREQUENCY['monthly'];
}

/** Create or update a product as an aBILLity service charge type. Returns its ID. */
function abillity_push_product(int $productId): int
{
    $p = db_one('SELECT * FROM products WHERE id = ?', [$productId]) ?? throw new AbillityException('Product not found.');
    $frequency = abillity_frequency($p['billing_frequency']);
    // Prices on products are per billing cycle. A cycle aBILLity doesn't have (weekly, bi-annually) is sent as monthly.
    $native = isset(ABILLITY_FREQUENCY[$p['billing_frequency'] ?? 'monthly']);
    $perCycle = fn($price) => $price === null ? null : ($native ? round((float)$price, 2) : monthly_equivalent($price, $p['billing_frequency']));
    $body = array_filter([
        'RecurringChargeType' => mb_substr($p['name'], 0, 50),
        'DefaultDescription'  => mb_substr($p['name'], 0, 100),
        'FrequencyTypeId'     => $frequency,
        'DefaultSalePrice'    => $perCycle($p['monthly_price']),
        'DefaultCost'         => $perCycle($p['cost_price']),
        'Rental'              => $frequency !== ABILLITY_FREQUENCY['one_off'],
        'Nominal'             => $p['sales_account_code'] ? mb_substr($p['sales_account_code'], 0, 50) : null,
    ], fn($v) => $v !== null);
    try {
        $id = (int)$p['abillity_charge_type_id'];
        $update = (bool)$id;
        if (!$id) {
            try {
                $id = (int)abillity_new_id(abillity_api('POST', 'servicechargetype', $body));
            } catch (AbillityException $e) {
                if (!str_contains($e->getMessage(), '(409)')) {
                    throw $e;
                }
                $update = true; // aBILLity already has one with this name: link to it and bring it up to date
            }
        }
        if (!$id) {
            $id = abillity_find_charge_type($p['name'])
                ?? throw new AbillityException('aBILLity has a charge type called "' . $p['name'] . '" but it couldn\'t be matched. Enter its ID on the product.');
        }
        if ($update) {
            abillity_api('PATCH', "servicechargetype/$id", $body);
        }
        db_exec('UPDATE products SET abillity_charge_type_id = ?, abillity_synced_at = NOW(), abillity_error = NULL WHERE id = ?', [$id, $productId]);
        return $id;
    } catch (IntegrationException $e) {
        db_exec('UPDATE products SET abillity_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 500), $productId]);
        throw $e;
    }
}

function abillity_find_charge_type(string $name): ?int
{
    $r = abillity_api('GET', 'servicechargetype', null, ['search_text' => $name, 'page_size' => 50]);
    foreach (is_array($r) ? ($r['servicechargetypelist'] ?? []) : [] as $t) {
        if (strcasecmp(trim((string)($t['RecurringChargeType'] ?? '')), trim(mb_substr($name, 0, 50))) === 0) {
            return (int)$t['Id'];
        }
    }
    return null;
}

/* -------------------------------------------------------------- Services --- */

/**
 * Queue a service to be sent (and send it now if sending automatically). Services that were on the CRM before
 * aBILLity was connected are already billed there, so they're only sent with the button (unless already linked).
 */
function abillity_queue_service(int $serviceId): void
{
    if (!abillity_configured()) {
        return;
    }
    $s = db_one('SELECT created_at, abillity_charge_id, abillity_setup_charge_id FROM services WHERE id = ?', [$serviceId]);
    $since = setting('abillity_connected_at');
    if (!$s || ($s['abillity_charge_id'] === null && $s['abillity_setup_charge_id'] === null && $since && $s['created_at'] < $since)) {
        return;
    }
    db_exec('UPDATE services SET abillity_pending = 1 WHERE id = ?', [$serviceId]);
    if (abillity_auto()) {
        try {
            abillity_push_service($serviceId);
        } catch (IntegrationException) {
            // Kept as pending with the error shown; the cron job tries again.
        }
    }
}

/** Days ahead a pending service's provisional start date is set, so it isn't billed before it's live. */
function abillity_provisional_days(): int
{
    return max(0, min(365, (int)(setting('abillity_provisional_days') ?? 30)));
}

/**
 * Send a service to aBILLity as a service charge (and a one-off charge for its setup fee), or update it.
 * Pending services get a provisional start date; when the service is live its real start date is sent.
 */
function abillity_push_service(int $serviceId): void
{
    $s = db_one('SELECT s.*, p.name AS product_name, p.billing_frequency, p.monthly_price AS product_price, p.cost_price, p.abillity_charge_type_id
        FROM services s LEFT JOIN products p ON p.id = s.product_id WHERE s.id = ?', [$serviceId]) ?? throw new AbillityException('Service not found.');
    try {
        $account = db_one('SELECT * FROM accounts WHERE id = ?', [$s['account_id']]);
        $siteId = (int)$account['abillity_site_id'];
        if (!$siteId || $account['abillity_pending']) {
            $siteId = abillity_push_customer((int)$account['id']);
        }
        if ($s['product_id'] && !$s['abillity_charge_type_id']) {
            try {
                $s['abillity_charge_type_id'] = abillity_push_product((int)$s['product_id']);
            } catch (IntegrationException) {
                // The charge still goes in with its own description; the product problem is shown on the product.
            }
        }

        $frequency = abillity_frequency($s['billing_frequency'] === 'one_off' ? 'monthly' : $s['billing_frequency']);
        $months = ABILLITY_FREQUENCY_MONTHS[$frequency];
        $monthly = $s['monthly_price'] !== null ? (float)$s['monthly_price'] : monthly_equivalent($s['product_price'], $s['billing_frequency']);
        $cost = $s['cost_price'] !== null ? monthly_equivalent($s['cost_price'], $s['billing_frequency']) : null;
        // Live (active, suspended or ceased): billed from its start date, or from today if none was entered.
        $live = in_array($s['status'], ['active', 'suspended', 'ceased'], true);
        $first = $live
            ? ($s['start_date'] ?: (!$s['abillity_provisional'] && $s['abillity_first_payment'] ? $s['abillity_first_payment'] : date('Y-m-d')))
            : ($s['start_date'] && $s['start_date'] > date('Y-m-d') ? $s['start_date'] : date('Y-m-d', strtotime('+' . abillity_provisional_days() . ' days')));
        $last = $s['status'] === 'ceased' ? ($s['abillity_last_payment'] ?: date('Y-m-d')) : null;
        $name = $s['product_name'] ?: (SERVICE_TYPES[$s['service_type']] ?? 'Service');
        $description = mb_substr("$name – {$s['identifier']}", 0, 100);
        $notes = "CRM service #{$s['id']}" . ($s['term_months'] ? "; {$s['term_months']} month term" : '') . ($s['contract_end_date'] ? '; contract ends ' . fmt_date($s['contract_end_date']) : '');

        $sent = fn($v) => $v !== null; // 0 means sent, but its aBILLity ID couldn't be found
        $unmatched = [];
        if ($s['status'] === 'ceased' && !$sent($s['abillity_charge_id']) && !$sent($s['abillity_setup_charge_id'])) {
            // Never billed: nothing to send.
            db_exec('UPDATE services SET abillity_pending = 0, abillity_error = NULL WHERE id = ?', [$serviceId]);
            return;
        }
        if ($s['status'] === 'ceased' && $s['abillity_provisional']) {
            throw new AbillityException("Cancelled before it went live. aBILLity has a charge for it from a provisional start date (" . fmt_date($s['abillity_first_payment'])
                . "): remove service charge {$s['abillity_charge_id']} in aBILLity.");
        }
        if ($s['status'] === 'ceased' && $last < $first && $s['abillity_charge_id']) {
            throw new AbillityException('Ceased before its billing start date (' . fmt_date($first) . "), so aBILLity can't be given an end date: remove service charge {$s['abillity_charge_id']} in aBILLity.");
        }

        $charge = array_filter([
            'Description' => $description, 'ChargeId' => $s['abillity_charge_type_id'] ? (int)$s['abillity_charge_type_id'] : null,
            'FrequencyTypeId' => $frequency, 'SalesPrice' => round($monthly * $months, 2), 'CostPrice' => $cost !== null ? round($cost * $months, 2) : null,
            'FirstPayment' => abillity_date($first), 'LastPayment' => abillity_date($last),
            'SerialNo' => mb_substr($s['identifier'], 0, 40), 'Notes' => $notes,
        ], fn($v) => $v !== null) + ['Quantity' => 1, 'Rental' => true, 'BackDatable' => true];

        if ($monthly > 0 || $sent($s['abillity_charge_id'])) {
            if ((int)$s['abillity_charge_id']) {
                abillity_api('PATCH', "servicecharge/V2/{$s['abillity_charge_id']}", $charge);
            } elseif ($sent($s['abillity_charge_id'])) {
                $unmatched[] = 'its charge';
            } else {
                $id = abillity_new_id(abillity_api('POST', 'servicecharge/v2', ['SiteId' => $siteId] + $charge))
                    ?? abillity_find_service_charge($siteId, $description, "CRM service #{$s['id']}");
                // Never send it again once aBILLity has it, even if its ID couldn't be found (that would bill twice).
                db_exec('UPDATE services SET abillity_charge_id = ? WHERE id = ?', [$id ?? 0, $serviceId]);
                if ($id === null) {
                    $unmatched[] = 'its charge';
                }
                log_activity((int)$s['account_id'], 'note', "Service {$s['identifier']} added to aBILLity" . ($live ? '' : ' (provisional start ' . fmt_date($first) . ')'));
            }
        }
        if ((float)$s['setup_fee'] > 0 && !$sent($s['abillity_setup_charge_id'])) {
            $setupDescription = mb_substr("Setup – $name – {$s['identifier']}", 0, 100);
            $setup = array_filter([
                'SiteId' => $siteId, 'Description' => $setupDescription, 'FrequencyTypeId' => ABILLITY_FREQUENCY['one_off'], 'SalesPrice' => round((float)$s['setup_fee'], 2),
                'FirstPayment' => abillity_date($first), 'SerialNo' => mb_substr($s['identifier'], 0, 40), 'Notes' => "CRM service #{$s['id']} setup",
            ]) + ['Quantity' => 1, 'Rental' => false, 'BackDatable' => true];
            $id = abillity_new_id(abillity_api('POST', 'servicecharge/v2', $setup)) ?? abillity_find_service_charge($siteId, $setupDescription, "CRM service #{$s['id']} setup");
            db_exec('UPDATE services SET abillity_setup_charge_id = ? WHERE id = ?', [$id ?? 0, $serviceId]);
            if ($id === null) {
                $unmatched[] = 'its setup charge';
            }
        } elseif ($s['abillity_provisional'] && $live && $sent($s['abillity_setup_charge_id'])) {
            if ((int)$s['abillity_setup_charge_id']) {
                abillity_api('PATCH', "servicecharge/V2/{$s['abillity_setup_charge_id']}", ['FirstPayment' => abillity_date($first)]);
            } else {
                $unmatched[] = 'its setup charge';
            }
        }
        if ($live && $s['abillity_provisional']) {
            log_activity((int)$s['account_id'], 'note', "Service {$s['identifier']} live: billing start in aBILLity set to " . fmt_date($first));
        }
        $problem = $unmatched ? 'Sent to aBILLity, but ' . implode(' and ', array_unique($unmatched)) . ' couldn\'t be found there afterwards, so changes (such as the live date) need making in aBILLity by hand.' : null;
        db_exec('UPDATE services SET abillity_first_payment = ?, abillity_provisional = ?, abillity_last_payment = ?, abillity_pending = 0, abillity_synced_at = NOW(), abillity_error = ? WHERE id = ?',
            [$first, $live ? 0 : 1, $last, $problem, $serviceId]);
    } catch (IntegrationException $e) {
        db_exec('UPDATE services SET abillity_pending = 1, abillity_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 500), $serviceId]);
        throw $e;
    }
}

/** Find a service charge we've just created (when the create response didn't include its ID). */
function abillity_find_service_charge(int $siteId, string $description, string $notesMarker): ?int
{
    $r = abillity_api('GET', "site/$siteId/servicecharge", null, ['search_text' => $description, 'page_size' => 50]);
    $rows = is_array($r) ? ($r['serviceChargeList'] ?? []) : [];
    $found = array_values(array_filter($rows, fn($c) => ($n = trim((string)($c['Notes'] ?? ''))) === $notesMarker || str_starts_with($n, $notesMarker . ';')))
        ?: array_values(array_filter($rows, fn($c) => (string)($c['Description'] ?? '') === $description));
    return $found ? (int)end($found)['Id'] : null;
}

/* ----------------------------------------------------------------- Sync --- */

/** Send everything waiting (cron and the "Send now" button). Returns ['sent' => n, 'failed' => [label => error]]. */
function abillity_push_pending(int $limit = 100): array
{
    $out = ['sent' => 0, 'failed' => []];
    if (!abillity_configured()) {
        return $out;
    }
    foreach (db_all('SELECT id, name FROM accounts WHERE abillity_pending = 1 ORDER BY id LIMIT ' . $limit) as $a) {
        try {
            abillity_push_customer((int)$a['id']);
            $out['sent']++;
        } catch (IntegrationException $e) {
            $out['failed'][$a['name']] = $e->getMessage();
        }
    }
    foreach (db_all('SELECT id, identifier FROM services WHERE abillity_pending = 1 ORDER BY id LIMIT ' . $limit) as $s) {
        try {
            abillity_push_service((int)$s['id']);
            $out['sent']++;
        } catch (IntegrationException $e) {
            $out['failed'][$s['identifier']] = $e->getMessage();
        }
    }
    set_setting('abillity_last_sync_at', date('Y-m-d H:i:s'));
    return $out;
}

/** What's waiting or failed, for the settings page. */
function abillity_problems(): array
{
    return [
        'accounts' => db_all("SELECT id, name, account_number, abillity_error, abillity_pending FROM accounts WHERE abillity_pending = 1 OR abillity_error IS NOT NULL ORDER BY name LIMIT 200"),
        'services' => db_all("SELECT s.id, s.identifier, s.account_id, a.name AS account_name, s.abillity_error FROM services s JOIN accounts a ON a.id = s.account_id
            WHERE s.abillity_pending = 1 OR s.abillity_error IS NOT NULL ORDER BY s.id DESC LIMIT 200"),
        'products' => db_all("SELECT id, name, abillity_error FROM products WHERE abillity_error IS NOT NULL ORDER BY name LIMIT 200"),
    ];
}

/**
 * After a customer, contact, product or service is saved in the CRM: send it to aBILLity (and, for a new
 * customer, Xero). Returns [message to add, ok].
 */
function abillity_after_save(string $entity, int $id, array $changes, bool $created): array
{
    if (!abillity_configured()) {
        return ['', true];
    }
    $report = function (?array $row, string $what): array {
        if (!$row) {
            return ['', true];
        }
        if ($row['abillity_error']) {
            return [" But $what aBILLity: " . $row['abillity_error'], false];
        }
        return [abillity_auto() ? ' Sent to aBILLity.' : ' It\'ll be sent to aBILLity with the next sync.', true];
    };
    switch ($entity) {
        case 'accounts':
            $a = db_one('SELECT * FROM accounts WHERE id = ?', [$id]);
            if (!$a['abillity_site_id'] && !abillity_account_billable($a)) {
                return ['', true]; // prospects aren't set up for billing until they become customers or order
            }
            $since = setting('abillity_connected_at');
            if (!$a['abillity_site_id'] && !$a['abillity_pending'] && $since && $a['created_at'] < $since) {
                return ['', true]; // already a customer before aBILLity was connected: set up with the button or "Send all active customers"
            }
            if (!$changes && !$created && $a['abillity_site_id'] && !$a['abillity_pending']) {
                return ['', true];
            }
            abillity_queue_account($id);
            return $report(db_one('SELECT abillity_error FROM accounts WHERE id = ?', [$id]), 'it couldn\'t be sent to');
        case 'contacts':
            $c = db_one('SELECT c.account_id FROM contacts c JOIN accounts a ON a.id = c.account_id
                WHERE c.id = ? AND a.abillity_site_id IS NOT NULL AND c.id IN (a.billing_contact_id, a.main_contact_id)', [$id]);
            if ($c && $changes) {
                abillity_queue_account((int)$c['account_id']);
            }
            return ['', true];
        case 'products':
            if (!abillity_auto() || (!$changes && !$created)) {
                return ['', true];
            }
            try {
                abillity_push_product($id);
                return [' Sent to aBILLity.', true];
            } catch (IntegrationException $e) {
                return [' But it couldn\'t be sent to aBILLity: ' . $e->getMessage(), false];
            }
        case 'services':
            if (!$changes && !$created) {
                return ['', true];
            }
            abillity_queue_service($id);
            return $report(db_one('SELECT abillity_error FROM services WHERE id = ?', [$id]), 'it couldn\'t be sent to');
    }
    return ['', true];
}

/* ----------------------------------------------------------- Controller --- */

function abillity_controller(): void
{
    require_permission('settings.manage');
    $back = url('abillity');
    if (is_post()) {
        verify_csrf();
        $do = query('do');
        try {
            switch ($do) {
                case 'remove':
                    foreach (['abillity_system', 'abillity_username', 'abillity_password'] as $k) {
                        set_setting($k, null);
                    }
                    audit('settings', 'aBILLity login removed');
                    flash('aBILLity login removed. Links to aBILLity records are kept.');
                    break;
                case 'sync':
                    $r = abillity_push_pending();
                    flash($r['sent'] . ' sent to aBILLity.' . ($r['failed'] ? ' ' . count($r['failed']) . ' couldn\'t be sent; see below.' : ''), $r['failed'] ? 'error' : 'success');
                    break;
                case 'send_customers':
                    $n = 0;
                    foreach (db_all("SELECT id FROM accounts WHERE status IN ('active','suspended') AND abillity_site_id IS NULL") as $a) {
                        db_exec('UPDATE accounts SET abillity_pending = 1 WHERE id = ?', [$a['id']]);
                        $n++;
                    }
                    $r = abillity_push_pending();
                    flash("$n customer" . ($n === 1 ? '' : 's') . ' queued; ' . $r['sent'] . ' sent.' . ($r['failed'] ? ' ' . count($r['failed']) . ' couldn\'t be sent; see below.' : ''), $r['failed'] ? 'error' : 'success');
                    break;
                default:
                    $system = trim((string)($_POST['system'] ?? ''));
                    $username = trim((string)($_POST['username'] ?? ''));
                    $password = (string)($_POST['password'] ?? '');
                    $apiUrl = trim((string)($_POST['api_url'] ?? ''));
                    if ($apiUrl !== '' && !preg_match('#^https://[^\s]+$#', $apiUrl)) {
                        throw new AbillityException('The API address must start with https://');
                    }
                    set_setting('abillity_url', $apiUrl === '' || rtrim($apiUrl, '/') === ABILLITY_DEFAULT_URL ? null : rtrim($apiUrl, '/'));
                    set_setting('abillity_auto', empty($_POST['auto']) ? '0' : '1');
                    $days = trim((string)($_POST['provisional_days'] ?? '30'));
                    set_setting('abillity_provisional_days', ctype_digit($days) ? (string)min(365, (int)$days) : '30');
                    if ($system !== '' && $username !== '') {
                        $auth = ['system' => $system, 'username' => $username, 'password' => $password !== '' ? $password : (string)setting('abillity_password')];
                        try {
                            abillity_api('GET', 'common/frequencytype', null, [], $auth);
                        } catch (IntegrationException $e) {
                            throw new AbillityException('Those details didn\'t work, so they weren\'t saved. ' . $e->getMessage());
                        }
                        if (!setting('abillity_connected_at')) {
                            set_setting('abillity_connected_at', date('Y-m-d H:i:s')); // services from before this are already billed
                        }
                        set_setting('abillity_system', $system);
                        set_setting('abillity_username', $username);
                        if ($password !== '') {
                            set_setting('abillity_password', $password);
                        }
                        audit('settings', "aBILLity login saved and tested ($username)");
                        flash('Connected to aBILLity.');
                    } else {
                        audit('settings', 'aBILLity settings saved');
                        flash('Saved.');
                    }
            }
        } catch (IntegrationException $e) {
            flash($e->getMessage(), 'error');
        }
        redirect($back);
    }
    $test = null;
    if (abillity_configured() && query('test') === '1') {
        try {
            $test = abillity_api('GET', 'company/MyCompany');
        } catch (IntegrationException $e) {
            flash($e->getMessage(), 'error');
        }
    }
    $counts = [
        'customers' => (int)db_value('SELECT COUNT(*) FROM accounts WHERE abillity_site_id IS NOT NULL'),
        'unsent_customers' => (int)db_value("SELECT COUNT(*) FROM accounts WHERE status IN ('active','suspended') AND abillity_site_id IS NULL"),
        'services' => (int)db_value('SELECT COUNT(*) FROM services WHERE abillity_charge_id IS NOT NULL OR abillity_setup_charge_id IS NOT NULL'),
        'provisional' => (int)db_value('SELECT COUNT(*) FROM services WHERE abillity_provisional = 1 AND status <> \'ceased\''),
        'products' => (int)db_value('SELECT COUNT(*) FROM products WHERE abillity_charge_type_id IS NOT NULL'),
    ];
    page('abillity_settings', ['test' => $test, 'counts' => $counts, 'problems' => abillity_problems()], 'aBILLity');
}

/** "Send to aBILLity" from a customer, service or product page. */
function abillity_send_controller(): void
{
    require_permission('sales.edit');
    if (!is_post()) {
        redirect(url('dashboard'));
    }
    verify_csrf();
    $type = query('type');
    $id = query_int('id') ?? 0;
    try {
        switch ($type) {
            case 'account':
                abillity_push_customer($id);
                $a = db_one('SELECT abillity_error, xero_contact_id FROM accounts WHERE id = ?', [$id]);
                flash('Sent to aBILLity' . ($a['xero_contact_id'] ? ' and set up in Xero.' : '.') . ($a['abillity_error'] ? ' ' . $a['abillity_error'] : ''), $a['abillity_error'] ? 'error' : 'success');
                redirect(url('accounts', ['action' => 'view', 'id' => $id]));
            case 'service':
                abillity_push_service($id);
                flash('Sent to aBILLity.');
                redirect(url('services', ['action' => 'view', 'id' => $id]));
            case 'product':
                abillity_push_product($id);
                flash('Sent to aBILLity.');
                redirect(url('products', ['action' => 'view', 'id' => $id]));
        }
    } catch (IntegrationException $e) {
        flash($e->getMessage(), 'error');
        redirect(url($type === 'account' ? 'accounts' : ($type === 'service' ? 'services' : 'products'), ['action' => 'view', 'id' => $id]));
    }
    not_found();
}
