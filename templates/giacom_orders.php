<div class="page-head"><h1>Broadband orders</h1>
  <div class="actions">
    <form method="post" action="<?= h(url('giacom', ['action' => 'sync'])) ?>" class="inline"><?= csrf_field() ?><button class="btn">Check for updates</button></form>
    <a class="btn btn-primary" href="<?= h(url('giacom', ['action' => 'check'])) ?>">Check availability</a>
  </div></div>
<p class="lead">Orders placed with Giacom from the CRM. To check or order for a customer, use <b>Check broadband</b> on their page or on one of their sites. Last updated from Giacom: <?= setting('giacom_last_sync_at') ? h(fmt_datetime(setting('giacom_last_sync_at'))) : 'never' ?>.</p>
<nav class="tabs"><?php foreach (['open' => 'In progress', 'completed' => 'Completed', 'all' => 'All'] as $k => $l): ?><a href="<?= h(url('giacom', ['show' => $k])) ?>" class="<?= $filter === $k ? 'active' : '' ?>"><?= $l ?></a><?php endforeach; ?></nav>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Order</th><th>Customer</th><th>Product</th><th>Address</th><th>Required by</th><th>Status</th><th>Placed</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
    <tr>
      <td><a class="row-link" href="<?= h(url('giacom', ['action' => 'view', 'id' => $o['id']])) ?>"><?= h($o['giacom_order_id']) ?></a><div class="muted small"><?= h(ucfirst($o['order_type'])) ?></div></td>
      <td><?php if ($o['account_id']): ?><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $o['account_id']])) ?>"><?= h($o['account_name']) ?></a><?php endif; ?></td>
      <td><?= h($o['product_name']) ?></td>
      <td class="small"><?= h($o['address_label']) ?></td>
      <td><?= h(fmt_date($o['crd'])) ?></td>
      <td><span class="badge <?= $o['completed_at'] ? 'badge-active' : (giacom_is_cancelled((string)$o['status']) ? 'badge-failed' : 'badge-pending') ?>"><?= h($o['status'] ?: 'Placed') ?></span></td>
      <td class="small"><?= h(fmt_date($o['created_at'])) ?><div class="muted"><?= h($o['user_name'] ?? '') ?></div></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="7" class="empty-row">No orders here.</td></tr><?php endif; ?>
  </tbody>
</table></div>

<?php if ($checks): ?>
<section class="card" style="margin-top:1.5rem">
  <div class="card-head"><h2>Recent availability checks</h2></div>
  <ul class="feed">
    <?php foreach ($checks as $c): $n = count(json_decode((string)$c['result'], true)['products'] ?? []); ?>
      <li><a href="<?= h(url('giacom', ['action' => 'result', 'id' => $c['id']])) ?>"><?= h($c['address_label']) ?></a>
        <?= $c['account_name'] ? '· ' . h($c['account_name']) : '' ?> <span class="muted">· <?= $n ?> product<?= $n === 1 ? '' : 's' ?> · <?= h(fmt_datetime($c['created_at'])) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
