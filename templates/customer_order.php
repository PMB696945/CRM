<?php
$id = (int)$order['id'];
$act = fn(string $a) => url('customer_orders', ['action' => $a, 'id' => $id]);
$canEdit = can('onboarding.edit');
$open = order_is_open($order);
$next = $open ? order_next_step($order['status']) : null;
$steps = array_keys(ORDER_STEPS);
$at = array_search($order['status'], $steps, true);
$me = (int)current_user()['id'];
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url('customer_orders')) ?>">Orders</a> · <a href="<?= h(url('accounts', ['action' => 'view', 'id' => $account['id']])) ?>"><?= h($account['name']) ?></a></div>
    <h1><?= h($order['reference']) ?> <span class="badge badge-<?= h($order['status'] === 'completed' ? 'active' : ($order['status'] === 'cancelled' ? 'cancelled' : 'pending')) ?>"><?= h(order_status_label($order['status'])) ?></span></h1>
    <p class="muted"><?= h($order['title']) ?> · <?= h(money($order['monthly_total'])) ?>/month, <?= h(money($order['setup_total'])) ?> one-off<?= $quote ? ' · from <a href="' . h(url('quotes', ['action' => 'view', 'id' => $quote['id']])) . '">' . h($quote['reference']) . '</a>' : '' ?></p>
  </div>
  <div class="actions">
    <?php if (can('audit.view')): ?><a class="btn btn-ghost" href="<?= h(url('audit', ['entity' => 'customer_orders', 'entity_id' => $id])) ?>">History</a><?php endif; ?>
    <a class="btn btn-ghost" href="<?= h(order_tracking_url($order)) ?>" target="_blank" rel="noopener">Customer's tracking page ↗</a>
  </div>
</div>

<div class="card">
  <ol class="order-steps">
    <?php foreach (ORDER_STEPS as $key => $label): $i = array_search($key, $steps, true); ?>
      <li class="<?= $order['status'] === 'cancelled' ? '' : ($i < $at ? 'done' : ($i === $at ? 'current' : '')) ?>"><span><?= $i + 1 ?></span><?= h($label) ?></li>
    <?php endforeach; ?>
  </ol>
  <?php if ($order['status'] === 'cancelled'): ?><p class="text-danger mt-2">This order was cancelled.</p><?php endif; ?>
</div>

<div class="grid-side">
  <div>
    <?php if ($canEdit && $open): ?>
    <section class="card">
      <div class="card-head"><h2><?= $next ? 'Move to the next step' : 'Update' ?></h2></div>
      <?php if (!$order['assigned_to']): ?>
        <div class="flash flash-warning">Nobody has picked this order up yet.
          <form method="post" action="<?= h($act('pick_up')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm btn-primary">Pick it up</button></form></div>
      <?php endif; ?>
      <form method="post" action="<?= h($act('status')) ?>" class="stack" data-order-step>
        <?= csrf_field() ?>
        <label>Step
          <select name="status" data-step-select>
            <?php foreach (ORDER_STATUSES as $key => $label): if ($key === $order['status'] || $key === 'accepted') continue; ?>
              <option value="<?= h($key) ?>" data-message="<?= h(order_default_message($key)) ?>" <?= $key === $next ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label>Message to the customer
          <textarea name="message" rows="4" data-step-message><?= h(order_default_message($next ?? 'cancelled')) ?></textarea></label>
        <label class="check"><input type="checkbox" name="notify" value="1" <?= $order['contact_email'] ? 'checked' : 'disabled' ?>>
          Email <?= $order['contact_email'] ? h($order['contact_name'] ?: 'the customer') . ' at ' . h($order['contact_email']) : 'the customer (add their email below first)' ?></label>
        <label>Internal note (optional)<input name="note" placeholder="Only staff see this"></label>
        <div><button class="btn btn-primary">Update the order</button></div>
      </form>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-head"><h2>Timeline</h2></div>
      <ul class="timeline">
        <?php foreach (array_reverse($events) as $e): ?>
          <li>
            <div class="timeline-meta"><?= h(fmt_datetime($e['created_at'])) ?><?= $e['user_name'] ? ' · ' . h($e['user_name']) : '' ?>
              <?= $e['emailed_to'] ? ' · <span class="text-ok">customer emailed (' . h($e['emailed_to']) . ')</span>' : '' ?></div>
            <?php if ($e['status']): ?><b><?= h(order_status_label($e['status'])) ?></b><?php endif; ?>
            <?php if ($e['message']): ?><div class="small"><?= nl2br(h($e['message'])) ?></div><?php endif; ?>
            <?php if ($e['note']): ?><div class="small muted"><?= $e['status'] ? 'Note: ' : '' ?><?= nl2br(h($e['note'])) ?></div><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($canEdit): ?>
        <form method="post" action="<?= h($act('note')) ?>" class="inline-form mt-4"><?= csrf_field() ?>
          <input name="note" placeholder="Add an internal note" aria-label="Internal note" required><button class="btn btn-sm">Add note</button></form>
      <?php endif; ?>
    </section>

    <?php if ($lines): ?>
    <section class="card">
      <div class="card-head"><h2>What was ordered</h2></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Service</th><th>Type</th><th class="num">Qty</th><th class="num">Monthly</th><th class="num">One-off</th><th class="num">Term</th></tr></thead>
        <tbody><?php foreach ($lines as $l): ?>
          <tr><td><?= h($l['description']) ?></td><td><?= h(SERVICE_TYPES[$l['service_type']] ?? $l['service_type']) ?></td><td class="num"><?= (int)$l['quantity'] ?></td>
            <td class="num"><?= h(money($l['monthly_price'])) ?></td><td class="num"><?= h(money($l['setup_fee'])) ?></td><td class="num"><?= h(term_label($l['term_months'])) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </section>
    <?php endif; ?>
  </div>

  <aside>
    <section class="card">
      <div class="card-head"><h2>Handled by</h2></div>
      <p><?= $assignee ? '<b>' . h($assignee['name']) . '</b>' . ($order['picked_up_at'] ? ' <span class="muted small">since ' . h(fmt_datetime($order['picked_up_at'])) . '</span>' : '') : '<span class="text-warning">Waiting to be picked up</span>' ?></p>
      <?php if ($canEdit && $open): ?>
        <form method="post" action="<?= h($act('assign')) ?>" class="inline-form mt-2"><?= csrf_field() ?>
          <select name="assigned_to" aria-label="Give to"><option value="">— Back to the queue —</option>
            <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$order['assigned_to'] === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?><?= (int)$u['id'] === $me ? ' (me)' : '' ?></option><?php endforeach; ?>
          </select><button class="btn btn-sm">Save</button></form>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Customer contact</h2></div>
      <?php if ($canEdit): ?>
        <form method="post" action="<?= h($act('contact')) ?>" class="stack"><?= csrf_field() ?>
          <label>Name<input name="contact_name" value="<?= h((string)$order['contact_name']) ?>"></label>
          <label>Email for updates<input type="email" name="contact_email" value="<?= h((string)$order['contact_email']) ?>"></label>
          <div><button class="btn btn-sm">Save</button></div></form>
      <?php else: ?>
        <p><?= h((string)$order['contact_name']) ?><br><?= h((string)$order['contact_email']) ?></p>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Get it done</h2></div>
      <ul class="link-list" style="columns:1">
        <?php foreach ($contracts as $c): ?><li>Contract <a href="<?= h(url('contracts', ['action' => 'view', 'id' => $c['id']])) ?>"><?= h($c['reference']) ?></a> <?= badge($c['status']) ?></li><?php endforeach; ?>
        <?php if (!$contracts && $quote): ?><li class="muted">No contract yet</li><?php endif; ?>
        <?php if (can('orders.check') && giacom_configured()): ?><li><a href="<?= h(url('giacom', ['action' => 'check', 'account_id' => $account['id']])) ?>">Check and order broadband (Giacom)</a></li><?php endif; ?>
        <?php foreach ($giacomOrders as $g): ?><li>Broadband order <a href="<?= h(url('giacom', ['action' => 'view', 'id' => $g['id']])) ?>"><?= h($g['giacom_order_id'] ?: '#' . $g['id']) ?></a> <?= badge(strtolower((string)$g['status'])) ?></li><?php endforeach; ?>
        <?php if (can('purchasing.edit')): ?><li><a href="<?= h(url('purchase_orders', ['action' => 'new', 'account_id' => $account['id']])) ?>">Raise a purchase order</a></li><?php endif; ?>
        <?php foreach ($purchaseOrders as $p): ?><li>PO <a href="<?= h(url('purchase_orders', ['action' => 'view', 'id' => $p['id']])) ?>"><?= h($p['reference']) ?></a> with <?= h($p['supplier_name']) ?> <?= badge($p['status']) ?></li><?php endforeach; ?>
        <?php if (can('services.edit')): ?><li><a href="<?= h(url('services', ['action' => 'new', 'account_id' => $account['id']])) ?>">Add a service</a> · <a href="<?= h(url('services', ['account_id' => $account['id']])) ?>">their services</a></li><?php endif; ?>
      </ul>
    </section>
  </aside>
</div>
