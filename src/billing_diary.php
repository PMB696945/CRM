<?php
declare(strict_types=1);

/*
 * Billing diary: every change to a service (added, live, price change, ceased, ...) recorded as it happens,
 * whichever way it was made (edited by hand, from a signed agreement, a Giacom order, closing a customer).
 * Each month's list is worked through when checking the billing, ticking each change off once it's
 * been allocated and accounted for.
 */

const SERVICE_CHANGE_TYPES = [
    'added'       => 'New service',
    'live'        => 'Went live',
    'price'       => 'Price change',
    'setup'       => 'Setup fee change',
    'product'     => 'Product change',
    'number'      => 'Number / circuit change',
    'start_date'  => 'Start date change',
    'term'        => 'Term change',
    'suspended'   => 'Suspended',
    'reactivated' => 'Reactivated',
    'ceased'      => 'Ceased',
    'cancelled'   => 'Cancelled before going live',
    'removed'     => 'Deleted',
    'status'      => 'Status change',
];

/** Statuses that are billed. */
function service_is_billed(?string $status): bool
{
    return in_array($status, ['active', 'suspended'], true);
}

/** A service's monthly price (its own, or its product's per month when it has none). */
function service_monthly(array $s): float
{
    if ($s['monthly_price'] !== null) {
        return (float)$s['monthly_price'];
    }
    $p = $s['product_id'] ? db_one('SELECT monthly_price, billing_frequency FROM products WHERE id = ?', [$s['product_id']]) : null;
    return $p ? monthly_equivalent($p['monthly_price'], $p['billing_frequency']) : 0.0;
}

/** Something about a service changed (or it was added): note it in the diary and send it to aBILLity. */
function service_changed(int $serviceId, ?array $before): void
{
    service_diary_record($serviceId, $before);
    abillity_queue_service($serviceId);
}

/** Compare a service before and after a change, and record each billing-relevant difference. Returns entries added. */
function service_diary_record(int $serviceId, ?array $before): int
{
    $a = db_one('SELECT * FROM services WHERE id = ?', [$serviceId]);
    if (!$a) {
        return 0;
    }
    $today = date('Y-m-d');
    $name = service_diary_name($a);
    $monthly = service_monthly($a);
    $entries = [];
    $add = function (string $type, string $summary, ?string $from = null, ?string $to = null, ?float $monthlyChange = null, ?float $oneOff = null, ?string $effective = null) use (&$entries, $today) {
        $entries[] = [$type, $summary, $from, $to, $monthlyChange, $oneOff, $effective ?? $today];
    };

    if (!$before) {
        $billed = service_is_billed($a['status']);
        $add('added', $name . ($billed ? '' : ' (' . $a['status'] . ': billed once live)'), null, ucfirst((string)$a['status']),
            $billed ? $monthly : null, $billed && (float)$a['setup_fee'] > 0 ? (float)$a['setup_fee'] : null, $a['start_date'] ?: $today);
    } else {
        $was = (string)$before['status'];
        $now = (string)$a['status'];
        $wasMonthly = service_monthly($before);
        $statusChanged = $was !== $now;
        if ($statusChanged) {
            if ($now === 'ceased' || $now === 'cancelled') {
                service_is_billed($was)
                    ? $add('ceased', $name, ucfirst($was), 'Ceased', -$wasMonthly)
                    : $add('cancelled', $name, ucfirst($was), 'Ceased');
            } elseif ($was === 'pending' && service_is_billed($now)) {
                $add('live', $name, 'Pending', ucfirst($now), $monthly, (float)$a['setup_fee'] > 0 ? (float)$a['setup_fee'] : null, $a['start_date'] ?: $today);
            } elseif ($now === 'suspended') {
                $add('suspended', $name, ucfirst($was), 'Suspended');
            } elseif ($was === 'suspended' && $now === 'active') {
                $add('reactivated', $name, 'Suspended', 'Active');
            } elseif ($was === 'ceased' && service_is_billed($now)) {
                $add('reactivated', $name, 'Ceased', ucfirst($now), $monthly);
            } else {
                $add('status', $name, ucfirst($was), ucfirst($now));
            }
        }
        $billedNow = service_is_billed($now) && service_is_billed($was);
        if (round($wasMonthly, 2) !== round($monthly, 2) && !($statusChanged && in_array($now, ['ceased'], true))) {
            $add('price', $name, money($wasMonthly) . '/mo', money($monthly) . '/mo', $billedNow ? round($monthly - $wasMonthly, 2) : null);
        }
        if (round((float)$before['setup_fee'], 2) !== round((float)$a['setup_fee'], 2)) {
            $add('setup', $name, money((float)$before['setup_fee']), money((float)$a['setup_fee']));
        }
        if ((int)$before['product_id'] !== (int)$a['product_id']) {
            $names = fn($id) => $id ? (string)db_value('SELECT name FROM products WHERE id = ?', [$id]) : '—';
            $add('product', $name, $names($before['product_id']), $names($a['product_id']));
        }
        if ((string)$before['identifier'] !== (string)$a['identifier']) {
            $add('number', $name, (string)$before['identifier'], (string)$a['identifier']);
        }
        if ((string)$before['start_date'] !== (string)$a['start_date'] && !($statusChanged && $was === 'pending')) {
            $add('start_date', $name, $before['start_date'] ? fmt_date($before['start_date']) : '—', $a['start_date'] ? fmt_date($a['start_date']) : '—', null, null, $a['start_date'] ?: $today);
        }
        if ((string)$before['term_months'] !== (string)$a['term_months'] || (string)$before['contract_end_date'] !== (string)$a['contract_end_date']) {
            $term = fn($r) => trim(($r['term_months'] !== null ? term_label((int)$r['term_months']) : '') . ($r['contract_end_date'] ? ', ends ' . fmt_date($r['contract_end_date']) : ''), ', ') ?: '—';
            if ($term($before) !== $term($a)) {
                $add('term', $name, $term($before), $term($a));
            }
        }
    }
    foreach ($entries as [$type, $summary, $from, $to, $monthlyChange, $oneOff, $effective]) {
        service_diary_insert($a, $type, $summary, $from, $to, $monthlyChange, $oneOff, $effective);
    }
    return count($entries);
}

/** A service is about to be deleted: keep a record of it in the diary. */
function service_diary_removed(array $s): void
{
    $billed = service_is_billed($s['status']);
    service_diary_insert($s, 'removed', service_diary_name($s), ucfirst((string)$s['status']), 'Deleted', $billed ? -service_monthly($s) : null, null, date('Y-m-d'));
}

function service_diary_name(array $s): string
{
    $product = $s['product_id'] ? db_value('SELECT name FROM products WHERE id = ?', [$s['product_id']]) : null;
    return mb_substr(($product ?: (SERVICE_TYPES[$s['service_type']] ?? 'Service')) . ' – ' . $s['identifier'], 0, 255);
}

function service_diary_insert(array $s, string $type, string $summary, ?string $from, ?string $to, ?float $monthlyChange, ?float $oneOff, ?string $effective): void
{
    db_exec('INSERT INTO service_changes (service_id, account_id, change_type, summary, from_value, to_value, monthly_change, one_off, effective_date, identifier, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $type === 'removed' ? null : $s['id'], $s['account_id'], $type, mb_substr($summary, 0, 255), $from !== null ? mb_substr($from, 0, 255) : null,
        $to !== null ? mb_substr($to, 0, 255) : null, $monthlyChange !== null ? round($monthlyChange, 2) : null, $oneOff, $effective,
        mb_substr((string)$s['identifier'], 0, 190), current_user()['id'] ?? null,
    ]);
}

/* ------------------------------------------------------------------ Page --- */

function billing_diary_month(?string $month): string
{
    return $month && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : date('Y-m');
}

/** The month's changes, with filters: type, checked ('no', 'yes' or ''), account_id. */
function billing_diary_rows(string $month, array $filters = []): array
{
    $where = ['c.created_at >= ? AND c.created_at < ?'];
    $params = [$month . '-01', date('Y-m-01', strtotime($month . '-01 +1 month'))];
    if (!empty($filters['type']) && isset(SERVICE_CHANGE_TYPES[$filters['type']])) {
        $where[] = 'c.change_type = ?';
        $params[] = $filters['type'];
    }
    if (($filters['checked'] ?? '') === 'no') {
        $where[] = 'c.checked_at IS NULL';
    } elseif (($filters['checked'] ?? '') === 'yes') {
        $where[] = 'c.checked_at IS NOT NULL';
    }
    if (!empty($filters['account_id'])) {
        $where[] = 'c.account_id = ?';
        $params[] = (int)$filters['account_id'];
    }
    return db_all('SELECT c.*, a.name AS account_name, a.account_number, u.name AS user_name, cu.name AS checked_by_name,
            s.status AS service_status, s.abillity_charge_id, s.abillity_pending, s.abillity_error, s.abillity_provisional
        FROM service_changes c JOIN accounts a ON a.id = c.account_id
        LEFT JOIN services s ON s.id = c.service_id LEFT JOIN users u ON u.id = c.user_id LEFT JOIN users cu ON cu.id = c.checked_by
        WHERE ' . implode(' AND ', $where) . ' ORDER BY c.created_at, c.id', $params);
}

function billing_diary_summary(string $month): array
{
    $range = [$month . '-01', date('Y-m-01', strtotime($month . '-01 +1 month'))];
    $byType = [];
    foreach (db_all('SELECT change_type, COUNT(*) AS n FROM service_changes WHERE created_at >= ? AND created_at < ? GROUP BY change_type', $range) as $r) {
        $byType[$r['change_type']] = (int)$r['n'];
    }
    $t = db_one('SELECT COUNT(*) AS total, SUM(checked_at IS NULL) AS unchecked, COALESCE(SUM(monthly_change), 0) AS monthly, COALESCE(SUM(one_off), 0) AS one_off
        FROM service_changes WHERE created_at >= ? AND created_at < ?', $range);
    return ['by_type' => $byType, 'total' => (int)$t['total'], 'unchecked' => (int)$t['unchecked'], 'monthly' => (float)$t['monthly'], 'one_off' => (float)$t['one_off']];
}

function billing_diary_controller(): void
{
    require_permission('finance.view');
    $month = billing_diary_month(query('month'));
    $filters = ['type' => (string)query('type'), 'checked' => (string)query('checked', ''), 'account_id' => query_int('account_id')];
    $back = url('billing_diary', array_filter(['month' => $month] + $filters));

    if (is_post()) {
        verify_csrf();
        // One row's own button (id), or the ticked rows (ids[]).
        $ids = array_filter(array_map('intval', isset($_POST['id']) ? [$_POST['id']] : (is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [])));
        $note = trim((string)($_POST['note'] ?? ''));
        if ($ids) {
            $in = implode(',', $ids);
            if (query('do') === 'uncheck') {
                db_exec("UPDATE service_changes SET checked_at = NULL, checked_by = NULL WHERE id IN ($in)");
                flash(count($ids) === 1 ? 'Marked as not checked.' : count($ids) . ' marked as not checked.');
            } else {
                db_exec("UPDATE service_changes SET checked_at = NOW(), checked_by = ?, check_note = COALESCE(NULLIF(?, ''), check_note) WHERE id IN ($in) AND checked_at IS NULL",
                    [current_user()['id'], mb_substr($note, 0, 500)]);
                if ($note !== '' && count($ids) === 1) {
                    db_exec('UPDATE service_changes SET check_note = ? WHERE id = ?', [mb_substr($note, 0, 500), $ids[0]]);
                }
                audit('update', 'Billing diary ' . date('F Y', strtotime($month . '-01')) . ': ' . count($ids) . ' change' . (count($ids) === 1 ? '' : 's') . ' checked');
                flash(count($ids) === 1 ? 'Checked.' : count($ids) . ' changes checked.');
            }
        }
        redirect($back);
    }

    $rows = billing_diary_rows($month, $filters);
    if (query('export') === 'csv') {
        require_permission('export');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="billing-diary-' . $month . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Customer', 'Account no.', 'Service', 'Change', 'From', 'To', 'Monthly change', 'One-off', 'Effective', 'By', 'Checked', 'Checked by', 'Note', 'aBILLity charge']);
        foreach ($rows as $r) {
            fputcsv($out, [substr($r['created_at'], 0, 16), $r['account_name'], $r['account_number'], $r['summary'], SERVICE_CHANGE_TYPES[$r['change_type']] ?? $r['change_type'],
                $r['from_value'], $r['to_value'], $r['monthly_change'], $r['one_off'], $r['effective_date'], $r['user_name'] ?: 'System',
                $r['checked_at'] ? substr($r['checked_at'], 0, 16) : '', $r['checked_by_name'], $r['check_note'], $r['abillity_charge_id']]);
        }
        exit;
    }
    $months = array_column(db_all("SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') AS m FROM service_changes ORDER BY m DESC LIMIT 36"), 'm');
    if (!in_array(date('Y-m'), $months, true)) {
        array_unshift($months, date('Y-m'));
    }
    $account = $filters['account_id'] ? db_one('SELECT id, name FROM accounts WHERE id = ?', [$filters['account_id']]) : null;
    page('billing_diary', ['month' => $month, 'filters' => $filters, 'rows' => $rows, 'summary' => billing_diary_summary($month), 'months' => $months, 'account' => $account],
        'Billing diary ' . date('F Y', strtotime($month . '-01')));
}
