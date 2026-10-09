<div class="page-head"><div><h1>Orders</h1></div></div>
<?php if ($broadband): ?>
<section class="card">
  <div class="card-head"><h2>Broadband</h2></div>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Service</th><th>Address</th><th>Ordered</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($broadband as $b): ?>
      <tr><td><?= h($b['product']) ?></td><td class="small"><?= h($b['address']) ?></td><td class="whitespace-nowrap"><?= h(fmt_date($b['placed'])) ?></td>
        <td><span class="badge badge-<?= h(['Live' => 'active', 'Cancelled' => 'cancelled'][$b['label']] ?? 'pending') ?>"><?= h($b['label']) ?></span><?php if ($b['detail'] !== ''): ?><div class="small muted"><?= h($b['detail']) ?></div><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<section class="card">
  <div class="card-head"><h2>Orders</h2></div>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Order</th><th>What</th><th>Placed</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
      <tr><td><b><?= h((string)$o['reference']) ?></b></td><td><?= h((string)$o['title']) ?></td><td class="whitespace-nowrap"><?= h(fmt_date($o['created_at'])) ?></td>
        <td><?= h(order_status_label($o['status'])) ?></td>
        <td class="right"><?php if ($o['token']): ?><a class="btn btn-sm" href="<?= h(order_tracking_url($o)) ?>" target="_blank" rel="noopener">Track</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$orders): ?><tr><td colspan="5" class="empty-row">No orders yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
