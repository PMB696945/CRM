<?php
$id = (int)$supplier['id'];
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url('suppliers')) ?>">Suppliers</a></div>
    <h1><?= h($supplier['name']) ?> <?= $supplier['active'] ? '' : badge('disabled') ?></h1>
    <?php if ($supplier['account_id']): ?><p class="small">Also a customer: <a href="<?= h(url('accounts', ['action' => 'view', 'id' => $supplier['account_id']])) ?>">open the company record →</a></p><?php endif; ?>
    <p class="muted"><?= h(SUPPLIER_CATEGORIES[$supplier['category']] ?? '') ?><?= $supplier['account_number'] ? ($supplier['category'] ? ' · ' : '') . 'Our account ' . h($supplier['account_number']) : '' ?></p>
  </div>
  <div class="actions">
    <?php if (can('audit.view')): ?><a class="btn btn-ghost" href="<?= h(url('audit', ['entity' => 'suppliers', 'entity_id' => $id])) ?>">History</a><?php endif; ?>
    <?php if (can('purchasing.edit')): ?><a class="btn" href="<?= h(url('purchase_orders', ['action' => 'new', 'supplier_id' => $id])) ?>"><?= icon('cart', 'size-4') ?>New purchase order</a><?php endif; ?>
    <?php if ($canWrite): ?>
      <a class="btn" href="<?= h(url('price_import', ['supplier_id' => $id])) ?>">Import price file</a>
      <a class="btn" href="<?= h(url('suppliers', ['action' => 'edit', 'id' => $id])) ?>">Edit</a>
      <?php if (can('records.delete')) render('_delete', ['name' => 'suppliers', 'id' => $id, 'label' => 'Supplier']); ?>
    <?php endif; ?>
  </div>
</div>

<?php if (!$supplier['account_id'] && $canWrite && can('customers.edit')): ?>
  <div class="small muted mb-4 flex flex-wrap items-center gap-2">
    <span>Is <?= h($supplier['name']) ?> also a customer or dealer? Make it one company record, with Customer and Supplier tabs:</span>
    <form method="post" action="<?= h(url('suppliers', ['action' => 'make_account', 'id' => $id])) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="role" value="customer"><button class="btn btn-sm">Also a customer</button></form>
    <form method="post" action="<?= h(url('suppliers', ['action' => 'make_account', 'id' => $id])) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="role" value="dealer"><button class="btn btn-sm">Also a dealer</button></form>
    <span class="small muted">Or link an existing customer by editing the supplier.</span>
  </div>
<?php endif; ?>

<?php render('_supplier_body', compact('supplier', 'products', 'orders', 'files', 'canWrite', 'invoices')); ?>
