<?php
$rows = $lines ?: [['supplier_product_id' => null, 'sku' => '', 'description' => '', 'quantity' => 1, 'unit_cost' => '']];
$cancel = $po ? url('purchase_orders', ['action' => 'view', 'id' => $po['id']]) : url('suppliers', ['action' => 'view', 'id' => $supplier['id']]);
$accountId = $values['account_id'] ?? null;
?>
<div class="page-head"><div><div class="crumbs"><a href="<?= h(url('suppliers', ['action' => 'view', 'id' => $supplier['id']])) ?>"><?= h($supplier['name']) ?></a></div>
  <h1><?= $po ? 'Edit ' . h($po['reference']) : 'New purchase order' ?></h1></div></div>
<?php if ($errors): ?><div class="flash flash-error"><?= h(implode(' ', array_filter($errors, 'is_string'))) ?></div><?php endif; ?>

<form method="post" class="po-form">
  <?= csrf_field() ?>
  <?php if (!$po && query_int('customer_order_id')): ?><input type="hidden" name="customer_order_id" value="<?= (int)query_int('customer_order_id') ?>"><?php endif; ?>
  <section class="card form-grid">
    <div class="field"><label>Supplier</label><input value="<?= h($supplier['name']) ?>" disabled></div>
    <div class="field <?= isset($errors['account_id']) ? 'has-error' : '' ?>"><label for="po_account">For customer (optional)</label>
      <select id="po_account" name="account_id"><option value="">— Stock / our own use —</option>
        <?php foreach (ref_options('accounts') as $k => $label): ?><option value="<?= (int)$k ?>" <?= (string)$accountId === (string)$k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="po_date">Order date</label><input id="po_date" type="date" name="order_date" value="<?= h($values['order_date']) ?>"></div>
    <div class="field"><label for="po_exp">Expected delivery</label><input id="po_exp" type="date" name="expected_date" value="<?= h($values['expected_date']) ?>"></div>
    <div class="field"><label for="po_ref">Supplier's order no.</label><input id="po_ref" name="supplier_ref" value="<?= h($values['supplier_ref']) ?>" maxlength="80"></div>
    <div class="field wide"><label for="po_deliver">Deliver to</label><textarea id="po_deliver" name="deliver_to" rows="3"><?= h($values['deliver_to']) ?></textarea></div>
    <div class="field wide"><label for="po_notes">Notes for the supplier</label><textarea id="po_notes" name="notes" rows="2"><?= h($values['notes']) ?></textarea></div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Items</h2><button type="button" class="btn btn-sm" data-add-line>+ Add line</button></div>
    <?php if (!empty($errors['_lines'])): ?><div class="flash flash-error"><?= h($errors['_lines']) ?></div><?php endif; ?>
    <div class="table-wrap">
      <table class="table lines-table">
        <thead><tr><th>Product</th><th>Code</th><th>Description</th><th class="num">Qty</th><th class="num">Unit cost £</th><th></th></tr></thead>
        <tbody data-lines>
        <?php foreach ($rows as $l): ?>
          <tr>
            <td><select name="line_supplier_product_id[]" data-po-product aria-label="Product">
              <option value="">Other</option>
              <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p['id'] ?>" data-sku="<?= h((string)$p['supplier_sku']) ?>" data-name="<?= h($p['description']) ?>" data-cost="<?= h($p['cost_price']) ?>" <?= (string)$l['supplier_product_id'] === (string)$p['id'] ? 'selected' : '' ?>><?= h($p['description']) ?><?= $p['supplier_sku'] ? ' (' . h($p['supplier_sku']) . ')' : '' ?></option>
              <?php endforeach; ?>
            </select></td>
            <td><input name="line_sku[]" value="<?= h((string)$l['sku']) ?>" class="w-28" aria-label="Code"></td>
            <td><input name="line_description[]" value="<?= h($l['description']) ?>" aria-label="Description" placeholder="Description"></td>
            <td><input name="line_quantity[]" type="number" min="1" value="<?= (int)$l['quantity'] ?>" class="w-20" aria-label="Quantity"></td>
            <td><input name="line_unit_cost[]" type="number" step="0.01" min="0" value="<?= h((string)$l['unit_cost']) ?>" class="w-28" aria-label="Unit cost"></td>
            <td><button type="button" class="btn btn-sm btn-ghost" data-remove-line aria-label="Remove line">✕</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="quote-totals">Total <b data-po-total>£0.00</b> <span class="muted">(excl. VAT)</span></p>
  </section>
  <div class="form-actions"><button class="btn btn-primary">Save order</button><a class="btn btn-ghost" href="<?= h($cancel) ?>">Cancel</a></div>
</form>
