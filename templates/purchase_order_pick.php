<div class="page-head"><div><div class="crumbs"><a href="<?= h(url('purchase_orders')) ?>">Purchase orders</a></div><h1>New purchase order</h1></div></div>
<?php if ($suppliers): ?>
<form method="get" class="card stack" style="max-width:32rem">
  <input type="hidden" name="page" value="purchase_orders"><input type="hidden" name="action" value="new">
  <?php if ($accountId): ?><input type="hidden" name="account_id" value="<?= (int)$accountId ?>"><?php endif; ?>
  <label>Who are you ordering from?
    <select name="supplier_id" required><option value="">Choose a supplier…</option>
      <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
    </select></label>
  <div><button class="btn btn-primary">Continue</button></div>
</form>
<?php else: ?>
  <div class="card empty"><p>Add a supplier first.</p><?php if (can('suppliers.edit')): ?><p><a class="btn btn-primary" href="<?= h(url('suppliers', ['action' => 'new'])) ?>">+ Add supplier</a></p><?php endif; ?></div>
<?php endif; ?>
