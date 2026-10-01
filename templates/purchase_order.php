<?php
$id = (int)$po['id'];
$act = fn(string $a) => url('purchase_orders', ['action' => $a, 'id' => $id]);
$status = $po['status'];
$canEdit = can('purchasing.edit');
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url('purchase_orders')) ?>">Purchase orders</a> · <a href="<?= h(url('suppliers', ['action' => 'view', 'id' => $supplier['id']])) ?>"><?= h($supplier['name']) ?></a></div>
    <h1><?= h($po['reference']) ?> <?= badge($status) ?></h1>
    <p class="muted"><?= $customerOrder ? 'Order <a href="' . h(url('customer_orders', ['action' => 'view', 'id' => $customerOrder['id']])) . '">' . h($customerOrder['reference']) . '</a> · ' : '' ?><?= $account ? 'For <a href="' . h(url('accounts', ['action' => 'view', 'id' => $account['id']])) . '">' . h($account['name']) . '</a> · ' : '' ?>Raised <?= h(fmt_date($po['created_at'])) ?><?= $creator ? ' by ' . h($creator) : '' ?></p>
  </div>
  <div class="actions">
    <button type="button" class="btn btn-ghost" data-print>Print</button>
    <?php if ($canEdit): ?>
      <?php if ($status === 'draft'): ?><a class="btn" href="<?= h($act('edit')) ?>">Edit</a><?php endif; ?>
      <form method="post" action="<?= h($act('duplicate')) ?>" class="inline"><?= csrf_field() ?><button class="btn">Duplicate</button></form>
      <?php if (in_array($status, ['sent', 'cancelled'], true)): ?><form method="post" action="<?= h($act('reopen')) ?>" class="inline" data-confirm="Put this order back into draft to change it?"><?= csrf_field() ?><button class="btn">Back to draft</button></form><?php endif; ?>
      <?php if ($status === 'draft'): ?>
        <form method="post" action="<?= h($act('delete')) ?>" class="inline" data-confirm="Delete this draft order?"><?= csrf_field() ?><button class="btn btn-danger">Delete</button></form>
      <?php elseif ($status === 'sent'): ?>
        <form method="post" action="<?= h($act('cancel')) ?>" class="inline" data-confirm="Cancel this order? Let the supplier know too."><?= csrf_field() ?><button class="btn btn-danger">Cancel order</button></form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="grid-side">
  <div>
    <section class="card">
      <div class="card-head"><h2>Items</h2></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Code</th><th>Description</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
          <tr><td class="small"><?= $l['supplier_product_id'] ? '<a href="' . h(url('supplier_products', ['action' => 'view', 'id' => $l['supplier_product_id']])) . '">' . h((string)$l['sku']) . '</a>' : h((string)$l['sku']) ?></td>
            <td><?= h($l['description']) ?></td><td class="num"><?= (int)$l['quantity'] ?></td><td class="num"><?= h(money($l['unit_cost'])) ?></td><td class="num"><?= h(money($l['quantity'] * $l['unit_cost'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="4" class="right"><b>Total (ex VAT)</b></td><td class="num"><b><?= h(money(po_total($lines))) ?></b></td></tr></tfoot>
      </table></div>
    </section>
    <section class="card">
      <dl class="details">
        <dt>Order date</dt><dd><?= h(fmt_date($po['order_date'])) ?: '<span class="muted">—</span>' ?></dd>
        <dt>Expected delivery</dt><dd><?= h(fmt_date($po['expected_date'])) ?: '<span class="muted">—</span>' ?></dd>
        <dt>Supplier's order no.</dt><dd><?= h((string)$po['supplier_ref']) ?: '<span class="muted">—</span>' ?></dd>
        <dt>Our account no.</dt><dd><?= h((string)$supplier['account_number']) ?: '<span class="muted">—</span>' ?></dd>
        <dt>Deliver to</dt><dd><?= nl2br(h((string)$po['deliver_to'])) ?: '<span class="muted">—</span>' ?></dd>
        <dt>Notes</dt><dd><?= nl2br(h((string)$po['notes'])) ?: '<span class="muted">—</span>' ?></dd>
        <dt>Sent</dt><dd><?= h(fmt_datetime($po['sent_at'])) ?: '<span class="muted">—</span>' ?></dd>
        <dt>Received</dt><dd><?= h(fmt_datetime($po['received_at'])) ?: '<span class="muted">—</span>' ?></dd>
      </dl>
    </section>
  </div>

  <aside class="no-print">
    <?php if ($invoices || ($canEdit && in_array($status, ['sent', 'received'], true))): ?>
    <section class="card">
      <div class="card-head"><h2>Supplier invoices</h2></div>
      <?php foreach ($invoices as $i): ?>
        <p class="small"><a href="<?= h(url('supplier_invoices', ['action' => 'view', 'id' => $i['id']])) ?>"><?= h($i['invoice_number'] ?: $i['file_name']) ?></a>
          <?= $i['total'] !== null ? h(money($i['total'])) : '' ?> <?= invoice_status_badge($i['status']) ?></p>
      <?php endforeach; ?>
      <?php if ($canEdit): ?>
        <form method="post" action="<?= h(url('supplier_invoices', ['action' => 'upload'])) ?>" enctype="multipart/form-data" class="stack mt-2">
          <?= csrf_field() ?><input type="hidden" name="po_id" value="<?= $id ?>"><input type="hidden" name="_return" value="<?= h(current_url()) ?>">
          <label>Upload their invoice<input type="file" name="files[]" required accept=".pdf,.png,.jpg,.jpeg,.gif,.webp"></label>
          <button class="btn btn-sm">Upload and check</button>
        </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($canEdit && in_array($status, ['draft', 'sent'], true)): ?>
    <section class="card">
      <div class="card-head"><h2><?= $status === 'draft' ? 'Send to supplier' : 'Send again' ?></h2></div>
      <form method="post" action="<?= h($act('send')) ?>" class="stack">
        <?= csrf_field() ?>
        <label>Name<input name="name" value="<?= h((string)$supplier['contact_name']) ?>" placeholder="Optional"></label>
        <label>Email<input type="email" name="email" value="<?= h((string)$supplier['email']) ?>" required></label>
        <button class="btn btn-primary">✉ Email purchase order</button>
        <?php if (!mail_configured()): ?><p class="help text-warning">Email isn't set up yet.</p><?php endif; ?>
      </form>
      <?php if ($status === 'draft'): ?>
        <form method="post" action="<?= h($act('mark_sent')) ?>" class="mt-4"><?= csrf_field() ?><button class="btn btn-sm">Ordered another way (portal / phone)</button></form>
      <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($canEdit && $status === 'sent'): ?>
    <section class="card">
      <div class="card-head"><h2>Delivered?</h2></div>
      <form method="post" action="<?= h($act('receive')) ?>" class="stack">
        <?= csrf_field() ?>
        <label>Supplier's order no. (optional)<input name="supplier_ref" value="<?= h((string)$po['supplier_ref']) ?>"></label>
        <button class="btn">✔ Mark as received</button>
      </form>
    </section>
    <?php endif; ?>
  </aside>
</div>
