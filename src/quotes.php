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
function quote_accept(array $quote, string $name, string $ip, bool $byStaff = false, ?string $email = null): ?array
{
    $email = $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null;
    db_exec("UPDATE quotes SET status = 'accepted', responded_at = NOW(), response_name = ?, response_email = ?, response_ip = ? WHERE id = ?", [$name, $email, $ip, $quote['id']]);
    log_activity((int)$quote['account_id'], 'note', "Quote {$quote['reference']} accepted by $name" . ($email ? " <$email>" : '') . ($byStaff ? ' (recorded by staff)' : " online from $ip"));
    if ($quote['opportunity_id']) {
        db_exec("UPDATE opportunities SET stage = 'won', probability = 100 WHERE id = ?", [$quote['opportunity_id']]);
    }
    quote_notify_staff($quote, "Quote {$quote['reference']} accepted", "$name accepted quote {$quote['reference']} – " . $quote['title'] . '.');

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
