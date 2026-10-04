<?php
declare(strict_types=1);

/* Quotes: lines, totals, sending, and the customer's accept/decline response. */

const QUOTE_OPEN = ['draft', 'sent'];

function quote_lines(int $quoteId): array
{
    return db_all('SELECT * FROM quote_lines WHERE quote_id = ? ORDER BY sort, id', [$quoteId]);
}

/** Totals for a set of lines: monthly, setup, total contract value, longest term. */
function quote_totals(array $lines): array
{
    $t = ['monthly' => 0.0, 'setup' => 0.0, 'tcv' => 0.0, 'term' => 0];
    foreach ($lines as $l) {
        $qty = (int)$l['quantity'];
        $t['monthly'] += $qty * (float)$l['monthly_price'];
        $t['setup'] += $qty * (float)$l['setup_fee'];
        $t['tcv'] += $qty * ((float)$l['monthly_price'] * (int)$l['term_months'] + (float)$l['setup_fee']);
        $t['term'] = max($t['term'], (int)$l['term_months']);
    }
    return array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $t);
}

/**
 * Validate posted line rows (parallel arrays from the editor).
 * Returns [lines, errors].
 */
function quote_parse_lines(array $post): array
{
    $lines = [];
    $errors = [];
    $n = count((array)($post['line_description'] ?? []));
    for ($i = 0; $i < $n; $i++) {
        $get = fn($k) => trim((string)(((array)($post[$k] ?? []))[$i] ?? ''));
        $desc = $get('line_description');
        $product = $get('line_product_id');
        if ($desc === '' && $product === '') {
            continue; // blank row
        }
        $row = $i + 1;
        $type = $get('line_service_type');
        $line = [
            'product_id'    => ctype_digit($product) && db_value('SELECT 1 FROM products WHERE id = ?', [$product]) ? (int)$product : null,
            'service_type'  => array_key_exists($type, SERVICE_TYPES) ? $type : 'other',
            'description'   => mb_substr($desc, 0, 255),
            'quantity'      => (int)$get('line_quantity'),
            'monthly_price' => round((float)str_replace([',', '£'], '', $get('line_monthly_price')), 2),
            'setup_fee'     => round((float)str_replace([',', '£'], '', $get('line_setup_fee')), 2),
            'term_months'   => (int)$get('line_term_months'),
        ];
        if ($line['description'] === '') {
            $errors[] = "Line $row needs a description.";
        }
        if ($line['quantity'] < 1 || $line['quantity'] > 10000) {
            $errors[] = "Line $row: quantity must be between 1 and 10,000.";
        }
        if ($line['monthly_price'] < 0 || $line['setup_fee'] < 0) {
            $errors[] = "Line $row: prices can't be negative.";
        }
        if ($line['term_months'] < 0 || $line['term_months'] > 120) {
            $errors[] = "Line $row: term must be 0–120 months.";
        }
        $lines[] = $line;
    }
    if (!$lines) {
        $errors[] = 'Add at least one service to the quote.';
    }
    return [$lines, $errors];
}

function quote_save_lines(int $quoteId, array $lines): void
{
    db_exec('DELETE FROM quote_lines WHERE quote_id = ?', [$quoteId]);
    foreach (array_values($lines) as $i => $l) {
        db_exec('INSERT INTO quote_lines (quote_id, product_id, service_type, description, quantity, monthly_price, setup_fee, term_months, sort)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$quoteId, $l['product_id'], $l['service_type'], $l['description'], $l['quantity'], $l['monthly_price'], $l['setup_fee'], $l['term_months'], $i]);
    }
}

function quote_public_url(array $quote): string
{
    return app_url() . '/quote.php?t=' . rawurlencode((string)$quote['token_hash']);
}

/** The quote for a public link token, or null. */
function quote_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    return db_one('SELECT * FROM quotes WHERE token_hash = ?', [$token]);
}

/** Mark sent quotes past their validity date as expired. */
function quote_expire_if_due(array $quote): array
{
    if ($quote['status'] === 'sent' && $quote['valid_until'] && $quote['valid_until'] < date('Y-m-d')) {
        db_exec("UPDATE quotes SET status = 'expired' WHERE id = ? AND status = 'sent'", [$quote['id']]);
        $quote['status'] = 'expired';
    }
    return $quote;
}

function log_activity(int $accountId, string $type, string $subject, ?string $body = null): void
{
    db_exec('INSERT INTO activities (account_id, user_id, type, subject, body, done) VALUES (?, ?, ?, ?, ?, 1)',
        [$accountId, current_user()['id'] ?? null, $type, mb_substr($subject, 0, 200), $body]);
}

/** Email the quote to the customer with a link to accept or decline. */
function quote_send(array $quote, string $email, string $name, array $documents = []): void
{
    $lines = quote_lines((int)$quote['id']);
    if (!$lines) {
        throw new IntegrationException('Add at least one service before sending the quote.');
    }
    $token = $quote['token_hash'] ?: bin2hex(random_bytes(24));
    $validUntil = $quote['valid_until'] ?: date('Y-m-d', strtotime('+' . (int)(setting('quote_validity_days') ?: 30) . ' days'));
    $quote = ['token_hash' => $token, 'valid_until' => $validUntil] + $quote;

    $totals = quote_totals($lines);
    $first = trim(explode(' ', preg_replace('/^(mr|mrs|ms|miss|dr)\.?\s+/i', '', $name))[0] ?? '');
    $body = '<p>Hi ' . h($first ?: 'there') . ',</p>'
        . '<p>Thank you for your interest. Your quote <b>' . h($quote['reference']) . '</b> – ' . h($quote['title']) . ' is ready.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:8px 0;font-size:14px">'
        . '<tr><td style="padding:6px 0;color:#667085">Monthly charges</td><td style="padding:6px 0;text-align:right;font-weight:bold">' . h(money($totals['monthly'])) . ' + VAT</td></tr>'
        . '<tr><td style="padding:6px 0;color:#667085">One-off charges</td><td style="padding:6px 0;text-align:right;font-weight:bold">' . h(money($totals['setup'])) . ' + VAT</td></tr>'
        . '<tr><td style="padding:6px 0;color:#667085">Valid until</td><td style="padding:6px 0;text-align:right">' . h(fmt_date($validUntil)) . '</td></tr></table>'
        . email_button(quote_public_url($quote), 'View quote and respond')
        . ($documents ? '<p>We\'ve also attached:</p><ul>' . implode('', array_map(fn($d) => '<li>' . h($d['title']) . '</li>', $documents)) . '</ul>' : '')
        . '<p style="color:#667085;font-size:13px">You can accept or decline the quote on that page. If you have any questions, just reply to this email.</p>';

    send_mail($email, $name, 'Your quote ' . $quote['reference'] . ' from ' . company('name', config('app_name')), email_layout('Your quote is ready', $body), null, [],
        document_attachments($documents));

    db_exec("UPDATE quotes SET status = 'sent', token_hash = ?, valid_until = ?, recipient_name = ?, recipient_email = ?, sent_at = NOW() WHERE id = ?",
        [$token, $validUntil, $name, $email, $quote['id']]);
    log_activity((int)$quote['account_id'], 'email', "Quote {$quote['reference']} emailed to $name <$email>",
        $documents ? 'Attached: ' . implode(', ', array_column($documents, 'title')) : null);
}

/**
 * Record the customer's acceptance, then (if enabled) create the contract and
 * send it for signature. Returns the contract created, if any.
 */
function quote_accept(array $quote, string $name, string $ip, bool $byStaff = false, ?string $email = null, string $userAgent = '', bool $confirm = true): ?array
{
    $email = $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null;
    $account = db_one('SELECT * FROM accounts WHERE id = ?', [$quote['account_id']]);
    $lines = quote_lines((int)$quote['id']);
    db_exec("UPDATE quotes SET status = 'accepted', responded_at = NOW(), response_name = ?, response_email = ?, response_ip = ?, response_user_agent = ?,
        response_method = ?, response_recorded_by = ?, response_statement = ?, response_fingerprint = ? WHERE id = ?", [
        $name, $email, $byStaff ? null : ($ip ?: null), $byStaff ? null : (mb_substr($userAgent, 0, 255) ?: null),
        $byStaff ? 'staff' : 'online', $byStaff ? (current_user()['id'] ?? null) : null,
        $byStaff ? null : quote_acceptance_statement($account), quote_fingerprint($quote, $lines), $quote['id'],
    ]);
    log_activity((int)$quote['account_id'], 'note', "Quote {$quote['reference']} accepted by $name" . ($email ? " <$email>" : '') . ($byStaff ? ' (recorded by staff)' : " online from $ip"));
    if ($quote['opportunity_id']) {
        db_exec("UPDATE opportunities SET stage = 'won', probability = 100 WHERE id = ?", [$quote['opportunity_id']]);
    }
    quote_notify_staff($quote, "Quote {$quote['reference']} accepted", "$name accepted quote {$quote['reference']} – " . $quote['title'] . '.');
    try {
        order_create_from_quote(db_one('SELECT * FROM quotes WHERE id = ?', [$quote['id']]));
    } catch (Throwable $e) {
        error_log('Order after quote acceptance failed: ' . $e->getMessage());
        log_activity((int)$quote['account_id'], 'task', "Order for quote {$quote['reference']} wasn't created", $e->getMessage());
    }
    if ($confirm) {
        try {
            quote_send_confirmation(db_one('SELECT * FROM quotes WHERE id = ?', [$quote['id']]));
        } catch (Throwable $e) {
            // The acceptance stands; staff can resend the confirmation from the quote.
            error_log('Quote confirmation failed: ' . $e->getMessage());
            log_activity((int)$quote['account_id'], 'task', "Acceptance confirmation for quote {$quote['reference']} wasn't sent", $e->getMessage());
        }
    }

    $contract = null;
    if (setting('contracts_auto_on_accept', '1') === '1') {
        try {
            $contract = contract_create_from_quote(db_one('SELECT * FROM quotes WHERE id = ?', [$quote['id']]));
            if ($contract && signable_configured() && setting('signable_auto_send', '1') === '1') {
                $contract = contract_send($contract);
            }
        } catch (Throwable $e) {
            // The customer's acceptance stands; staff see the problem on the quote.
            error_log('Contract after quote acceptance failed: ' . $e->getMessage());
            log_activity((int)$quote['account_id'], 'task', "Contract for quote {$quote['reference']} needs attention", $e->getMessage());
            if ($contract) {
                db_exec('UPDATE contracts SET last_error = ? WHERE id = ?', [$e->getMessage(), $contract['id']]);
            }
        }
    }
    return $contract;
}

/** What the customer agrees to when they tick the box on the quote page. */
function quote_acceptance_statement(array $account): string
{
    return 'I accept this quote on behalf of ' . $account['name'] . (signable_configured() ? ' and understand a contract will be sent for signature' : '') . '.';
}

/** A SHA-256 fingerprint of what was accepted: the quote's lines, totals, validity and terms. */
function quote_fingerprint(array $quote, array $lines): string
{
    return hash('sha256', json_encode([
        'reference' => $quote['reference'], 'title' => $quote['title'], 'valid_until' => $quote['valid_until'],
        'lines' => array_map(fn($l) => [$l['description'], (int)$l['quantity'], number_format((float)$l['monthly_price'], 2, '.', ''),
            number_format((float)$l['setup_fee'], 2, '.', ''), (int)$l['term_months']], $lines),
        'terms' => (string)setting('quote_terms'),
    ], JSON_UNESCAPED_UNICODE));
}

/** The quote as a PDF; once accepted, with a record of the acceptance. */
function quote_pdf(array $quote): string
{
    $account = db_one('SELECT * FROM accounts WHERE id = ?', [$quote['account_id']]);
    $lines = quote_lines((int)$quote['id']);
    $totals = quote_totals($lines);
    $company = company('name', config('app_name'));
    $ink = [29, 41, 57];
    $muted = [102, 112, 133];
    $brand = brand_colour();
    $pdf = new SimplePdf(50, "Quote {$quote['reference']} - $company");
    $m = $pdf->margin;
    $right = SimplePdf::W - $m;
    $width = $right - $m;
    $pdf->addPage();

    // Header: company on the left, quote reference on the right.
    $pdf->rect(0, 0, SimplePdf::W, 6, $brand);
    $pdf->text($right, $pdf->y + 2, 'QUOTE', 10, true, $brand, 'right');
    $pdf->text($right, $pdf->y + 16, (string)$quote['reference'], 14, true, $ink, 'right');
    // Our logo (Settings → Branding), or the company name.
    $logo = brand_logo();
    $drawn = $logo ? $pdf->image($logo['bytes'], $m, $pdf->y - 6, 200, 52) : null;
    if ($drawn) {
        $pdf->y += $drawn[1] + 4;
    } else {
        $pdf->text($m, $pdf->y, $company, 18, true, $ink);
        $pdf->y += 26;
    }
    $contact = array_filter([company_address_line(), implode(' · ', array_filter([company('phone'), company('email')])),
        company('number') ? 'Company no. ' . company('number') : '']);
    foreach ($contact as $c) {
        foreach ($pdf->wrap($c, $width * 0.6, 9) as $l) {
            $pdf->text($m, $pdf->y, $l, 9, false, $muted);
            $pdf->y += 12;
        }
    }
    $pdf->y = max($pdf->y, $m + 60) + 14;

    // Who it's for, and dates.
    $top = $pdf->y;
    $pdf->text($m, $pdf->y, 'PREPARED FOR', 8, true, $muted);
    $pdf->y += 13;
    $pdf->text($m, $pdf->y, $account['name'], 11, true, $ink);
    $pdf->y += 15;
    $addr = implode(', ', array_filter(array_map(fn($v) => trim((string)$v), [$account['address'] ?? '', $account['address2'] ?? '', $account['city'] ?? '', $account['postcode'] ?? ''])));
    foreach (array_filter([$addr, $quote['recipient_name'] ? 'FAO ' . $quote['recipient_name'] : '']) as $l) {
        foreach ($pdf->wrap($l, $width * 0.55, 9.5) as $w) {
            $pdf->text($m, $pdf->y, $w, 9.5, false, $ink);
            $pdf->y += 13;
        }
    }
    $dy = $top;
    foreach (array_filter(['Date' => fmt_date($quote['sent_at'] ?: $quote['created_at']), 'Valid until' => fmt_date($quote['valid_until'])]) as $k => $v) {
        $pdf->text($right - 110, $dy, $k, 9, false, $muted);
        $pdf->text($right, $dy, $v, 9, true, $ink, 'right');
        $dy += 14;
    }
    $pdf->y = max($pdf->y, $dy) + 16;

    $pdf->paragraph($m, $width, (string)$quote['title'], 14, true, $ink);
    $pdf->y += 4;
    if (trim((string)$quote['intro']) !== '') {
        $pdf->paragraph($m, $width, (string)$quote['intro'], 10, false, $ink);
    }
    $pdf->y += 12;

    // Lines.
    $cols = [['Service', 215, 'left'], ['Qty', 35, 'right'], ['Monthly', 75, 'right'], ['One-off', 75, 'right'], ['Term', 95, 'right']];
    $header = function () use ($pdf, $cols, $m, $width, $muted) {
        $pdf->rect($m, $pdf->y, $width, 20, [242, 244, 247]);
        $x = $m + 8;
        foreach ($cols as [$label, $w, $align]) {
            $pdf->text($align === 'right' ? $x + $w - 16 : $x, $pdf->y + 6, $label, 8.5, true, $muted, $align);
            $x += $w;
        }
        $pdf->y += 26;
    };
    $pdf->need(60);
    $header();
    foreach ($lines as $l) {
        $desc = $pdf->wrap((string)$l['description'], $cols[0][1] - 12, 9.5, true);
        $type = SERVICE_TYPES[$l['service_type']] ?? '';
        $h = count($desc) * 13 + ($type !== '' ? 12 : 0) + 8;
        if ($pdf->need($h + 4)) {
            $header();
        }
        $rowTop = $pdf->y;
        foreach ($desc as $i => $d) {
            $pdf->text($m + 8, $rowTop + $i * 13, $d, 9.5, true, $ink);
        }
        if ($type !== '') {
            $pdf->text($m + 8, $rowTop + count($desc) * 13, $type, 8.5, false, $muted);
        }
        $x = $m + 8 + $cols[0][1];
        foreach ([(string)(int)$l['quantity'], money($l['monthly_price']), money($l['setup_fee']), term_label($l['term_months'])] as $i => $v) {
            $w = $cols[$i + 1][1];
            $pdf->text($x + $w - 16, $rowTop, $v, 9.5, false, $ink, 'right');
            $x += $w;
        }
        $pdf->y = $rowTop + $h;
        $pdf->line($m, $pdf->y - 4, $right, $pdf->y - 4);
    }

    // Totals.
    $pdf->need(80);
    $pdf->y += 8;
    foreach ([['Monthly total', money($totals['monthly']) . ' + VAT', true], ['One-off total', money($totals['setup']) . ' + VAT', true],
              ['Minimum term', term_label($totals['term']), false], ['Total contract value', money($totals['tcv']) . ' + VAT', false]] as [$k, $v, $bold]) {
        $pdf->text($right - 230, $pdf->y, $k, 10, false, $muted);
        $pdf->text($right - 8, $pdf->y, $v, $bold ? 11 : 10, $bold, $ink, 'right');
        $pdf->y += 17;
    }
    $pdf->y += 6;
    if ($terms = trim((string)setting('quote_terms'))) {
        $pdf->need(30);
        $pdf->paragraph($m, $width, $terms, 8.5, false, $muted);
    }

    // The acceptance record.
    if ($quote['status'] === 'accepted' && $quote['responded_at']) {
        $staff = $quote['response_method'] === 'staff';
        $recordedBy = $staff && $quote['response_recorded_by'] ? db_value('SELECT name FROM users WHERE id = ?', [$quote['response_recorded_by']]) : null;
        $rows = array_filter([
            'Accepted by' => (string)$quote['response_name'],
            'Email' => (string)$quote['response_email'],
            'Date and time' => date('j F Y \a\t H:i:s T', strtotime((string)$quote['responded_at'])),
            'How' => $staff ? 'Recorded by ' . ($recordedBy ?: 'a member of staff') . ' on the customer\'s behalf' : 'Online, using the link sent with the quote',
            'IP address' => $staff ? '' : (string)$quote['response_ip'],
            'Device / browser' => $staff ? '' : (string)$quote['response_user_agent'],
            'They confirmed' => $staff ? '' : '"' . ($quote['response_statement'] ?: quote_acceptance_statement($account)) . '"',
            'Fingerprint' => $quote['response_fingerprint'] ? 'SHA-256 ' . $quote['response_fingerprint'] : '',
        ], fn($v) => $v !== '');
        $labelW = 105;
        $valueW = $width - $labelW - 24;
        $heights = array_map(fn($v) => count($pdf->wrap($v, $valueW, 9)) * 12 + 4, $rows);
        $boxH = 34 + array_sum($heights) + 8;
        $pdf->need($boxH + 20);
        $pdf->y += 14;
        $pdf->rect($m, $pdf->y, $width, $boxH, [236, 253, 243], [166, 244, 197]);
        $pdf->text($m + 12, $pdf->y + 12, 'ACCEPTED', 10, true, [2, 122, 72]);
        $pdf->y += 32;
        foreach ($rows as $k => $v) {
            $pdf->text($m + 12, $pdf->y, $k, 9, false, $muted);
            foreach ($pdf->wrap($v, $valueW, 9) as $i => $l) {
                $pdf->text($m + 12 + $labelW, $pdf->y + $i * 12, $l, 9, $k === 'Accepted by', $ink);
            }
            $pdf->y += $heights[$k];
        }
    }

    // Footers.
    $count = $pdf->pageCount();
    for ($i = 0; $i < $count; $i++) {
        $pdf->setPage($i);
        $pdf->line($m, SimplePdf::H - 42, $right, SimplePdf::H - 42);
        $pdf->text($m, SimplePdf::H - 36, "$company · Quote {$quote['reference']}", 8, false, $muted);
        $pdf->text($right, SimplePdf::H - 36, 'Page ' . ($i + 1) . " of $count", 8, false, $muted, 'right');
    }
    return $pdf->output();
}

/**
 * Email the customer a confirmation of their acceptance with the quote PDF,
 * and keep a copy in the customer's files. Returns who it was sent to, or null
 * when there's no email address (the PDF is still filed).
 */
function quote_send_confirmation(array $quote): ?string
{
    $account = db_one('SELECT * FROM accounts WHERE id = ?', [$quote['account_id']]);
    $pdf = quote_pdf($quote);
    $file = "Quote {$quote['reference']} accepted.pdf";
    $tmp = tempnam(sys_get_temp_dir(), 'qpdf');
    file_put_contents($tmp, $pdf);
    try {
        // Keep the record with the customer (replacing an older copy for this quote).
        foreach (db_all('SELECT * FROM documents WHERE account_id = ? AND file_name = ?', [$account['id'], $file]) as $old) {
            db_exec('DELETE FROM documents WHERE id = ?', [$old['id']]);
            @unlink(document_path($old));
        }
        document_store(['name' => $file, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($pdf)], ['account_id' => (int)$account['id']],
            "Quote {$quote['reference']} – accepted", 'Signed-off quote with the acceptance record', false);

        $to = $quote['response_email'] ?: $quote['recipient_email'];
        if (!$to || !mail_configured()) {
            return null;
        }
        $name = (string)($quote['response_name'] ?: $quote['recipient_name']);
        $first = trim(explode(' ', preg_replace('/^(mr|mrs|ms|miss|dr)\.?\s+/i', '', $name))[0] ?? '');
        $totals = quote_totals(quote_lines((int)$quote['id']));
        $when = date('j F Y \a\t H:i', strtotime((string)$quote['responded_at']));
        $body = '<p>Hi ' . h($first ?: 'there') . ',</p>'
            . '<p>Thank you for accepting quote <b>' . h($quote['reference']) . '</b> – ' . h($quote['title']) . '. This email confirms your acceptance.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:8px 0;font-size:14px">'
            . '<tr><td style="padding:6px 0;color:#667085">Accepted by</td><td style="padding:6px 0;text-align:right">' . h($name) . '</td></tr>'
            . '<tr><td style="padding:6px 0;color:#667085">On</td><td style="padding:6px 0;text-align:right">' . h($when) . '</td></tr>'
            . '<tr><td style="padding:6px 0;color:#667085">Monthly charges</td><td style="padding:6px 0;text-align:right;font-weight:bold">' . h(money($totals['monthly'])) . ' + VAT</td></tr>'
            . '<tr><td style="padding:6px 0;color:#667085">One-off charges</td><td style="padding:6px 0;text-align:right;font-weight:bold">' . h(money($totals['setup'])) . ' + VAT</td></tr>'
            . '<tr><td style="padding:6px 0;color:#667085">Minimum term</td><td style="padding:6px 0;text-align:right">' . h(term_label($totals['term'])) . '</td></tr></table>'
            . '<p>A copy of the quote with the record of your acceptance is attached for your files.</p>'
            . (($order = db_one('SELECT * FROM customer_orders WHERE quote_id = ?', [$quote['id']]))
                ? '<p>Your order reference is <b>' . h($order['reference']) . '</b>. We\'ll email you as it progresses, and you can follow it at any time:</p>' . email_button(order_tracking_url($order), 'Track your order')
                : '')
            . (signable_configured() && setting('signable_auto_send', '1') === '1' ? '<p>Your contract will arrive in a separate email for you to sign online.</p>' : '<p>We\'ll be in touch shortly about the next steps.</p>')
            . '<p style="color:#667085;font-size:13px">If you didn\'t accept this quote, or anything looks wrong, please reply to this email straight away.</p>';
        send_mail($to, $name, 'Confirmation: quote ' . $quote['reference'] . ' accepted', email_layout('Thank you, your quote is accepted', $body), null, [],
            [['name' => $file, 'path' => $tmp, 'mime' => 'application/pdf']]);
        db_exec('UPDATE quotes SET confirmation_sent_at = NOW() WHERE id = ?', [$quote['id']]);
        log_activity((int)$quote['account_id'], 'email', "Acceptance confirmation for quote {$quote['reference']} emailed to $name <$to>", 'With the quote PDF attached');
        return $to;
    } finally {
        @unlink($tmp);
    }
}

function quote_decline(array $quote, string $reason, string $ip): void
{
    db_exec("UPDATE quotes SET status = 'declined', responded_at = NOW(), decline_reason = ?, response_ip = ? WHERE id = ?", [$reason ?: null, $ip, $quote['id']]);
    log_activity((int)$quote['account_id'], 'note', "Quote {$quote['reference']} declined", $reason ?: null);
    quote_notify_staff($quote, "Quote {$quote['reference']} declined", "Quote {$quote['reference']} – {$quote['title']} was declined." . ($reason ? "\n\nReason: $reason" : ''));
}

/** Let the person who created the quote know (best effort). */
function quote_notify_staff(array $quote, string $subject, string $message): void
{
    $user = $quote['created_by'] ? db_one('SELECT name, email FROM users WHERE id = ? AND active = 1', [$quote['created_by']]) : null;
    if (!$user || !mail_configured()) {
        return;
    }
    try {
        $link = app_url() . '/index.php?page=quotes&action=view&id=' . $quote['id'];
        send_mail($user['email'], $user['name'], $subject, email_layout($subject, nl2br(h($message)) . email_button($link, 'Open the quote')));
    } catch (IntegrationException $e) {
        error_log('Quote notification failed: ' . $e->getMessage());
    }
}
