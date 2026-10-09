<?php
$names = [];
foreach (db_all("SELECT id, name, sku FROM products WHERE category = 'broadband'") as $p) {
    $names[(int)$p['id']] = $p['name'] . ' (' . $p['sku'] . ')';
}
$fmt = function (string $col, mixed $v): string {
    if ($v === null || $v === '') {
        return '—';
    }
    return match (BB_PRICE_COLUMNS[$col][1]) {
        'money' => money($v),
        'bool' => $v ? 'Yes' : 'No',
        'term' => term_label($v),
        default => (string)$v,
    };
};
$nChanged = count($plan['changes']);
$nNew = count($plan['new']);
?>
<div class="page-head"><div><div class="crumbs"><a href="<?= h(url('broadband_prices')) ?>">Broadband prices</a></div>
  <h1>Check the changes from <?= h($file) ?></h1>
  <p class="muted"><?= $nChanged ?> product<?= $nChanged === 1 ? '' : 's' ?> to change · <?= $nNew ?> to add · <?= (int)$plan['same'] ?> unchanged<?= $plan['errors'] ? ' · ' . count($plan['errors']) . ' row' . (count($plan['errors']) === 1 ? '' : 's') . ' skipped' : '' ?></p></div></div>
<?php if ($plan['errors']): ?>
  <div class="flash flash-warning"><b>These rows will be skipped:</b><ul class="small"><?php foreach ($plan['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($plan['changes']): ?>
<section class="card"><div class="card-head"><h2>Changes</h2></div>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Product</th><th>What</th><th class="num">Now</th><th class="num">New</th></tr></thead>
    <tbody>
    <?php foreach ($plan['changes'] as $id => $diff): $first = true; foreach ($diff as $col => [$old, $new]): ?>
      <tr><td><?= $first ? h($names[$id] ?? '#' . $id) : '' ?></td><td><?= h(BB_PRICE_COLUMNS[$col][0]) ?></td>
        <td class="num muted"><?= h($fmt($col, $old)) ?></td><td class="num"><b><?= h($fmt($col, $new)) ?></b></td></tr>
    <?php $first = false; endforeach; endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php if ($plan['new']): ?>
<section class="card"><div class="card-head"><h2>New products</h2></div>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>SKU</th><th>Product</th><th>Term</th><th class="num">Buy</th><th class="num">Sell</th><th class="num">Dealer</th></tr></thead>
    <tbody>
    <?php foreach ($plan['new'] as $n): ?>
      <tr><td><?= h($n['sku']) ?></td><td><?= h($n['name']) ?></td><td><?= h(term_label($n['term_months'] ?? 24)) ?></td>
        <td class="num"><?= h($fmt('cost_price', $n['cost_price'] ?? null)) ?></td><td class="num"><?= h($fmt('monthly_price', $n['monthly_price'] ?? null)) ?></td><td class="num"><?= h($fmt('dealer_price', $n['dealer_price'] ?? null)) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php if ($plan['changes'] || $plan['new']): ?>
  <form method="post" action="<?= h(url('broadband_prices', ['action' => 'confirm'])) ?>" class="form-actions">
    <?= csrf_field() ?><input type="hidden" name="token" value="<?= h($token) ?>">
    <button class="btn btn-primary">Apply <?= $nChanged + $nNew ?> change<?= $nChanged + $nNew === 1 ? '' : 's' ?></button>
    <a class="btn btn-ghost" href="<?= h(url('broadband_prices')) ?>">Cancel</a>
  </form>
<?php else: ?>
  <div class="card empty"><p>Nothing to change: the file matches the current prices.</p><p><a class="btn" href="<?= h(url('broadband_prices')) ?>">Back</a></p></div>
<?php endif; ?>
