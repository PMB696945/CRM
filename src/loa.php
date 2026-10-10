<?php
declare(strict_types=1);

/*
 * Letters of Authority (LoA) for porting numbers. Admins upload their own Word LoA under
 * Contract templates ("Letter of Authority") and type {{fields}} where the details go.
 * The requester's name, job title, email and signature are set once and filled in on every
 * letter; the date is the day the letter is created. Without a template, a plain letter is made.
 */

const LOA_SETTINGS = ['loa_provider_name', 'loa_provider_address', 'loa_provider_email', 'loa_signer_name', 'loa_signer_title', 'loa_signer_email'];

/** The uploaded LoA template, if there is one (the newest active). */
function loa_template(): ?array
{
    return db_one("SELECT * FROM contract_templates WHERE service_type = 'loa' AND active = 1 ORDER BY id DESC LIMIT 1");
}

/** The saved signature image for letters, if one has been uploaded. */
function loa_signature_file(): ?string
{
    $name = (string)setting('loa_signature');
    $file = $name !== '' ? storage_path('branding') . '/' . basename($name) : '';
    return $file !== '' && is_file($file) ? $file : null;
}

/** Fields for an LoA template, with descriptions (shown under Contract templates). */
function loa_merge_field_help(): array
{
    return [
        'customer_name' => 'Customer\'s company name', 'company_number' => 'Companies House number',
        'billing_address' => 'Customer\'s billing address (multi-line)', 'billing_line1' => 'Billing address: first line', 'billing_line2' => 'Billing address: second line',
        'billing_town' => 'Billing address: town', 'billing_county' => 'Billing address: county', 'billing_postcode' => 'Billing address: postcode',
        'site_address' => 'Site address the numbers are registered to (multi-line)', 'site_line1' => 'Site address: first line', 'site_line2' => 'Site address: second line',
        'site_town' => 'Site address: town', 'site_county' => 'Site address: county', 'site_postcode' => 'Site address: postcode',
        'numbers' => 'Numbers to be ported (one per line)', 'numbers_list' => 'Numbers to be ported (comma separated)', 'number_count' => 'How many numbers',
        'main_billing_number' => 'Main billing number (the first number)', 'current_provider' => 'Current provider',
        'new_provider_name' => 'New provider\'s name', 'new_provider_address' => 'New provider\'s address', 'new_provider_email' => 'New provider\'s contact email',
        'signer_name' => 'Requester\'s name', 'signer_title' => 'Requester\'s job title', 'signer_email' => 'Requester\'s email',
        'signature' => 'Requester\'s signature (image; put it on its own in the box)',
        'date' => 'Date created, e.g. 22nd September 2026', 'date_short' => 'Date created, e.g. 22/09/2026', 'valid_until' => 'Six months after the date',
    ];
}

/** A UK number spaced the usual way: 020 7946 0000, 0113 496 0000, 01242 234418, 07700 900123, 0800 123 4567. */
function uk_number_format(string $n): string
{
    if (strlen($n) !== 11 || !str_starts_with($n, '0')) {
        return $n;
    }
    if (str_starts_with($n, '02')) {
        return substr($n, 0, 3) . ' ' . substr($n, 3, 4) . ' ' . substr($n, 7);
    }
    if (preg_match('/^(01\d1|011\d|0[389]\d\d)/', $n)) {
        return substr($n, 0, 4) . ' ' . substr($n, 4, 3) . ' ' . substr($n, 7);
    }
    return substr($n, 0, 5) . ' ' . substr($n, 5);
}

/** An address as its parts. */
function loa_address_parts(?array $a): array
{
    $a ??= [];
    return ['line1' => (string)($a['address'] ?? ''), 'line2' => (string)($a['address2'] ?? ''), 'town' => (string)($a['city'] ?? ''),
        'county' => (string)($a['county'] ?? ''), 'postcode' => (string)($a['postcode'] ?? '')];
}

/** The values for a letter: the customer, the order's site and numbers, the requester. */
function loa_fields(array $account, array $details, ?int $time = null): array
{
    $time ??= time();
    $site = loa_address_parts(($details['type'] ?? '') === 'hosted_pbx' ? ($details['current_address'] ?? null) : ($details['address'] ?? null));
    $billing = loa_address_parts($account);
    $numbers = array_values($details['numbers'] ?? []);
    $pretty = array_map('uk_number_format', $numbers);
    $us = company('name', (string)config('app_name'));
    $fields = [
        'customer_name' => $account['name'], 'company_number' => (string)($account['company_number'] ?? ''),
        'billing_address' => implode("\n", array_filter($billing, fn($v) => trim($v) !== '')),
        'site_address' => implode("\n", array_filter($site, fn($v) => trim($v) !== '')),
        'numbers' => implode("\n", $pretty), 'numbers_list' => implode(', ', $pretty), 'number_count' => (string)count($numbers),
        'main_billing_number' => $pretty[0] ?? '', 'current_provider' => (string)($details['provider'] ?? ''),
        'new_provider_name' => (string)(setting('loa_provider_name') ?: $us),
        'new_provider_address' => (string)(setting('loa_provider_address') ?: company_address_line()),
        'new_provider_email' => (string)(setting('loa_provider_email') ?: company('email')),
        'signer_name' => (string)setting('loa_signer_name'), 'signer_title' => (string)setting('loa_signer_title'), 'signer_email' => (string)setting('loa_signer_email'),
        'date' => date('jS F Y', $time), 'date_short' => date('d/m/Y', $time), 'valid_until' => date('jS F Y', strtotime('+6 months', $time)),
    ];
    foreach ($billing as $k => $v) {
        $fields["billing_$k"] = $v;
    }
    foreach ($site as $k => $v) {
        $fields["site_$k"] = $v;
    }
    return $fields;
}

/** Make the letter as a Word document at $outPath: from the uploaded template, or a plain letter without one. */
function loa_generate(array $account, array $details, string $outPath, ?int $time = null): void
{
    $fields = loa_fields($account, $details, $time);
    if ($template = loa_template()) {
        docx_merge(template_file($template), $outPath, $fields, [], array_filter(['signature' => loa_signature_file()]));
        return;
    }
    $p = [
        ['Letter of Authority', 'Title'],
        'Date: ' . $fields['date'],
        'To: ' . ($fields['current_provider'] ?: 'the current provider'),
        "We, {$fields['customer_name']}" . ($fields['company_number'] ? " (company number {$fields['company_number']})" : '') . ', are the account holder for the telephone numbers below, '
            . 'registered at ' . str_replace("\n", ', ', $fields['site_address']) . '.',
        "We have chosen to move our telephone services to {$fields['new_provider_name']} and authorise them to act on our behalf to port these numbers, "
            . 'and you to give them any details needed for the port.',
        ['Numbers to be ported', 'Heading1'],
    ];
    foreach (explode("\n", $fields['numbers']) as $n) {
        $p[] = $n;
    }
    $p[] = ['Requester', 'Heading1'];
    foreach (['Name' => 'signer_name', 'Job title' => 'signer_title', 'Email' => 'signer_email'] as $label => $k) {
        if ($fields[$k] !== '') {
            $p[] = "$label: {$fields[$k]}";
        }
    }
    $p[] = 'Valid for 6 months from the date above.';
    docx_create($outPath, $p);
}

/** The file name for a customer's letter. */
function loa_file_name(array $account, array $details): string
{
    return 'Letter of Authority - ' . preg_replace('/[^A-Za-z0-9 &\'().-]+/', '', $account['name']) . (!empty($details['provider']) ? ' - ' . preg_replace('/[^A-Za-z0-9 &\'().-]+/', '', $details['provider']) : '') . '.docx';
}

/** Save the letter in the customer's files. Returns the document id. */
function loa_store(array $account, array $details): int
{
    $tmp = storage_path('tmp') . '/loa-' . bin2hex(random_bytes(6)) . '.docx';
    loa_generate($account, $details, $tmp);
    try {
        return document_store(['name' => loa_file_name($account, $details), 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)],
            ['account_id' => (int)$account['id']], 'Letter of Authority' . (!empty($details['provider']) ? ' – ' . $details['provider'] : ''),
            'Porting ' . count($details['numbers'] ?? []) . ' number(s): ' . implode(', ', $details['numbers'] ?? []), false);
    } finally {
        @unlink($tmp);
    }
}

/** Save the LoA settings and signature (Contract templates page). */
function loa_save_settings(array $post, ?array $signature): void
{
    $before = [];
    foreach (LOA_SETTINGS as $k) {
        $before[$k] = (string)setting($k);
        set_setting($k, trim((string)($post[$k] ?? '')) ?: null);
    }
    if (!empty($post['remove_signature'])) {
        if ($old = loa_signature_file()) {
            @unlink($old);
        }
        set_setting('loa_signature', null);
    }
    if ($signature && (int)$signature['error'] !== UPLOAD_ERR_NO_FILE) {
        $info = (int)$signature['error'] === UPLOAD_ERR_OK ? @getimagesize($signature['tmp_name']) : false;
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || $signature['size'] > 2 * 1024 * 1024) {
            throw new IntegrationException('The signature must be a PNG or JPEG image up to 2 MB. A PNG with a transparent background looks best.');
        }
        if ($old = loa_signature_file()) {
            @unlink($old);
        }
        $name = 'loa-signature-' . bin2hex(random_bytes(4)) . ($info[2] === IMAGETYPE_PNG ? '.png' : '.jpg');
        $dest = storage_path('branding') . '/' . $name;
        if (!(is_uploaded_file($signature['tmp_name']) ? move_uploaded_file($signature['tmp_name'], $dest) : copy($signature['tmp_name'], $dest))) {
            throw new IntegrationException('Couldn\'t save the signature. Check the storage folder is writable.');
        }
        set_setting('loa_signature', $name);
    }
    $changes = [];
    foreach (LOA_SETTINGS as $k) {
        if ($before[$k] !== (string)setting($k)) {
            $changes[$k] = ['from' => $before[$k], 'to' => (string)setting($k)];
        }
    }
    audit('settings', 'Letter of Authority details updated', null, null, null, $changes ?: null);
}

/** A starting template with every field in place, for admins to restyle. */
function loa_example_template(string $outPath): void
{
    docx_create($outPath, [
        ['Customer Letter of Authority (CLoA)', 'Title'],
        ['Current provider', 'Heading1'], 'Name: {{current_provider}}',
        ['New provider', 'Heading1'], 'Name: {{new_provider_name}}', 'Address: {{new_provider_address}}', 'Contact email: {{new_provider_email}}',
        ['Site address to register against numbers', 'Heading1'], '{{site_address}}',
        ['Numbers to be ported', 'Heading1'], '{{numbers}}', 'Main billing number: {{main_billing_number}}',
        ['Customer\'s company details', 'Heading1'], 'Company name: {{customer_name}}', 'Billing address: {{billing_address}}', 'Company registration no.: {{company_number}}',
        'This CLoA is to notify you that we have decided to move our telephony services to a new provider and require the numbers associated with those services to be ported to the new provider stated above. '
            . 'Our new provider is authorised to act on our behalf, and you have our authority to disclose to them any details they need for the port.',
        ['Requester\'s details', 'Heading1'], 'Signed: {{signature}}', 'Print name: {{signer_name}}', 'Job title: {{signer_title}}',
        'Date: {{date}}', 'Email: {{signer_email}}', 'This CLoA is valid for 6 months from the above date.',
    ]);
}
