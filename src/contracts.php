<?php
declare(strict_types=1);

/*
 * Contracts: generated from uploaded Word templates (one per service type) and
 * a quote, then sent for e-signature with Signable.
 *
 * Signable API: https://api.signable.co.uk/v1, HTTP Basic auth (API key as the
 * username), form-encoded requests with JSON-encoded documents and parties.
 */

/** Template types: every service type plus agreement-level documents. */
function contract_template_types(): array
{
    return SERVICE_TYPES + [
        'general'      => 'General (any service without its own template)',
        'msa_schedule' => 'Service schedule under a dealer MSA',
        'msa'          => 'Master services agreement (MSA)',
    ];
}

/** Private storage folder (outside the web root), created on first use. */
function storage_path(string $sub = ''): string
{
    $base = config('storage_path') ?: APP_ROOT . '/storage';
    $dir = $base . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new IntegrationException("Couldn't create the folder $dir. Make sure the CRM folder is writable.");
    }
    if (!is_file($base . '/.htaccess')) {
        @file_put_contents($base . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

function template_file(array $template): string
{
    return storage_path('templates') . '/' . basename($template['stored_name']);
}

/**
 * Pick templates for a quote's lines. Returns [[template, lines], ...].
 * Customers covered by their dealer's MSA get a single service schedule.
 */
function contract_templates_for(array $account, array $lines): array
{
    $active = [];
    foreach (db_all('SELECT * FROM contract_templates WHERE active = 1 ORDER BY id DESC') as $t) {
        $active[$t['service_type']] ??= $t;
    }
    if ($account['msa_covered'] && $account['parent_id'] && isset($active['msa_schedule'])) {
        return [[$active['msa_schedule'], $lines]];
    }
    $groups = [];
    $missing = [];
    foreach ($lines as $line) {
        $template = $active[$line['service_type']] ?? $active['general'] ?? null;
        if (!$template) {
            $missing[SERVICE_TYPES[$line['service_type']] ?? $line['service_type']] = true;
            continue;
        }
        $groups[$template['id']] ??= [$template, []];
        $groups[$template['id']][1][] = $line;
    }
    if ($missing) {
        throw new IntegrationException('There\'s no contract template for: ' . implode(', ', array_keys($missing))
            . '. Upload one (or a "General" template) under Contract templates.');
    }
    return array_values($groups);
}

/** Merge field values for a contract document. */
function contract_fields(array $account, ?array $quote, array $contract, array $lines, string $signerName): array
{
    $totals = quote_totals($lines);
    $contact = gc_billing_contact((int)$account['id']);
    $dealer = $account['parent_id'] ? db_one('SELECT * FROM accounts WHERE id = ?', [$account['parent_id']]) : null;
    $msa = $dealer ? db_one("SELECT reference, signed_at FROM contracts WHERE account_id = ? AND kind = 'msa' AND status = 'signed' ORDER BY signed_at DESC LIMIT 1", [$dealer['id']]) : null;
    $address = implode("\n", array_filter([$account['address'], $account['city'], $account['postcode']]));
    return [
        'our_company_name'    => company('name', config('app_name')),
        'our_company_address' => company_address_line(),
        'our_company_number'  => company('number'),
        'customer_name'       => $account['name'],
        'account_number'      => $account['account_number'],
        'company_number'      => $account['company_number'] ?: 'n/a',
        'customer_address'    => $address,
        'customer_email'      => $account['email'] ?? '',
        'customer_phone'      => $account['phone'] ?? '',
        'contact_name'        => $contact['name'] ?? '',
        'contact_email'       => $contact['email'] ?? '',
        'signer_name'         => $signerName,
        'dealer_name'         => $dealer['name'] ?? '',
        'msa_reference'       => $msa['reference'] ?? '',
        'msa_date'            => $msa ? fmt_date($msa['signed_at']) : '',
        'quote_reference'     => $quote['reference'] ?? '',
        'quote_title'         => $quote['title'] ?? '',
        'contract_reference'  => $contract['reference'] ?? '',
        'date'                => date('j F Y'),
        'term_months'         => (string)$totals['term'],
        'term'                => term_label($totals['term']),
        'monthly_total'       => money($totals['monthly']),
        'setup_total'         => money($totals['setup']),
        'contract_value'      => money($totals['tcv']),
    ];
}

/** Rows for {{services_table}}. */
function contract_table_rows(array $lines): array
{
    if (!$lines) {
        return [];
    }
    $rows = [['Service', 'Qty', 'Monthly (each)', 'One-off (each)', 'Term']];
    foreach ($lines as $l) {
        $rows[] = [$l['description'] . ' (' . (SERVICE_TYPES[$l['service_type']] ?? $l['service_type']) . ')', (string)$l['quantity'],
            money($l['monthly_price']), money($l['setup_fee']), term_label($l['term_months'])];
    }
    $t = quote_totals($lines);
    $rows[] = ['Total', '', money($t['monthly']) . '/mo', money($t['setup']), ''];
    return $rows;
}

/** Create a contract record and its documents. $lines may be empty (e.g. an MSA). */
function contract_generate(array $account, array $templatesWithLines, string $title, string $kind, string $signerName, string $signerEmail, ?array $quote = null): array
{
    db_exec('INSERT INTO contracts (account_id, quote_id, kind, title, status, signer_name, signer_email, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$account['id'], $quote['id'] ?? null, $kind, $title, 'draft', $signerName, $signerEmail, current_user()['id'] ?? ($quote['created_by'] ?? null)]);
    $id = (int)db()->lastInsertId();
    $reference = sprintf('CON-%06d', $id);
    db_exec('UPDATE contracts SET reference = ? WHERE id = ?', [$reference, $id]);
    $contract = db_one('SELECT * FROM contracts WHERE id = ?', [$id]);

    try {
        $docs = [];
        $dir = storage_path('contracts');
        foreach ($templatesWithLines as $i => [$template, $lines]) {
            $file = sprintf('%s-%d-%s.docx', $reference, $i + 1, bin2hex(random_bytes(4)));
            $fields = contract_fields($account, $quote, $contract, $lines, $signerName);
            docx_merge(template_file($template), "$dir/$file", $fields, contract_table_rows($lines));
            $docs[] = ['title' => $template['name'], 'file' => $file];
        }
        db_exec('UPDATE contracts SET documents = ? WHERE id = ?', [json_encode($docs), $id]);
    } catch (Throwable $e) {
        db_exec("UPDATE contracts SET status = 'failed', last_error = ? WHERE id = ?", [$e->getMessage(), $id]);
        throw $e;
    }
    log_activity((int)$account['id'], 'note', "Contract $reference created" . ($quote ? " from quote {$quote['reference']}" : ''));
    return db_one('SELECT * FROM contracts WHERE id = ?', [$id]);
}

function contract_create_from_quote(array $quote): array
{
    if ($existing = db_one("SELECT * FROM contracts WHERE quote_id = ? AND status NOT IN ('cancelled','rejected','expired','failed') ORDER BY id DESC LIMIT 1", [$quote['id']])) {
        return $existing;
    }
    $account = db_one('SELECT * FROM accounts WHERE id = ?', [$quote['account_id']]);
    $lines = quote_lines((int)$quote['id']);
    $groups = contract_templates_for($account, $lines);
    $signerName = $quote['response_name'] ?: ($quote['recipient_name'] ?: $account['name']);
    // The person who accepted signs; fall back to whoever the quote was sent to.
    $signerEmail = ($quote['response_email'] ?? null) ?: ($quote['recipient_email'] ?: (gc_billing_contact((int)$account['id'])['email'] ?? $account['email']));
    if (!$signerEmail) {
        throw new IntegrationException('No email address to send the contract to. Add one to the quote recipient or the customer.');
    }
    return contract_generate($account, $groups, 'Contract for ' . $quote['title'], 'services', $signerName, $signerEmail, $quote);
}

function contract_documents(array $contract): array
{
    return json_decode((string)$contract['documents'], true) ?: [];
}

/* ------------------------------------------------------------- Signable --- */

function signable_configured(): bool
{
    return (bool)setting('signable_api_key');
}

function signable_url(): string
{
    return rtrim(config('signable_url') ?: 'https://api.signable.co.uk/v1', '/');
}

/** Call the Signable API. Returns the decoded JSON body. */
function signable_request(string $method, string $path, array $data = []): array
{
    $key = setting('signable_api_key');
    if (!$key) {
        throw new IntegrationException('Signable isn\'t set up. Add your API key under Signable.');
    }
    $url = signable_url() . '/' . ltrim($path, '/');
    $headers = ['Authorization: Basic ' . base64_encode($key . ':x'), 'Accept: application/json'];
    $body = null;
    if ($method === 'GET') {
        $url .= $data ? '?' . http_build_query($data) : '';
    } else {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $body = http_build_query($data);
    }
    try {
        [$status, $response] = http_request($method, $url, $headers, $body);
    } catch (IntegrationException $e) {
        throw new IntegrationException('Could not reach Signable: ' . $e->getMessage());
    }
    if ($status >= 200 && $status < 300 && is_array($response)) {
        return $response;
    }
    $message = is_array($response) ? ($response['message'] ?? json_encode($response)) : substr(strip_tags((string)$response), 0, 200);
    if ($status === 401) {
        $message = 'Signable rejected the API key. Check it under Signable.';
    }
    throw new IntegrationException("Signable error ($status): $message");
}

/** Send a draft contract to the signer via Signable. */
function contract_send(array $contract): array
{
    if (!in_array($contract['status'], ['draft', 'failed'], true) || !contract_documents($contract)) {
        throw new IntegrationException('Only draft contracts with documents can be sent.');
    }
    $documents = [];
    foreach (contract_documents($contract) as $doc) {
        $path = storage_path('contracts') . '/' . basename($doc['file']);
        $documents[] = [
            'document_title'        => $doc['title'],
            'document_file_name'    => $contract['reference'] . ' ' . preg_replace('/[^A-Za-z0-9 _-]/', '', $doc['title']) . '.docx',
            'document_file_content' => base64_encode((string)file_get_contents($path)),
        ];
    }
    $message = setting('signable_message') ?: 'Please review and sign your agreement with ' . company('name', config('app_name')) . '.';
    $data = [
        'envelope_title'     => $contract['reference'] . ' – ' . $contract['title'],
        'envelope_documents' => json_encode($documents),
        'envelope_parties'   => json_encode([[
            'party_name'    => $contract['signer_name'],
            'party_email'   => $contract['signer_email'],
            'party_role'    => 'signer1',
            'party_message' => $message,
        ]]),
        'envelope_meta'      => json_encode(['crm_contract_id' => (int)$contract['id'], 'crm_reference' => $contract['reference']]),
    ];
    if ($hours = (int)setting('signable_remind_hours')) {
        $data['envelope_auto_remind_hours'] = $hours;
    }
    if ($redirect = setting('signable_redirect_url')) {
        $data['envelope_redirect_url'] = $redirect;
    }
    try {
        $response = signable_request('POST', 'envelopes', $data);
    } catch (IntegrationException $e) {
        db_exec("UPDATE contracts SET status = 'failed', last_error = ? WHERE id = ?", [$e->getMessage(), $contract['id']]);
        throw $e;
    }
    $fingerprint = $response['envelope_fingerprint'] ?? null;
    if (!$fingerprint) {
        throw new IntegrationException('Signable accepted the request but returned no envelope reference.');
    }
    db_exec("UPDATE contracts SET status = 'sent', signable_fingerprint = ?, sent_at = NOW(), last_error = NULL WHERE id = ?", [$fingerprint, $contract['id']]);
    log_activity((int)$contract['account_id'], 'email', "Contract {$contract['reference']} sent for signature to {$contract['signer_name']} <{$contract['signer_email']}>");
    return db_one('SELECT * FROM contracts WHERE id = ?', [$contract['id']]);
}

/** Refresh a sent contract's status from Signable (and save the signed PDF). */
function contract_sync(array $contract): array
{
    if (!$contract['signable_fingerprint'] || $contract['status'] !== 'sent') {
        return $contract;
    }
    $env = signable_request('GET', 'envelopes/' . rawurlencode($contract['signable_fingerprint']));
    $status = strtolower((string)($env['envelope_status'] ?? ''));
    $map = ['signed' => 'signed', 'rejected' => 'rejected', 'cancelled' => 'cancelled', 'expired' => 'expired', 'failed' => 'failed'];
    if (!isset($map[$status])) {
        return $contract; // still sent / processing / draft
    }
    $new = $map[$status];
    $signedFile = null;
    if ($new === 'signed' && !empty($env['envelope_signed_pdf'])) {
        $signedFile = contract_download_signed($contract, (string)$env['envelope_signed_pdf']);
    }
    db_exec('UPDATE contracts SET status = ?, signed_at = IF(? = \'signed\', NOW(), signed_at), signed_file = COALESCE(?, signed_file) WHERE id = ?',
        [$new, $new, $signedFile, $contract['id']]);
    log_activity((int)$contract['account_id'], 'note', "Contract {$contract['reference']} " . ($new === 'signed' ? "signed by {$contract['signer_name']}" : $new));
    if ($new === 'signed' && $contract['quote_id'] && ($order = db_one('SELECT id FROM customer_orders WHERE quote_id = ?', [$contract['quote_id']]))) {
        order_add_event((int)$order['id'], null, null, "Contract {$contract['reference']} signed by {$contract['signer_name']}");
    }
    if ($new === 'signed' && $contract['quote_id']) {
        $quote = db_one('SELECT * FROM quotes WHERE id = ?', [$contract['quote_id']]);
        if ($quote) {
            quote_notify_staff($quote, "Contract {$contract['reference']} signed", "{$contract['signer_name']} signed contract {$contract['reference']} for quote {$quote['reference']}.");
        }
    }
    return db_one('SELECT * FROM contracts WHERE id = ?', [$contract['id']]);
}

function contract_download_signed(array $contract, string $url): ?string
{
    $signableHost = parse_url(signable_url(), PHP_URL_HOST);
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || (!str_starts_with($url, 'https://') && $host !== $signableHost)) {
        return null;
    }
    // Pre-signed storage links reject extra auth headers, so try without first.
    $attempts = [[]];
    if ($host === $signableHost || str_ends_with((string)$host, '.signable.co.uk') || str_ends_with((string)$host, '.signable.app')) {
        $attempts[] = ['Authorization: Basic ' . base64_encode(setting('signable_api_key') . ':x')];
    }
    foreach ($attempts as $headers) {
        try {
            [$status, $body] = http_request('GET', $url, $headers);
        } catch (IntegrationException) {
            continue;
        }
        if ($status === 200 && is_string($body) && str_starts_with($body, '%PDF')) {
            $file = $contract['reference'] . '-signed-' . bin2hex(random_bytes(4)) . '.pdf';
            file_put_contents(storage_path('contracts') . '/' . $file, $body);
            return $file;
        }
    }
    return null;
}

/** Sync every contract awaiting signature (cron / webhook fallback). */
function contracts_sync_open(): array
{
    $result = ['checked' => 0, 'changed' => 0];
    foreach (db_all("SELECT * FROM contracts WHERE status = 'sent' AND signable_fingerprint IS NOT NULL") as $c) {
        $result['checked']++;
        if (contract_sync($c)['status'] !== 'sent') {
            $result['changed']++;
        }
    }
    return $result;
}

function contract_cancel(array $contract): void
{
    if ($contract['status'] === 'sent' && $contract['signable_fingerprint']) {
        signable_request('PUT', 'envelopes/' . rawurlencode($contract['signable_fingerprint']) . '/cancel');
    }
    db_exec("UPDATE contracts SET status = 'cancelled' WHERE id = ?", [$contract['id']]);
    log_activity((int)$contract['account_id'], 'note', "Contract {$contract['reference']} cancelled");
}

/** Create pending services from a signed contract's quote lines. Returns the number created. */
function contract_create_services(array $contract): int
{
    $lines = $contract['quote_id'] ? quote_lines((int)$contract['quote_id']) : [];
    $created = 0;
    foreach ($lines as $l) {
        // One-off charges (installation, hardware bought outright) aren't ongoing services.
        if ((int)$l['term_months'] === 0 && (float)$l['monthly_price'] == 0.0) {
            continue;
        }
        for ($n = 1; $n <= min((int)$l['quantity'], 100); $n++) {
            insert_row('services', [
                'account_id' => $contract['account_id'], 'product_id' => $l['product_id'], 'service_type' => $l['service_type'],
                'identifier' => 'TBC – ' . $l['description'] . ((int)$l['quantity'] > 1 ? " #$n" : ''),
                'carrier' => null, 'status' => 'pending', 'monthly_price' => $l['monthly_price'], 'setup_fee' => $l['setup_fee'],
                'start_date' => null, 'term_months' => $l['term_months'], 'contract_end_date' => null, 'install_address' => null,
                'notes' => "From contract {$contract['reference']}",
            ]);
            $created++;
        }
    }
    log_activity((int)$contract['account_id'], 'note', "$created pending service(s) created from contract {$contract['reference']}");
    return $created;
}
