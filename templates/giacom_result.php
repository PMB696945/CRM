<?php $canOrder = can('orders.place') && $check['account_id']; ?>
<div class="page-head"><div>
  <?php if ($check['account_id']): ?><div class="crumbs"><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $check['account_id']])) ?>"><?= h($check['account_name']) ?></a><?= $check['site_name'] ? ' · ' . h($check['site_name']) : '' ?></div><?php endif; ?>
  <h1>Broadband availability</h1>
  <p class="muted"><?= h($check['address_label']) ?><?= $check['cli'] ? ' · line ' . h($check['cli']) : '' ?> · checked <?= h(fmt_datetime($check['created_at'])) ?> by <?= h($check['user_name'] ?? '—') ?></p></div>
  <div class="actions"><a class="btn" href="<?= h(url('giacom', ['action' => 'check', 'account_id' => $check['account_id'], 'site_id' => $check['site_id']])) ?>">Check again</a></div>
</div>

<div class="kpis kpis-sm">
  <?php if ($result['exchange'] ?? null): ?><div class="kpi"><span class="kpi-label">Exchange</span><span class="kpi-value kpi-text"><?= h($result['exchange']['name']) ?></span><span class="kpi-sub"><?= h($result['exchange']['code']) ?> · <?= h($result['exchange']['state']) ?></span></div><?php endif; ?>
  <?php if ($result['fttc'] ?? null): ?><div class="kpi"><span class="kpi-label">Likely FTTC speed</span><span class="kpi-value kpi-text"><?= h(giacom_mbps($result['fttc'][0])) ?></span><span class="kpi-sub">up to <?= h(giacom_mbps($result['fttc'][1])) ?> upload</span></div><?php endif; ?>
  <div class="kpi"><span class="kpi-label">Products available</span><span class="kpi-value"><?= count($result['products']) ?></span></div>
</div>
<?php if ($result['quick_text'] ?? null): ?><div class="flash <?= in_array($result['quick_result'], [5, 12], true) ? 'flash-success' : 'flash-info' ?>"><?= h($result['quick_text']) ?></div><?php endif; ?>

<?php if ($result['products']): ?>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Product</th><th>Technology</th><th>Speed</th><th>Estimated download</th><th>Care levels</th><th>Earliest date</th><?php if ($canOrder): ?><th></th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($result['products'] as $i => $p): ?>
    <tr>
      <td><b><?= h($p['name']) ?></b><div class="muted small"><?= h($p['product_id']) ?> · <?= h($p['supplier_ref']) ?></div></td>
      <td><?= h(strtoupper($p['technology'])) ?></td>
      <td><?= h(giacom_mbps($p['speed'])) ?></td>
      <td><?= $p['estimate'] ? h(giacom_mbps($p['estimate']['down'], 'kbps')) . '<div class="muted small">up ' . h(giacom_mbps($p['estimate']['up'], 'kbps')) . '</div>' : ($p['likely_range'] ? h(giacom_mbps($p['likely_range'][0]) . ' – ' . giacom_mbps($p['likely_range'][1])) : '<span class="muted">—</span>') ?></td>
      <td class="small"><?= h(implode(', ', array_map('ucfirst', $p['care_levels']))) ?: '<span class="muted">—</span>' ?></td>
      <td><?= $p['leadtime'] ? h(fmt_date($p['leadtime']['first_date'])) . '<div class="muted small">' . (int)$p['leadtime']['days'] . ' working days</div>' : '<span class="muted">—</span>' ?></td>
      <?php if ($canOrder): ?><td class="right"><a class="btn btn-sm btn-primary" href="<?= h(url('giacom', ['action' => 'order', 'check' => $check['id'], 'product' => $i])) ?>">Order</a></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php else: ?>
  <div class="card empty"><p>Giacom didn't return any products for this address.</p></div>
<?php endif; ?>
<?php if (!empty($result['raw']) && can('settings.manage')): ?>
  <details class="card"><summary>Giacom's full response (for troubleshooting)</summary>
    <pre class="small" style="white-space:pre-wrap;max-height:30rem;overflow:auto"><?= h(json_encode($result['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
  </details>
<?php endif; ?>
<?php if (!$check['account_id'] && can('orders.place')): ?><p class="help">To order, run the check from the customer's page.</p><?php endif; ?>
