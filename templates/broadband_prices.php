<?php
$money = fn($v) => $v === null || $v === '' ? '' : number_format((float)$v, 2, '.', '');
$pct = fn(?float $m) => $m === null ? '—' : number_format($m * 100, 1) . '%';
$terms = TERM_OPTIONS;
$row = function (string $key, array $p) use ($money, $terms, $canCost, $canEditCost): string {
    $n = fn($c) => 'p[' . $key . '][' . $c . ']';
    $in = fn($c, $v, $cls = '', $extra = '') => '<input name="' . $n($c) . '" value="' . h((string)$v) . '" class="' . $cls . '" ' . $extra . '>';
    $termSel = '<select name="' . $n('term_months') . '" aria-label="Term">';
    foreach (term_options($p['term_months'] ?? 24) as $m => $l) {
        $termSel .= '<option value="' . (int)$m . '"' . ((string)($p['term_months'] ?? 24) === (string)$m ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    $termSel .= '</select>';
    $margin = bb_margin($p['monthly_price'] ?? null, $p['cost_price'] ?? null);
    $dealerMargin = bb_margin($p['dealer_price'] ?? null, $p['cost_price'] ?? null);
    $html = '<tr data-bb-row' . (isset($p['active']) && !$p['active'] ? ' class="muted"' : '') . '>'
        . '<td>' . $in('name', $p['name'] ?? '', 'w-full', 'aria-label="Product" placeholder="' . ($key === 'new' ? 'New product name' : '') . '"')
        . '<div class="small">' . $in('sku', $p['sku'] ?? '', 'w-full', 'aria-label="SKU" placeholder="SKU"') . '</div></td>'
        . '<td>' . $in('supplier_product_ids', $p['supplier_product_ids'] ?? '', 'w-full', 'aria-label="Supplier product IDs" placeholder="e.g. 34350"') . '</td>'
        . '<td>' . $termSel . '</td>';
    if ($canCost) {
        $html .= '<td class="num">' . ($canEditCost ? $in('cost_price', $money($p['cost_price'] ?? null), 'num', 'inputmode="decimal" data-bb="buy" aria-label="Buy price"')
            : '<span data-bb-buy="' . h($money($p['cost_price'] ?? null)) . '">' . h($money($p['cost_price'] ?? null)) . '</span>') . '</td>';
    }
    $html .= '<td class="num">' . $in('monthly_price', $money($p['monthly_price'] ?? null), 'num', 'inputmode="decimal" data-bb="sell" aria-label="Sell price"') . '</td>'
        . ($canCost ? '<td class="num" data-bb-margin>' . ($margin === null ? '—' : number_format($margin * 100, 1) . '%') . '</td>' : '')
        . '<td class="num">' . $in('setup_fee', $money($p['setup_fee'] ?? null), 'num', 'inputmode="decimal" aria-label="Setup fee"') . '</td>'
        . '<td class="num">' . $in('dealer_price', $money($p['dealer_price'] ?? null), 'num', 'inputmode="decimal" data-bb="dealer" aria-label="Dealer price"') . '</td>'
        . ($canCost ? '<td class="num" data-bb-dealer-margin>' . ($dealerMargin === null ? '—' : number_format($dealerMargin * 100, 1) . '%') . '</td>' : '')
        . '<td class="num">' . $in('dealer_setup_fee', $money($p['dealer_setup_fee'] ?? null), 'num', 'inputmode="decimal" aria-label="Dealer setup fee"') . '</td>'
        . '<td class="center"><input type="checkbox" name="' . $n('active') . '" value="1"' . (($p['active'] ?? 1) ? ' checked' : '') . ' aria-label="Active"></td>'
        . '<td>' . ($key !== 'new' ? '<a class="small" href="' . h(url('products', ['action' => 'view', 'id' => $p['id']])) . '">Open</a>' : '') . '</td></tr>';
    return $html;
};
?>
<div class="page-head">
  <div><h1>Broadband prices <span class="count"><?= count($products) ?></span></h1>
    <p class="muted">What each broadband product costs you, what customers pay and the dealer price, per month, ex VAT. Edit in the table and press Save, or update in bulk with a CSV.</p></div>
  <div class="actions">
    <a class="btn" href="<?= h(url('broadband_prices', ['action' => 'csv'])) ?>">Download CSV</a>
    <a class="btn btn-ghost" href="<?= h(url('broadband_prices', $showInactive ? [] : ['inactive' => 1])) ?>"><?= $showInactive ? 'Hide' : 'Show' ?> inactive</a>
  </div>
</div>

<form method="post" action="<?= h(url('broadband_prices', ['action' => 'save'] + ($showInactive ? ['inactive' => 1] : []))) ?>">
  <?= csrf_field() ?>
  <div class="table-wrap"><table class="table compact bb-prices">
    <thead><tr>
      <th>Product / SKU</th><th>Supplier product IDs</th><th>Term</th>
      <?php if ($canCost): ?><th class="num">Buy</th><?php endif; ?>
      <th class="num">Sell</th><?php if ($canCost): ?><th class="num">Margin</th><?php endif; ?><th class="num">Setup fee</th>
      <th class="num">Dealer</th><?php if ($canCost): ?><th class="num">Our margin on dealer</th><?php endif; ?><th class="num">Dealer setup</th>
      <th class="center">Active</th><th></th>
    </tr></thead>
    <tbody>
      <?php foreach ($products as $p): ?><?= $row((string)$p['id'], $p) ?><?php endforeach; ?>
      <tr class="bb-new-label"><td colspan="13" class="small muted">Add a product: fill in the row below (SKU, name and sell price are needed).</td></tr>
      <?= $row('new', ['term_months' => 24, 'active' => 1]) ?>
    </tbody>
  </table></div>
  <div class="form-actions" style="margin-top:1rem"><button class="btn btn-primary">Save prices</button>
    <span class="help">Margin is (price − buy) ÷ price.<?= $canCost ? '' : ' Buy prices are hidden: your role can\'t see costs.' ?> Products with a dealer price and supplier product IDs are offered on the partner portal.</span></div>
</form>

<section class="card" style="margin-top:1.5rem">
  <div class="card-head"><h2>Update prices from a CSV</h2></div>
  <p class="muted">Download the CSV above, change the prices in Excel, and upload it here. You'll see every change before anything is saved.</p>
  <ul class="small muted">
    <li>Rows are matched to products by <b>SKU</b>. A new SKU adds a broadband product (it needs a product name and sell price).</li>
    <li>Columns: <?= h(implode(', ', array_map(fn($c) => $c[0], BB_PRICE_COLUMNS))) ?>. Any can be left out; a blank cell leaves that value as it is.</li>
    <li>Prices are per month, ex VAT; £ signs and commas are fine. Active is Yes or No.</li>
  </ul>
  <form method="post" action="<?= h(url('broadband_prices', ['action' => 'upload'])) ?>" enctype="multipart/form-data" class="filters">
    <?= csrf_field() ?>
    <input type="file" name="file" accept=".csv,.txt,.xlsx" required aria-label="Price CSV">
    <button class="btn">Upload and preview</button>
  </form>
</section>
