<?php
declare(strict_types=1);

/*
 * Service alerts and marketing emails, targeted by the services customers have.
 *
 * Service alerts go to the main contact (and the site contact where a site is
 * affected) of every customer with matching live services, unless they have
 * opted out of alerts. Marketing only goes to contacts who have opted in to
 * email marketing (and to the chosen topic). Every email has an unsubscribe link.
 */

const CAMPAIGN_KINDS = ['service_alert' => 'Service alert', 'marketing' => 'Marketing'];
const ALERT_AUDIENCES = [
    'main_site' => 'Main contact, plus the site contact for affected sites',
    'main'      => 'Main contact only',
    'all'       => 'Everyone at the customer who gets service alerts',
];

require_once __DIR__ . '/mailchimp.php';

/** Clean up filters posted from the campaign form. */
function campaign_filters(array $in, string $kind): array
{
    $list = fn($key, array $allowed) => array_values(array_intersect(array_map('strval', $allowed), array_map('strval', is_array($in[$key] ?? null) ? $in[$key] : [])));
    $f = [
        'service_types' => $list('service_types', array_keys(SERVICE_TYPES)),
        'carriers'      => $list('carriers', CARRIERS),
        'product_ids'   => array_map('intval', $list('product_ids', array_column(db_all('SELECT id FROM products'), 'id'))),
        'postcodes'     => implode(', ', array_filter(array_map(fn($p) => strtoupper(trim($p)), preg_split('/[,\s]+/', (string)($in['postcodes'] ?? ''))))),
        'statuses'      => $list('statuses', ['active', 'suspended', 'prospect']) ?: ['active'],
        'account_type'  => in_array($in['account_type'] ?? '', ['business', 'residential'], true) ? $in['account_type'] : '',
        'dealer_id'     => (int)($in['dealer_id'] ?? 0) ?: null,
        'account_id'    => (int)($in['account_id'] ?? 0) ?: null,
    ];
    if ($kind === 'marketing') {
        $f['topic'] = isset(marketing_topics()[$in['topic'] ?? '']) ? $in['topic'] : '';
    } else {
        $f['who'] = isset(ALERT_AUDIENCES[$in['who'] ?? '']) ? $in['who'] : 'main_site';
    }
    return $f;
}

function campaign_has_service_filter(array $f): bool
{
    return $f['service_types'] || $f['carriers'] || $f['product_ids'] || $f['postcodes'] !== '';
}

/**
 * Who a campaign goes to. Returns a list of
 * ['email', 'name', 'contact_id', 'account_id', 'account_name', 'account_number', 'services' => [...]].
 */
function campaign_audience(string $kind, array $f): array
{
    $where = [];
    $params = [];
    $marks = fn(array $v) => implode(',', array_fill(0, count($v), '?'));
    $where[] = 'a.status IN (' . $marks($f['statuses']) . ')';
    array_push($params, ...$f['statuses']);
    if ($f['account_type']) {
        $where[] = 'a.type = ?';
        $params[] = $f['account_type'];
    }
    if ($f['dealer_id']) {
        $where[] = '(a.parent_id = ? OR a.id = ?)';
        array_push($params, $f['dealer_id'], $f['dealer_id']);
    }
    if ($f['account_id']) {
        $where[] = 'a.id = ?';
        $params[] = $f['account_id'];
    }

    // Accounts with matching live services (always required for service alerts).
    $services = [];
    if ($kind === 'service_alert' || campaign_has_service_filter($f)) {
        $sw = $where;
        $sp = $params;
        $sw[] = "s.status = 'active'";
        if ($f['service_types']) {
            $sw[] = 's.service_type IN (' . $marks($f['service_types']) . ')';
            array_push($sp, ...$f['service_types']);
        }
        if ($f['carriers']) {
            $sw[] = 's.carrier IN (' . $marks($f['carriers']) . ')';
            array_push($sp, ...$f['carriers']);
        }
        if ($f['product_ids']) {
            $sw[] = 's.product_id IN (' . $marks($f['product_ids']) . ')';
            array_push($sp, ...$f['product_ids']);
        }
        if ($f['postcodes'] !== '') {
            // Matches the site's postcode, or head office when the service has no site.
            $likes = [];
            foreach (explode(', ', $f['postcodes']) as $p) {
                $likes[] = 'REPLACE(COALESCE(st.postcode, a.postcode), \' \', \'\') LIKE ?';
                $sp[] = addcslashes(str_replace(' ', '', $p), '%_\\') . '%';
            }
            $sw[] = '(' . implode(' OR ', $likes) . ')';
        }
        $rows = db_all('SELECT s.id, s.account_id, s.identifier, s.service_type, s.site_id, st.name AS site_name, st.contact_id AS site_contact_id
            FROM services s JOIN accounts a ON a.id = s.account_id LEFT JOIN sites st ON st.id = s.site_id
            WHERE ' . implode(' AND ', $sw) . ' ORDER BY s.identifier', $sp);
        foreach ($rows as $r) {
            $services[(int)$r['account_id']][] = $r;
        }
        $accountIds = array_keys($services);
    } else {
        $accountIds = array_map('intval', array_column(db_all('SELECT a.id FROM accounts a WHERE ' . implode(' AND ', $where), $params), 'id'));
    }
    if (!$accountIds) {
        return [];
    }

    $accounts = [];
    foreach (array_chunk($accountIds, 500) as $chunk) {
        foreach (db_all('SELECT id, name, account_number, main_contact_id FROM accounts WHERE id IN (' . implode(',', $chunk) . ')') as $a) {
            $accounts[(int)$a['id']] = $a;
        }
    }

    $out = [];
    foreach (array_chunk($accountIds, 500) as $chunk) {
        $contacts = db_all("SELECT * FROM contacts WHERE account_id IN (" . implode(',', $chunk) . ") AND email IS NOT NULL AND email <> '' ORDER BY is_primary DESC, id");
        foreach ($contacts as $c) {
            $aid = (int)$c['account_id'];
            $a = $accounts[$aid];
            if ($kind === 'marketing') {
                if (!$c['marketing_email']) {
                    continue;
                }
                $topics = array_filter(explode(',', (string)$c['marketing_topics']));
                if (!empty($f['topic']) && $topics && !in_array($f['topic'], $topics, true)) {
                    continue;
                }
            } else {
                if (!$c['service_alerts']) {
                    continue;
                }
                $isMain = $a['main_contact_id'] ? (int)$a['main_contact_id'] === (int)$c['id'] : (bool)$c['is_primary'];
                $siteContacts = array_map('intval', array_filter(array_column($services[$aid] ?? [], 'site_contact_id')));
                $include = match ($f['who']) {
                    'all'  => true,
                    'main' => $isMain,
                    default => $isMain || in_array((int)$c['id'], $siteContacts, true),
                };
                if (!$include) {
                    continue;
                }
            }
            $key = strtolower($c['email']);
            if (isset($out[$key])) {
                continue;
            }
            $out[$key] = [
                'email' => $key, 'name' => $c['name'], 'contact_id' => (int)$c['id'], 'account_id' => $aid,
                'account_name' => $a['name'], 'account_number' => $a['account_number'],
                'services' => $services[$aid] ?? [],
            ];
        }
    }
    return array_values($out);
}

/** Customers matched (for service alerts) that nobody at would receive the email. */
function campaign_unreachable(string $kind, array $f, array $audience): array
{
    if ($kind !== 'service_alert') {
        return [];
    }
    $reached = array_flip(array_column($audience, 'account_id'));
    $all = [];
    foreach (campaign_audience_accounts($f) as $id => $name) {
        if (!isset($reached[$id])) {
            $all[$id] = $name;
        }
    }
    return $all;
}

/** Customers with matching live services, whether or not they have a contact to email. */
function campaign_audience_accounts(array $f): array
{
    $copy = $f;
    $copy['who'] = 'all';
    $ids = [];
    // Reuse the audience query with a fake "contact" per account by listing services directly.
    $rows = campaign_services_for($copy);
    foreach ($rows as $r) {
        $ids[(int)$r['account_id']] = $r['account_name'];
    }
    return $ids;
}

function campaign_services_for(array $f): array
{
    $where = ["s.status = 'active'"];
    $params = [];
    $marks = fn(array $v) => implode(',', array_fill(0, count($v), '?'));
    $where[] = 'a.status IN (' . $marks($f['statuses']) . ')';
    array_push($params, ...$f['statuses']);
    foreach (['service_types' => 's.service_type', 'carriers' => 's.carrier', 'product_ids' => 's.product_id'] as $key => $col) {
        if ($f[$key]) {
            $where[] = "$col IN (" . $marks($f[$key]) . ')';
            array_push($params, ...$f[$key]);
        }
    }
    if ($f['account_type']) {
        $where[] = 'a.type = ?';
        $params[] = $f['account_type'];
    }
    if ($f['dealer_id']) {
        $where[] = '(a.parent_id = ? OR a.id = ?)';
        array_push($params, $f['dealer_id'], $f['dealer_id']);
    }
    if ($f['account_id']) {
        $where[] = 'a.id = ?';
        $params[] = $f['account_id'];
    }
    if ($f['postcodes'] !== '') {
        $likes = [];
        foreach (explode(', ', $f['postcodes']) as $p) {
            $likes[] = "REPLACE(COALESCE(st.postcode, a.postcode), ' ', '') LIKE ?";
            $params[] = addcslashes(str_replace(' ', '', $p), '%_\\') . '%';
        }
        $where[] = '(' . implode(' OR ', $likes) . ')';
    }
    return db_all('SELECT s.account_id, a.name AS account_name FROM services s JOIN accounts a ON a.id = s.account_id
        LEFT JOIN sites st ON st.id = s.site_id WHERE ' . implode(' AND ', $where), $params);
}

/* ------------------------------------------------------------ Content --- */

/** Signed token for one-click unsubscribe links. $what: marketing | alerts */
function unsubscribe_token(int $contactId, string $what): string
{
    return substr(hash_hmac('sha256', "unsubscribe|$contactId|$what", app_key()), 0, 32);
}

function unsubscribe_url(int $contactId, string $what): string
{
    return app_url() . '/unsubscribe.php?' . http_build_query(['c' => $contactId, 'w' => $what, 't' => unsubscribe_token($contactId, $what)]);
}

/** Turn the plain-text message into simple, safe HTML paragraphs with links. */
function campaign_text_to_html(string $text): string
{
    $paragraphs = preg_split('/\n\s*\n/', str_replace("\r", '', trim($text)));
    $html = '';
    foreach ($paragraphs as $p) {
        $p = h($p);
        $p = preg_replace('#\bhttps?://[^\s<]+[^\s<.,;:!?)\]\'"]#', '<a href="$0">$0</a>', $p);
        $html .= '<p>' . nl2br($p) . '</p>';
    }
    return $html;
}

/**
 * Email HTML for one recipient (or for Mailchimp, which fills in its own merge
 * tags and unsubscribe link when $forMailchimp is true).
 */
function campaign_html(array $campaign, ?array $recipient, bool $forMailchimp = false): string
{
    $body = campaign_text_to_html($campaign['body']);
    if ($recipient) {
        $first = strtok((string)$recipient['name'], ' ') ?: 'there';
        $servicesHtml = '';
        if (!empty($recipient['services'])) {
            $servicesHtml = '<ul>' . implode('', array_map(fn($s) => '<li>' . h($s['identifier']) . ' – ' . h(SERVICE_TYPES[$s['service_type']] ?? $s['service_type'])
                . ($s['site_name'] ? ' at ' . h($s['site_name']) : '') . '</li>', $recipient['services'])) . '</ul>';
        }
        $body = strtr($body, [
            '{{first_name}}' => h($first), '{{name}}' => h($recipient['name']),
            '{{company}}' => h($recipient['account_name']), '{{account_number}}' => h($recipient['account_number']),
            '{{services}}' => $servicesHtml,
        ]);
        // {{services}} on its own line becomes a list rather than a list inside a paragraph.
        $body = str_replace(['<p>' . $servicesHtml . '</p>'], [$servicesHtml], $body);
    }
    if ($forMailchimp) {
        $footer = 'You are receiving this because you agreed to hear from us. <a href="*|UNSUB|*">Unsubscribe</a>. *|LIST:ADDRESSLINE|*';
    } elseif ($campaign['kind'] === 'marketing') {
        $footer = 'You are receiving this because you agreed to hear from us. '
            . ($recipient ? '<a href="' . h(unsubscribe_url((int)$recipient['contact_id'], 'marketing')) . '">Unsubscribe from marketing emails</a>.' : '');
    } else {
        $footer = 'This is a service message about services you have with us. '
            . ($recipient ? '<a href="' . h(unsubscribe_url((int)$recipient['contact_id'], 'alerts')) . '">Stop service alert emails</a>.' : '');
    }
    return email_layout($campaign['subject'], $body . '<p style="margin-top:28px;font-size:12px;color:#667085">' . $footer . '</p>');
}

/** Merge tags a campaign uses that the chosen channel can't fill in. */
function campaign_content_problems(array $campaign): array
{
    $problems = [];
    if (preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $campaign['subject'] . ' ' . $campaign['body'], $m)) {
        foreach (array_unique($m[1]) as $tag) {
            if (!in_array($tag, ['first_name', 'name', 'company', 'account_number', 'services'], true)) {
                $problems[] = "{{{$tag}}} isn't a merge field the CRM knows.";
            } elseif ($tag === 'services' && $campaign['channel'] === 'mailchimp') {
                $problems[] = '{{services}} can\'t be used when sending through Mailchimp.';
            }
        }
    }
    if (str_contains($campaign['subject'], '{{')) {
        $problems[] = 'Merge fields can\'t be used in the subject line.';
    }
    return $problems;
}

/* ------------------------------------------------------------ Sending --- */

/** Freeze the audience and start sending. Returns a message for the user. */
function campaign_start(array $campaign): string
{
    $filters = json_decode((string)$campaign['filters'], true) ?: campaign_filters([], $campaign['kind']);
    $audience = campaign_audience($campaign['kind'], $filters);
    if (!$audience) {
        throw new IntegrationException('Nobody matches this audience, so there is nothing to send.');
    }
    if ($campaign['channel'] === 'email' && !mail_configured()) {
        throw new IntegrationException('Email isn\'t set up yet. An admin can add it under Settings → Email.');
    }
    if ($campaign['channel'] === 'mailchimp' && !mailchimp_configured()) {
        throw new IntegrationException('Mailchimp isn\'t connected. An admin can connect it under Admin → Mailchimp.');
    }
    if ($problems = campaign_content_problems($campaign)) {
        throw new IntegrationException(implode(' ', $problems));
    }
    $claimed = db_exec("UPDATE campaigns SET status = 'sending', sent_by = ?, sent_at = NOW(), recipients = ?, last_error = NULL WHERE id = ? AND status = 'draft'",
        [current_user()['id'] ?? null, count($audience), $campaign['id']]);
    if (!$claimed) {
        throw new IntegrationException('This has already been sent.');
    }
    foreach ($audience as $r) {
        db_exec('INSERT IGNORE INTO campaign_recipients (campaign_id, account_id, contact_id, email, name) VALUES (?, ?, ?, ?, ?)',
            [$campaign['id'], $r['account_id'], $r['contact_id'], $r['email'], $r['name']]);
    }
    audit('campaign_send', CAMPAIGN_KINDS[$campaign['kind']] . " {$campaign['reference']} \"{$campaign['subject']}\" sending to " . count($audience) . ' recipients via ' . ($campaign['channel'] === 'mailchimp' ? 'Mailchimp' : 'email'), 'campaigns', (int)$campaign['id']);

    if ($campaign['channel'] === 'mailchimp') {
        try {
            [$sent, $skipped] = mailchimp_send_campaign($campaign);
        } catch (IntegrationException $e) {
            db_exec("UPDATE campaigns SET status = 'failed', last_error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), $campaign['id']]);
            throw $e;
        }
        campaign_update_counts((int)$campaign['id']);
        return "Sent to Mailchimp for $sent recipient" . ($sent === 1 ? '' : 's') . ($skipped ? " ($skipped skipped)" : '') . '.';
    }
    $left = campaign_send_batch((int)$campaign['id']);
    return $left ? 'Sending… ' . $left . ' still to go.' : 'Sent.';
}

function campaign_update_counts(int $id): void
{
    db_exec("UPDATE campaigns SET
        sent_count = (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'sent'),
        failed_count = (SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'failed')
        WHERE id = ?", [$id, $id, $id]);
    $pending = (int)db_value("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending'", [$id]);
    if ($pending === 0) {
        db_exec("UPDATE campaigns SET status = IF(sent_count = 0 AND failed_count > 0, 'failed', 'sent') WHERE id = ? AND status = 'sending'", [$id]);
    }
}

/** Send the next batch of a campaign by email. Returns how many are left. */
function campaign_send_batch(int $id, int $limit = 0, float $maxSeconds = 20.0): int
{
    $campaign = db_one('SELECT * FROM campaigns WHERE id = ?', [$id]);
    if (!$campaign || $campaign['status'] !== 'sending' || $campaign['channel'] !== 'email') {
        return 0;
    }
    $limit = $limit ?: max(1, (int)(setting('campaign_batch_size') ?: 50));
    $filters = json_decode((string)$campaign['filters'], true) ?: [];
    $start = microtime(true);
    $rows = db_all("SELECT r.*, a.name AS account_name, a.account_number FROM campaign_recipients r LEFT JOIN accounts a ON a.id = r.account_id
        WHERE r.campaign_id = ? AND r.status = 'pending' ORDER BY r.id LIMIT " . (int)$limit, [$id]);
    $servicesCache = [];
    foreach ($rows as $r) {
        if (microtime(true) - $start > $maxSeconds) {
            break;
        }
        // Claim the row so two workers (browser + cron) never send twice.
        if (!db_exec("UPDATE campaign_recipients SET status = 'sent', sent_at = NOW() WHERE id = ? AND status = 'pending'", [$r['id']])) {
            continue;
        }
        try {
            $contact = $r['contact_id'] ? db_one('SELECT * FROM contacts WHERE id = ?', [$r['contact_id']]) : null;
            $optedOut = $contact && ($campaign['kind'] === 'marketing' ? !$contact['marketing_email'] : !$contact['service_alerts']);
            if (!$contact || $optedOut) {
                throw new IntegrationException($contact ? 'Opted out before this was sent' : 'Contact no longer exists');
            }
            if ($campaign['kind'] === 'service_alert' && $r['account_id']) {
                $servicesCache[$r['account_id']] ??= campaign_account_services((int)$r['account_id'], $filters);
            }
            $recipient = $r + ['services' => $servicesCache[$r['account_id']] ?? []];
            $what = $campaign['kind'] === 'marketing' ? 'marketing' : 'alerts';
            $link = unsubscribe_url((int)$r['contact_id'], $what);
            send_mail($r['email'], (string)$r['name'], $campaign['subject'], campaign_html($campaign, $recipient), null, [
                'List-Unsubscribe' => '<' . $link . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ]);
        } catch (IntegrationException $e) {
            db_exec("UPDATE campaign_recipients SET status = 'failed', sent_at = NULL, error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), $r['id']]);
        }
    }
    campaign_update_counts($id);
    return (int)db_value("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending'", [$id]);
}

/** A customer's live services matching a campaign's service filters (for {{services}}). */
function campaign_account_services(int $accountId, array $f): array
{
    $f['account_id'] = $accountId;
    $f['statuses'] = ['active', 'suspended', 'prospect', 'churned'];
    $f['dealer_id'] = null;
    $f['account_type'] = '';
    $f['service_types'] ??= [];
    $f['carriers'] ??= [];
    $f['product_ids'] ??= [];
    $f['postcodes'] ??= '';
    $f['who'] = 'all';
    $audience = campaign_audience('service_alert', $f);
    return $audience[0]['services'] ?? [];
}

/** Continue any campaigns that are part-way through sending (from cron). */
function campaigns_process_queue(): int
{
    $sent = 0;
    foreach (db_all("SELECT id FROM campaigns WHERE status = 'sending' AND channel = 'email'") as $c) {
        $before = (int)db_value("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending'", [$c['id']]);
        $left = campaign_send_batch((int)$c['id'], 200, 50.0);
        $sent += $before - $left;
    }
    return $sent;
}

/** Record an opt-out (from an unsubscribe link, or Mailchimp). */
function marketing_unsubscribe(int $contactId, string $what, string $via): bool
{
    $c = db_one('SELECT id, name, email, account_id, marketing_email, service_alerts FROM contacts WHERE id = ?', [$contactId]);
    if (!$c) {
        return false;
    }
    if ($what === 'alerts') {
        if ($c['service_alerts']) {
            db_exec('UPDATE contacts SET service_alerts = 0, marketing_updated_at = NOW() WHERE id = ?', [$contactId]);
            audit('unsubscribe', "{$c['email']} stopped service alert emails (via $via)", 'contacts', $contactId, null, ['Service alerts' => ['from' => 'Yes', 'to' => 'No']]);
        }
        return true;
    }
    if ($c['marketing_email']) {
        db_exec("UPDATE contacts SET marketing_email = 0, unsubscribed_at = NOW(), marketing_source = 'unsubscribed', marketing_updated_at = NOW() WHERE id = ?", [$contactId]);
        audit('unsubscribe', "{$c['email']} unsubscribed from marketing emails (via $via)", 'contacts', $contactId, null, ['Marketing by email' => ['from' => 'Yes', 'to' => 'No']]);
    }
    return true;
}

/* --------------------------------------------------------- Controller --- */

function campaigns_controller(): void
{
    require_permission('marketing.send');
    $action = query('action', 'list');
    $id = query_int('id');
    $campaign = $id ? db_one('SELECT c.*, u.name AS created_by_name, s.name AS sent_by_name FROM campaigns c
        LEFT JOIN users u ON u.id = c.created_by LEFT JOIN users s ON s.id = c.sent_by WHERE c.id = ?', [$id]) : null;
    if ($id && !$campaign) {
        not_found('Not found.');
    }
    $back = $campaign ? url('campaigns', ['action' => 'view', 'id' => $campaign['id']]) : url('campaigns');

    switch ($action) {
        case 'new':
        case 'edit':
            if ($campaign && $campaign['status'] !== 'draft') {
                flash('This has already been sent. Duplicate it to send something similar.', 'error');
                redirect($back);
            }
            $kind = $campaign['kind'] ?? (query('kind') === 'marketing' ? 'marketing' : 'service_alert');
            $values = $campaign ?? ['kind' => $kind, 'channel' => 'email', 'subject' => '', 'body' => $kind === 'marketing'
                ? "Hi {{first_name}},\n\n"
                : "Hi {{first_name}},\n\nWe're writing to let you know about planned maintenance that may affect the following services:\n\n{{services}}\n\nWhat's happening: \nWhen: \nWhat you need to do: nothing – we'll let you know when it's complete.\n\nIf you have any questions, just reply to this email."];
            $filters = $campaign ? (json_decode((string)$campaign['filters'], true) ?: []) : campaign_filters(['account_id' => query('account_id')], $kind);
            $errors = [];
            if (is_post()) {
                verify_csrf();
                $kind = ($_POST['kind'] ?? '') === 'marketing' ? 'marketing' : 'service_alert';
                $values = [
                    'kind'    => $kind,
                    'channel' => $kind === 'marketing' && ($_POST['channel'] ?? '') === 'mailchimp' ? 'mailchimp' : 'email',
                    'subject' => trim((string)($_POST['subject'] ?? '')),
                    'body'    => trim((string)($_POST['body'] ?? '')),
                ];
                $filters = campaign_filters($_POST, $kind);
                if ($values['subject'] === '') {
                    $errors['subject'] = 'Enter a subject.';
                } elseif (mb_strlen($values['subject']) > 200) {
                    $errors['subject'] = 'Keep the subject under 200 characters.';
                }
                if ($values['body'] === '') {
                    $errors['body'] = 'Write the message.';
                }
                if ($values['channel'] === 'mailchimp' && !mailchimp_configured()) {
                    $errors['channel'] = 'Mailchimp isn\'t connected yet.';
                }
                if (!$errors) {
                    $json = json_encode($filters);
                    if ($campaign) {
                        db_exec('UPDATE campaigns SET kind = ?, channel = ?, subject = ?, body = ?, filters = ? WHERE id = ?',
                            [$values['kind'], $values['channel'], $values['subject'], $values['body'], $json, $campaign['id']]);
                        $savedId = (int)$campaign['id'];
                    } else {
                        db_exec('INSERT INTO campaigns (kind, channel, subject, body, filters, created_by) VALUES (?, ?, ?, ?, ?, ?)',
                            [$values['kind'], $values['channel'], $values['subject'], $values['body'], $json, current_user()['id']]);
                        $savedId = (int)db()->lastInsertId();
                        db_exec('UPDATE campaigns SET reference = ? WHERE id = ?', [sprintf('MSG-%05d', $savedId), $savedId]);
                    }
                    audit($campaign ? 'campaign_update' : 'campaign_create', CAMPAIGN_KINDS[$kind] . ' "' . $values['subject'] . '" ' . ($campaign ? 'updated' : 'drafted'), 'campaigns', $savedId);
                    flash('Draft saved. Check who it will go to, then send.');
                    redirect(url('campaigns', ['action' => 'view', 'id' => $savedId]));
                }
            }
            $filters += campaign_filters([], $values['kind']);
            page('campaign_form', [
                'campaign' => $campaign, 'values' => $values, 'filters' => $filters, 'errors' => $errors,
                'products' => db_all('SELECT id, name, category FROM products ORDER BY name'),
                'dealers' => ref_options('accounts', null, 'is_dealer = 1'),
                'accountName' => $filters['account_id'] ? db_value('SELECT name FROM accounts WHERE id = ?', [$filters['account_id']]) : null,
            ], $campaign ? 'Edit ' . strtolower(CAMPAIGN_KINDS[$values['kind']]) : 'New ' . strtolower(CAMPAIGN_KINDS[$kind]));
            return;

        case 'view':
            $filters = json_decode((string)$campaign['filters'], true) ?: campaign_filters([], $campaign['kind']);
            $audience = $campaign['status'] === 'draft' ? campaign_audience($campaign['kind'], $filters) : [];
            $unreachable = $campaign['status'] === 'draft' ? campaign_unreachable($campaign['kind'], $filters, $audience) : [];
            $recipients = $campaign['status'] !== 'draft'
                ? db_all('SELECT r.*, a.name AS account_name FROM campaign_recipients r LEFT JOIN accounts a ON a.id = r.account_id
                    WHERE r.campaign_id = ? ORDER BY FIELD(r.status, \'failed\', \'pending\', \'sent\'), r.email LIMIT 500', [$campaign['id']])
                : [];
            $pending = (int)db_value("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending'", [$campaign['id']]);
            $preview = campaign_html($campaign, $audience[0] ?? null);
            page('campaign', compact('campaign', 'filters', 'audience', 'unreachable', 'recipients', 'pending', 'preview'), $campaign['reference'] . ' ' . $campaign['subject']);
            return;

        case 'preview':
            // The email as the first recipient will see it, shown inside a sandboxed frame.
            $filters = json_decode((string)$campaign['filters'], true) ?: [];
            $first = $campaign['status'] === 'draft' ? (campaign_audience($campaign['kind'], $filters)[0] ?? null) : null;
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data: https:; frame-ancestors 'self'");
            header('X-Frame-Options: SAMEORIGIN');
            echo campaign_html($campaign, $first);
            return;
    }

    if (is_post()) {
        verify_csrf();
        try {
            switch ($action) {
                case 'test':
                    $me = current_user();
                    $sample = campaign_audience($campaign['kind'], json_decode((string)$campaign['filters'], true) ?: [])[0] ?? null;
                    $recipient = $sample ? ['email' => $me['email'], 'name' => $me['name']] + $sample : null;
                    if ($recipient === null && $campaign['kind'] === 'service_alert') {
                        $recipient = ['email' => $me['email'], 'name' => $me['name'], 'contact_id' => 0, 'account_name' => 'Example Ltd', 'account_number' => 'ACC-00000', 'services' => []];
                    }
                    send_mail($me['email'], $me['name'], '[TEST] ' . $campaign['subject'], campaign_html($campaign, $recipient ? $recipient + ['contact_id' => 0] : null));
                    flash('Test sent to ' . $me['email'] . ($sample ? ' (filled in as ' . $sample['name'] . ' at ' . $sample['account_name'] . ')' : '') . '.');
                    break;
                case 'send':
                    flash(campaign_start($campaign));
                    break;
                case 'continue':
                    $left = campaign_send_batch((int)$campaign['id']);
                    flash($left ? "Sending… $left still to go." : 'All sent.');
                    break;
                case 'cancel':
                    if ($campaign['status'] === 'sending') {
                        db_exec("DELETE FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending'", [$campaign['id']]);
                        db_exec("UPDATE campaigns SET status = 'cancelled' WHERE id = ?", [$campaign['id']]);
                        campaign_update_counts((int)$campaign['id']);
                        audit('campaign_cancel', "{$campaign['reference']} stopped part-way", 'campaigns', (int)$campaign['id']);
                        flash('Stopped. Emails already sent can\'t be recalled.');
                    }
                    break;
                case 'duplicate':
                    db_exec('INSERT INTO campaigns (kind, channel, subject, body, filters, created_by) VALUES (?, ?, ?, ?, ?, ?)',
                        [$campaign['kind'], $campaign['channel'], $campaign['subject'], $campaign['body'], $campaign['filters'], current_user()['id']]);
                    $newId = (int)db()->lastInsertId();
                    db_exec('UPDATE campaigns SET reference = ? WHERE id = ?', [sprintf('MSG-%05d', $newId), $newId]);
                    audit('campaign_create', "Copied {$campaign['reference']} as a new draft", 'campaigns', $newId);
                    flash('Copied as a new draft.');
                    redirect(url('campaigns', ['action' => 'edit', 'id' => $newId]));
                case 'delete':
                    if ($campaign['status'] === 'draft') {
                        db_exec('DELETE FROM campaigns WHERE id = ?', [$campaign['id']]);
                        audit('campaign_delete', "Draft {$campaign['reference']} \"{$campaign['subject']}\" deleted", 'campaigns', (int)$campaign['id']);
                        flash('Draft deleted.');
                        redirect(url('campaigns'));
                    }
                    break;
            }
        } catch (IntegrationException $e) {
            flash($e->getMessage(), 'error');
        }
        redirect($back);
    }

    $kind = query('kind');
    $where = in_array($kind, array_keys(CAMPAIGN_KINDS), true) ? 'WHERE c.kind = ' . db()->quote($kind) : '';
    $rows = db_all("SELECT c.*, u.name AS created_by_name FROM campaigns c LEFT JOIN users u ON u.id = c.created_by $where ORDER BY c.id DESC LIMIT 200");
    page('campaigns', compact('rows', 'kind'), 'Service alerts & marketing');
}
