<?php
declare(strict_types=1);

/*
 * Built-in electronic signatures for contracts (a UK "simple electronic signature"):
 *
 *  1. The signer is emailed a private link, with the Contract Summary and the agreement attached
 *     (so they hold them, in a durable form, before they can agree to anything).
 *  2. On the signing page, in this order (enforced here, not just on the page): they read the
 *     Contract Summary and confirm they've received it; read the agreement; confirm their email
 *     address with a 6-digit code; then type their name and tick to agree.
 *  3. Every step is timestamped in contract_events. The CRM records who, when, from where (IP address
 *     and device), and a SHA-256 fingerprint of each document, then produces a signature certificate
 *     PDF with the full timeline. Documents and certificate are emailed to the signer and to us.
 *
 * Any later change to a document would no longer match the fingerprints on the
 * certificate everyone already holds, which is what makes the record tamper-evident.
 */

const ESIGN_CODE_MINUTES = 15;      // a code works for this long
const ESIGN_CODE_ATTEMPTS = 5;      // wrong codes before a new one is needed
const ESIGN_CODE_SENDS_PER_HOUR = 5;
const ESIGN_VERIFIED_MINUTES = 60;  // after confirming the code, sign within this long

/** What each step in the signing record means. */
const ESIGN_EVENTS = [
    'created'           => 'Agreement prepared',
    'sent'              => 'Emailed to sign, with the documents attached',
    'reminder'          => 'Reminder emailed, with the documents attached',
    'opened'            => 'Signing page opened',
    'downloaded'        => 'Document downloaded',
    'summary_confirmed' => 'Receipt of the Contract Summary confirmed',
    'agreement_shown'   => 'Agreement shown',
    'code_sent'         => 'Confirmation code emailed',
    'code_wrong'        => 'Wrong code entered',
    'code_confirmed'    => 'Email address confirmed with the code',
    'signed'            => 'Signed',
    'copies_sent'       => 'Signed copies and certificate emailed',
    'declined'          => 'Declined',
    'cancelled'         => 'Cancelled by us',
    'marked_signed'     => 'Recorded as signed by staff',
];

/** Wording the customer agrees to, from Settings (your solicitor's), or the defaults. {customer} is replaced with their name. */
function esign_wording(string $key, string $default, array $account): string
{
    $text = trim((string)setting($key)) ?: $default;
    return str_replace('{customer}', $account['name'], $text);
}

const ESIGN_DEFAULT_SUMMARY_STATEMENT = 'I confirm I have received and read the Contract Summary for this agreement.';
const ESIGN_DEFAULT_SIGN_STATEMENT = 'I have read the agreement above and agree to its terms on behalf of {customer}. I understand that typing my name and pressing Sign is my electronic signature, and has the same effect as signing by hand.';

function esign_url(array $contract): string
{
    return app_url() . '/sign.php?t=' . rawurlencode((string)$contract['sign_token']);
}

function contract_by_sign_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    return db_one('SELECT * FROM contracts WHERE sign_token = ?', [$token]);
}

/** Whether contracts made when a quote is accepted are sent for signature straight away. */
function esign_auto_send(): bool
{
    return setting('contracts_auto_send', '1') === '1';
}

/**
 * Send (or resend) a contract to its signer: email them the private signing link.
 * Resending keeps the same link, so an earlier email still works.
 */
function contract_send(array $contract, bool $reminder = false): array
{
    if (!in_array($contract['status'], ['draft', 'failed', 'sent'], true) || !contract_documents($contract)) {
        throw new IntegrationException('Only a contract with documents that hasn\'t been signed can be sent.');
    }
    if (!filter_var((string)$contract['signer_email'], FILTER_VALIDATE_EMAIL)) {
        throw new IntegrationException('Add the signer\'s email address first.');
    }
    $first = $contract['status'] !== 'sent';
    $token = $contract['sign_token'] ?: bin2hex(random_bytes(24));
    db_exec("UPDATE contracts SET status = 'sent', sign_token = ?, sent_at = COALESCE(sent_at, NOW()), last_error = NULL WHERE id = ?", [$token, $contract['id']]);
    $contract = db_one('SELECT * FROM contracts WHERE id = ?', [$contract['id']]);

    $company = company('name', config('app_name'));
    $first_name = explode(' ', trim((string)$contract['signer_name']))[0] ?: 'there';
    $intro = $reminder
        ? "<p>Hi " . h($first_name) . ",</p><p>Just a reminder that your agreement with " . h($company) . " is ready for you to sign.</p>"
        : "<p>Hi " . h($first_name) . ",</p><p>Thank you for your order. Your agreement with " . h($company) . " is ready for you to review and sign online. It only takes a minute.</p>";
    // The documents go with the email, so the customer holds them before agreeing to anything.
    $summary = contract_summary_document($contract);
    [$attachments, $supplied] = esign_document_attachments($contract);
    $body = $intro
        . '<p><b>' . h($contract['title']) . '</b> (' . h($contract['reference']) . ')</p>'
        . ($summary ? '<p>Attached are your <b>Contract Summary</b> and the agreement. Please read the Contract Summary first: it sets out the main points of what you\'re agreeing to.</p>'
            : '<p>The agreement is attached for you to keep.</p>')
        . email_button(esign_url($contract), 'Review and sign')
        . '<p style="color:#667085">To confirm it\'s you, we\'ll email a 6-digit code to this address when you sign. If you have any questions, just reply to this email.</p>';
    try {
        send_mail((string)$contract['signer_email'], (string)$contract['signer_name'],
            ($reminder ? 'Reminder: ' : '') . "Please sign your agreement with $company", email_layout($reminder ? 'Your agreement is waiting' : 'Your agreement is ready to sign', $body),
            null, [], $attachments);
    } catch (IntegrationException $e) {
        db_exec('UPDATE contracts SET status = ?, last_error = ? WHERE id = ?', [$first ? 'failed' : 'sent', 'The signing email couldn\'t be sent: ' . $e->getMessage(), $contract['id']]);
        throw new IntegrationException('The signing email couldn\'t be sent: ' . $e->getMessage());
    }
    if ($reminder) {
        db_exec('UPDATE contracts SET reminders_sent = reminders_sent + 1, last_reminded_at = NOW() WHERE id = ?', [$contract['id']]);
    }
    contract_event((int)$contract['id'], $reminder ? 'reminder' : 'sent', 'To ' . $contract['signer_email'] . '. Attached: ' . $supplied);
    log_activity((int)$contract['account_id'], 'email', ($reminder ? 'Reminder to sign' : 'Contract') . " {$contract['reference']} " . ($reminder ? 'sent' : 'sent for signature') . " to {$contract['signer_name']} <{$contract['signer_email']}>");
    if ($first && ($order = contract_order($contract))) {
        order_add_event((int)$order['id'], 'contract_sent', "We've emailed your agreement to {$contract['signer_name']} ({$contract['signer_email']}) to sign online. We'll carry on with your order once it's signed.",
            "Contract {$contract['reference']} sent for signature");
    }
    return db_one('SELECT * FROM contracts WHERE id = ?', [$contract['id']]);
}

/** The contract's documents as email attachments, and a line describing them with their fingerprints (what was supplied, exactly). */
function esign_document_attachments(array $contract): array
{
    $dir = storage_path('contracts');
    $attachments = [];
    $described = [];
    foreach (contract_documents($contract) as $d) {
        $path = $dir . '/' . basename($d['file']);
        if (!is_file($path)) {
            continue;
        }
        $attachments[] = ['name' => $contract['reference'] . ' ' . preg_replace('/[^A-Za-z0-9 _-]/', '', $d['title']) . '.docx', 'path' => $path,
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $described[] = $d['title'] . ' (SHA-256 ' . hash_file('sha256', $path) . ')';
    }
    return [$attachments, $described ? implode('; ', $described) : 'none'];
}

/** The signer opened the signing page (recorded in the signing record; a reload within 15 minutes isn't counted again). */
function esign_viewed(array $contract, string $ip, string $userAgent): void
{
    $recent = db_value("SELECT 1 FROM contract_events WHERE contract_id = ? AND event = 'opened' AND created_at > NOW() - INTERVAL 15 MINUTE", [$contract['id']]);
    if (!$recent) {
        contract_event((int)$contract['id'], 'opened', null, $ip, $userAgent);
    }
    if ($contract['viewed_at']) {
        return;
    }
    db_exec('UPDATE contracts SET viewed_at = NOW(), viewed_ip = ? WHERE id = ?', [mb_substr($ip, 0, 45) ?: null, $contract['id']]);
    log_activity((int)$contract['account_id'], 'note', "Contract {$contract['reference']} opened by the signer" . ($ip ? " from $ip" : ''));
}

/** A document was downloaded from the signing page. */
function esign_downloaded(array $contract, array $doc, string $ip, string $userAgent): void
{
    contract_event((int)$contract['id'], 'downloaded', $doc['title'], $ip, $userAgent);
}

/** Has the signer confirmed receipt of the Contract Summary (or is there none)? */
function esign_summary_confirmed(array $contract): bool
{
    return !contract_summary_document($contract) || $contract['summary_ack_at'];
}

/** The signer confirms they've received and read the Contract Summary: the first step, before anything else. */
function esign_confirm_summary(array $contract, string $ip, string $userAgent): void
{
    if ($contract['status'] !== 'sent') {
        throw new IntegrationException('This agreement can\'t be signed any more.');
    }
    $summary = contract_summary_document($contract);
    if (!$summary || $contract['summary_ack_at']) {
        return;
    }
    $account = db_one('SELECT name FROM accounts WHERE id = ?', [$contract['account_id']]);
    $path = storage_path('contracts') . '/' . basename($summary['file']);
    db_exec('UPDATE contracts SET summary_ack_at = NOW(), summary_ack_ip = ? WHERE id = ?', [mb_substr($ip, 0, 45) ?: null, $contract['id']]);
    contract_event((int)$contract['id'], 'summary_confirmed', '"' . esign_summary_statement($account['name']) . '" Contract Summary SHA-256 ' . (is_file($path) ? hash_file('sha256', $path) : 'missing'), $ip, $userAgent);
    log_activity((int)$contract['account_id'], 'note', "Contract {$contract['reference']}: signer confirmed receipt of the Contract Summary");
}

function esign_summary_statement(string $account): string
{
    return esign_wording('esign_summary_statement', ESIGN_DEFAULT_SUMMARY_STATEMENT, ['name' => $account]);
}

/** Email the signer a 6-digit code to confirm it's them. */
function esign_send_code(array $contract): void
{
    if ($contract['status'] !== 'sent') {
        throw new IntegrationException('This agreement can\'t be signed any more.');
    }
    if (!esign_summary_confirmed($contract)) {
        throw new IntegrationException('Please read the Contract Summary and confirm you\'ve received it first.');
    }
    if ($contract['code_sent_at'] && time() - strtotime($contract['code_sent_at']) < 30) {
        throw new IntegrationException('We\'ve just sent a code. Please check your inbox (and spam folder), or wait a moment before asking for another.');
    }
    $recent = $contract['code_sent_at'] && time() - strtotime($contract['code_sent_at']) < 3600 ? (int)$contract['code_sends'] : 0;
    if ($recent >= ESIGN_CODE_SENDS_PER_HOUR) {
        throw new IntegrationException('Too many codes have been requested. Please try again in an hour, or contact us.');
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db_exec('UPDATE contracts SET code_hash = ?, code_expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), code_attempts = 0, code_sent_at = NOW(), code_sends = ? WHERE id = ?',
        [password_hash($code, PASSWORD_DEFAULT), ESIGN_CODE_MINUTES, $recent + 1, $contract['id']]);
    $company = company('name', config('app_name'));
    send_mail((string)$contract['signer_email'], (string)$contract['signer_name'], "Your code to sign with $company: $code",
        email_layout('Your signing code', '<p>Enter this code on the signing page to confirm it\'s you:</p>'
            . '<p style="font-size:28px;font-weight:bold;letter-spacing:6px;font-family:monospace">' . $code . '</p>'
            . '<p style="color:#667085">It works for ' . ESIGN_CODE_MINUTES . ' minutes. If you didn\'t ask for it, you can ignore this email.</p>'));
    contract_event((int)$contract['id'], 'code_sent', 'To ' . $contract['signer_email']);
}

/** Check the code; on success the signer's email is verified (for this browser session). */
function esign_verify_code(array $contract, string $code): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (!$contract['code_hash'] || strtotime((string)$contract['code_expires_at']) < time()) {
        throw new IntegrationException('That code has expired. Please ask for a new one.');
    }
    if ((int)$contract['code_attempts'] >= ESIGN_CODE_ATTEMPTS) {
        throw new IntegrationException('Too many wrong codes. Please ask for a new one.');
    }
    if (!password_verify($code, (string)$contract['code_hash'])) {
        db_exec('UPDATE contracts SET code_attempts = code_attempts + 1 WHERE id = ?', [$contract['id']]);
        contract_event((int)$contract['id'], 'code_wrong', null, client_ip_or_null());
        return false;
    }
    db_exec('UPDATE contracts SET code_hash = NULL, verified_at = NOW() WHERE id = ?', [$contract['id']]);
    contract_event((int)$contract['id'], 'code_confirmed', $contract['signer_email'], client_ip_or_null());
    $_SESSION['esign_verified'][(int)$contract['id']] = time();
    log_activity((int)$contract['account_id'], 'note', "Contract {$contract['reference']}: signer confirmed their email ({$contract['signer_email']}) with a code");
    return true;
}

function esign_is_verified(array $contract): bool
{
    $at = (int)($_SESSION['esign_verified'][(int)$contract['id']] ?? 0);
    return $at > 0 && time() - $at < ESIGN_VERIFIED_MINUTES * 60;
}

/** SHA-256 fingerprints of a contract's documents: [title => hash]. */
function esign_document_hashes(array $contract): array
{
    $out = [];
    foreach (contract_documents($contract) as $d) {
        $path = storage_path('contracts') . '/' . basename($d['file']);
        if (!is_file($path)) {
            throw new IntegrationException('Part of this agreement ("' . $d['title'] . '") is missing, so it can\'t be signed. Please contact us and we\'ll send it again.');
        }
        $out[$d['title']] = hash_file('sha256', $path);
    }
    return $out;
}

/** The statement the signer agrees to. */
function esign_statement(array $contract, string $account): string
{
    return esign_wording('esign_sign_statement', ESIGN_DEFAULT_SIGN_STATEMENT, ['name' => $account]);
}

/** The requester's IP address, when there is a request (not from the cron job or tests). */
function client_ip_or_null(): ?string
{
    return PHP_SAPI === 'cli' ? null : client_ip();
}

/**
 * Sign the contract: record the signature and evidence, make the certificate, and send copies.
 * The signer must have confirmed their email with a code in this session.
 */
function esign_sign(array $contract, string $name, string $position, string $ip, string $userAgent): array
{
    if ($contract['status'] !== 'sent') {
        throw new IntegrationException('This agreement can\'t be signed any more.');
    }
    if (!esign_summary_confirmed($contract)) {
        throw new IntegrationException('Please read the Contract Summary and confirm you\'ve received it first.');
    }
    if (!esign_is_verified($contract)) {
        throw new IntegrationException('Please confirm your email address with the code first.');
    }
    $account = db_one('SELECT name FROM accounts WHERE id = ?', [$contract['account_id']]);
    $hashes = esign_document_hashes($contract);
    db_exec('UPDATE contracts SET signed_name = ?, signed_position = ?, signed_ip = ?, signed_user_agent = ?, signed_statement = ?, document_hashes = ? WHERE id = ?', [
        mb_substr($name, 0, 150), mb_substr($position, 0, 150) ?: null, mb_substr($ip, 0, 45) ?: null, mb_substr($userAgent, 0, 255) ?: null,
        esign_statement($contract, $account['name']), json_encode($hashes), $contract['id'],
    ]);
    db_exec("UPDATE contracts SET signed_at = NOW() WHERE id = ?", [$contract['id']]);
    contract_event((int)$contract['id'], 'signed', 'By ' . mb_substr($name, 0, 150) . ($position !== '' ? ', ' . mb_substr($position, 0, 150) : ''), $ip, $userAgent);
    $contract = db_one('SELECT * FROM contracts WHERE id = ?', [$contract['id']]);
    $certificate = $contract['reference'] . '-certificate-' . bin2hex(random_bytes(4)) . '.pdf';
    file_put_contents(storage_path('contracts') . '/' . $certificate, esign_certificate_pdf($contract));
    unset($_SESSION['esign_verified'][(int)$contract['id']]);
    $contract = contract_mark_signed($contract, $certificate, "online from $ip");
    esign_send_copies($contract);
    return $contract;
}

/** The customer chose not to sign. */
function esign_decline(array $contract, string $reason): void
{
    if ($contract['status'] !== 'sent') {
        return;
    }
    db_exec("UPDATE contracts SET status = 'rejected', declined_reason = ? WHERE id = ?", [mb_substr($reason, 0, 2000) ?: null, $contract['id']]);
    contract_event((int)$contract['id'], 'declined', $reason ?: null, client_ip_or_null());
    log_activity((int)$contract['account_id'], 'task', "Contract {$contract['reference']} declined by {$contract['signer_name']}", $reason ?: null);
    if ($order = contract_order($contract)) {
        order_add_event((int)$order['id'], null, null, "Contract {$contract['reference']} declined by the customer" . ($reason ? ": $reason" : ''));
    }
    if ($contract['quote_id'] && ($quote = db_one('SELECT * FROM quotes WHERE id = ?', [$contract['quote_id']]))) {
        quote_notify_staff($quote, "Contract {$contract['reference']} declined", "{$contract['signer_name']} declined to sign contract {$contract['reference']}." . ($reason ? "\n\nTheir reason: $reason" : ''));
    }
}

/** Email the signed agreement and its certificate to the signer, and a copy to us. */
function esign_send_copies(array $contract): void
{
    $dir = storage_path('contracts');
    $attachments = [['name' => "{$contract['reference']} signature certificate.pdf", 'path' => $dir . '/' . basename((string)$contract['signed_file']), 'mime' => 'application/pdf']];
    foreach (contract_documents($contract) as $d) {
        $attachments[] = ['name' => $contract['reference'] . ' ' . preg_replace('/[^A-Za-z0-9 _-]/', '', $d['title']) . '.docx', 'path' => $dir . '/' . basename($d['file']),
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    }
    $company = company('name', config('app_name'));
    $body = '<p>Thank you, ' . h(explode(' ', (string)$contract['signed_name'])[0]) . '. You signed <b>' . h($contract['title']) . '</b> (' . h($contract['reference']) . ') on ' . h(fmt_datetime($contract['signed_at'])) . '.</p>'
        . '<p>Your signed agreement and its signature certificate are attached for your records.</p>';
    try {
        send_mail((string)$contract['signer_email'], (string)$contract['signed_name'], "Signed: your agreement with $company ({$contract['reference']})",
            email_layout('Agreement signed', $body), null, [], $attachments);
        contract_event((int)$contract['id'], 'copies_sent', 'To ' . $contract['signer_email']);
    } catch (IntegrationException $e) {
        error_log('Signed agreement email failed: ' . $e->getMessage());
        log_activity((int)$contract['account_id'], 'task', "The signed copy of {$contract['reference']} wasn't emailed to the customer", $e->getMessage());
    }
    $ours = setting('company_email') ?: setting('mail_from_email');
    if ($ours && filter_var($ours, FILTER_VALIDATE_EMAIL)) {
        try {
            send_mail($ours, $company, "Signed: {$contract['reference']} by {$contract['signed_name']}",
                email_layout('Agreement signed', '<p>' . h($contract['signed_name']) . ' signed ' . h($contract['reference']) . '. Copies are attached, and saved on the contract in the CRM.</p>'), null, [], $attachments);
        } catch (IntegrationException $e) {
            error_log('Signed agreement copy failed: ' . $e->getMessage());
        }
    }
}

/** The signature certificate: what was signed, by whom, when, how they were verified, and the documents' fingerprints. */
function esign_certificate_pdf(array $contract): string
{
    $account = db_one('SELECT * FROM accounts WHERE id = ?', [$contract['account_id']]);
    $company = company('name', config('app_name'));
    $ink = [29, 41, 57];
    $muted = [102, 112, 133];
    $brand = brand_colour();
    $pdf = new SimplePdf(50, "Signature certificate {$contract['reference']} - $company");
    $m = $pdf->margin;
    $right = SimplePdf::W - $m;
    $width = $right - $m;
    $pdf->addPage();
    $pdf->rect(0, 0, SimplePdf::W, 6, $brand);
    $pdf->text($right, $pdf->y + 2, 'SIGNATURE CERTIFICATE', 10, true, $brand, 'right');
    $pdf->text($right, $pdf->y + 16, (string)$contract['reference'], 14, true, $ink, 'right');
    $logo = brand_logo();
    $drawn = $logo ? $pdf->image($logo['bytes'], $m, $pdf->y - 6, 200, 52) : null;
    if ($drawn) {
        $pdf->y += $drawn[1] + 10;
    } else {
        $pdf->text($m, $pdf->y, $company, 18, true, $ink);
        $pdf->y += 30;
    }
    $pdf->y += 6;
    $pdf->text($m, $pdf->y, (string)$contract['title'], 14, true, $ink);
    $pdf->y += 24;

    $row = function (string $label, string $value) use ($pdf, $m, $width, $ink, $muted): void {
        $lines = $pdf->wrap($value !== '' ? $value : '—', $width - 150, 9.5);
        $pdf->need(13 * count($lines) + 4);
        $pdf->text($m, $pdf->y, $label, 9, true, $muted);
        foreach ($lines as $i => $l) {
            $pdf->text($m + 150, $pdf->y + $i * 13, $l, 9.5, false, $ink);
        }
        $pdf->y += 13 * count($lines) + 5;
    };
    $section = function (string $title) use ($pdf, $m, $right, $muted): void {
        $pdf->need(40);
        $pdf->y += 8;
        $pdf->text($m, $pdf->y, strtoupper($title), 8, true, $muted);
        $pdf->y += 6;
        $pdf->line($m, $pdf->y + 4, $right, $pdf->y + 4);
        $pdf->y += 14;
    };

    $section('Agreement');
    $row('Reference', (string)$contract['reference']);
    $row('Between', $company . ' and ' . $account['name'] . ($account['company_number'] ? ' (company no. ' . $account['company_number'] . ')' : ''));
    if ($contract['quote_id'] && ($quote = db_one('SELECT reference FROM quotes WHERE id = ?', [$contract['quote_id']]))) {
        $row('From quote', (string)$quote['reference']);
    }

    $section('Signed by');
    $row('Name', (string)$contract['signed_name']);
    if ($contract['signed_position']) {
        $row('Position', (string)$contract['signed_position']);
    }
    $row('Email', (string)$contract['signer_email']);
    $row('Statement agreed', (string)$contract['signed_statement']);
    $row('Signature', (string)$contract['signed_name'] . ' (typed)');

    $when = fn($v) => $v ? date('j M Y, H:i:s', strtotime((string)$v)) . ' (UK time)' : '';
    $section('Verification');
    $row('Email verified', $when($contract['verified_at']) . ': a one-time code sent to ' . $contract['signer_email'] . ' was entered correctly');
    $row('Signed', $when($contract['signed_at']));
    $row('IP address', (string)$contract['signed_ip']);
    $row('Device', (string)$contract['signed_user_agent']);
    if ($summary = contract_summary_document($contract)) {
        $section('Contract Summary');
        $row('Supplied', $when($contract['sent_at']) . ', attached to the signing email (and shown first on the signing page)');
        $row('Receipt confirmed', $when($contract['summary_ack_at']) . ($contract['summary_ack_ip'] ? ' from ' . $contract['summary_ack_ip'] : '')
            . ', before the agreement was signed');
    }

    // Every step, in the order it happened.
    $section('Timeline');
    $pdf->need(20);
    foreach (contract_events((int)$contract['id']) as $e) {
        $label = ESIGN_EVENTS[$e['event']] ?? $e['event'];
        $detail = trim((string)$e['detail'] . ($e['ip'] ? ' · IP ' . $e['ip'] : ''), ' ·');
        $row(date('j M Y H:i:s', strtotime($e['created_at'])), $label . ($detail !== '' ? ': ' . $detail : ''));
    }

    $section('Documents signed (SHA-256 fingerprints)');
    foreach (json_decode((string)$contract['document_hashes'], true) ?: [] as $title => $hash) {
        $row((string)$title, (string)$hash);
    }
    $pdf->y += 6;
    $pdf->need(60);
    $pdf->paragraph($m, $width, 'Each fingerprint identifies the exact document that was signed, and matches the fingerprint of the copy attached to the signing email (see the timeline). '
        . 'If a document were changed in any way, its fingerprint would no longer match. This electronic signature was made under the Electronic Communications Act 2000. '
        . 'Copies of the documents and this certificate were emailed to the signer when they signed. Times are UK time.', 8.5, false, $muted);
    return $pdf->output();
}

/** Send reminders for agreements still waiting to be signed (from the cron job). Returns how many were sent. */
function esign_send_reminders(): int
{
    $days = (int)(setting('esign_remind_days') ?? 3);
    if ($days <= 0) {
        return 0;
    }
    $n = 0;
    foreach (db_all("SELECT * FROM contracts WHERE status = 'sent' AND sign_token IS NOT NULL AND reminders_sent < 3
        AND COALESCE(last_reminded_at, sent_at) < NOW() - INTERVAL ? DAY", [$days]) as $c) {
        try {
            contract_send($c, true);
            $n++;
        } catch (IntegrationException $e) {
            error_log("Reminder for {$c['reference']} failed: " . $e->getMessage());
        }
    }
    return $n;
}
