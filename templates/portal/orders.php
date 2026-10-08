<div class="page-head">
  <h1>Orders <span class="count"><?= count($orders) ?></span></h1>
  <div class="actions"><a class="btn btn-primary" href="<?= h(portal_url('check')) ?>">New order</a></div>
</div>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Order</th><th>Customer</th><th>Product</th><th>Placed</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): [$label, $detail] = dealer_order_progress($o); $agreement = $o['status'] === 'submitted' ? dealer_order_agreement($o) : null; ?>
    <tr>
      <td><a href="<?= h(portal_url('order', ['id' => $o['id']])) ?>"><b><?= h($o['reference']) ?></b></a></td>
      <td><?= h($o['account_name']) ?></td>
      <td><?= h((string)$o['product_name']) ?></td>
      <td class="whitespace-nowrap"><?= h(fmt_date($o['created_at'])) ?></td>
      <td><span class="badge badge-<?= h(['Live' => 'active', 'Cancelled' => 'cancelled', 'Not accepted' => 'rejected', 'Withdrawn' => 'cancelled'][$label] ?? 'pending') ?>"><?= h($label) ?></span>
        <?php if ($agreement && $agreement['status'] === 'sent'): ?><div class="small text-danger">Agreement to sign</div><?php endif; ?>
        <?php if ($detail !== ''): ?><div class="small muted"><?= h($detail) ?></div><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="5" class="empty-row">No orders yet. <a href="<?= h(portal_url('check')) ?>">Check availability</a> to place your first.</td></tr><?php endif; ?>
  </tbody>
</table></div>
