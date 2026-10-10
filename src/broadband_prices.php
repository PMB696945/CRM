<?php
declare(strict_types=1);

/*
 * Broadband prices: every broadband product on one page with what it costs us (buy), what customers pay (sell)
 * and the dealer price, edited in place or updated in bulk from a CSV (previewed before anything changes).
 */

/** CSV columns, in order: column => [label, kind]. */
const BB_PRICE_COLUMNS = [
    'sku'                  => ['SKU', 'text'],
    'name'                 => ['Product', 'text'],
    'supplier_product_ids' => ['Supplier product IDs', 'text'],
    'term_months'          => ['Term (months)', 'term'],
    'cost_price'           => ['Buy (monthly)', 'money'],
    'monthly_price'        => ['Sell (monthly)', 'money'],
    'setup_fee'            => ['Sell setup fee', 'money'],
    'dealer_price'         => ['Dealer (monthly)', 'money'],
    'dealer_setup_fee'     => ['Dealer setup fee', 'money'],
    'active'               => ['Active', 'bool'],
];

/** Header names a CSV may use for each column (as well as the labels above). */
const BB_PRICE_ALIASES = [
    'cost' => 'cost_price', 'buy' => 'cost_price', 'buy price' => 'cost_price', 'cost price' => 'cost_price',
    'sell' => 'monthly_price', 'sell price' => 'monthly_price', 'price' => 'monthly_price', 'monthly price' => 'monthly_price',
    'dealer' => 'dealer_price', 'dealer price' => 'dealer_price', 'setup' => 'setup_fee', 'setup fee' => 'setup_fee',
    'product' => 'name', 'product name' => 'name', 'term' => 'term_months', 'giacom product ids' => 'supplier_product_ids',
];

function bb_products(bool $withInactive = true): array
{
    return db_all("SELECT * FROM products WHERE category = 'broadband'" . ($withInactive ? '' : ' AND active = 1') . ' ORDER BY active DESC, name');
}

/** Margin on a price: (price − cost) ÷ price, or null. */
function bb_margin(mixed $price, mixed $cost): ?float
{
    return $price !== null && $cost !== null && (float)$price > 0 ? ((float)$price - (float)$cost) / (float)$price : null;
}

/** Columns this user may change (buy prices need "edit costs"). */
function bb_editable_columns(): array
{
    return array_filter(array_keys(BB_PRICE_COLUMNS), fn($c) => $c !== 'cost_price' || can('costs.edit'));
}

/** Read one value the way a cell or form field gives it. Returns [ok, value]; blank means "no value". */
function bb_parse(string $column, string $raw): array
{
    $raw = trim($raw);
    return match (BB_PRICE_COLUMNS[$column][1]) {
        'money' => $raw === '' ? [true, null] : (($v = price_parse_money($raw)) === null ? [false, null] : [true, $v]),
        'term'  => $raw === '' ? [true, null] : (ctype_digit($raw) && (int)$raw <= 120 ? [true, (int)$raw] : [false, null]),
        'bool'  => [true, in_array(strtolower($raw), ['1', 'yes', 'y', 'true', 'active', 'on'], true) ? 1 : 0],
        default => [true, $raw === '' ? null : mb_substr($raw, 0, $column === 'sku' ? 40 : ($column === 'name' ? 150 : 255))],
    };
}

/** Is a new value different from the stored one? */
function bb_differs(string $column, mixed $old, mixed $new): bool
{
    if (BB_PRICE_COLUMNS[$column][1] === 'money') {
        return ($old === null) !== ($new === null) || ($old !== null && abs((float)$old - (float)$new) >= 0.005);
    }
    return (string)($old ?? '') !== (string)($new ?? '');
}

/**
 * Work out what a set of rows (from the page or a CSV) would change.
 * $rows: [['line' => label, 'id' => ?int, 'values' => [column => raw string]], ...]. Only columns given are touched;
 * in a CSV a blank cell leaves the value as it is (except the page, which sends every field).
 * Returns ['changes' => [id => [column => [old, new]]], 'new' => [[column => value]], 'errors' => [...], 'same' => n].
 */
function bb_plan(array $rows, bool $blankClears): array
{
    $products = [];
    foreach (db_all('SELECT * FROM products') as $p) {
        $products[(int)$p['id']] = $p;
    }
    $bySku = [];
    foreach ($products as $p) {
        $bySku[mb_strtolower((string)$p['sku'])] = $p;
    }
    $editable = bb_editable_columns();
    $plan = ['changes' => [], 'new' => [], 'errors' => [], 'same' => 0];
    $seen = [];
    foreach ($rows as $row) {
        $line = $row['line'];
        $vals = [];
        foreach ($row['values'] as $col => $raw) {
            if (!isset(BB_PRICE_COLUMNS[$col])) {
                continue;
            }
            [$ok, $v] = bb_parse($col, (string)$raw);
            if (!$ok) {
                $plan['errors'][] = "$line: \"" . mb_substr((string)$raw, 0, 30) . '" isn\'t a valid ' . strtolower(BB_PRICE_COLUMNS[$col][0]) . '.';
                continue 2;
            }
            $vals[$col] = $v;
        }
        $sku = (string)($vals['sku'] ?? '');
        $product = $row['id'] ? ($products[$row['id']] ?? null) : ($sku !== '' ? ($bySku[mb_strtolower($sku)] ?? null) : null);
        if ($product && $product['category'] !== 'broadband') {
            $plan['errors'][] = "$line: {$product['sku']} is a " . (SERVICE_TYPES[$product['category']] ?? $product['category']) . ' product, not broadband; change it under Products & tariffs.';
            continue;
        }
        if ($product) {
            if (isset($seen[$product['id']])) {
                $plan['errors'][] = "$line: {$product['sku']} appears more than once; only the first is used.";
                continue;
            }
            $seen[$product['id']] = true;
            $diff = [];
            foreach ($vals as $col => $v) {
                if (!in_array($col, $editable, true) || ($v === null && !$blankClears)) {
                    continue;
                }
                if ($col === 'setup_fee' && $v === null) {
                    $v = 0.0; // no setup fee
                }
                if (in_array($col, ['sku', 'name', 'monthly_price', 'term_months'], true) && $v === null) {
                    continue; // required: blank leaves it as it is
                }
                if ($col === 'sku' && $v !== null && mb_strtolower($v) !== mb_strtolower((string)$product['sku']) && isset($bySku[mb_strtolower($v)])) {
                    $plan['errors'][] = "$line: SKU $v is already used by another product.";
                    continue 2;
                }
                if (bb_differs($col, $product[$col], $v)) {
                    $diff[$col] = [$product[$col], $v];
                }
            }
            $diff ? $plan['changes'][(int)$product['id']] = $diff : $plan['same']++;
            continue;
        }
        if ($row['id']) {
            $plan['errors'][] = "$line: that product no longer exists.";
            continue;
        }
        // A new product: needs a SKU, a name and a selling price.
        if ($sku === '' && ($vals['name'] ?? null) === null) {
            continue; // blank row
        }
        if ($sku === '' || ($vals['name'] ?? null) === null || ($vals['monthly_price'] ?? null) === null) {
            $plan['errors'][] = "$line: a new product needs a SKU, a product name and a sell price.";
            continue;
        }
        if (isset($seen['new:' . mb_strtolower($sku)])) {
            $plan['errors'][] = "$line: $sku appears more than once; only the first is used.";
            continue;
        }
        $seen['new:' . mb_strtolower($sku)] = true;
        $plan['new'][] = array_intersect_key($vals, array_flip($editable)) + ['active' => 1, 'term_months' => 24];
    }
    return $plan;
}

/** Apply a plan. Returns [updated, added]. */
function bb_apply(array $plan, string $how): array
{
    db()->beginTransaction();
    try {
        foreach ($plan['changes'] as $id => $diff) {
            $p = db_one('SELECT name FROM products WHERE id = ?', [$id]);
            update_row('products', (int)$id, array_map(fn($d) => $d[1], $diff));
            $changes = [];
            foreach ($diff as $col => [$old, $new]) {
                $changes[BB_PRICE_COLUMNS[$col][0]] = ['from' => (string)($old ?? ''), 'to' => (string)($new ?? '')];
            }
            audit('update', "Broadband prices ($how): {$p['name']} updated", 'products', (int)$id, null, $changes);
        }
        foreach ($plan['new'] as $n) {
            $id = insert_row('products', $n + ['category' => 'broadband', 'billing_frequency' => 'monthly']);
            audit('create', "Broadband prices ($how): {$n['name']} added", 'products', $id);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return [count($plan['changes']), count($plan['new'])];
}

/** The columns this user may export (buy prices need "see costs"). */
function bb_export_columns(): array
{
    return array_values(array_filter(array_keys(BB_PRICE_COLUMNS), fn($c) => $c !== 'cost_price' || can('costs.view')));
}

/** The products as rows of text with a header row: the export, and the template to upload again. */
function bb_export_rows(array $products): array
{
    $cols = bb_export_columns();
    $rows = [array_map(fn($c) => BB_PRICE_COLUMNS[$c][0], $cols)];
    foreach ($products as $p) {
        $rows[] = array_map(fn($c) => match (BB_PRICE_COLUMNS[$c][1]) {
            'money' => $p[$c] === null ? '' : number_format((float)$p[$c], 2, '.', ''),
            'bool' => $p[$c] ? 'Yes' : 'No',
            default => (string)($p[$c] ?? ''),
        }, $cols);
    }
    return $rows;
}

/** The products as an Excel file at $path. */
function bb_xlsx(array $products, string $path): void
{
    $kinds = array_map(fn($c) => ['money' => 'money', 'term' => 'int'][BB_PRICE_COLUMNS[$c][1]] ?? 'text', bb_export_columns());
    xlsx_write($path, bb_export_rows($products), $kinds, 'Broadband prices');
}

/** The products as CSV rows (for download, and as the upload template). */
function bb_csv(array $products): string
{
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, "\xEF\xBB\xBF");
    foreach (bb_export_rows($products) as $i => $row) {
        fputcsv($fh, $i ? array_map('csv_safe', $row) : $row, escape: '');
    }
    rewind($fh);
    $csv = (string)stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

/** Map a CSV's header row to our columns. Returns [column => index]. */
function bb_csv_map(array $header): array
{
    $map = [];
    foreach ($header as $i => $h) {
        $key = strtolower(trim(preg_replace('/\s*\(.*?\)\s*|[£*]/u', ' ', (string)$h)));
        $key = trim(preg_replace('/\s+/', ' ', $key));
        foreach (BB_PRICE_COLUMNS as $col => [$label]) {
            $l = strtolower(trim(preg_replace('/\s*\(.*?\)\s*/', ' ', $label)));
            if ($key === $l || $key === $col || $key === str_replace('_', ' ', $col)) {
                $map[$col] ??= $i;
            }
        }
        if (isset(BB_PRICE_ALIASES[$key])) {
            $map[BB_PRICE_ALIASES[$key]] ??= $i;
        }
    }
    return $map;
}

function broadband_prices_controller(): void
{
    require_permission('products.edit');
    $action = query('action');
    if ($action === 'csv') {
        audit('export', 'Broadband prices exported to CSV', 'products');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="broadband-prices-' . date('Y-m-d') . '.csv"');
        echo bb_csv(bb_products());
        exit;
    }
    if ($action === 'xlsx') {
        audit('export', 'Broadband prices exported to Excel', 'products');
        $path = storage_path('tmp') . '/bb-prices-' . bin2hex(random_bytes(4)) . '.xlsx';
        bb_xlsx(bb_products(), $path);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="broadband-prices-' . date('Y-m-d') . '.xlsx"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        @unlink($path);
        exit;
    }
    if (is_post()) {
        verify_csrf();
        try {
            if ($action === 'save') {
                // The page: every row's fields; a blank optional field clears it.
                $rows = [];
                foreach ((array)($_POST['p'] ?? []) as $key => $fields) {
                    if (!is_array($fields)) {
                        continue;
                    }
                    $fields['active'] = !empty($fields['active']) ? '1' : '0';
                    $rows[] = ['line' => $key === 'new' ? 'New product' : (string)($fields['name'] ?? 'A row'), 'id' => $key === 'new' ? null : (int)$key,
                        'values' => array_map('strval', $fields)];
                }
                if (isset($_POST['p']['new']) && trim((string)($_POST['p']['new']['sku'] ?? '') . ($_POST['p']['new']['name'] ?? '')) === '') {
                    array_pop($rows); // the empty "add a product" row
                }
                $plan = bb_plan($rows, true);
                if ($plan['errors']) {
                    throw new IntegrationException('Nothing was saved. ' . implode(' ', $plan['errors']));
                }
                [$u, $a] = bb_apply($plan, 'edited');
                flash($u || $a ? "Saved: $u product" . ($u === 1 ? '' : 's') . ' updated' . ($a ? ", $a added" : '') . '.' : 'Nothing had changed.');
            } elseif ($action === 'upload') {
                $file = $_FILES['file'] ?? null;
                if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                    throw new IntegrationException('Choose a CSV file to upload.');
                }
                $rows = price_file_rows($file['tmp_name'], (string)$file['name']);
                if (count($rows) < 2) {
                    throw new IntegrationException('That file has no rows of prices.');
                }
                $map = bb_csv_map($rows[0]);
                if (!isset($map['sku'])) {
                    throw new IntegrationException('The file needs a SKU column, so each row can be matched to a product. Download the CSV here to start from.');
                }
                $items = [];
                foreach (array_slice($rows, 1) as $i => $r) {
                    $items[] = ['line' => 'Row ' . ($i + 2), 'id' => null, 'values' => array_map(fn($idx) => (string)($r[$idx] ?? ''), $map)];
                }
                $plan = bb_plan($items, false);
                $token = bin2hex(random_bytes(8));
                $_SESSION['bb_import'] = ['token' => $token, 'plan' => $plan, 'file' => (string)$file['name']];
                page('broadband_prices_preview', ['plan' => $plan, 'token' => $token, 'file' => (string)$file['name'], 'columns' => array_keys($map)], 'Check the price changes');
                return;
            } elseif ($action === 'confirm') {
                $saved = $_SESSION['bb_import'] ?? null;
                if (!$saved || !hash_equals($saved['token'], (string)($_POST['token'] ?? ''))) {
                    throw new IntegrationException('That upload has expired. Please upload the file again.');
                }
                unset($_SESSION['bb_import']);
                [$u, $a] = bb_apply($saved['plan'], 'CSV ' . $saved['file']);
                flash("Prices updated from {$saved['file']}: $u product" . ($u === 1 ? '' : 's') . ' changed' . ($a ? ", $a added" : '') . '.');
            }
        } catch (IntegrationException $e) {
            flash($e->getMessage(), 'error');
        }
        redirect(url('broadband_prices', query('inactive') ? ['inactive' => 1] : []));
    }
    $showInactive = query('inactive') === '1';
    page('broadband_prices', ['products' => bb_products($showInactive), 'showInactive' => $showInactive,
        'canCost' => can('costs.view'), 'canEditCost' => can('costs.edit')], 'Broadband prices');
}
