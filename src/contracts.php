<?php
declare(strict_types=1);

/*
 * Contracts: generated from uploaded Word templates (one per service type) and
 * a quote, then signed online with the built-in e-signature (esign.php).
 */

/** Template types: every service type plus agreement-level documents. */
function contract_template_types(): array
{
    return SERVICE_TYPES + [
        'general'      => 'General (any service without its own template)',
        'contract_summary' => 'Contract Summary (sent and confirmed before the agreement)',
        'msa_schedule' => 'Service schedule under a dealer MSA',
        'msa'          => 'Master services agreement (MSA)',
    ];
}

/** Customer sizes. Ofcom's pre-contract rules (Contract Summary and Contract Information) protect all but larger businesses. */
const CUSTOMER_SIZES = [
    'consumer'       => 'Consumer (an individual)',
    'micro'          => 'Microenterprise (under 10 staff)',
    'small'          => 'Small business (10 to 49 staff)',
    'not_for_profit' => 'Not-for-profit',
    'larger'         => 'Larger business (50 or more staff)',
];

/** Is this customer covered by Ofcom's pre-contract rules? When unsure (size not set), assume so. */
function customer_is_protected(array $account): bool
{
    return ($account['type'] ?? '') === 'residential' || ($account['customer_size'] ?? null) !== 'larger';
}

/** Does a contract for this customer need a Contract Summary first? (Service contracts; dealer MSAs don't.) */
function contract_summary_required(array $account, string $kind): bool
{
    if ($kind !== 'services') {
        return false;
    }
    return setting('contract_summary_for', 'all') === 'all' || customer_is_protected($account);
}

function contract_summary_template(): ?array
{
    return db_one("SELECT * FROM contract_templates WHERE service_type = 'contract_summary' AND active = 1 ORDER BY id DESC LIMIT 1");
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
    // The Contract Summary comes first, covering everything in the agreement.
    $summaryTemplate = null;
    if (contract_summary_required($account, $kind)) {
        $summaryTemplate = contract_summary_template() ?? throw new IntegrationException('A Contract Summary has to be sent before this agreement, but there\'s no Contract Summary template.'
            . ' Upload one under Contract templates (choose "Contract Summary").');
    }
    db_exec('INSERT INTO contracts (account_id, quote_id, kind, title, status, signer_name, signer_email, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$account['id'], $quote['id'] ?? null, $kind, $title, 'draft', $signerName, $signerEmail, current_user()['id'] ?? ($quote['created_by'] ?? null)]);
    $id = (int)db()->lastInsertId();
    $reference = sprintf('CON-%06d', $id);
    db_exec('UPDATE contracts SET reference = ? WHERE id = ?', [$reference, $id]);
    $contract = db_one('SELECT * FROM contracts WHERE id = ?', [$id]);

    try {
        $docs = [];
        $dir = storage_path('contracts');
        if ($summaryTemplate) {
            $allLines = array_merge(...array_map(fn($g) => $g[1], $templatesWithLines ?: [[null, []]]));
            $file = sprintf('%s-0-summary-%s.docx', $reference, bin2hex(random_bytes(4)));
            docx_merge(template_file($summaryTemplate), "$dir/$file", contract_fields($account, $quote, $contract, $allLines, $signerName), contract_table_rows($allLines));
            $docs[] = ['title' => 'Contract Summary', 'file' => $file, 'kind' => 'summary'];
        }
        foreach ($templatesWithLines as $i => [$template, $lines]) {
            $file = sprintf('%s-%d-%s.docx', $reference, $i + 1, bin2hex(random_bytes(4)));
            $fields = contract_fields($account, $quote, $contract, $lines, $signerName);
            docx_merge(template_file($template), "$dir/$file", $fields, contract_table_rows($lines));
            $docs[] = ['title' => $template['name'], 'file' => $file];
        }
        // Numbers moving from another provider: the letter of authority is signed with the agreement.
        if ($quote && order_needs_loa($details = quote_order_details($quote))) {
            $file = sprintf('%s-%d-loa-%s.docx', $reference, count($docs) + 1, bin2hex(random_bytes(4)));
            docx_create("$dir/$file", order_loa_paragraphs($account, $details, $reference, $signerName));
            $docs[] = ['title' => 'Letter of Authority', 'file' => $file, 'kind' => 'loa'];
        }
        db_exec('UPDATE contracts SET documents = ? WHERE id = ?', [json_encode($docs), $id]);
    } catch (Throwable $e) {
        db_exec("UPDATE contracts SET status = 'failed', last_error = ? WHERE id = ?", [$e->getMessage(), $id]);
        throw $e;
    }
    log_activity((int)$account['id'], 'note', "Contract $reference created" . ($quote ? " from quote {$quote['reference']}" : ''));
    contract_event($id, 'created', implode(', ', array_column($docs, 'title')));
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

/** The contract's Contract Summary document, if it has one. */
function contract_summary_document(array $contract): ?array
{
    foreach (contract_documents($contract) as $i => $d) {
        if (($d['kind'] ?? '') === 'summary') {
            return $d + ['index' => $i];
        }
    }
    return null;
}

/** A timestamped step in signing (sent, opened, summary confirmed, signed...), for the signing record and certificate. */
function contract_event(int $contractId, string $event, ?string $detail = null, ?string $ip = null, ?string $userAgent = null): void
{
    db_exec('INSERT INTO contract_events (contract_id, event, detail, ip, user_agent) VALUES (?, ?, ?, ?, ?)',
        [$contractId, $event, $detail, $ip !== null ? mb_substr($ip, 0, 45) : null, $userAgent !== null ? mb_substr($userAgent, 0, 255) : null]);
}

function contract_events(int $contractId): array
{
    return db_all('SELECT * FROM contract_events WHERE contract_id = ? ORDER BY id', [$contractId]);
}

/** The customer order a contract belongs to (through its quote), if any. */
function contract_order(array $contract): ?array
{
    return $contract['quote_id'] ? db_one('SELECT * FROM customer_orders WHERE quote_id = ?', [$contract['quote_id']]) : null;
}

/** The contract for an order: its quote's latest one that isn't cancelled. */
function order_contract(array $order): ?array
{
    return $order['quote_id'] ? db_one("SELECT * FROM contracts WHERE quote_id = ? AND status NOT IN ('cancelled') ORDER BY id DESC LIMIT 1", [$order['quote_id']]) : null;
}

/**
 * Record a contract as signed (online, or by hand for one signed another way), and move its order on:
 * the customer's tracking page shows it, the services are added as pending, and the order's owner
 * (or, if nobody has it yet, the onboarding team) and the sales team are told.
 */
function contract_mark_signed(array $contract, ?string $signedFile, string $how): array
{
    db_exec("UPDATE contracts SET status = 'signed', signed_at = NOW(), signed_file = COALESCE(?, signed_file), last_error = NULL WHERE id = ?", [$signedFile, $contract['id']]);
    if (!str_starts_with($how, 'online')) {
        contract_event((int)$contract['id'], 'marked_signed', $how);
    }
    log_activity((int)$contract['account_id'], 'note', "Contract {$contract['reference']} signed by {$contract['signer_name']} ($how)");
    try {
        contract_create_services(db_one('SELECT * FROM contracts WHERE id = ?', [$contract['id']]));
    } catch (Throwable $e) {
        error_log('Pending services after signing failed: ' . $e->getMessage());
        log_activity((int)$contract['account_id'], 'task', "Pending services for contract {$contract['reference']} weren't created", $e->getMessage());
    }
    if ($order = contract_order($contract)) {
        order_add_event((int)$order['id'], 'contract_signed', 'Thank you, your agreement has been signed. We\'re now getting your order under way.',
            "Contract {$contract['reference']} signed by {$contract['signer_name']} ($how)");
        if ($order['assigned_to'] && ($owner = db_one('SELECT name, email FROM users WHERE id = ? AND active = 1', [$order['assigned_to']]))) {
            try {
                send_mail($owner['email'], $owner['name'], "Contract signed: order {$order['reference']}",
                    email_layout('Contract signed', '<p>' . h($contract['signer_name']) . ' has signed contract ' . h($contract['reference']) . ' for order <b>' . h($order['reference']) . '</b> (' . h($order['title']) . ').</p>'
                        . '<p><a href="' . h(app_url() . '/index.php?page=customer_orders&action=view&id=' . $order['id']) . '">Open the order</a></p>'));
            } catch (Throwable $e) {
                error_log('Contract signed email failed: ' . $e->getMessage());
            }
        } elseif (order_is_open($order)) {
            order_notify_team(db_one('SELECT * FROM customer_orders WHERE id = ?', [$order['id']]));
        }
    }
    try {
        dealer_order_agreement_signed($contract);
    } catch (Throwable $e) {
        error_log('Dealer order after signing failed: ' . $e->getMessage());
    }
    if ($contract['quote_id'] && ($quote = db_one('SELECT * FROM quotes WHERE id = ?', [$contract['quote_id']]))) {
        quote_notify_staff($quote, "Contract {$contract['reference']} signed", "{$contract['signer_name']} signed contract {$contract['reference']} for quote {$quote['reference']}.");
    }
    return db_one('SELECT * FROM contracts WHERE id = ?', [$contract['id']]);
}

function contract_cancel(array $contract): void
{
    // The signing link stops working once the contract is cancelled.
    db_exec("UPDATE contracts SET status = 'cancelled' WHERE id = ?", [$contract['id']]);
    contract_event((int)$contract['id'], 'cancelled', current_user()['name'] ?? null);
    log_activity((int)$contract['account_id'], 'note', "Contract {$contract['reference']} cancelled");
    $order = contract_order($contract);
    if (!$order || !order_is_open($order)) {
        return;
    }
    $held = order_unsigned_contract($order);
    order_add_event((int)$order['id'], null, null, "Contract {$contract['reference']} cancelled" . ($held ? ". The order is waiting for {$held['reference']} instead." : '. The order is no longer waiting for an agreement.'));
    if ($held) {
        return; // a replacement agreement holds the order; the team hears when that's signed
    }
    // Whoever has the order (or, if nobody, the onboarding team) needs to know it can go ahead.
    $why = "Agreement {$contract['reference']} was cancelled, so the order is no longer waiting for it to be signed";
    if ($order['assigned_to'] && ($owner = db_one('SELECT name, email FROM users WHERE id = ? AND active = 1', [$order['assigned_to']]))) {
        try {
            send_mail($owner['email'], $owner['name'], "Agreement cancelled: order {$order['reference']}",
                email_layout('Agreement cancelled', '<p>' . h($why) . ': order <b>' . h($order['reference']) . '</b> (' . h($order['title']) . ') can go ahead.</p>'
                    . '<p><a href="' . h(app_url() . '/index.php?page=customer_orders&action=view&id=' . $order['id']) . '">Open the order</a></p>'));
        } catch (Throwable $e) {
            error_log('Contract cancelled email failed: ' . $e->getMessage());
        }
    } else {
        order_notify_team($order, $why);
    }
}

/** The services made from a contract (as pending, when it was signed). */
function contract_services(array $contract): array
{
    return db_all('SELECT * FROM services WHERE account_id = ? AND notes = ? ORDER BY id', [$contract['account_id'], "From contract {$contract['reference']}"]);
}

/** Create pending services from a signed contract's quote lines (once). Returns the number created. */
function contract_create_services(array $contract): int
{
    if (contract_services($contract)) {
        return 0;
    }
    $lines = $contract['quote_id'] ? quote_lines((int)$contract['quote_id']) : [];
    $created = 0;
    foreach ($lines as $l) {
        // One-off charges (installation, hardware bought outright) aren't ongoing services.
        if ((int)$l['term_months'] === 0 && (float)$l['monthly_price'] == 0.0) {
            continue;
        }
        for ($n = 1; $n <= min((int)$l['quantity'], 100); $n++) {
            $ids[] = insert_row('services', [
                'account_id' => $contract['account_id'], 'product_id' => $l['product_id'], 'service_type' => $l['service_type'],
                'identifier' => 'TBC – ' . $l['description'] . ((int)$l['quantity'] > 1 ? " #$n" : ''),
                'carrier' => null, 'status' => 'pending', 'monthly_price' => $l['monthly_price'], 'cost_price' => $l['cost_price'] ?? null, 'setup_fee' => $l['setup_fee'],
                'start_date' => null, 'term_months' => $l['term_months'], 'contract_end_date' => null, 'install_address' => null,
                'notes' => "From contract {$contract['reference']}",
            ]);
            $created++;
        }
    }
    if ($created) {
        log_activity((int)$contract['account_id'], 'note', "$created pending service(s) created from contract {$contract['reference']}");
    }
    foreach ($ids ?? [] as $serviceId) {
        service_changed($serviceId, null);
    }
    return $created;
}
