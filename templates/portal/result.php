<?php $fmt = fn($v) => rtrim(rtrim(number_format((float)$v, 1), '0'), '.'); ?>
<div class="page-head"><div>
  <div class="crumbs"><a href="<?= h(portal_url('customers')) ?>">Customers</a> · <?= h($customer['name']) ?></div>
  <h1>Available at this address</h1>
  <p class="muted"><?= h($check['address_label']) ?><?= $check['cli'] ? ' · line ' . h($check['cli']) : '' ?> · checked <?= h(fmt_datetime($check['created_at'])) ?></p></div>
  <div class="actions"><a class="btn" href="<?= h(portal_url('check', ['customer' => $customer['id']])) ?>">Check another address</a></div>
</div>
<?php if (!empty($result['partial'])): ?><div class="flash flash-warning"><?= h($result['partial']) ?></div><?php endif; ?>
<?php $mv = $result['min_visit'] ?? []; $needs = array_filter(['a new line' => $mv['new_line'] ?? null, 'taking over the existing line' => $mv['existing_line'] ?? null], fn($x) => $x && $x !== 'NO_SITE_VISIT'); ?>
<?php if ($needs): ?><div class="flash flash-info">An engineer visit is needed at this address for <?= h(implode(' and for ', array_keys($needs))) ?>.</div><?php endif; ?>
<?php if ($offers): ?>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Product</th><th>Speed</th><th>Contract</th><th>Monthly</th><th>Setup</th><th>Earliest date</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($offers as $o): $p = $o['product']; $sp = $o['supplier']; $mbps = $sp['down_mbps'] ?? (!empty($sp['speed']) ? $sp['speed'] / 1000000 : 0); ?>
    <tr>
      <td><b><?= h($p['name']) ?></b><?php if ($p['description']): ?><div class="muted small"><?= h(mb_strimwidth((string)$p['description'], 0, 120, '…')) ?></div><?php endif; ?></td>
      <td class="whitespace-nowrap"><?= $mbps ? h($fmt($mbps) . (!empty($sp['up_mbps']) ? '/' . $fmt($sp['up_mbps']) : '') . ' Mbps') : '—' ?>
        <?php if (!empty($sp['estimate'])): ?><div class="muted small">est. <?= h(giacom_mbps($sp['estimate']['down'], 'kbps')) ?> here</div><?php endif; ?></td>
      <td><?= h(term_label((int)$p['term_months'])) ?></td>
      <td><b><?= h(money($p['dealer_price'])) ?></b></td>
      <td><?= h(money((float)($p['dealer_setup_fee'] ?? 0))) ?></td>
      <td><?= !empty($sp['leadtime']) ? h(fmt_date($sp['leadtime']['first_date'])) : '<span class="muted">Confirmed on ordering</span>' ?></td>
      <td class="right"><a class="btn btn-sm btn-primary" href="<?= h(portal_url('order_new', ['check' => $check['id'], 'product' => $p['id']])) ?>">Order</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<p class="help">Prices are your dealer prices, excluding VAT.</p>
<?php else: ?>
  <div class="card empty"><p>None of our broadband products are available at this address. If you think that's wrong, please contact us.</p></div>
<?php endif; ?>
