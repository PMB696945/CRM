<div class="page-head">
  <h1>Dealer orders <span class="count"><?= count($orders) ?></span></h1>
  <div class="actions">
    <a class="btn <?= $show !== 'all' ? 'btn-primary' : '' ?>" href="<?= h(url('dealer_orders')) ?>">Waiting</a>
    <a class="btn <?= $show === 'all' ? 'btn-primary' : '' ?>" href="<?= h(url('dealer_orders', ['show' => 'all'])) ?>">All</a>
  </div>
</div>
<p class="lead">Orders dealers send from the partner portal. Each has its own agreement with the dealer, which they sign online; once it's signed, check the order and approve it to place it.</p>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Order</th><th>Dealer</th><th>Customer</th><th>Product</th><th>Sent</th><th>Agreement</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
    <tr>
      <td><a class="row-link" href="<?= h(url('dealer_orders', ['action' => 'view', 'id' => $o['id']])) ?>"><b><?= h($o['reference']) ?></b></a></td>
      <td><?= h($o['dealer_name']) ?></td>
      <td><?= h($o['account_name']) ?></td>
      <td><?= h((string)$o['product_name']) ?></td>
      <td class="whitespace-nowrap"><?= h(fmt_datetime($o['created_at'])) ?></td>
      <td><?= $o['agreement_status'] ? badge($o['agreement_status']) . ' <span class="muted small">' . h($o['agreement_reference']) . '</span>' : '<span class="text-danger small">Not sent</span>' ?></td>
      <td><span class="badge badge-<?= h(['submitted' => 'pending', 'placed' => 'active', 'rejected' => 'rejected', 'withdrawn' => 'cancelled'][$o['status']] ?? 'pending') ?>"><?= h(DEALER_ORDER_STATUSES[$o['status']] ?? $o['status']) ?></span>
        <?php if ($o['status'] === 'submitted' && $o['agreement_status'] === 'signed'): ?><div class="small text-success">Ready to approve</div><?php endif; ?>
        <?php if ($o['last_error']): ?><div class="small text-danger">Needs attention</div><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="7" class="empty-row"><?= $show === 'all' ? 'No dealer orders yet.' : 'Nothing waiting.' ?></td></tr><?php endif; ?>
  </tbody>
</table></div>
