<?php $back = url('suppliers', ['action' => 'view', 'id' => $supplier['id']]); ?>
<div class="page-head"><div>
  <div class="crumbs"><a href="<?= h(url('suppliers')) ?>">Suppliers</a> · <a href="<?= h($back) ?>"><?= h($supplier['name']) ?></a></div>
  <h1>Import price file</h1>
  <p class="muted">Update <?= h($supplier['name']) ?>'s prices from their price list. Products are matched on the supplier's product code.</p>
</div></div>
<?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>

<?php if ($step === 'upload'): ?>
<form method="post" enctype="multipart/form-data" class="card stack" style="max-width:40rem">
  <?= csrf_field() ?>
  <label>Price file<input type="file" name="file" accept=".csv,.txt,.xlsx" required></label>
  <p class="help">A CSV or Excel (.xlsx) file with a header row, and at least a product code and a cost price on each line. You'll choose which columns are which next, and see what will change before anything is saved.</p>
  <div><button class="btn btn-primary">Upload and continue</button> <a class="btn btn-ghost" href="<?= h($back) ?>">Cancel</a></div>
</form>

<?php else: ?>
<form method="post" class="stack">
  <?= csrf_field() ?><input type="hidden" name="token" value="<?= h($token) ?>">
  <section class="card">
    <div class="card-head"><h2>1. Which columns are which?</h2><span class="muted small"><?= h($fileName) ?></span></div>
    <div class="form-grid">
      <?php foreach (PRICE_FILE_FIELDS as $f => $label): $required = in_array($f, ['supplier_sku', 'cost_price'], true); ?>
        <div class="field"><label for="m_<?= h($f) ?>"><?= h($label) ?><?= $required ? ' <span class="req">*</span>' : '' ?></label>
          <select id="m_<?= h($f) ?>" name="map[<?= h($f) ?>]" <?= $required ? 'required' : '' ?>>
            <option value=""><?= $required ? 'Choose…' : 'Not in the file' ?></option>
            <?php foreach ($headers as $i => $hd): ?><option value="<?= (int)$i ?>" <?= isset($map[$f]) && (int)$map[$f] === $i ? 'selected' : '' ?>><?= h($hd) ?></option><?php endforeach; ?>
          </select></div>
      <?php endforeach; ?>
      <div class="field"><label for="m_freq">Prices are per</label>
        <select id="m_freq" name="frequency"><?php foreach (BILLING_FREQUENCIES as $k => $l): ?><option value="<?= h($k) ?>" <?= $opts['frequency'] === $k ? 'selected' : '' ?>><?= h(['weekly' => 'Week', 'monthly' => 'Month', 'quarterly' => 'Quarter', 'biannually' => 'Half year', 'yearly' => 'Year'][$k] ?? $l) ?></option><?php endforeach; ?></select>
        <div class="help">For products new to the CRM. Existing products keep their billing cycle.</div></div>
      <div class="field wide">
        <label class="check"><input type="checkbox" name="add_new" value="1" <?= $opts['add_new'] ? 'checked' : '' ?>> Add products that aren't in the CRM yet</label>
        <label class="check"><input type="checkbox" name="update_descriptions" value="1" <?= $opts['update_descriptions'] ? 'checked' : '' ?>> Update descriptions of existing products from the file</label>
        <label class="check"><input type="checkbox" name="retire_missing" value="1" <?= $opts['retire_missing'] ? 'checked' : '' ?>> Mark products missing from this file as unavailable</label>
      </div>
    </div>
    <?php if ($sample): ?>
      <p class="small muted mt-4">First rows of the file:</p>
      <div class="table-wrap"><table class="table small">
        <thead><tr><?php foreach ($headers as $hd): ?><th><?= h($hd) ?></th><?php endforeach; ?></tr></thead>
        <tbody><?php foreach ($sample as $r): ?><tr><?php foreach (array_keys($headers) as $i): ?><td><?= h((string)($r[$i] ?? '')) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
    <div class="mt-4"><button class="btn"><?= $plan ? 'Check again' : 'Check what will change' ?></button></div>
  </section>

  <?php if ($plan): ?>
  <section class="card">
    <div class="card-head"><h2>2. What will change</h2></div>
    <div class="kpis kpis-sm">
      <div class="kpi"><span class="kpi-label">Prices changing</span><span class="kpi-value"><?= count($plan['changed']) ?></span></div>
      <div class="kpi"><span class="kpi-label">New products</span><span class="kpi-value"><?= count($plan['new']) ?></span></div>
      <div class="kpi"><span class="kpi-label">Unchanged</span><span class="kpi-value"><?= (int)$plan['same'] ?></span></div>
      <div class="kpi"><span class="kpi-label">Not in this file</span><span class="kpi-value"><?= count($plan['missing']) ?></span><span class="kpi-sub"><?= $opts['retire_missing'] ? 'will be marked unavailable' : 'left as they are' ?></span></div>
    </div>
    <?php if ($plan['errors']): ?><div class="flash flash-warning"><b><?= count($plan['errors']) ?> row<?= count($plan['errors']) === 1 ? '' : 's' ?> skipped:</b> <?= h(implode(' ', array_slice($plan['errors'], 0, 10))) ?><?= count($plan['errors']) > 10 ? ' …' : '' ?></div><?php endif; ?>
    <?php if ($plan['changed']): ?>
      <h3 class="subhead">Price changes</h3>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Code</th><th>Description</th><th class="num">Was</th><th class="num">Now</th><th class="num">Change</th></tr></thead>
        <tbody><?php foreach (array_slice($plan['changed'], 0, 300) as $c): $diff = $c['cost_price'] - $c['old_cost']; ?>
          <tr><td class="small"><?= h($c['sku']) ?></td><td><?= h($c['old_description']) ?><?= $c['preferred'] ? ' <span class="badge badge-active" title="Its product\'s cost price will be updated too">Updates product cost</span>' : '' ?><?= $c['reactivate'] ? ' <span class="badge">Available again</span>' : '' ?></td>
            <td class="num"><?= h(money($c['old_cost'])) ?></td><td class="num"><b><?= h(money($c['cost_price'])) ?></b></td>
            <td class="num <?= $diff > 0 ? 'text-danger' : ($diff < 0 ? 'text-ok' : '') ?>"><?= $diff > 0 ? '+' : '' ?><?= h(money($diff)) ?><?= $c['old_cost'] > 0 ? ' <span class="small">(' . ($diff > 0 ? '+' : '') . round($diff / $c['old_cost'] * 100, 1) . '%)</span>' : '' ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php if (count($plan['changed']) > 300): ?><p class="muted small">…and <?= count($plan['changed']) - 300 ?> more.</p><?php endif; ?>
    <?php endif; ?>
    <?php if ($plan['new']): ?>
      <h3 class="subhead">New products</h3>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Code</th><th>Description</th><th class="num">Cost</th><th class="num">Setup</th></tr></thead>
        <tbody><?php foreach (array_slice($plan['new'], 0, 300) as $n): ?>
          <tr><td class="small"><?= h($n['sku']) ?></td><td><?= h($n['description']) ?></td><td class="num"><?= h(money($n['cost_price'])) ?></td><td class="num"><?= $n['setup_cost'] !== null ? h(money($n['setup_cost'])) : '—' ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php if (count($plan['new']) > 300): ?><p class="muted small">…and <?= count($plan['new']) - 300 ?> more.</p><?php endif; ?>
    <?php endif; ?>
    <div class="form-actions">
      <?php if ($plan['changed'] || $plan['new'] || ($opts['retire_missing'] && $plan['missing'])): ?>
        <input type="hidden" name="plan_key" value="<?= h($planKey) ?>">
        <button class="btn btn-primary" name="apply" value="1">Apply these changes</button>
      <?php else: ?><p class="muted">Nothing to change: the prices already match.</p><?php endif; ?>
      <a class="btn btn-ghost" href="<?= h($back) ?>">Cancel</a>
    </div>
  </section>
  <?php endif; ?>
</form>
<?php endif; ?>
