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
  <?php $mv = $result['min_visit'] ?? []; if (array_filter($mv)): $vl = ['NO_SITE_VISIT' => 'None', 'STANDARD' => 'Standard', 'PREMIUM' => 'Premium', 'ADVANCED' => 'Advanced']; ?><div class="kpi"><span class="kpi-label">Minimum engineer visit</span><span class="kpi-value kpi-text"><?= h($vl[$mv['new_line']] ?? '—') ?> <span class="muted small">new line</span></span><span class="kpi-sub"><?= h($vl[$mv['existing_line']] ?? '—') ?> on an existing line<?= !empty($result['site_classification']) ? ' · ' . h($result['site_classification']) : '' ?></span></div><?php endif; ?>
  <div class="kpi"><span class="kpi-label">Products available</span><span class="kpi-value"><?= count($result['products']) ?></span></div>
</div>
<?php if (!empty($result['partial'])): ?><div class="flash flash-warning"><?= h($result['partial']) ?></div><?php endif; ?>
<?php $ont = $result['ont'] ?? null; if (($result['quick_text'] ?? null) || $check['uprn'] || !empty($ont['onts'])): ?>
<div class="flash <?= in_array($result['quick_result'] ?? null, [5, 12], true) ? 'flash-success' : 'flash-info' ?>">
  <?= h($result['quick_text'] ?? '') ?>
  <div class="small" style="margin-top:.25rem">
    UPRN: <b><?= $check['uprn'] ? h($check['uprn']) : 'not known' ?></b>
    <?php foreach ($ont['onts'] ?? [] as $o): ?>
      · ONT: <b><?= h($o['reference']) ?></b><?= $o['serial'] ? ' (serial ' . h((string)$o['serial']) . ')' : '' ?><?= $o['max_speed'] ? ', ' . h((string)$o['max_speed']) . ' Mbps' : '' ?><?php foreach ($o['ports'] as $pt): ?>, port <?= h((string)$pt['number']) ?><?= $pt['status'] ? ' ' . h(strtolower((string)$pt['status'])) : '' ?><?php endforeach; ?>
    <?php endforeach; ?>
    <?php if ($ont && !$ont['onts']): ?> · No ONT at the address<?php endif; ?>
    <?php if ($ont && $ont['new_ont']): ?> · A new ONT can be ordered<?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($result['products']):
    $rows = [];
    foreach ($result['products'] as $i => $p) {
        $p['supplier'] ??= giacom_supplier_name('', (string)$p['supplier_ref'], (string)$p['name']);
        $p['tech_label'] ??= giacom_tech_label((string)$p['technology'], (string)$p['supplier_ref'], (string)$p['name']);
        $p['mbps'] = $p['down_mbps'] ?? ($p['speed'] ? $p['speed'] / 1000000 : 0);
        $rows[$i] = $p;
    }
    $suppliers = array_unique(array_column($rows, 'supplier')); sort($suppliers);
    $techs = array_unique(array_column($rows, 'tech_label')); sort($techs); ?>
<div class="filters" data-filter-for="giacom-products">
  <select name="f_supplier" aria-label="Supplier"><option value="">Supplier: any</option><?php foreach ($suppliers as $x): ?><option value="<?= h($x) ?>"><?= h($x) ?></option><?php endforeach; ?></select>
  <select name="f_tech" aria-label="Technology"><option value="">Technology: any</option><?php foreach ($techs as $x): ?><option value="<?= h($x) ?>"><?= h($x) ?></option><?php endforeach; ?></select>
  <select name="f_speed" aria-label="Speed"><option value="0">Speed: any</option><?php foreach ([10, 30, 70, 150, 300, 500, 900] as $mb): ?><option value="<?= $mb ?>">At least <?= $mb ?> Mbps</option><?php endforeach; ?></select>
  <span class="muted small" data-filter-count></span>
</div>
<div class="table-wrap"><table class="table" id="giacom-products">
  <thead><tr><th>Product</th><th>Supplier</th><th>Technology</th><th>Speed</th><th>Contract</th><th>Estimated download</th><th>Care levels</th><th>Earliest date</th><?php if ($canOrder): ?><th></th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $i => $p): ?>
    <tr data-supplier="<?= h($p['supplier']) ?>" data-tech="<?= h($p['tech_label']) ?>" data-speed="<?= h((string)$p['mbps']) ?>">
      <td><b><?= h($p['name']) ?></b><div class="muted small"><?= h($p['product_id']) ?> · <?= h($p['supplier_ref']) ?><?= !empty($p['install_type']) ? ' · ' . h($p['install_type']) . ' install' : '' ?></div></td>
      <td><?= h($p['supplier']) ?></td>
      <td><?= h($p['tech_label']) ?></td>
      <td style="white-space:nowrap"><?php $fmt = fn($v) => rtrim(rtrim(number_format((float)$v, 1), '0'), '.'); ?><?= $p['mbps'] ? h($fmt($p['mbps']) . (!empty($p['up_mbps']) ? '/' . $fmt($p['up_mbps']) : '') . ' Mbps') : '<span class="muted">—</span>' ?></td>
      <td><?= !empty($p['contract_months']) ? (int)$p['contract_months'] . ' months' : '<span class="muted">—</span>' ?></td>
      <td><?= $p['estimate'] ? h(giacom_mbps($p['estimate']['down'], 'kbps')) . '<div class="muted small">up ' . h(giacom_mbps($p['estimate']['up'], 'kbps')) . '</div>' : ($p['likely_range'] ? h(giacom_mbps($p['likely_range'][0]) . ' – ' . giacom_mbps($p['likely_range'][1])) : '<span class="muted">—</span>') ?></td>
      <td class="small"><?= h(implode(', ', array_map('ucfirst', $p['care_levels']))) ?: '<span class="muted">—</span>' ?></td>
      <td><?= $p['leadtime'] ? h(fmt_date($p['leadtime']['first_date'])) . '<div class="muted small">' . (int)$p['leadtime']['days'] . ' working days</div>' : '<span class="muted">On order</span>' ?></td>
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
