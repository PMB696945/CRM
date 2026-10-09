<div class="page-head"><div><h1>Services <span class="count"><?= count($services) ?></span></h1><p class="muted">Your lines and services, with their setup details.</p></div></div>
<?php if ($services): ?>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Service</th><th>Type</th><th>Status</th><th>Monthly</th><th>Contract ends</th><th>Setup details</th></tr></thead>
  <tbody>
  <?php foreach ($services as $s): $l = service_login($s); ?>
    <tr>
      <td><b><?= h($s['identifier']) ?></b><div class="muted small"><?= h(implode(' · ', array_filter([(string)$s['product_name'], (string)$s['site_name']]))) ?></div></td>
      <td><?= h(SERVICE_TYPES[$s['service_type']] ?? ucfirst((string)$s['service_type'])) ?></td>
      <td><span class="badge badge-<?= h(['active' => 'active', 'pending' => 'pending', 'suspended' => 'suspended'][$s['status']] ?? 'pending') ?>"><?= h(['active' => 'Live', 'pending' => 'Being set up', 'suspended' => 'Suspended'][$s['status']] ?? ucfirst($s['status'])) ?></span></td>
      <td class="whitespace-nowrap"><?= $s['monthly_price'] !== null ? h(money($s['monthly_price'])) : '<span class="muted">—</span>' ?></td>
      <td class="whitespace-nowrap"><?= $s['contract_end_date'] ? h(fmt_date($s['contract_end_date'])) : '<span class="muted">—</span>' ?></td>
      <td class="small"><?php if ($l['username'] === '' && $l['password'] === '' && $l['ip'] === ''): ?><span class="muted">—</span><?php else: ?>
        <?php if ($l['username'] !== ''): ?><div>Username <code><?= h($l['username']) ?></code></div><?php endif; ?>
        <?php if ($l['password'] !== ''): ?><details><summary>Password</summary><code><?= h($l['password']) ?></code></details><?php endif; ?>
        <?php if ($l['ip'] !== ''): ?><div>IP <?= h($l['ip']) ?></div><?php endif; ?><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<p class="help">Prices exclude VAT. Static IP addresses appear here once a line is live. Keep your passwords safe; if you need one changed, contact us.</p>
<?php else: ?>
  <div class="card empty"><p>No services yet.</p></div>
<?php endif; ?>
