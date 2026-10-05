<?php
$id = (int)$order['id'];
$act = fn(string $a) => url('customer_orders', ['action' => $a, 'id' => $id]);
$canEdit = can('onboarding.edit');
$open = order_is_open($order);
$next = $open ? order_next_step($order['status']) : null;
$progress = order_progress($order, $contract);
$unsigned = $contract && $contract['status'] !== 'signed';
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
  <ol class="order-steps <?= count($progress) > 4 ? 'order-steps-6' : '' ?>">
    <?php foreach ($progress as $n => $s): ?>
      <li class="<?= h($s['state']) ?>"><span><?= $s['state'] === 'done' ? '✓' : $n + 1 ?></span><?= h($s['label']) ?></li>
    <?php endforeach; ?>
  </ol>
  <?php if ($order['status'] === 'cancelled'): ?><p class="text-danger mt-2">This order was cancelled.</p><?php endif; ?>
</div>

<div class="grid-side">
  <div>
    <?php if ($open && ($contract || setting('contracts_auto_on_accept', '1') === '1')): $cs = $contract['status'] ?? null; ?>
    <section class="card contract-status contract-<?= h($cs ?? 'none') ?>">
      <div class="card-head"><h2>Agreement</h2><?php if ($contract): ?><a href="<?= h(url('contracts', ['action' => 'view', 'id' => $contract['id']])) ?>"><?= h($contract['reference']) ?> →</a><?php endif; ?></div>
      <?php if (!$contract): ?>
        <p><b>No agreement yet.</b> <?= $hasTemplates ? 'One couldn\'t be made automatically when the quote was accepted (see the customer\'s activity for why).' : 'There are no contract templates yet, so one couldn\'t be made when the quote was accepted.' ?></p>
        <?php if (!$hasTemplates): ?><p class="help">Upload your Word agreement under <a href="<?= h(url('contract_templates')) ?>">Admin → Contract templates</a> (a "General" one covers everything).</p><?php endif; ?>
        <?php if ($quote && can('sales.edit') && $hasTemplates): ?><form method="post" action="<?= h(url('quotes', ['action' => 'contract', 'id' => $quote['id']])) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-primary">Create the agreement</button></form><?php endif; ?>
      <?php elseif (in_array($cs, ['draft', 'failed'], true)): ?>
        <p><b>Ready, but not sent yet.</b> <?= signable_configured() ? 'Send it to ' . h($contract['signer_name']) . ' to sign online from the agreement page.' : 'Signable isn\'t set up, so it hasn\'t been emailed. Download it and get it signed, then mark it as signed, or set up Signable to send it for e-signature.' ?></p>
        <?php if ($contract['last_error']): ?><p class="small text-danger"><?= h($contract['last_error']) ?></p><?php endif; ?>
        <a class="btn btn-sm btn-primary" href="<?= h(url('contracts', ['action' => 'view', 'id' => $contract['id']])) ?>"><?= signable_configured() ? 'Send for signature' : 'Open the agreement' ?></a>
      <?php elseif ($cs === 'sent'): ?>
        <p><b>Waiting for <?= h($contract['signer_name']) ?> to sign.</b> Sent <?= h(fmt_datetime($contract['sent_at'])) ?> to <?= h($contract['signer_email']) ?>.</p>
        <form method="post" action="<?= h(url('contracts', ['action' => 'check', 'id' => $contract['id']])) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm">↻ Check now</button></form>
        <form method="post" action="<?= h(url('contracts', ['action' => 'remind', 'id' => $contract['id']])) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm">Send a reminder</button></form>
      <?php elseif ($cs === 'signed'): ?>
        <p class="text-ok"><b>✔ Signed</b> by <?= h($contract['signer_name']) ?> on <?= h(fmt_datetime($contract['signed_at'])) ?>.</p>
      <?php else: ?>
        <p><b>The agreement was <?= h($cs) ?>.</b> Open it to see what happened.</p>
      <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($canEdit && $open): ?>
    <section class="card">
      <div class="card-head"><h2><?= $next ? 'Move to the next step' : 'Update' ?></h2></div>
      <?php if (!$order['assigned_to']): ?>
        <div class="flash flash-warning">Nobody has picked this order up yet.
          <form method="post" action="<?= h($act('pick_up')) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm btn-primary">Pick it up</button></form></div>
      <?php endif; ?>
      <form method="post" action="<?= h($act('status')) ?>" class="stack" data-order-step <?= $unsigned ? 'data-unsigned="' . h($contract['reference']) . '"' : '' ?>>
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
        <?php if ($poPlan['suppliers'] && can('purchasing.edit') && $order['status'] === 'accepted'): ?>
          <label class="check" data-when-step="processing"><input type="checkbox" name="raise_pos" value="1" checked>
            Raise and email purchase orders to <?= h(implode(', ', array_map(fn($p) => $p['supplier']['name'], $poPlan['suppliers']))) ?></label>
        <?php endif; ?>
        <label>Internal note (optional)<input name="note" placeholder="Only staff see this"></label>
        <div><button class="btn btn-primary">Update the order</button></div>
      </form>
    </section>
    <?php endif; ?>

    <?php if (can('suppliers.view') && ($purchaseOrders || $poPlan['suppliers'] || $poPlan['skipped'])): ?>
    <section class="card" id="purchase-orders">
      <div class="card-head"><h2>Purchase orders</h2></div>
      <?php if ($purchaseOrders): ?>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>PO</th><th>Supplier</th><th>Status</th><th class="num">Total</th><th>Sent</th><th>Invoice</th></tr></thead>
          <tbody><?php foreach ($purchaseOrders as $p): $inv = db_one("SELECT id, status, total FROM supplier_invoices WHERE po_id = ? ORDER BY id DESC LIMIT 1", [$p['id']]); ?>
            <tr><td><a class="row-link" href="<?= h(url('purchase_orders', ['action' => 'view', 'id' => $p['id']])) ?>"><?= h($p['reference']) ?></a></td>
              <td><?= h($p['supplier_name']) ?></td><td><?= badge($p['status']) ?></td><td class="num"><?= h(money($p['total'])) ?></td>
              <td class="small"><?= h(fmt_datetime($p['sent_at'])) ?: '<span class="muted">—</span>' ?></td>
              <td class="small"><?= $inv ? '<a href="' . h(url('supplier_invoices', ['action' => 'view', 'id' => $inv['id']])) . '">' . invoice_status_badge($inv['status']) . '</a>' : '<span class="muted">Not yet</span>' ?></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
      <?php if ($poPlan['suppliers']): ?>
        <form method="post" action="<?= h($act('raise_pos')) ?>" class="stack mt-4">
          <?= csrf_field() ?>
          <p class="small muted"><?= $purchaseOrders ? 'Still to order:' : 'From the products on this order and their preferred suppliers:' ?></p>
          <?php foreach ($poPlan['suppliers'] as $sid => $p): $total = po_total($p['lines']); ?>
            <label class="check"><input type="checkbox" name="suppliers[]" value="<?= (int)$sid ?>" checked>
              <span><b><?= h($p['supplier']['name']) ?></b> – <?= count($p['lines']) ?> line<?= count($p['lines']) === 1 ? '' : 's' ?>, <?= h(money($total)) ?>
                <span class="muted small"><?= $p['supplier']['email'] ? 'to ' . h($p['supplier']['email']) : '· no orders email set, so it will be saved as a draft' ?></span>
                <span class="block small muted"><?= h(implode(' · ', array_map(fn($l) => $l['quantity'] . ' × ' . $l['description'], $p['lines']))) ?></span></span></label>
          <?php endforeach; ?>
          <label class="check"><input type="checkbox" name="send" value="1" checked> Email them to the suppliers now</label>
          <div><button class="btn btn-primary">Raise purchase orders</button></div>
        </form>
      <?php endif; ?>
      <?php if ($poPlan['skipped']): ?>
        <p class="small muted mt-4">Not on a purchase order:</p>
        <ul class="small">
          <?php foreach ($poPlan['skipped'] as [$what, $why]): ?><li><b><?= h($what) ?></b>: <?= h($why) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
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
        <?php if (can('purchasing.edit')): ?><li><a href="<?= h(url('purchase_orders', ['action' => 'new', 'account_id' => $account['id'], 'customer_order_id' => $order['id']])) ?>">Raise another purchase order</a></li><?php endif; ?>
        <?php if (can('services.edit')): ?><li><a href="<?= h(url('services', ['action' => 'new', 'account_id' => $account['id']])) ?>">Add a service</a> · <a href="<?= h(url('services', ['account_id' => $account['id']])) ?>">their services</a></li><?php endif; ?>
      </ul>
    </section>
  </aside>
</div>
