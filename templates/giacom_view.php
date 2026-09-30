<?php $open = !$order['completed_at'] && !giacom_is_cancelled((string)$order['status']); ?>
<div class="page-head"><div>
  <div class="crumbs"><a href="<?= h(url('giacom')) ?>">Broadband orders</a><?php if ($order['account_id']): ?> · <a href="<?= h(url('accounts', ['action' => 'view', 'id' => $order['account_id']])) ?>"><?= h($order['account_name']) ?></a><?php endif; ?></div>
  <h1>Giacom order <?= h($order['giacom_order_id']) ?> <span class="badge <?= $order['completed_at'] ? 'badge-active' : (giacom_is_cancelled((string)$order['status']) ? 'badge-failed' : 'badge-pending') ?>"><?= h($order['status'] ?: 'Placed') ?></span></h1></div>
  <div class="actions">
    <form method="post" class="inline"><?= csrf_field() ?><button class="btn">Refresh from Giacom</button></form>
  </div>
</div>
<?php if ($order['last_error']): ?><div class="flash flash-error"><?= h($order['last_error']) ?></div><?php endif; ?>

<div class="grid-side">
  <div>
    <section class="card">
      <dl class="details">
        <dt>Product</dt><dd><?= h($order['product_name']) ?> <span class="muted small"><?= h($order['product_id']) ?> · <?= h(strtoupper((string)$order['technology_type'])) ?></span></dd>
        <dt>Order type</dt><dd><?= h(ucfirst($order['order_type'])) ?></dd>
        <dt>Address</dt><dd><?= h($order['address_label']) ?><?= $order['site_name'] ? ' <span class="muted">(' . h($order['site_name']) . ')</span>' : '' ?></dd>
        <?php if ($order['cli']): ?><dt>Line</dt><dd><?= h($order['cli']) ?></dd><?php endif; ?>
        <dt>Broadband username</dt><dd><?= h($order['broadband_username'] ?: '—') ?></dd>
        <dt>Required by</dt><dd><?= h(fmt_date($order['crd'])) ?></dd>
        <dt>Your reference</dt><dd><?= h($order['client_ref']) ?></dd>
        <dt>Giacom service ID</dt><dd><?= h($order['giacom_service_id'] ?: '—') ?></dd>
        <dt>Placed</dt><dd><?= h(fmt_datetime($order['created_at'])) ?> by <?= h($order['user_name'] ?? '—') ?></dd>
        <dt>Status updated</dt><dd><?= h(fmt_datetime($order['status_updated_at'])) ?></dd>
        <?php if ($order['completed_at']): ?><dt>Completed</dt><dd><?= h(fmt_datetime($order['completed_at'])) ?></dd><?php endif; ?>
        <?php if ($order['service_id']): ?><dt>Service in the CRM</dt><dd><a href="<?= h(url('services', ['action' => 'view', 'id' => $order['service_id']])) ?>">View service</a></dd><?php endif; ?>
      </dl>
    </section>
    <section class="card">
      <div class="card-head"><h2>Progress</h2></div>
      <?php if ($events): ?>
        <ul class="feed"><?php foreach ($events as $e): ?><li><b><?= h(humanize($e['name'])) ?></b> <?= h($e['value']) ?> <span class="muted">· <?= h(fmt_datetime($e['event_date'])) ?></span></li><?php endforeach; ?></ul>
      <?php else: ?><p class="muted">No updates from Giacom yet. They're checked hourly, or press Refresh.</p><?php endif; ?>
    </section>
  </div>
  <aside>
    <?php if ($open && can('orders.place')): ?>
      <section class="card">
        <div class="card-head"><h2>Cancel order</h2></div>
        <form method="post" action="<?= h(url('giacom', ['action' => 'view', 'id' => $order['id'], 'do' => 'abort'])) ?>" class="stack" data-confirm="Ask Giacom to cancel this order?">
          <?= csrf_field() ?>
          <label>Reason<input name="reason" required maxlength="250"></label>
          <button class="btn btn-danger">Cancel with Giacom</button>
          <p class="help">Giacom confirms whether it could be cancelled; once work has started at the carrier it may not be possible.</p>
        </form>
      </section>
    <?php endif; ?>
  </aside>
</div>
