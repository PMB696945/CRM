<?php
declare(strict_types=1);

/*
 * Supplier invoices: upload a PDF or photo, read it (built in for PDFs with
 * text, or Claude for anything including scans), then match it to a purchase
 * order and flag anything that doesn't add up: no PO number, an unknown PO,
 * the wrong supplier, a different amount, a duplicate, or a PO already billed.
 */

const INVOICE_STATUSES = ['needs_review' => 'Needs checking', 'matched' => 'Matched', 'approved' => 'Approved to pay', 'disputed' => 'Disputed'];
const INVOICE_TYPES = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
const INVOICE_MAX_BYTES = 20 * 1024 * 1024;
const INVOICE_DEFAULT_MODEL = 'claude-opus-5-5';

function invoice_status_badge(?string $status): string
{
    $class = ['needs_review' => 'suspended', 'matched' => 'pending', 'approved' => 'active', 'disputed' => 'failed'][$status] ?? '';
    return '<span class="badge badge-' . $class . '">' . h(INVOICE_STATUSES[$status] ?? humanize((string)$status)) . '</span>';
}

function invoice_path(array $inv): string
{
    return storage_path('invoices') . '/' . basename($inv['stored_name']);
}

/** Amount tolerance when comparing an invoice with its purchase order (Settings). */
function invoice_tolerance(): float
{
    $t = setting('invoice_tolerance');
    return is_numeric($t) ? max(0.0, (float)$t) : 1.00;
}

function invoices_attention_count(): int
{
    return (int)db_value("SELECT COUNT(*) FROM supplier_invoices WHERE status = 'needs_review'");
}

/* ------------------------------------------------------------ Reading --- */

/** Turn "£1,234.50" / "1.234,50" style text into a number. */
function invoice_amount(?string $s): ?float
{
    if ($s === null) {
        return null;
    }
    $s = preg_replace('/[^\d.,\-]/', '', $s);
    if ($s === '' || !preg_match('/\d/', $s)) {
        return null;
    }
    if (preg_match('/,\d{2}$/', $s) && !str_contains($s, '.')) {
        $s = str_replace(',', '.', $s); // 1234,50
    }
    $s = str_replace(',', '', $s);
    return is_numeric($s) ? round((float)$s, 2) : null;
}

/** A date written on an invoice as YYYY-MM-DD (UK day-first), or null. */
function invoice_date(?string $s): ?string
{
    $s = trim((string)$s);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : null;
    }
    if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})$#', $s, $m)) {
        $y = strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3];
        return checkdate((int)$m[2], (int)$m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
    }
    $ts = strtotime(preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', $s));
    return $ts ? date('Y-m-d', $ts) : null;
}

/** Read an invoice's details from its text with patterns (for PDFs with real text). */
function invoice_read_text(string $text): array
{
    $money = '(?:£|GBP|\$|€)?\s*(-?[\d,]+\.\d{2})';
    $gap = '[^\d£$€\n]{0,40}\n?[^\d£$€\n]{0,20}'; // label, then the value on the same or the next line
    $find = function (string $label) use ($text, $gap, $money): array {
        preg_match_all('/' . $label . $gap . $money . '/i', $text, $m);
        return array_values(array_filter(array_map('invoice_amount', $m[1]), fn($v) => $v !== null));
    };
    $f = [];
    if (preg_match('/(?:invoice|inv)\s*(?:no\.?|number|num|#|ref(?:erence)?)\s*[:.#]?\s*\n?\s*([A-Z0-9][A-Z0-9\-\/_.]{1,40})/i', $text, $m)) {
        $f['invoice_number'] = $m[1];
    }
    $dateRe = '(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}|\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]{3,9}\.?\s+\d{4}|\d{4}-\d{2}-\d{2})';
    if (preg_match('/(?:invoice\s*date|tax\s*point|date\s*of\s*issue|issue\s*date)[^\d\n]{0,20}\n?\s*' . $dateRe . '/i', $text, $m)
        || preg_match('/\bdate\b[^\d\n]{0,10}\n?\s*' . $dateRe . '/i', $text, $m)) {
        $f['invoice_date'] = invoice_date($m[1]);
    }
    if (preg_match('/(?:due\s*date|payment\s*due|due\s*by|due)[^\d\n]{0,20}\n?\s*' . $dateRe . '/i', $text, $m)) {
        $f['due_date'] = invoice_date($m[1]);
    }
    // Our purchase order numbers look like PO-000123; also take whatever follows an order reference label.
    $pos = [];
    if (preg_match_all('/\bPO[\s\-#:]*0*(\d{1,6})\b/i', $text, $m)) {
        foreach ($m[1] as $n) {
            $pos[] = sprintf('PO-%06d', (int)$n);
        }
    }
    if (preg_match_all('/(?:purchase\s*order|order\s*(?:no|number|ref(?:erence)?)|your\s*(?:order\s*)?ref(?:erence)?|p\.?o\.?\s*(?:no|number|ref))\s*[:.#]?\s*\n?\s*([A-Z0-9][A-Z0-9\-\/]{2,30})/i', $text, $m)) {
        array_push($pos, ...$m[1]);
    }
    $f['purchase_order_numbers'] = array_values(array_unique($pos));
    $gross = $find('(?:total\s*(?:due|amount|payable|to\s*pay|inc(?:l|luding)?\.?\s*vat|gbp)?|amount\s*(?:due|payable)|balance\s*due|grand\s*total|invoice\s*total)');
    $net = $find('(?:sub\s*-?\s*total|net\s*(?:total|amount|value)?|total\s*(?:ex|excl|excluding)\.?\s*vat|goods)');
    $vat = $find('(?:vat|tax)\s*(?:@\s*[\d.]+\s*%|\(?[\d.]+\s*%\)?)?\s*(?:amount|total)?');
    $f['gross_total'] = $gross ? max($gross) : null;
    $f['net_total'] = $net ? max(array_filter($net, fn($n) => $f['gross_total'] === null || $n <= $f['gross_total']) ?: $net) : null;
    $f['vat_total'] = $vat ? max(array_filter($vat, fn($v) => $f['gross_total'] === null || $v < $f['gross_total']) ?: [0]) ?: null : null;
    if ($f['net_total'] === null && $f['gross_total'] !== null && $f['vat_total'] !== null) {
        $f['net_total'] = round($f['gross_total'] - $f['vat_total'], 2);
    }
    if (preg_match('/\bVAT\s*(?:reg(?:istration)?\.?\s*)?(?:no\.?|number)?\s*[:.]?\s*((?:GB)?\s?\d{3}\s?\d{4}\s?\d{2}(?:\s?\d{3})?)/i', $text, $m)) {
        $f['supplier_vat_number'] = preg_replace('/\s+/', '', strtoupper($m[1]));
    }
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m)) {
        $f['supplier_email'] = strtolower($m[0]);
    }
    $f['currency'] = preg_match('/€|\bEUR\b/', $text) ? 'EUR' : (preg_match('/\$|\bUSD\b/', $text) && !str_contains($text, '£') ? 'USD' : 'GBP');
    $f['lines'] = [];
    return $f;
}

/** The JSON shape Claude fills in. */
function invoice_schema(): array
{
    $str = ['type' => 'string'];
    $num = ['anyOf' => [['type' => 'number'], ['type' => 'null']]];
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['supplier_name', 'supplier_vat_number', 'supplier_email', 'invoice_number', 'invoice_date', 'due_date', 'purchase_order_numbers',
            'currency', 'net_total', 'vat_total', 'gross_total', 'lines'],
        'properties' => [
            'supplier_name' => $str, 'supplier_vat_number' => $str, 'supplier_email' => $str,
            'invoice_number' => $str,
            'invoice_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, or empty'],
            'due_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, or empty'],
            'purchase_order_numbers' => ['type' => 'array', 'items' => $str],
            'currency' => ['type' => 'string', 'description' => 'ISO code, e.g. GBP'],
            'net_total' => $num, 'vat_total' => $num, 'gross_total' => $num,
            'lines' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['description', 'quantity', 'unit_price', 'net_amount'],
                'properties' => ['description' => $str, 'quantity' => $num, 'unit_price' => $num, 'net_amount' => $num],
            ]],
        ],
    ];
}

/** Seconds to wait before retry $attempt (1, 2, …): exponential with jitter, or the server's retry-after (capped). */
function anthropic_retry_delay(int $attempt, ?string $retryAfter): float
{
    if ($retryAfter !== null && is_numeric($retryAfter)) {
        return min(20.0, max(0.0, (float)$retryAfter));
    }
    return min(8.0, 2 ** ($attempt - 1)) * (0.75 + mt_rand() / mt_getrandmax() * 0.5);
}

/**
 * POST to the Messages API, retrying when Anthropic is briefly unavailable:
 * rate limits (429), overloaded (529), other server errors (5xx), timeouts (408),
 * lock conflicts (409) and dropped connections. Up to 3 attempts in all, the same
 * as the official SDKs. Other errors (bad key, bad request) aren't retried.
 * Returns [status, decoded body].
 */
function anthropic_request(string $key, array $body, int $attempts = 3): array
{
    $url = (config('anthropic_url') ?? 'https://api.anthropic.com') . '/v1/messages';
    $payload = json_encode($body);
    for ($attempt = 1; ; $attempt++) {
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: server-side-fallback-2026-07-01',
        ];
        try {
            [$status, $res, $responseHeaders] = http_request('POST', $url, $headers, $payload, 180);
        } catch (IntegrationException $e) {
            // Couldn't connect, or the connection dropped.
            if ($attempt >= $attempts) {
                throw new IntegrationException("Couldn't reach Claude after $attempts tries: " . $e->getMessage());
            }
            anthropic_sleep(anthropic_retry_delay($attempt, null));
            continue;
        }
        $retryable = in_array($status, [408, 409, 429], true) || $status >= 500;
        if (!$retryable || $attempt >= $attempts) {
            return [$status, $res];
        }
        // The API says when to retry; it also says when not to.
        if (strtolower((string)($responseHeaders['x-should-retry'] ?? '')) === 'false') {
            return [$status, $res];
        }
        anthropic_sleep(anthropic_retry_delay($attempt, $responseHeaders['retry-after'] ?? null));
    }
}

function anthropic_sleep(float $seconds): void
{
    if ($seconds > 0 && !config('anthropic_no_sleep')) {
        usleep((int)($seconds * 1000000));
    }
}

/** Read an invoice file with Claude. Returns the fields, or throws IntegrationException. */
function invoice_read_claude(string $path, string $mime): array
{
    $key = (string)setting('anthropic_api_key');
    if ($key === '') {
        throw new IntegrationException('Add your Anthropic API key under Settings → Supplier invoices to read invoices with Claude.');
    }
    $data = base64_encode((string)file_get_contents($path));
    $source = ['type' => 'base64', 'media_type' => $mime, 'data' => $data];
    $file = $mime === 'application/pdf' ? ['type' => 'document', 'source' => $source] : ['type' => 'image', 'source' => $source];
    $prompt = "This is a supplier's invoice sent to " . company('name', config('app_name')) . ". Read it and fill in the fields.\n"
        . "- purchase_order_numbers: every purchase order or order reference printed on it. Our purchase order numbers look like PO-000123; include them exactly as printed.\n"
        . "- Totals are for the whole invoice: net_total excluding VAT, vat_total, and gross_total including VAT. Use null for anything not shown.\n"
        . "- Dates as YYYY-MM-DD (UK invoices write the day first). Use an empty string for anything not shown.\n"
        . "- lines: each charge on the invoice.";
    $body = [
        'model' => (string)(setting('invoice_model') ?: INVOICE_DEFAULT_MODEL),
        'max_tokens' => 16000,
        'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => invoice_schema()]],
        'fallbacks' => 'default',
        'messages' => [['role' => 'user', 'content' => [$file, ['type' => 'text', 'text' => $prompt]]]],
    ];
    [$status, $res] = anthropic_request($key, $body);
    if ($status !== 200 || !is_array($res)) {
        $msg = is_array($res) ? ($res['error']['message'] ?? json_encode($res)) : substr((string)$res, 0, 200);
        throw new IntegrationException("Claude couldn't read the invoice ($status): $msg");
    }
    if (($res['stop_reason'] ?? '') === 'refusal') {
        throw new IntegrationException('Claude declined to read this file. Enter the details by hand.');
    }
    if (($res['stop_reason'] ?? '') === 'max_tokens') {
        throw new IntegrationException('The invoice was too long to read in one go. Enter the details by hand.');
    }
    foreach ($res['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text' && is_array($fields = json_decode((string)$block['text'], true))) {
            return $fields;
        }
    }
    throw new IntegrationException('Claude\'s reply didn\'t contain the invoice details.');
}

/** Which reader to use: Claude when switched on, otherwise built in (PDFs with text only). */
function invoice_reader(): string
{
    return setting('invoice_reader') === 'claude' && setting('anthropic_api_key') ? 'claude' : 'builtin';
}

/** Read an invoice file and store what was found on the invoice row. */
function invoice_read(array $inv): array
{
    $path = invoice_path($inv);
    $text = $inv['mime'] === 'application/pdf' ? pdf_extract_text((string)file_get_contents($path)) : '';
    $reader = invoice_reader();
    $error = null;
    $fields = [];
    try {
        if ($reader === 'claude') {
            $fields = invoice_read_claude($path, (string)$inv['mime']);
        } elseif (trim($text) === '') {
            $error = $inv['mime'] === 'application/pdf'
                ? 'This PDF has no text in it (it is probably a scan). Enter the details, or switch on reading with Claude in Settings.'
                : 'Photos can only be read with Claude (Settings → Supplier invoices). Enter the details by hand for now.';
        } else {
            $fields = invoice_read_text($text);
        }
    } catch (IntegrationException $e) {
        $error = $e->getMessage();
        if (trim($text) !== '') {
            $fields = invoice_read_text($text); // fall back to the built-in reader
            $reader = 'builtin';
        }
    }
    $pos = array_values(array_filter(array_map('trim', (array)($fields['purchase_order_numbers'] ?? []))));
    db_exec('UPDATE supplier_invoices SET invoice_number = ?, invoice_date = ?, due_date = ?, po_reference = ?, supplier_name = ?, net = ?, vat = ?, total = ?,
        currency = ?, line_items = ?, reader = ?, read_error = ?, raw_text = ? WHERE id = ?', [
        mb_substr(trim((string)($fields['invoice_number'] ?? '')), 0, 80) ?: null,
        invoice_date($fields['invoice_date'] ?? null), invoice_date($fields['due_date'] ?? null),
        $pos ? mb_substr(implode(', ', $pos), 0, 255) : null,
        mb_substr(trim((string)($fields['supplier_name'] ?? '')), 0, 190) ?: null,
        isset($fields['net_total']) && is_numeric($fields['net_total']) ? round((float)$fields['net_total'], 2) : null,
        isset($fields['vat_total']) && is_numeric($fields['vat_total']) ? round((float)$fields['vat_total'], 2) : null,
        isset($fields['gross_total']) && is_numeric($fields['gross_total']) ? round((float)$fields['gross_total'], 2) : null,
        strtoupper(substr((string)($fields['currency'] ?? 'GBP'), 0, 3)) ?: 'GBP',
        !empty($fields['lines']) ? json_encode($fields['lines']) : null,
        $reader, $error ? mb_substr($error, 0, 500) : null, $text !== '' ? mb_substr($text, 0, 200000) : null, $inv['id'],
    ]);
    // Hints for matching that aren't stored as columns.
    return ['vat_number' => preg_replace('/\s+/', '', strtoupper((string)($fields['supplier_vat_number'] ?? ''))), 'email' => strtolower((string)($fields['supplier_email'] ?? ''))];
}

/* ----------------------------------------------------------- Matching --- */

/** Find the supplier an invoice is from. */
function invoice_find_supplier(array $inv, array $hints = []): ?int
{
    if ($inv['supplier_id']) {
        return (int)$inv['supplier_id'];
    }
    $vat = $hints['vat_number'] ?? '';
    if ($vat !== '') {
        foreach (db_all('SELECT id, vat_number FROM suppliers WHERE vat_number IS NOT NULL') as $s) {
            if (preg_replace('/[^0-9]/', '', $s['vat_number']) === preg_replace('/[^0-9]/', '', $vat)) {
                return (int)$s['id'];
            }
        }
    }
    $suppliers = db_all('SELECT id, name, email, accounts_email, website FROM suppliers');
    if ($inv['supplier_name']) {
        $key = company_match_key($inv['supplier_name']);
        foreach ($suppliers as $s) {
            if ($key !== '' && company_match_key($s['name']) === $key) {
                return (int)$s['id'];
            }
        }
    }
    $email = $hints['email'] ?? '';
    $domain = $email !== '' ? substr(strrchr($email, '@'), 1) : '';
    foreach ($suppliers as $s) {
        foreach ([$s['email'], $s['accounts_email']] as $e) {
            if ($domain !== '' && $e && str_ends_with(strtolower($e), '@' . $domain)) {
                return (int)$s['id'];
            }
        }
    }
    // A supplier's name appearing in the invoice text (longest name wins).
    $text = mb_strtolower((string)$inv['raw_text']);
    $best = null;
    foreach ($suppliers as $s) {
        $name = mb_strtolower(trim(preg_replace('/\b(ltd|limited|plc|llp)\b\.?/i', '', $s['name'])));
        if (mb_strlen($name) >= 4 && str_contains($text, $name) && (!$best || mb_strlen($name) > $best[1])) {
            $best = [(int)$s['id'], mb_strlen($name)];
        }
    }
    return $best[0] ?? null;
}

/**
 * Match an invoice to a supplier and purchase order, and list anything wrong.
 * Sets status: matched when it all agrees, otherwise needs_review (approved and
 * disputed invoices keep their status). Returns the problems found.
 */
function invoice_match(int $id, array $hints = []): array
{
    $inv = db_one('SELECT * FROM supplier_invoices WHERE id = ?', [$id]);
    $problems = [];
    $supplierId = invoice_find_supplier($inv, $hints);
    $supplier = $supplierId ? db_one('SELECT * FROM suppliers WHERE id = ?', [$supplierId]) : null;
    $tolerance = invoice_tolerance();

    // The purchase order: one already linked, else a PO number printed on the invoice.
    $po = $inv['po_id'] ? db_one('SELECT * FROM purchase_orders WHERE id = ?', [$inv['po_id']]) : null;
    $printed = array_values(array_filter(array_map('trim', explode(',', (string)$inv['po_reference']))));
    if (!$po) {
        foreach ($printed as $ref) {
            $norm = preg_match('/^PO[\s\-#:]*0*(\d{1,6})$/i', $ref, $m) ? sprintf('PO-%06d', (int)$m[1]) : $ref;
            if ($found = db_one('SELECT * FROM purchase_orders WHERE reference = ? OR supplier_ref = ?', [$norm, $ref])) {
                $po = $found;
                break;
            }
        }
    }
    if (!$supplier && $po) {
        $supplier = db_one('SELECT * FROM suppliers WHERE id = ?', [$po['supplier_id']]);
    }
    $byPo = !$supplier || $supplier['ordering'] === 'email';

    if (!$supplier) {
        $problems[] = 'We couldn\'t tell which supplier this is from' . ($inv['supplier_name'] ? " (it says \"{$inv['supplier_name']}\")" : '') . '.';
    }
    if (!$po) {
        if ($printed) {
            $problems[] = 'The purchase order number on the invoice (' . implode(', ', $printed) . ') doesn\'t match any of ours.';
        } elseif ($byPo) {
            $problems[] = 'There\'s no purchase order number on the invoice.';
        }
    } else {
        if ($supplier && (int)$po['supplier_id'] !== (int)$supplier['id']) {
            $problems[] = "{$po['reference']} was raised with " . db_value('SELECT name FROM suppliers WHERE id = ?', [$po['supplier_id']]) . ", but this invoice is from {$supplier['name']}.";
        }
        if ($po['status'] === 'cancelled') {
            $problems[] = "{$po['reference']} was cancelled.";
        } elseif ($po['status'] === 'draft') {
            $problems[] = "{$po['reference']} was never sent to the supplier.";
        }
        $poTotal = (float)$po['total'];
        $net = $inv['net'] !== null ? (float)$inv['net'] : ($inv['total'] !== null && $inv['vat'] !== null ? (float)$inv['total'] - (float)$inv['vat'] : null);
        if ($net === null && $inv['total'] === null) {
            $problems[] = 'We couldn\'t find the invoice total to compare with ' . $po['reference'] . '.';
        } elseif ($net !== null && abs($net - $poTotal) > $tolerance) {
            $problems[] = sprintf('The invoice is %s before VAT but %s is for %s (%s %s).', money($net), $po['reference'], money($poTotal), $net > $poTotal ? 'over by' : 'under by', money(abs($net - $poTotal)));
        } elseif ($net === null && abs((float)$inv['total'] - $poTotal) > $tolerance && abs((float)$inv['total'] - $poTotal * 1.2) > $tolerance) {
            $problems[] = sprintf('The invoice total is %s but %s is for %s before VAT (%s with VAT at 20%%).', money($inv['total']), $po['reference'], money($poTotal), money($poTotal * 1.2));
        }
        $billed = (float)db_value("SELECT COALESCE(SUM(COALESCE(net, total)), 0) FROM supplier_invoices WHERE po_id = ? AND id <> ? AND status <> 'disputed'", [$po['id'], $id]);
        if ($billed > 0 && $billed + (float)($net ?? $inv['total']) > $poTotal + $tolerance) {
            $problems[] = sprintf('%s has already been invoiced for %s; with this invoice that\'s more than the order.', $po['reference'], money($billed));
        }
    }
    if ($inv['invoice_number'] && $supplier && ($dup = db_value('SELECT id FROM supplier_invoices WHERE supplier_id = ? AND invoice_number = ? AND id <> ?', [$supplier['id'], $inv['invoice_number'], $id]))) {
        $problems[] = "This looks like a duplicate: invoice {$inv['invoice_number']} from {$supplier['name']} was already uploaded (#$dup).";
    } elseif ($inv['file_hash'] && ($dup = db_value('SELECT id FROM supplier_invoices WHERE file_hash = ? AND id <> ?', [$inv['file_hash'], $id]))) {
        $problems[] = "The same file was already uploaded (#$dup).";
    }
    if ($inv['currency'] && $inv['currency'] !== 'GBP') {
        $problems[] = "The invoice is in {$inv['currency']}.";
    }
    if ($inv['read_error']) {
        array_unshift($problems, $inv['read_error']);
    }

    $status = in_array($inv['status'], ['approved', 'disputed'], true) ? $inv['status'] : ($problems || !$po && $byPo ? 'needs_review' : 'matched');
    db_exec('UPDATE supplier_invoices SET supplier_id = ?, po_id = ?, problems = ?, status = ? WHERE id = ?',
        [$supplier['id'] ?? null, $po['id'] ?? null, $problems ? json_encode($problems) : null, $status, $id]);
    return $problems;
}

/** Tell the purchasing team about an invoice that doesn't match. */
function invoice_alert(int $id): void
{
    $inv = db_one('SELECT i.*, s.name AS supplier FROM supplier_invoices i LEFT JOIN suppliers s ON s.id = i.supplier_id WHERE i.id = ?', [$id]);
    $problems = json_decode((string)$inv['problems'], true) ?: [];
    if (!$problems || !mail_configured()) {
        return;
    }
    $to = setting('invoice_alert_email') ? [['email' => setting('invoice_alert_email'), 'name' => 'Accounts']] : users_with_permission('purchasing.edit');
    $what = trim(($inv['supplier'] ?: 'Supplier') . ' invoice ' . ($inv['invoice_number'] ?: '#' . $inv['id']));
    $body = '<p><b>' . h($what) . '</b>' . ($inv['total'] !== null ? ' for ' . h(money($inv['total'])) : '') . ' needs checking:</p><ul>'
        . implode('', array_map(fn($p) => '<li>' . h($p) . '</li>', $problems)) . '</ul>'
        . email_button(app_url() . '/' . url('supplier_invoices', ['action' => 'view', 'id' => $id]), 'Check the invoice');
    foreach ($to as $u) {
        try {
            send_mail($u['email'], $u['name'], "Invoice to check: $what", email_layout('An invoice doesn\'t match', $body));
        } catch (IntegrationException $e) {
            error_log('Invoice alert failed: ' . $e->getMessage());
        }
    }
}

/** Store an uploaded invoice file, read it and match it. Returns the new invoice id. */
function invoice_upload(array $file, ?int $poId = null, ?int $supplierId = null, bool $moveUploaded = true): int
{
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new IntegrationException('Choose the invoice to upload' . ((int)($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? ' (it is larger than the server allows)' : '') . '.');
    }
    $name = mb_substr(basename(str_replace('\\', '/', (string)$file['name'])), 0, 255);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(INVOICE_TYPES[$ext])) {
        throw new IntegrationException("\"$name\": invoices must be PDFs or photos (JPG, PNG, GIF or WebP).");
    }
    $size = (int)($file['size'] ?? filesize($file['tmp_name']));
    if ($size > INVOICE_MAX_BYTES) {
        throw new IntegrationException("\"$name\" is too big (" . file_size_label($size) . ').');
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = storage_path('invoices') . '/' . $stored;
    if (!($moveUploaded ? move_uploaded_file($file['tmp_name'], $dest) : copy($file['tmp_name'], $dest))) {
        throw new IntegrationException('Couldn\'t save the file. Check the CRM\'s storage folder is writable.');
    }
    $po = $poId ? db_one('SELECT * FROM purchase_orders WHERE id = ?', [$poId]) : null;
    db_exec('INSERT INTO supplier_invoices (supplier_id, po_id, file_name, stored_name, mime, size, file_hash, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        $supplierId ?: ($po['supplier_id'] ?? null), $po['id'] ?? null, $name, $stored, INVOICE_TYPES[$ext], $size, hash_file('sha256', $dest), current_user()['id'] ?? null,
    ]);
    $id = (int)db()->lastInsertId();
    $hints = invoice_read(db_one('SELECT * FROM supplier_invoices WHERE id = ?', [$id]));
    $problems = invoice_match($id, $hints);
    $inv = db_one('SELECT * FROM supplier_invoices WHERE id = ?', [$id]);
    audit('invoice_upload', "Supplier invoice " . ($inv['invoice_number'] ?: $name) . ' uploaded' . ($problems ? ' – needs checking' : ' – matched'), 'supplier_invoices', $id);
    if ($problems) {
        invoice_alert($id);
    }
    return $id;
}

/* --------------------------------------------------------------- Pages --- */

function supplier_invoices_controller(): void
{
    if (!can('suppliers.view') && !can('purchasing.edit')) {
        forbidden();
    }
    $action = query('action', 'list');
    $id = query_int('id');
    $inv = $id ? (db_one('SELECT * FROM supplier_invoices WHERE id = ?', [$id]) ?? not_found('Invoice not found.')) : null;

    if ($action === 'file' && $inv) {
        $path = invoice_path($inv);
        if (!is_file($path)) {
            not_found('The file is missing from the server.');
        }
        header('Content-Type: ' . $inv['mime']);
        header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $inv['file_name']) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
        readfile($path);
        exit;
    }

    if (is_post()) {
        verify_csrf();
        require_permission('purchasing.edit');
        $back = $inv ? url('supplier_invoices', ['action' => 'view', 'id' => $inv['id']]) : safe_return($_POST['_return'] ?? null, url('supplier_invoices'));
        try {
            switch ($action) {
                case 'upload':
                    $files = uploaded_files('files');
                    if (!$files) {
                        throw new IntegrationException('Choose the invoice to upload.');
                    }
                    $poId = (int)($_POST['po_id'] ?? 0) ?: null;
                    $ids = [];
                    $errors = [];
                    foreach ($files as $f) {
                        try {
                            $ids[] = invoice_upload($f, $poId, (int)($_POST['supplier_id'] ?? 0) ?: null);
                        } catch (IntegrationException $e) {
                            $errors[] = $e->getMessage();
                        }
                    }
                    $check = $ids ? (int)db_value('SELECT COUNT(*) FROM supplier_invoices WHERE id IN (' . implode(',', $ids) . ") AND status = 'needs_review'") : 0;
                    flash(($ids ? count($ids) . ' invoice' . (count($ids) === 1 ? '' : 's') . ' uploaded' . ($check ? ", $check need checking" : ', all matched') . '. ' : '') . implode(' ', $errors),
                        $errors || $check ? 'error' : 'success');
                    if (count($ids) === 1) {
                        $back = url('supplier_invoices', ['action' => 'view', 'id' => $ids[0]]);
                    }
                    break;
                case 'save':
                    $poRef = trim((string)($_POST['po_reference'] ?? ''));
                    $poId = (int)($_POST['po_id'] ?? 0);
                    $num = fn($k) => ($v = trim((string)($_POST[$k] ?? ''))) === '' ? null : invoice_amount($v);
                    db_exec('UPDATE supplier_invoices SET supplier_id = ?, po_id = ?, invoice_number = ?, invoice_date = ?, due_date = ?, po_reference = ?, net = ?, vat = ?, total = ?, notes = ?, read_error = NULL WHERE id = ?', [
                        ((int)($_POST['supplier_id'] ?? 0)) ?: null, $poId ?: null, mb_substr(trim((string)($_POST['invoice_number'] ?? '')), 0, 80) ?: null,
                        invoice_date($_POST['invoice_date'] ?? null), invoice_date($_POST['due_date'] ?? null), $poRef !== '' ? mb_substr($poRef, 0, 255) : null,
                        $num('net'), $num('vat'), $num('total'), trim((string)($_POST['notes'] ?? '')) ?: null, $inv['id'],
                    ]);
                    if (in_array($inv['status'], ['approved', 'disputed'], true)) {
                        db_exec("UPDATE supplier_invoices SET status = 'needs_review' WHERE id = ?", [$inv['id']]);
                    }
                    $problems = invoice_match((int)$inv['id']);
                    audit('update', 'Supplier invoice ' . ($inv['invoice_number'] ?: '#' . $inv['id']) . ' details corrected', 'supplier_invoices', (int)$inv['id']);
                    flash($problems ? 'Saved. It still needs checking.' : 'Saved, and it now matches its purchase order.', $problems ? 'error' : 'success');
                    break;
                case 'reread':
                    db_exec('UPDATE supplier_invoices SET supplier_id = NULL, po_id = NULL, read_error = NULL WHERE id = ?', [$inv['id']]);
                    $hints = invoice_read(db_one('SELECT * FROM supplier_invoices WHERE id = ?', [$inv['id']]));
                    $problems = invoice_match((int)$inv['id'], $hints);
                    flash($problems ? 'Read again. It needs checking.' : 'Read again, and it matches.', $problems ? 'error' : 'success');
                    break;
                case 'approve':
                    db_exec("UPDATE supplier_invoices SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?", [current_user()['id'], $inv['id']]);
                    audit('invoice_approve', 'Supplier invoice ' . ($inv['invoice_number'] ?: '#' . $inv['id']) . ' approved to pay'
                        . ($inv['problems'] ? ' despite: ' . implode(' ', json_decode($inv['problems'], true) ?: []) : ''), 'supplier_invoices', (int)$inv['id']);
                    if (xero_bills_enabled()) {
                        try {
                            xero_post_bill((int)$inv['id']);
                            flash('Approved to pay and sent to Xero as a bill' . (setting('xero_bill_attach', '1') === '1' ? ', with the invoice attached.' : '.'));
                        } catch (IntegrationException $e) {
                            flash('Approved to pay, but it couldn\'t be sent to Xero: ' . $e->getMessage(), 'error');
                        }
                    } else {
                        flash('Approved to pay.');
                    }
                    break;
                case 'xero':
                    if ($inv['status'] !== 'approved') {
                        throw new IntegrationException('Approve the invoice before sending it to Xero.');
                    }
                    xero_post_bill((int)$inv['id']);
                    flash(($inv['xero_invoice_id'] ? 'Bill updated in Xero.' : 'Sent to Xero as a bill.'));
                    break;
                case 'dispute':
                    $note = trim((string)($_POST['notes'] ?? ''));
                    db_exec("UPDATE supplier_invoices SET status = 'disputed', notes = COALESCE(NULLIF(?, ''), notes) WHERE id = ?", [$note, $inv['id']]);
                    audit('invoice_dispute', 'Supplier invoice ' . ($inv['invoice_number'] ?: '#' . $inv['id']) . ' disputed' . ($note ? ": $note" : ''), 'supplier_invoices', (int)$inv['id']);
                    flash('Marked as disputed.');
                    break;
                case 'reopen':
                    db_exec("UPDATE supplier_invoices SET status = 'needs_review', approved_by = NULL, approved_at = NULL WHERE id = ?", [$inv['id']]);
                    invoice_match((int)$inv['id']);
                    flash('Back to checking.');
                    break;
                case 'delete':
                    db_exec('DELETE FROM supplier_invoices WHERE id = ?', [$inv['id']]);
                    @unlink(invoice_path($inv));
                    audit('delete', 'Supplier invoice ' . ($inv['invoice_number'] ?: $inv['file_name']) . ' deleted', 'supplier_invoices', (int)$inv['id']);
                    flash('Invoice deleted.');
                    $back = url('supplier_invoices');
                    break;
                default:
                    not_found();
            }
        } catch (IntegrationException $e) {
            flash($e->getMessage(), 'error');
        }
        redirect($back);
    }

    if ($action === 'view' && $inv) {
        $supplier = $inv['supplier_id'] ? db_one('SELECT * FROM suppliers WHERE id = ?', [$inv['supplier_id']]) : null;
        $po = $inv['po_id'] ? db_one('SELECT * FROM purchase_orders WHERE id = ?', [$inv['po_id']]) : null;
        $poLines = $po ? po_lines((int)$po['id']) : [];
        $suppliers = db_all('SELECT id, name FROM suppliers ORDER BY name');
        $openPos = db_all("SELECT p.id, p.reference, p.total, s.name AS supplier FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id
            WHERE p.status IN ('sent','received') OR p.id = ? ORDER BY p.id DESC LIMIT 300", [$inv['po_id'] ?? 0]);
        $approver = $inv['approved_by'] ? db_value('SELECT name FROM users WHERE id = ?', [$inv['approved_by']]) : null;
        page('supplier_invoice', compact('inv', 'supplier', 'po', 'poLines', 'suppliers', 'openPos', 'approver'), 'Invoice ' . ($inv['invoice_number'] ?: '#' . $inv['id']));
        return;
    }

    $status = query('status', array_key_exists('status', $_GET) ? '' : 'needs_review');
    $where = $status !== '' && isset(INVOICE_STATUSES[$status]) ? 'WHERE i.status = ?' : '';
    $rows = db_all("SELECT i.*, s.name AS supplier, p.reference AS po FROM supplier_invoices i LEFT JOIN suppliers s ON s.id = i.supplier_id
        LEFT JOIN purchase_orders p ON p.id = i.po_id $where ORDER BY i.id DESC LIMIT 500", $where ? [$status] : []);
    $counts = [];
    foreach (db_all('SELECT status, COUNT(*) AS n FROM supplier_invoices GROUP BY status') as $c) {
        $counts[$c['status']] = (int)$c['n'];
    }
    page('supplier_invoices', compact('rows', 'status', 'counts'), 'Supplier invoices');
}
