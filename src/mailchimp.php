<?php
declare(strict_types=1);

/*
 * Mailchimp Marketing API (v3). Marketing campaigns created in the CRM can be
 * sent through a Mailchimp audience: the CRM adds/updates the recipients as
 * audience members, puts them in a segment, then creates and sends a regular
 * campaign to that segment. Unsubscribes made in Mailchimp flow back to the CRM.
 */

final class MailchimpException extends IntegrationException
{
}

function mailchimp_configured(): bool
{
    return (bool)setting('mailchimp_api_key') && (bool)setting('mailchimp_list_id');
}

function mailchimp_base(string $key): string
{
    if ($base = config('mailchimp_base')) {
        return rtrim($base, '/');
    }
    $dc = substr(strrchr($key, '-') ?: '', 1);
    if (!preg_match('/^[a-z]+\d+$/', $dc)) {
        throw new MailchimpException('That doesn\'t look like a Mailchimp API key. It should end in something like "-us21".');
    }
    return "https://$dc.api.mailchimp.com/3.0";
}

function mailchimp_request(string $method, string $path, ?array $json = null, ?string $key = null): mixed
{
    $key ??= (string)setting('mailchimp_api_key');
    if ($key === '') {
        throw new MailchimpException('Add your Mailchimp API key first (Admin → Mailchimp).');
    }
    $headers = ['Authorization: Basic ' . base64_encode('crm:' . $key), 'Accept: application/json'];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    try {
        [$status, $body] = http_request($method, mailchimp_base($key) . $path, $headers, $json === null ? null : json_encode($json));
    } catch (MailchimpException $e) {
        throw $e;
    } catch (IntegrationException $e) {
        throw new MailchimpException('Could not reach Mailchimp: ' . $e->getMessage());
    }
    if ($status >= 200 && $status < 300) {
        return $body;
    }
    $detail = is_array($body) ? trim(($body['title'] ?? '') . ': ' . ($body['detail'] ?? ''), ': ') : substr(strip_tags((string)$body), 0, 200);
    if (is_array($body) && !empty($body['errors'])) {
        $detail .= ' (' . implode('; ', array_map(fn($e) => ($e['field'] ?? '') . ' ' . ($e['message'] ?? ''), $body['errors'])) . ')';
    }
    throw new MailchimpException("Mailchimp error ($status): $detail");
}

/** Audiences in the account: [id => "Name (n contacts)"]. */
function mailchimp_lists(?string $key = null): array
{
    $body = mailchimp_request('GET', '/lists?count=100&fields=lists.id,lists.name,lists.stats.member_count', null, $key);
    $out = [];
    foreach ($body['lists'] ?? [] as $l) {
        $out[$l['id']] = $l['name'] . ' (' . number_format((int)($l['stats']['member_count'] ?? 0)) . ' contacts)';
    }
    return $out;
}

function mailchimp_member_hash(string $email): string
{
    return md5(strtolower(trim($email)));
}

/** Make sure the audience has the merge fields the CRM fills in. */
function mailchimp_ensure_merge_fields(string $listId): void
{
    $body = mailchimp_request('GET', "/lists/$listId/merge-fields?count=100&fields=merge_fields.tag");
    $have = array_column($body['merge_fields'] ?? [], 'tag');
    foreach (['COMPANY' => 'Company', 'ACCNO' => 'Account number'] as $tag => $name) {
        if (!in_array($tag, $have, true)) {
            mailchimp_request('POST', "/lists/$listId/merge-fields", ['tag' => $tag, 'name' => $name, 'type' => 'text', 'public' => false]);
        }
    }
}

/**
 * Add or update a recipient in the audience. New people are added as subscribed
 * (they opted in to email marketing in the CRM); people who unsubscribed in
 * Mailchimp are left unsubscribed. Returns their Mailchimp status.
 */
function mailchimp_upsert_member(string $listId, array $r): string
{
    $parts = preg_split('/\s+/', trim((string)$r['name']), 2);
    $member = mailchimp_request('PUT', "/lists/$listId/members/" . mailchimp_member_hash($r['email']), [
        'email_address' => $r['email'],
        'status_if_new' => 'subscribed',
        'merge_fields'  => ['FNAME' => $parts[0] ?? '', 'LNAME' => $parts[1] ?? '', 'COMPANY' => (string)($r['account_name'] ?? ''), 'ACCNO' => (string)($r['account_number'] ?? '')],
    ]);
    return (string)($member['status'] ?? 'subscribed');
}

/** CRM merge tags → Mailchimp merge tags. */
function mailchimp_merge_tags(string $html): string
{
    return strtr($html, [
        '{{first_name}}' => '*|FNAME|*', '{{name}}' => '*|FNAME|* *|LNAME|*',
        '{{company}}' => '*|COMPANY|*', '{{account_number}}' => '*|ACCNO|*',
    ]);
}

/** Send a marketing campaign through Mailchimp. Returns [sent, skipped]. */
function mailchimp_send_campaign(array $campaign): array
{
    $listId = (string)setting('mailchimp_list_id');
    mailchimp_sync_unsubscribes();
    mailchimp_ensure_merge_fields($listId);

    $recipients = db_all("SELECT r.*, a.name AS account_name, a.account_number FROM campaign_recipients r
        LEFT JOIN accounts a ON a.id = r.account_id WHERE r.campaign_id = ? AND r.status = 'pending'", [$campaign['id']]);
    $emails = [];
    $skipped = 0;
    foreach ($recipients as $r) {
        try {
            $status = mailchimp_upsert_member($listId, $r);
        } catch (MailchimpException $e) {
            db_exec("UPDATE campaign_recipients SET status = 'failed', error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), $r['id']]);
            $skipped++;
            continue;
        }
        if ($status !== 'subscribed') {
            db_exec("UPDATE campaign_recipients SET status = 'failed', error = ? WHERE id = ?", ["Not subscribed in Mailchimp ($status)", $r['id']]);
            if (in_array($status, ['unsubscribed', 'cleaned'], true) && $r['contact_id']) {
                marketing_unsubscribe((int)$r['contact_id'], 'marketing', 'Mailchimp');
            }
            $skipped++;
            continue;
        }
        $emails[$r['id']] = $r['email'];
    }
    if (!$emails) {
        throw new MailchimpException('None of the recipients can receive this in Mailchimp (all unsubscribed or failed).');
    }

    $segment = mailchimp_request('POST', "/lists/$listId/segments", ['name' => 'CRM ' . $campaign['reference'] . ' ' . date('Y-m-d H:i'), 'static_segment' => []]);
    foreach (array_chunk(array_values($emails), 500) as $chunk) {
        mailchimp_request('POST', "/lists/$listId/segments/{$segment['id']}", ['members_to_add' => $chunk]);
    }

    $mc = mailchimp_request('POST', '/campaigns', [
        'type'       => 'regular',
        'recipients' => ['list_id' => $listId, 'segment_opts' => ['saved_segment_id' => (int)$segment['id']]],
        'settings'   => [
            'subject_line' => $campaign['subject'],
            'title'        => $campaign['reference'] . ' ' . $campaign['subject'],
            'from_name'    => (string)(setting('mail_from_name') ?: company('name', config('app_name'))),
            'reply_to'     => (string)(setting('mail_reply_to') ?: setting('mail_from_email') ?: company('email')),
        ],
    ]);
    db_exec('UPDATE campaigns SET mailchimp_id = ? WHERE id = ?', [$mc['id'], $campaign['id']]);
    mailchimp_request('PUT', "/campaigns/{$mc['id']}/content", ['html' => mailchimp_merge_tags(campaign_html($campaign, null, true))]);
    mailchimp_request('POST', "/campaigns/{$mc['id']}/actions/send");

    $ids = array_keys($emails);
    foreach (array_chunk($ids, 500) as $chunk) {
        db_exec("UPDATE campaign_recipients SET status = 'sent', sent_at = NOW() WHERE id IN (" . implode(',', array_map('intval', $chunk)) . ')');
    }
    return [count($emails), $skipped];
}

/** Pull unsubscribes made in Mailchimp into the CRM. Returns how many contacts changed. */
function mailchimp_sync_unsubscribes(): int
{
    if (!mailchimp_configured()) {
        return 0;
    }
    $listId = (string)setting('mailchimp_list_id');
    $since = setting('mailchimp_unsub_since');
    $started = gmdate('Y-m-d\TH:i:s\Z');
    $changed = 0;
    for ($offset = 0; ; $offset += 1000) {
        $query = http_build_query(array_filter([
            'status' => 'unsubscribed', 'count' => 1000, 'offset' => $offset,
            'fields' => 'members.email_address,total_items', 'since_last_changed' => $since,
        ]));
        $body = mailchimp_request('GET', "/lists/$listId/members?$query");
        foreach ($body['members'] ?? [] as $m) {
            foreach (db_all('SELECT id FROM contacts WHERE email = ? AND marketing_email = 1', [strtolower($m['email_address'])]) as $c) {
                marketing_unsubscribe((int)$c['id'], 'marketing', 'Mailchimp');
                $changed++;
            }
        }
        if (count($body['members'] ?? []) < 1000) {
            break;
        }
    }
    set_setting('mailchimp_unsub_since', $started);
    set_setting('mailchimp_last_sync_at', date('Y-m-d H:i:s'));
    return $changed;
}

function mailchimp_controller(): void
{
    require_permission('settings.manage');
    if (is_post()) {
        verify_csrf();
        $action = query('action');
        try {
            if ($action === 'key') {
                $key = trim((string)($_POST['api_key'] ?? ''));
                if ($key !== '') {
                    mailchimp_lists($key); // check it works before saving
                    set_setting('mailchimp_api_key', $key);
                }
                $list = (string)($_POST['list_id'] ?? '');
                if ($list !== '' && setting('mailchimp_api_key')) {
                    if (!isset(mailchimp_lists()[$list])) {
                        throw new MailchimpException('Choose one of your audiences.');
                    }
                    if ($list !== setting('mailchimp_list_id')) {
                        set_setting('mailchimp_unsub_since', null);
                    }
                    set_setting('mailchimp_list_id', $list);
                }
                audit('settings', 'Mailchimp settings saved');
                flash(setting('mailchimp_list_id') ? 'Mailchimp settings saved.' : 'API key saved. Now choose the audience to use.');
            } elseif ($action === 'sync') {
                $n = mailchimp_sync_unsubscribes();
                audit('sync', "Mailchimp unsubscribes synced ($n changed)");
                flash("Checked Mailchimp for unsubscribes: $n contact" . ($n === 1 ? '' : 's') . ' opted out in the CRM.');
            } elseif ($action === 'remove') {
                foreach (['mailchimp_api_key', 'mailchimp_list_id', 'mailchimp_unsub_since', 'mailchimp_last_sync_at'] as $k) {
                    set_setting($k, null);
                }
                audit('settings', 'Mailchimp disconnected');
                flash('Mailchimp disconnected.');
            }
        } catch (IntegrationException $e) {
            flash($e->getMessage(), 'error');
        }
        redirect(url('mailchimp'));
    }
    $lists = [];
    $error = null;
    if (setting('mailchimp_api_key')) {
        try {
            $lists = mailchimp_lists();
        } catch (IntegrationException $e) {
            $error = $e->getMessage();
        }
    }
    page('mailchimp', compact('lists', 'error'), 'Mailchimp');
}
