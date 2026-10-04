<?php
declare(strict_types=1);

/* Quotes, contracts, contract templates, settings and Signable pages. */

function quotes_controller(): void
{
    $action = query('action', 'list');
    $id = query_int('id');
    if (in_array($action, ['list', 'export'], true)) {
        entity_controller('quotes');
        return;
    }
    $quote = $id ? db_one('SELECT * FROM quotes WHERE id = ?', [$id]) : null;
    if ($id && !$quote) {
        not_found('Quote not found.');
    }
    if ($quote) {
        $quote = quote_expire_if_due($quote);
    }
    $back = $quote ? url('quotes', ['action' => 'view', 'id' => $quote['id']]) : url('quotes');
    if ($action === 'pdf' && $quote) {
        $pdf = quote_pdf($quote);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Quote ' . $quote['reference'] . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit;
    }
    if ($action !== 'view' && !can('sales.edit')) {
        forbidden();
    }

    switch ($action) {
        case 'new':
        case 'edit':
            if ($quote && $quote['status'] !== 'draft') {
                flash('Only draft quotes can be edited. Use "Revise" to make changes to a sent quote.', 'error');
                redirect($back);
            }
            $values = $quote ?? ['account_id' => query_int('account_id'), 'title' => '', 'valid_until' => date('Y-m-d', strtotime('+' . (int)(setting('quote_validity_days') ?: 30) . ' days')),
                'intro' => '', 'opportunity_id' => query_int('opportunity_id')];
            $lines = $quote ? quote_lines((int)$quote['id']) : [];
            $errors = [];
            if (is_post()) {
                verify_csrf();
                [$data, $errors] = validate(entity('quotes'), $_POST);
                $errors += validate_scoped_refs(entity('quotes'), $data);
                [$lines, $lineErrors] = quote_parse_lines($_POST);
                if ($lineErrors) {
                    $errors['_lines'] = implode(' ', $lineErrors);
                }
                $values = $data + $values;
                if (!$errors) {
                    if ($quote) {
                        db_exec('UPDATE quotes SET account_id = ?, title = ?, valid_until = ?, opportunity_id = ?, intro = ? WHERE id = ?',
                            [$data['account_id'], $data['title'], $data['valid_until'], $data['opportunity_id'], $data['intro'], $quote['id']]);
                        $qid = (int)$quote['id'];
                    } else {
                        db_exec('INSERT INTO quotes (account_id, title, valid_until, opportunity_id, intro, created_by) VALUES (?, ?, ?, ?, ?, ?)',
                            [$data['account_id'], $data['title'], $data['valid_until'], $data['opportunity_id'], $data['intro'], current_user()['id']]);
                        $qid = (int)db()->lastInsertId();
                        db_exec('UPDATE quotes SET reference = ? WHERE id = ?', [sprintf('Q-%06d', $qid), $qid]);
                    }
                    quote_save_lines($qid, $lines);
                    flash('Quote saved.');
                    redirect(url('quotes', ['action' => 'view', 'id' => $qid]));
                }
            }
            // Quotes work in monthly amounts; a one-off product is a one-off charge with no term.
            $products = array_map(fn($p) => $p['billing_frequency'] === 'one_off'
                ? ['monthly_price' => 0, 'setup_fee' => round((float)$p['monthly_price'] + (float)$p['setup_fee'], 2), 'term_months' => 0] + $p
                : ['monthly_price' => monthly_equivalent($p['monthly_price'], $p['billing_frequency'])] + $p,
                db_all('SELECT id, name, category, monthly_price, billing_frequency, setup_fee, term_months FROM products WHERE active = 1 ORDER BY name'));
            page('quote_form', compact('quote', 'values', 'lines', 'errors', 'products'), $quote ? 'Edit quote' : 'New quote');
            return;

        case 'view':
            $account = db_one('SELECT * FROM accounts WHERE id = ?', [$quote['account_id']]);
            $lines = quote_lines((int)$quote['id']);
            $contracts = db_all('SELECT * FROM contracts WHERE quote_id = ? ORDER BY id DESC', [$quote['id']]);
            $recipients = quote_recipients($account);
            $library = library_documents();
            $customerFiles = account_documents((int)$account['id']);
            $picked = array_map('intval', array_column(quote_documents((int)$quote['id']), 'id'));
            $order = db_one('SELECT * FROM customer_orders WHERE quote_id = ?', [$quote['id']]);
            page('quote', compact('order', 'quote', 'account', 'lines', 'contracts', 'recipients', 'library', 'customerFiles', 'picked'), $quote['reference'] . ' ' . $quote['title']);
            return;
    }

    // Everything below changes state.
    if (!is_post() || !$quote) {
        redirect($back);
    }
    verify_csrf();
    audit('quote_' . $action, "Quote {$quote['reference']}: $action", 'quotes', (int)$quote['id']);
    try {
        switch ($action) {
            case 'send':
                if (!in_array($quote['status'], ['draft', 'sent', 'expired'], true)) {
                    throw new IntegrationException('This quote has already been ' . $quote['status'] . '.');
                }
                $email = trim((string)($_POST['recipient_email'] ?? ''));
                $name = trim((string)($_POST['recipient_name'] ?? ''));
                if ($name === '') {
                    throw new IntegrationException('Enter the recipient\'s name.');
                }
                if ($quote['status'] === 'expired') {
                    $quote['valid_until'] = null; // fresh validity period
                }
                $docs = quote_set_documents($quote, is_array($_POST['documents'] ?? null) ? $_POST['documents'] : []);
                quote_send($quote, $email, $name, $docs);
                flash("Quote emailed to $name" . ($docs ? ' with ' . count($docs) . ' document' . (count($docs) === 1 ? '' : 's') . ' attached' : '') . ". You'll get an email when they respond.");
                break;
            case 'revise':
                db_exec("UPDATE quotes SET status = 'draft', token_hash = NULL WHERE id = ? AND status IN ('sent','expired','declined')", [$quote['id']]);
                flash('The quote is back in draft. The old link no longer works; send it again when you\'re ready.');
                break;
            case 'accept':
                if (!in_array($quote['status'], ['draft', 'sent', 'expired'], true)) {
                    throw new IntegrationException('This quote has already been ' . $quote['status'] . '.');
                }
                $name = trim((string)($_POST['accepted_by'] ?? '')) ?: ($quote['recipient_name'] ?: 'Customer');
                $email = trim((string)($_POST['accepted_email'] ?? '')) ?: $quote['recipient_email'];
                $confirm = !empty($_POST['send_confirmation']);
                $contract = quote_accept($quote, $name, '', true, $email, '', $confirm);
                $sent = (string)db_value('SELECT confirmation_sent_at FROM quotes WHERE id = ?', [$quote['id']]);
                flash('Quote marked as accepted.' . ($confirm && $sent ? " Confirmation with the quote PDF emailed to $email." : '')
                    . ($contract ? " Contract {$contract['reference']} " . ($contract['status'] === 'sent' ? 'sent for signature.' : 'created as a draft.') : ''));
                break;
            case 'confirmation':
                if ($quote['status'] !== 'accepted') {
                    throw new IntegrationException('Only accepted quotes have a confirmation to send.');
                }
                $to = quote_send_confirmation($quote);
                flash($to ? "Confirmation with the quote PDF emailed to $to." : 'There\'s no email address for whoever accepted it, so nothing was sent. The PDF is in the customer\'s files.', $to ? 'success' : 'error');
                break;
            case 'decline':
                quote_decline($quote, trim((string)($_POST['reason'] ?? '')), 'recorded by ' . current_user()['name']);
                flash('Quote marked as declined.');
                break;
            case 'cancel':
                db_exec("UPDATE quotes SET status = 'cancelled', token_hash = NULL WHERE id = ?", [$quote['id']]);
                flash('Quote cancelled. Its link no longer works.');
                break;
            case 'contract':
                $contract = contract_create_from_quote($quote);
                flash("Contract {$contract['reference']} created.");
                redirect(url('contracts', ['action' => 'view', 'id' => $contract['id']]));
            case 'duplicate':
                db_exec('INSERT INTO quotes (account_id, title, valid_until, opportunity_id, intro, created_by) VALUES (?, ?, ?, ?, ?, ?)',
                    [$quote['account_id'], $quote['title'], date('Y-m-d', strtotime('+' . (int)(setting('quote_validity_days') ?: 30) . ' days')), $quote['opportunity_id'], $quote['intro'], current_user()['id']]);
                $qid = (int)db()->lastInsertId();
                db_exec('UPDATE quotes SET reference = ? WHERE id = ?', [sprintf('Q-%06d', $qid), $qid]);
                quote_save_lines($qid, quote_lines((int)$quote['id']));
                db_exec('INSERT INTO quote_documents (quote_id, document_id) SELECT ?, document_id FROM quote_documents WHERE quote_id = ?', [$qid, $quote['id']]);
                flash('Copy created.');
                redirect(url('quotes', ['action' => 'view', 'id' => $qid]));
            case 'delete':
                if ($quote['status'] !== 'draft') {
                    throw new IntegrationException('Only draft quotes can be deleted. Cancel it instead.');
                }
                db_exec('DELETE FROM quotes WHERE id = ?', [$quote['id']]);
                flash('Quote deleted.');
                redirect(url('accounts', ['action' => 'view', 'id' => $quote['account_id']]));
            default:
                not_found();
        }
    } catch (IntegrationException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect($back);
}

/** People a quote can be sent to: the customer's contacts, then their dealer's. */
function quote_recipients(array $account): array
{
    $list = [];
    foreach (db_all('SELECT name, email, is_billing, is_primary FROM contacts WHERE account_id = ? AND email IS NOT NULL ORDER BY is_billing DESC, is_primary DESC, name', [$account['id']]) as $c) {
        $list[] = ['name' => $c['name'], 'email' => $c['email'], 'label' => $c['name'] . ($c['is_billing'] ? ' (billing)' : ($c['is_primary'] ? ' (primary)' : ''))];
    }
    if ($account['parent_id']) {
        $dealer = db_one('SELECT name FROM accounts WHERE id = ?', [$account['parent_id']]);
        foreach (db_all('SELECT name, email FROM contacts WHERE account_id = ? AND email IS NOT NULL ORDER BY is_billing DESC, is_primary DESC', [$account['parent_id']]) as $c) {
            $list[] = ['name' => $c['name'], 'email' => $c['email'], 'label' => $c['name'] . ' – dealer ' . $dealer['name']];
        }
    }
    return $list;
}

function contracts_controller(): void
{
    $action = query('action', 'list');
    $id = query_int('id');
    if (in_array($action, ['list', 'export'], true)) {
        entity_controller('contracts');
        return;
    }
    $contract = $id ? db_one('SELECT * FROM contracts WHERE id = ?', [$id]) : null;
    if ($id && !$contract) {
        not_found('Contract not found.');
    }
    $back = $contract ? url('contracts', ['action' => 'view', 'id' => $contract['id']]) : url('contracts');

    if ($action === 'view') {
        $account = db_one('SELECT * FROM accounts WHERE id = ?', [$contract['account_id']]);
        $quote = $contract['quote_id'] ? db_one('SELECT * FROM quotes WHERE id = ?', [$contract['quote_id']]) : null;
        page('contract', compact('contract', 'account', 'quote'), $contract['reference'] . ' ' . $contract['title']);
        return;
    }

    if ($action !== 'download' && !can('sales.edit')) {
        forbidden();
    }

    if ($action === 'download') {
        $file = query('file');
        $names = array_column(contract_documents($contract), 'file');
        if ($contract['signed_file']) {
            $names[] = $contract['signed_file'];
        }
        if (!in_array($file, $names, true) || !is_file($path = storage_path('contracts') . '/' . basename($file))) {
            not_found('File not found.');
        }
        audit('download', "Downloaded a document from contract {$contract['reference']}", 'contracts', (int)$contract['id']);
        $title = array_column(contract_documents($contract), 'title', 'file')[$file] ?? 'signed';
        send_download($path, $contract['reference'] . ' ' . preg_replace('/[^A-Za-z0-9 _-]/', '', $title) . (str_ends_with($file, '.pdf') ? '.pdf' : '.docx'));
    }

    if ($action === 'new') {
        // A contract without a quote, e.g. a dealer's master services agreement.
        $account = db_one('SELECT * FROM accounts WHERE id = ?', [query_int('account_id') ?? (int)($_POST['account_id'] ?? 0)]);
        if (!$account) {
            not_found('Choose a customer first.');
        }
        $templates = db_all('SELECT * FROM contract_templates WHERE active = 1 ORDER BY name');
        $contacts = quote_recipients($account);
        $errors = [];
        $values = ['template_id' => '', 'title' => $account['is_dealer'] ? 'Master services agreement' : 'Service agreement',
            'kind' => $account['is_dealer'] ? 'msa' : 'services', 'signer_name' => $contacts[0]['name'] ?? '', 'signer_email' => $contacts[0]['email'] ?? ''];
        if (is_post()) {
            verify_csrf();
            $values = array_map(fn($v) => trim((string)$v), array_intersect_key($_POST, $values)) + $values;
            $template = db_one('SELECT * FROM contract_templates WHERE id = ? AND active = 1', [(int)$values['template_id']]);
            if (!$template) {
                $errors[] = 'Choose a template.';
            }
            if ($values['title'] === '') {
                $errors[] = 'Enter a title.';
            }
            if ($values['signer_name'] === '' || !filter_var($values['signer_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter the signer\'s name and a valid email.';
            }
            if (!$errors) {
                try {
                    // Include the customer's current services in {{services_table}}.
                    $lines = array_map(fn($s) => ['description' => $s['identifier'], 'service_type' => $s['service_type'], 'quantity' => 1,
                        'monthly_price' => $s['monthly_price'], 'setup_fee' => 0, 'term_months' => (int)$s['term_months']],
                        db_all("SELECT * FROM services WHERE account_id = ? AND status IN ('active','pending')", [$account['id']]));
                    $contract = contract_generate($account, [[$template, $lines]], $values['title'], $values['kind'] === 'msa' ? 'msa' : 'services',
                        $values['signer_name'], $values['signer_email']);
                    audit('contract_create', "Contract {$contract['reference']} created", 'contracts', (int)$contract['id']);
                    flash("Contract {$contract['reference']} created. Check the document, then send it for signature.");
                    redirect(url('contracts', ['action' => 'view', 'id' => $contract['id']]));
                } catch (IntegrationException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
        page('contract_form', compact('account', 'templates', 'contacts', 'values', 'errors'), 'New contract');
        return;
    }

    if (!is_post() || !$contract) {
        redirect($back);
    }
    verify_csrf();
    audit('contract_' . $action, "Contract {$contract['reference']}: $action", 'contracts', (int)$contract['id']);
    try {
        switch ($action) {
            case 'send':
                $name = trim((string)($_POST['signer_name'] ?? ''));
                $email = trim((string)($_POST['signer_email'] ?? ''));
                if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    db_exec('UPDATE contracts SET signer_name = ?, signer_email = ? WHERE id = ?', [$name, $email, $contract['id']]);
                    $contract = db_one('SELECT * FROM contracts WHERE id = ?', [$contract['id']]);
                }
                if ($contract['status'] === 'failed') {
                    db_exec("UPDATE contracts SET status = 'draft' WHERE id = ?", [$contract['id']]);
                    $contract['status'] = 'draft';
                }
                contract_send($contract);
                flash('Contract sent for signature via Signable.');
                break;
            case 'check':
                $after = contract_sync($contract);
                flash($after['status'] === $contract['status'] ? 'Still awaiting signature.' : 'Contract is now ' . $after['status'] . '.');
                break;
            case 'remind':
                signable_request('PUT', 'envelopes/' . rawurlencode((string)$contract['signable_fingerprint']) . '/remind');
                flash('Reminder sent to ' . $contract['signer_name'] . '.');
                break;
            case 'cancel':
                contract_cancel($contract);
                flash('Contract cancelled.');
                break;
            case 'services':
                $n = contract_create_services($contract);
                flash("$n pending service" . ($n === 1 ? '' : 's') . ' created. Fill in numbers/circuit IDs as they\'re provisioned.');
                redirect(url('accounts', ['action' => 'view', 'id' => $contract['account_id']]));
            default:
                not_found();
        }
    } catch (IntegrationException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect($back);
}

function send_download(string $path, string $name): never
{
    $types = ['pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    header('Content-Type: ' . ($types[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function contract_templates_controller(): void
{
    require_permission('settings.manage');
    $action = query('action', 'list');
    $id = query_int('id');

    if ($action === 'example') {
        $path = storage_path('tmp') . '/example-' . bin2hex(random_bytes(4)) . '.docx';
        docx_example_template($path);
        register_shutdown_function(fn() => @unlink($path));
        send_download($path, 'Example contract template.docx');
    }
    if ($action === 'download' && $id) {
        $t = db_one('SELECT * FROM contract_templates WHERE id = ?', [$id]) ?? not_found();
        send_download(template_file($t), $t['file_name']);
    }
    if (is_post()) {
        verify_csrf();
        if ($action === 'upload') {
            try {
                $name = trim((string)($_POST['name'] ?? ''));
                $type = (string)($_POST['service_type'] ?? '');
                $file = $_FILES['file'] ?? null;
                if ($name === '' || !isset(contract_template_types()[$type])) {
                    throw new IntegrationException('Enter a name and choose what the template is for.');
                }
                if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                    throw new IntegrationException('Choose a .docx file to upload' . ($file && $file['error'] === UPLOAD_ERR_INI_SIZE ? ' (that file is larger than the server allows)' : '') . '.');
                }
                if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'docx' || $file['size'] > 10 * 1024 * 1024) {
                    throw new IntegrationException('Templates must be Word .docx files up to 10 MB.');
                }
                if ($problem = docx_validate($file['tmp_name'])) {
                    throw new IntegrationException($problem);
                }
                $stored = bin2hex(random_bytes(12)) . '.docx';
                if (!move_uploaded_file($file['tmp_name'], storage_path('templates') . '/' . $stored)) {
                    throw new IntegrationException('Couldn\'t save the file. Check the CRM folder is writable.');
                }
                db_exec('INSERT INTO contract_templates (name, service_type, file_name, stored_name, uploaded_by, notes) VALUES (?, ?, ?, ?, ?, ?)',
                    [$name, $type, mb_substr(basename($file['name']), 0, 255), $stored, current_user()['id'], trim((string)($_POST['notes'] ?? '')) ?: null]);
                audit('template_upload', "Contract template \"$name\" uploaded ($type)", 'contract_templates', (int)db()->lastInsertId());
                $fields = docx_placeholders(storage_path('templates') . '/' . $stored);
                $unknown = array_diff($fields, array_keys(contract_merge_field_help()));
                flash('Template uploaded' . ($fields ? ' with ' . count($fields) . ' merge field(s)' : ' (no {{merge_fields}} found)') . '.'
                    . ($unknown ? ' Unrecognised fields left as-is: {{' . implode('}}, {{', $unknown) . '}}.' : ''));
            } catch (IntegrationException $e) {
                flash($e->getMessage(), 'error');
            }
        } elseif ($action === 'toggle' && $id) {
            db_exec('UPDATE contract_templates SET active = 1 - active WHERE id = ?', [$id]);
            flash('Template updated.');
        } elseif ($action === 'delete' && $id) {
            $t = db_one('SELECT * FROM contract_templates WHERE id = ?', [$id]);
            if ($t) {
                @unlink(template_file($t));
                db_exec('DELETE FROM contract_templates WHERE id = ?', [$id]);
                audit('template_delete', "Contract template \"{$t['name']}\" deleted", 'contract_templates', $id);
                flash('Template deleted.');
            }
        }
        redirect(url('contract_templates'));
    }

    $templates = db_all('SELECT t.*, u.name AS uploaded_by_name FROM contract_templates t LEFT JOIN users u ON u.id = t.uploaded_by ORDER BY t.service_type, t.active DESC, t.id DESC');
    page('contract_templates', ['templates' => $templates], 'Contract templates');
}

/** Merge fields available in templates, with descriptions. */
function contract_merge_field_help(): array
{
    return [
        'customer_name' => 'Customer\'s name', 'account_number' => 'CRM account number', 'company_number' => 'Companies House number',
        'customer_address' => 'Customer\'s address (multi-line)', 'customer_email' => 'Customer\'s email', 'customer_phone' => 'Customer\'s phone',
        'contact_name' => 'Billing/primary contact', 'contact_email' => 'Contact\'s email', 'signer_name' => 'Person signing',
        'dealer_name' => 'Their dealer (if any)', 'msa_reference' => 'Dealer\'s signed MSA reference', 'msa_date' => 'Date the MSA was signed',
        'quote_reference' => 'Quote reference', 'quote_title' => 'Quote title', 'contract_reference' => 'Contract reference', 'date' => 'Today\'s date',
        'services_table' => 'Table of services (put on its own line)', 'monthly_total' => 'Total monthly charges', 'setup_total' => 'Total one-off charges',
        'contract_value' => 'Total contract value', 'term_months' => 'Longest term in months', 'term' => 'Longest term in words, e.g. 36 months or 30 days',
        'our_company_name' => 'Your company name', 'our_company_address' => 'Your address', 'our_company_number' => 'Your company number',
    ];
}

function settings_controller(): void
{
    require_permission('settings.manage');
    $keys = ['company_name', 'company_address', 'company_number', 'company_phone', 'company_email', 'app_url',
        'mail_from_email', 'mail_from_name', 'mail_reply_to', 'mail_transport', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username',
        'quote_validity_days', 'quote_terms', 'contracts_auto_on_accept', 'session_idle_minutes', 'require_2fa', 'force_https',
        'marketing_topics', 'campaign_batch_size',
        'invoice_reader', 'invoice_model', 'invoice_tolerance', 'invoice_alert_email',
        'order_group_id', 'order_message_processing', 'order_message_confirmed', 'order_message_completed', 'order_message_cancelled'];
    $before = array_combine($keys, array_map(fn($k) => (string)setting($k), $keys));
    if (is_post()) {
        verify_csrf();
        if (query('action') === 'branding') {
            if (!empty($_POST['remove_logo'])) {
                foreach (glob(storage_path('branding') . '/logo.*') ?: [] as $old) {
                    @unlink($old);
                }
                set_setting('brand_logo', null);
                audit('settings', 'Logo removed');
                flash('Logo removed.');
                redirect(url('settings'));
            }
            $colour = trim((string)($_POST['brand_colour'] ?? ''));
            if ($colour !== '' && !preg_match('/^#[0-9a-f]{6}$/i', $colour)) {
                flash('The brand colour should look like #465FFF.', 'error');
                redirect(url('settings'));
            }
            set_setting('brand_colour', $colour === '' ? null : strtoupper($colour));
            if (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($problem = brand_save_logo($_FILES['logo'])) {
                    flash($problem, 'error');
                    redirect(url('settings'));
                }
                audit('settings', 'Logo uploaded');
            }
            flash('Branding saved.');
            redirect(url('settings'));
        }
        if (query('action') === 'test_email') {
            try {
                $me = current_user();
                send_mail($me['email'], $me['name'], 'Test email from ' . config('app_name'), email_layout('Email is working', '<p>This test email was sent from the CRM\'s Settings page.</p>'));
                flash('Test email sent to ' . $me['email'] . '. Check your inbox (and spam folder).');
            } catch (IntegrationException $e) {
                flash('Test email failed: ' . $e->getMessage(), 'error');
            }
            redirect(url('settings'));
        }
        foreach ($keys as $key) {
            $value = trim((string)($_POST[$key] ?? ''));
            if ($key === 'company_address') {
                // One tidy line per line typed: no carriage returns, stray spaces or blank lines.
                $value = implode("\n", array_filter(array_map('trim', preg_split('/\R/u', $value)), fn($l) => $l !== ''));
            }
            if (in_array($key, ['contracts_auto_on_accept', 'require_2fa', 'force_https'], true)) {
                $value = empty($_POST[$key]) ? '0' : '1';
            }
            if ($key === 'force_https' && $value === '1' && !is_https()) {
                $value = '0'; // can't be switched on over plain HTTP, or you'd be locked out
            }
            if ($key === 'require_2fa' && $value === '1' && !current_user()['totp_enabled']) {
                $value = '0'; // set it up yourself first
                flash('Set up two-factor sign-in on your own profile before requiring it for everyone.', 'error');
            }
            if ($key === 'invoice_reader' && !in_array($value, ['builtin', 'claude'], true)) {
                $value = 'builtin';
            }
            if ($key === 'invoice_tolerance') {
                $value = is_numeric($value) ? (string)round(max(0, (float)$value), 2) : '';
            }
            if ($key === 'invoice_alert_email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $value = '';
            }
            if ($key === 'invoice_model' && !preg_match('/^[a-z0-9.\-]{3,60}$/', $value)) {
                $value = '';
            }
            if ($key === 'order_group_id' && !($value !== '' && ctype_digit($value) && db_value('SELECT 1 FROM ticket_groups WHERE id = ?', [$value]))) {
                $value = '';
            }
            if (str_starts_with($key, 'order_message_') && $value === ORDER_DEFAULT_MESSAGES[substr($key, 14)]) {
                $value = ''; // unchanged from the default
            }
            if ($key === 'campaign_batch_size') {
                $value = ctype_digit($value) ? (string)max(5, min(500, (int)$value)) : '';
            }
            if ($key === 'mail_transport' && !in_array($value, ['php', 'smtp', 'mandrill'], true)) {
                $value = 'php';
            }
            if ($key === 'session_idle_minutes') {
                $value = ctype_digit($value) ? (string)max(5, min(720, (int)$value)) : '';
            }
            set_setting($key, $value === '' ? null : $value);
        }
        foreach (['smtp_password', 'mandrill_api_key', 'anthropic_api_key'] as $secret) {
            if (($pw = trim((string)($_POST[$secret] ?? ''))) !== '') {
                set_setting($secret, $pw);
                $before[$secret] = '';
            }
        }
        foreach (['mail_from_email', 'mail_reply_to', 'company_email'] as $k) {
            if (setting($k) && !filter_var(setting($k), FILTER_VALIDATE_EMAIL)) {
                flash('Settings saved, but "' . setting($k) . '" isn\'t a valid email address.', 'error');
                redirect(url('settings'));
            }
        }
        $changes = [];
        foreach ($before as $key => $old) {
            $new = in_array($key, ['smtp_password', 'mandrill_api_key'], true) ? '(changed)' : (string)setting($key);
            if (in_array($key, ['smtp_password', 'mandrill_api_key'], true) || $new !== $old) {
                $changes[humanize($key)] = ['from' => in_array($key, ['smtp_password', 'mandrill_api_key'], true) ? '' : $old, 'to' => $new];
            }
        }
        audit('settings', 'Settings saved' . ($changes ? ': ' . implode(', ', array_keys($changes)) : ' (no changes)'), null, null, null, $changes ?: null);
        if (empty($_SESSION['flash'])) {
            flash('Settings saved.');
        }
        redirect(url('settings'));
    }
    page('settings', ['detectedUrl' => detected_app_url()], 'Settings');
}

function signable_controller(): void
{
    require_permission('settings.manage');
    $action = query('action');
    if (is_post()) {
        verify_csrf();
        try {
            if ($action === 'save') {
                if (($key = trim((string)($_POST['api_key'] ?? ''))) !== '') {
                    set_setting('signable_api_key', $key);
                }
                set_setting('signable_auto_send', empty($_POST['auto_send']) ? '0' : '1');
                set_setting('signable_remind_hours', ctype_digit((string)($_POST['remind_hours'] ?? '')) ? $_POST['remind_hours'] : null);
                set_setting('signable_redirect_url', trim((string)($_POST['redirect_url'] ?? '')) ?: null);
                set_setting('signable_message', trim((string)($_POST['message'] ?? '')) ?: null);
                if (!setting('signable_webhook_secret')) {
                    set_setting('signable_webhook_secret', bin2hex(random_bytes(16)));
                }
                signable_request('GET', 'envelopes', ['offset' => 0, 'limit' => 1]); // test the key
                audit('settings', 'Signable settings saved');
                flash('Signable connected.');
            } elseif ($action === 'webhook') {
                signable_request('POST', 'webhooks', ['webhook_type' => 'signed-envelope', 'webhook_url' => signable_webhook_url()]);
                set_setting('signable_webhook_registered', date('Y-m-d H:i:s'));
                flash('Webhook added in Signable: signed contracts will update here straight away.');
            } elseif ($action === 'sync') {
                $r = contracts_sync_open();
                flash("Checked {$r['checked']} contract(s) awaiting signature; {$r['changed']} changed.");
            }
        } catch (IntegrationException $e) {
            flash($e->getMessage(), 'error');
        }
        redirect(url('signable'));
    }
    page('signable', ['webhookUrl' => signable_configured() ? signable_webhook_url() : null], 'Signable');
}

function signable_webhook_url(): string
{
    if (!setting('signable_webhook_secret')) {
        set_setting('signable_webhook_secret', bin2hex(random_bytes(16)));
    }
    return app_url() . '/signable-webhook.php?key=' . setting('signable_webhook_secret');
}
