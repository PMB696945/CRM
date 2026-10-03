<?php
$id = (int)$inv['id'];
$act = fn(string $a) => url('supplier_invoices', ['action' => $a, 'id' => $id]);
$canEdit = can('purchasing.edit');
$problems = json_decode((string)$inv['problems'], true) ?: [];
$lines = json_decode((string)$inv['line_items'], true) ?: [];
$isImage = str_starts_with((string)$inv['mime'], 'image/');
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url('supplier_invoices')) ?>">Supplier invoices</a><?= $supplier ? ' · <a href="' . h(url('suppliers', ['action' => 'view', 'id' => $supplier['id']])) . '">' . h($supplier['name']) . '</a>' : '' ?></div>
    <h1>Invoice <?= h($inv['invoice_number'] ?: '#' . $id) ?> <?= invoice_status_badge($inv['status']) ?></h1>
    <p class="muted">Uploaded <?= h(fmt_datetime($inv['created_at'])) ?> · read by <?= $inv['reader'] === 'claude' ? 'Claude' : ($inv['reader'] === 'builtin' ? 'the built-in reader' : 'hand') ?>
      <?= $approver ? ' · approved by ' . h($approver) . ' ' . h(fmt_datetime($inv['approved_at'])) : '' ?></p>
  </div>
  <div class="actions">
    <a class="btn btn-ghost" href="<?= h($act('file')) ?>" target="_blank" rel="noopener">Open the invoice ↗</a>
    <?php if ($inv['xero_invoice_id'] && can('xero.open')): ?><a class="btn btn-ghost" href="<?= h(xero_bill_url($inv['xero_invoice_id'])) ?>" target="_blank" rel="noopener">Bill in Xero ↗</a><?php endif; ?>
    <?php if ($canEdit && $inv['status'] === 'approved' && xero_connected() && xero_can_write_bills()): ?>
      <form method="post" action="<?= h($act('xero')) ?>" class="inline"><?= csrf_field() ?><button class="btn"><?= $inv['xero_invoice_id'] ? 'Update in Xero' : 'Send to Xero' ?></button></form>
    <?php endif; ?>
    <?php if ($canEdit): ?>
      <form method="post" action="<?= h($act('reread')) ?>" class="inline"><?= csrf_field() ?><button class="btn">Read again</button></form>
      <?php if ($inv['status'] === 'approved' || $inv['status'] === 'disputed'): ?>
        <form method="post" action="<?= h($act('reopen')) ?>" class="inline"><?= csrf_field() ?><button class="btn">Back to checking</button></form>
      <?php else: ?>
        <form method="post" action="<?= h($act('approve')) ?>" class="inline" <?= $problems ? 'data-confirm="This invoice doesn\'t match its purchase order. Approve it to pay anyway?"' : '' ?>><?= csrf_field() ?><button class="btn btn-primary">Approve to pay</button></form>
      <?php endif; ?>
      <form method="post" action="<?= h($act('delete')) ?>" class="inline" data-confirm="Delete this invoice?"><?= csrf_field() ?><button class="btn btn-danger">Delete</button></form>
    <?php endif; ?>
  </div>
</div>

<?php if ($inv['xero_error']): ?><div class="flash <?= $inv['xero_invoice_id'] ? 'flash-warning' : 'flash-error' ?>">Xero: <?= h($inv['xero_error']) ?></div><?php endif; ?>
<?php if ($inv['reverse_charge']): ?><div class="flash flash-info">This invoice is under the <b>reverse charge</b>: you account for the VAT. Its bill in Xero uses your reverse charge tax rate.</div><?php endif; ?>
<?php if ($inv['xero_invoice_id'] && !$inv['xero_error']): ?><div class="flash flash-info">In Xero as a bill since <?= h(fmt_datetime($inv['xero_posted_at'])) ?><?= $inv['xero_attached'] ? ', with the invoice attached' : '' ?>.</div><?php endif; ?>
<?php if ($problems && $inv['status'] !== 'approved'): ?>
  <div class="flash flash-warning"><b>Check this invoice:</b><ul class="mt-1"><?php foreach ($problems as $p): ?><li><?= h($p) ?></li><?php endforeach; ?></ul></div>
<?php elseif ($inv['status'] === 'matched'): ?>
  <div class="flash flash-success">This invoice matches <?= $po ? h($po['reference']) : 'the supplier' ?>. Approve it when you're ready to pay.</div>
<?php endif; ?>

<div class="grid-side">
  <div>
    <section class="card">
      <div class="card-head"><h2>Invoice vs purchase order</h2></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th></th><th class="num">Invoice</th><th class="num"><?= $po ? h($po['reference']) : 'Purchase order' ?></th></tr></thead>
        <tbody>
          <tr><td>Supplier</td><td class="num"><?= h($inv['supplier_name'] ?: ($supplier['name'] ?? '—')) ?></td><td class="num"><?= $po ? h(db_value('SELECT name FROM suppliers WHERE id = ?', [$po['supplier_id']])) : '—' ?></td></tr>
          <tr><td>Before VAT</td><td class="num"><?= $inv['net'] !== null ? h(money($inv['net'])) : '—' ?></td><td class="num"><?= $po ? h(money($po['total'])) : '—' ?></td></tr>
          <tr><td>VAT</td><td class="num"><?= $inv['vat'] !== null ? h(money($inv['vat'])) : '—' ?></td><td class="num muted">—</td></tr>
          <tr><td>Total</td><td class="num"><b><?= $inv['total'] !== null ? h(money($inv['total'])) : '—' ?></b></td><td class="num"><?= $po ? h(money($po['total'] * 1.2)) . ' <span class="muted small">at 20% VAT</span>' : '—' ?></td></tr>
          <?php if ($po): ?><tr><td>PO status</td><td></td><td class="num"><?= badge($po['status']) ?></td></tr><?php endif; ?>
        </tbody>
      </table></div>
      <?php if ($po): ?><p class="small mt-2"><a href="<?= h(url('purchase_orders', ['action' => 'view', 'id' => $po['id']])) ?>">Open <?= h($po['reference']) ?></a>
        <?= $po['customer_order_id'] ? ' · for order <a href="' . h(url('customer_orders', ['action' => 'view', 'id' => $po['customer_order_id']])) . '">' . h((string)db_value('SELECT reference FROM customer_orders WHERE id = ?', [$po['customer_order_id']])) . '</a>' : '' ?></p><?php endif; ?>
    </section>

    <?php if ($lines || $poLines): ?>
    <section class="card">
      <div class="card-head"><h2>Lines</h2></div>
      <div class="grid gap-6 lg:grid-cols-2">
        <div><h3 class="subhead">On the invoice</h3>
          <?php if ($lines): ?><table class="table small"><tbody><?php foreach ($lines as $l): ?>
            <tr><td><?= h((string)($l['description'] ?? '')) ?></td><td class="num"><?= isset($l['quantity']) ? h((string)$l['quantity']) . ' ×' : '' ?></td><td class="num"><?= isset($l['net_amount']) && $l['net_amount'] !== null ? h(money($l['net_amount'])) : '' ?></td>
              <td class="num muted"><?= match ($rate = $l['vat_rate'] ?? null) { null => '', 'RC' => 'Reverse charge', 'exempt' => 'Exempt', default => h($rate) . '% VAT' } ?></td></tr>
          <?php endforeach; ?></tbody></table><?php else: ?><p class="muted small">No lines could be read from the invoice.</p><?php endif; ?></div>
        <div><h3 class="subhead">On the purchase order</h3>
          <?php if ($poLines): ?><table class="table small"><tbody><?php foreach ($poLines as $l): ?>
            <tr><td><?= h($l['description']) ?></td><td class="num"><?= (int)$l['quantity'] ?> ×</td><td class="num"><?= h(money($l['quantity'] * $l['unit_cost'])) ?></td></tr>
          <?php endforeach; ?></tbody></table><?php else: ?><p class="muted small">No purchase order.</p><?php endif; ?></div>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($isImage): ?><section class="card"><img src="<?= h($act('file')) ?>" alt="The invoice" style="max-width:100%;border-radius:8px"></section><?php endif; ?>
  </div>

  <aside>
    <section class="card">
      <div class="card-head"><h2>Details</h2></div>
      <form method="post" action="<?= h($act('save')) ?>" class="stack">
        <?= csrf_field() ?>
        <label>Supplier<select name="supplier_id" <?= $canEdit ? '' : 'disabled' ?>><option value="">— Not known —</option>
          <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$inv['supplier_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?></select></label>
        <label>Purchase order<select name="po_id" <?= $canEdit ? '' : 'disabled' ?>><option value="">— None —</option>
          <?php foreach ($openPos as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$inv['po_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['reference'] . ' · ' . $p['supplier'] . ' · ' . money($p['total'])) ?></option><?php endforeach; ?></select></label>
        <label>PO number on the invoice<input name="po_reference" value="<?= h((string)$inv['po_reference']) ?>"></label>
        <label>Invoice number<input name="invoice_number" value="<?= h((string)$inv['invoice_number']) ?>"></label>
        <div class="grid grid-cols-2 gap-3">
          <label>Invoice date<input type="date" name="invoice_date" value="<?= h((string)$inv['invoice_date']) ?>"></label>
          <label>Due<input type="date" name="due_date" value="<?= h((string)$inv['due_date']) ?>"></label>
        </div>
        <div class="grid grid-cols-3 gap-3">
          <label>Net £<input name="net" value="<?= h((string)$inv['net']) ?>" inputmode="decimal"></label>
          <label>VAT £<input name="vat" value="<?= h((string)$inv['vat']) ?>" inputmode="decimal"></label>
          <label>Total £<input name="total" value="<?= h((string)$inv['total']) ?>" inputmode="decimal"></label>
        </div>
        <label>Notes<textarea name="notes" rows="2"><?= h((string)$inv['notes']) ?></textarea></label>
        <?php if ($canEdit): ?><div><button class="btn">Save and match again</button></div><?php endif; ?>
      </form>
      <?php if ($canEdit && $inv['status'] !== 'disputed'): ?>
        <form method="post" action="<?= h($act('dispute')) ?>" class="stack mt-4"><?= csrf_field() ?>
          <label>Dispute with the supplier<input name="notes" placeholder="Reason (optional)"></label>
          <div><button class="btn btn-sm btn-danger">Mark as disputed</button></div></form>
      <?php endif; ?>
    </section>
    <?php if (trim((string)$inv['raw_text']) !== ''): ?>
    <section class="card">
      <details><summary class="small">Text read from the file</summary>
        <pre class="small mt-2" style="white-space:pre-wrap"><?= h((string)$inv['raw_text']) ?></pre></details>
    </section>
    <?php endif; ?>
  </aside>
</div>
