<?php
declare(strict_types=1);

/*
 * Generic persistence for the entities defined in entities.php.
 * Column names only ever come from entity definitions, never from user input,
 * and all values are bound as parameters.
 */

const PER_PAGE = 25;

/**
 * Validate and normalise raw form input.
 * Returns [array $data, array $errors] where errors are keyed by field.
 */
function validate(array $entity, array $input): array
{
    $data = [];
    $errors = [];

    foreach ($entity['fields'] as $field => $def) {
        if (!empty($def['readonly']) || !field_enabled($def)) {
            continue;
        }
        $raw = $input[$field] ?? null;
        $raw = is_int($raw) || is_float($raw) ? (string)$raw : $raw;
        $raw = is_string($raw) ? trim($raw) : $raw;
        $label = $def['label'];

        if ($def['type'] === 'checkboxes') {
            $picked = is_array($raw) ? array_values(array_intersect(array_keys($def['options']), array_map('strval', $raw))) : [];
            $data[$field] = $picked ? implode(',', $picked) : null;
            continue;
        }
        if ($def['type'] === 'bool') {
            $data[$field] = !empty($raw) ? 1 : 0;
            continue;
        }
        if (is_array($raw)) {
            $errors[$field] = "$label is invalid.";
            continue;
        }
        if ($raw === null || $raw === '') {
            if (!empty($def['required'])) {
                $errors[$field] = "$label is required.";
            }
            $data[$field] = null;
            continue;
        }

        switch ($def['type']) {
            case 'email':
                if (!filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = "$label must be a valid email address.";
                }
                $data[$field] = strtolower($raw);
                break;

            case 'money':
                $clean = str_replace([',', '£', ' '], '', $raw);
                if (!is_numeric($clean) || (float)$clean < 0) {
                    $errors[$field] = "$label must be a positive amount.";
                }
                $data[$field] = round((float)$clean, 2);
                break;

            case 'int':
            case 'ref':
                if (!preg_match('/^\d+$/', $raw)) {
                    $errors[$field] = "$label must be a whole number.";
                    break;
                }
                $value = (int)$raw;
                if (isset($def['min']) && $value < $def['min']) {
                    $errors[$field] = "$label must be at least {$def['min']}.";
                }
                if (isset($def['max']) && $value > $def['max']) {
                    $errors[$field] = "$label must be at most {$def['max']}.";
                }
                if ($def['type'] === 'ref' && !db_value("SELECT 1 FROM {$def['ref']} WHERE id = ?", [$value])) {
                    $errors[$field] = "$label does not exist.";
                }
                $data[$field] = $value;
                break;

            case 'term':
                // One of the set terms; an older value already on the record can be kept.
                if (!preg_match('/^\d+$/', $raw) || (int)$raw > 120) {
                    $errors[$field] = "Choose a $label.";
                    break;
                }
                $data[$field] = (int)$raw;
                break;

            case 'date':
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
                if (!$d || $d->format('Y-m-d') !== $raw) {
                    $errors[$field] = "$label must be a valid date.";
                }
                $data[$field] = $raw;
                break;

            case 'select':
                if (!array_key_exists($raw, $def['options'])) {
                    $errors[$field] = "$label has an invalid value.";
                }
                $data[$field] = $raw;
                break;

            default:
                if (mb_strlen($raw) > ($def['type'] === 'textarea' ? 65000 : 255)) {
                    $errors[$field] = "$label is too long.";
                }
                $data[$field] = $raw;
        }
    }

    return [$data, $errors];
}

/** Check that scoped refs (e.g. a ticket's service) belong to the chosen customer. */
function validate_scoped_refs(array $entity, array $data): array
{
    $errors = [];
    foreach ($entity['fields'] as $field => $def) {
        if (empty($def['scoped']) || empty($data[$field]) || empty($data['account_id'])) {
            continue;
        }
        $owner = db_value("SELECT account_id FROM {$def['ref']} WHERE id = ?", [$data[$field]]);
        if ((int)$owner !== (int)$data['account_id']) {
            $errors[$field] = "{$def['label']} does not belong to the selected customer.";
        }
    }
    return $errors;
}

/** Build SELECT columns and JOINs for an entity's ref fields. */
function select_parts(array $entity): array
{
    $cols = ['t.*'];
    $joins = [];
    $i = 0;
    foreach ($entity['fields'] as $field => $def) {
        if ($def['type'] !== 'ref') {
            continue;
        }
        $alias = 'r' . $i++;
        $label = REF_LABELS[$def['ref']];
        $cols[] = "$alias.$label AS `{$field}__label`";
        if (isset(EXTERNAL_REFS[$def['ref']])) {
            $cols[] = "$alias." . EXTERNAL_REFS[$def['ref']][0] . " AS `{$field}__ext`";
        }
        $joins[] = "LEFT JOIN {$def['ref']} $alias ON $alias.id = t.$field";
    }
    foreach ($entity['computed'] ?? [] as $name => $def) {
        $cols[] = "{$def['sql']} AS `$name`";
    }
    return [implode(', ', $cols), implode(' ', $joins)];
}

function find(string $name, int $id): ?array
{
    $entity = entity($name);
    [$cols, $joins] = select_parts($entity);
    return db_one("SELECT $cols FROM $name t $joins WHERE t.id = ?", [$id]);
}

/**
 * List rows with search, filters, preset, sort and pagination.
 * $opts: q, filters[field=>value], preset, sort, dir, page, per_page (0 = all)
 * Returns ['rows' => [...], 'total' => int].
 */
function list_rows(string $name, array $opts = []): array
{
    $entity = entity($name);
    [$cols, $joins] = select_parts($entity);
    $where = [];
    $params = [];

    $q = trim((string)($opts['q'] ?? ''));
    if ($q !== '' && !empty($entity['search'])) {
        $likes = [];
        foreach ($entity['search'] as $i => $field) {
            $likes[] = "t.$field LIKE :q$i";
            $params["q$i"] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $where[] = '(' . implode(' OR ', $likes) . ')';
    }

    foreach ($opts['filters'] ?? [] as $field => $value) {
        if ($value === '' || $value === null || !isset($entity['fields'][$field])) {
            continue;
        }
        $where[] = "t.$field = :f_$field";
        $params["f_$field"] = $value;
    }

    // Records the signed-in user may see (e.g. tickets in their groups).
    if (!empty($entity['scope']) && ($scope = ($entity['scope'])())) {
        $where[] = $scope[0];
        $params += $scope[1];
    }

    $preset = $entity['presets'][$opts['preset'] ?? ''] ?? null;
    if ($preset) {
        $where[] = '(' . $preset['sql'] . ')';
        if (isset($preset['params'])) {
            $params += ($preset['params'])();
        }
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    [$defaultSort, $defaultDir] = $entity['default_sort'] ?? ['id', 'desc'];
    $sort = $opts['sort'] ?? '';
    if (isset($entity['computed'][$sort])) {
        $sortSql = "`$sort`";
    } elseif (isset($entity['fields'][$sort]) && empty($entity['fields'][$sort]['virtual'])) {
        $sortSql = ($entity['fields'][$sort]['type'] === 'ref') ? "`{$sort}__label`" : "t.$sort";
    } else {
        $sortSql = "t.$defaultSort";
    }
    $dir = strtolower($opts['dir'] ?? ($sort ? 'asc' : $defaultDir)) === 'desc' ? 'DESC' : 'ASC';
    // Put NULLs last regardless of direction.
    $order = "ORDER BY ($sortSql IS NULL), $sortSql $dir, t.id DESC";

    $total = (int)db_value("SELECT COUNT(*) FROM $name t $joins $whereSql", $params);

    $perPage = (int)($opts['per_page'] ?? PER_PAGE);
    $limit = '';
    if ($perPage > 0) {
        $page = max(1, (int)($opts['page'] ?? 1));
        $limit = sprintf('LIMIT %d OFFSET %d', $perPage, ($page - 1) * $perPage);
    }

    $rows = db_all("SELECT $cols FROM $name t $joins $whereSql $order $limit", $params);
    return ['rows' => $rows, 'total' => $total];
}

/** Form-only fields that aren't table columns (handled in after_save()). */
function virtual_fields(string $name): array
{
    return array_filter(entity($name)['fields'] ?? [], fn($def) => !empty($def['virtual']));
}

function insert_row(string $name, array $data): int
{
    $virtual = array_intersect_key($data, virtual_fields($name));
    $data = before_save($name, array_diff_key($data, $virtual), null);
    $cols = array_keys($data);
    $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $name,
        implode(', ', $cols), implode(', ', array_map(fn($c) => ":$c", $cols)));
    db_exec($sql, $data);
    $id = (int)db()->lastInsertId();
    after_insert($name, $id, $data);
    after_save($name, $id, $data + $virtual, null);
    return $id;
}

function update_row(string $name, int $id, array $data): void
{
    $existing = db_one("SELECT * FROM $name WHERE id = ?", [$id]);
    if (!$existing) {
        throw new RuntimeException('Record not found');
    }
    $virtual = array_intersect_key($data, virtual_fields($name));
    $data = before_save($name, array_diff_key($data, $virtual), $existing);
    $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($data)));
    db_exec("UPDATE $name SET $sets WHERE id = :_id", $data + ['_id' => $id]);
    after_save($name, $id, $data + $virtual, $existing);
}

function delete_row(string $name, int $id): void
{
    db_exec("DELETE FROM $name WHERE id = ?", [$id]);
}

/** Options for a ref <select>, optionally limited to one customer. */
function ref_options(string $ref, ?int $accountId = null, ?string $extraWhere = null): array
{
    $label = REF_LABELS[$ref];
    $where = [];
    $params = [];
    if ($ref === 'users') {
        $where[] = 'active = 1';
    }
    if ($ref === 'products' || $ref === 'ticket_groups') {
        $where[] = 'active = 1';
    }
    if ($extraWhere !== null) {
        $where[] = $extraWhere; // from entity definitions only, never user input
    }
    if ($accountId !== null && in_array($ref, ['services', 'contacts', 'opportunities', 'sites'], true)) {
        $where[] = 'account_id = ?';
        $params[] = $accountId;
    }
    $sql = "SELECT id, $label AS label" . ($ref === 'products' ? ', monthly_price, billing_frequency' : '') . ($ref === 'sites' ? ', postcode' : '') . " FROM $ref"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY $label";
    $out = [];
    foreach (db_all($sql, $params) as $row) {
        $out[$row['id']] = $row['label'] . (isset($row['monthly_price']) ? ' (' . money($row['monthly_price']) . ' ' . strtolower(BILLING_FREQUENCIES[$row['billing_frequency']] ?? 'monthly') . ')' : '')
            . (!empty($row['postcode']) ? ' (' . $row['postcode'] . ')' : '');
    }
    return $out;
}

/** Render a single cell value for list views and detail pages. */
function display_value(array $entity, string $column, array $row, bool $link = true): string
{
    $def = column_def($entity, $column) ?? ['type' => 'text'];
    $value = $row[$column] ?? null;

    switch ($def['type']) {
        case 'ref':
            $label = $row[$column . '__label'] ?? null;
            if ($label === null) {
                return '<span class="muted">—</span>';
            }
            if (isset(EXTERNAL_REFS[$def['ref']]) && !empty($row[$column . '__ext'])) {
                $href = (EXTERNAL_REFS[$def['ref']][1])($row[$column . '__ext']);
                if ($def['ref'] === 'xero_contacts') {
                    return xero_link($href, h($label));
                }
                return '<a href="' . h($href) . '" target="_blank" rel="noopener">' . h($label) . ' ↗</a>';
            }
            if ($link && entity($def['ref']) !== null) {
                return '<a href="' . h(url($def['ref'], ['action' => 'view', 'id' => $value])) . '">' . h($label) . '</a>';
            }
            return h($label);
        case 'select':
            if ($value === null || $value === '') {
                return '<span class="muted">—</span>';
            }
            return in_array($column, ['status', 'stage', 'priority', 'type'], true)
                ? badge($value)
                : h($def['options'][$value] ?? humanize($value));
        case 'money':
            return $value === null ? '<span class="muted">—</span>' : h(money($value));
        case 'term':
            return $value === null ? '<span class="muted">—</span>' : h(term_label($value));
        case 'mandate':
            return gc_mandate_badge($value);
        case 'percent':
            return $value === null ? '<span class="muted">—</span>' : '<span class="' . ((float)$value < 0 ? 'text-danger' : '') . '">' . h(rtrim(rtrim(number_format((float)$value, 1), '0'), '.')) . '%</span>';
        case 'xero_item':
            return match ($value) {
                'sent'    => '<span class="badge badge-active">In Xero</span>',
                'changed' => '<span class="badge badge-suspended">Changed</span>',
                'error'   => '<span class="badge badge-failed">Problem</span>',
                default   => '<span class="muted">—</span>',
            };
        case 'bool':
            return $value ? '✔' : '<span class="muted">—</span>';
        case 'checkboxes':
            return $value ? h(checkbox_labels($def, $value)) : '<span class="muted">—</span>';
        case 'date':
            if ($column === 'contract_end_date' && $value) {
                return contract_end_html($value);
            }
            return h(fmt_date($value));
        case 'datetime':
            if ($column === 'sla_due_at' && $value) {
                return sla_html($value, $row['status'] ?? null, $row['resolved_at'] ?? null);
            }
            return h(fmt_datetime($value));
        case 'textarea':
            return nl2br(h($value));
        case 'email':
            return $value ? '<a href="mailto:' . h($value) . '">' . h($value) . '</a>' : '';
        case 'tel':
            return $value ? '<a href="tel:' . h(preg_replace('/[^\d+]/', '', $value)) . '">' . h($value) . '</a>' : '';
        default:
            return h($value);
    }
}

function contract_end_html(string $date): string
{
    $days = days_until($date);
    $window = (int)config('renewal_window_days');
    $class = $days < 0 ? 'text-danger' : ($days <= $window ? 'text-warning' : '');
    $hint = $days < 0 ? 'out of contract' : ($days <= $window ? "{$days}d left" : '');
    return '<span class="' . $class . '">' . h(fmt_date($date)) . '</span>'
        . ($hint ? ' <small class="' . $class . '">(' . h($hint) . ')</small>' : '');
}

function sla_html(string $due, ?string $status, ?string $resolvedAt): string
{
    $dueTs = strtotime($due);
    if (in_array($status, ['resolved', 'closed'], true)) {
        $met = $resolvedAt && strtotime($resolvedAt) <= $dueTs;
        return $met ? '<span class="text-ok">Met</span>' : '<span class="text-danger">Missed</span>';
    }
    $mins = (int)round(($dueTs - time()) / 60);
    if ($mins < 0) {
        return '<span class="text-danger">Breached ' . h(duration_label(-$mins)) . ' ago</span>';
    }
    $class = $mins < 120 ? 'text-warning' : '';
    return '<span class="' . $class . '">' . h(duration_label($mins)) . ' left</span>';
}

function duration_label(int $minutes): string
{
    if ($minutes < 60) {
        return $minutes . 'm';
    }
    if ($minutes < 60 * 48) {
        return intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm';
    }
    return intdiv($minutes, 1440) . 'd';
}

/** Plain-text value for CSV export. */
function export_value(array $entity, string $column, array $row): string
{
    $def = column_def($entity, $column) ?? ['type' => 'text'];
    $value = $row[$column] ?? null;
    return match ($def['type']) {
        'ref'    => (string)($row[$column . '__label'] ?? ''),
        'select' => (string)($def['options'][$value] ?? $value ?? ''),
        'bool'   => $value ? 'Yes' : 'No',
        'term'   => term_label($value),
        'money'  => $value === null ? '' : number_format((float)$value, 2, '.', ''),
        'mandate' => gc_mandate_label($value),
        'percent' => $value === null ? '' : (string)$value,
        'xero_item' => ['sent' => 'In Xero', 'changed' => 'Changed since sent', 'error' => 'Problem', 'not_sent' => ''][$value] ?? '',
        'checkboxes' => checkbox_labels($def, $value),
        default  => (string)($value ?? ''),
    };
}

function checkbox_labels(array $def, ?string $value): string
{
    $keys = array_filter(explode(',', (string)$value));
    return implode(', ', array_map(fn($k) => $def['options'][$k] ?? humanize($k), $keys));
}
