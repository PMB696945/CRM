<?php $titleField = array_key_first(array_filter($entity['fields'], fn($d) => $d['type'] === 'text' && !empty($d['required']))) ?? 'id'; ?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url($name)) ?>"><?= h($entity['plural']) ?></a></div>
    <h1><?= h($row[$titleField] ?? $entity['label']) ?></h1>
  </div>
  <div class="actions">
    <?php if (can('audit.view')): ?><a class="btn btn-ghost" href="<?= h(url('audit', ['entity' => $name, 'entity_id' => $row['id']])) ?>">History</a><?php endif; ?>
    <?php if ($name === 'sites' && can('orders.check') && giacom_configured()): ?><a class="btn" href="<?= h(url('giacom', ['action' => 'check', 'account_id' => $row['account_id'], 'site_id' => $row['id']])) ?>">Check broadband</a><?php endif; ?>
    <?php if ($name === 'products' && xero_connected() && can('products.edit')): ?>
      <form method="post" action="<?= h(url('products', ['action' => 'xero_push', 'id' => $row['id']])) ?>" class="inline"><?= csrf_field() ?><button class="btn"><?= $row['xero_synced_at'] ? 'Update in Xero' : 'Send to Xero' ?></button></form>
    <?php endif; ?>
    <?php if (in_array($name, ['services', 'products'], true) && abillity_configured() && $canWrite && can('sales.edit')): ?>
      <form method="post" action="<?= h(url('abillity_send', ['type' => $name === 'services' ? 'service' : 'product', 'id' => $row['id']])) ?>" class="inline"><?= csrf_field() ?>
        <button class="btn"><?= ($name === 'services' ? $row['abillity_charge_id'] : $row['abillity_charge_type_id']) ? 'Update in aBILLity' : 'Send to aBILLity' ?></button></form>
    <?php endif; ?>
    <?php if ($canWrite): ?><a class="btn" href="<?= h(url($name, ['action' => 'edit', 'id' => $row['id']])) ?>">Edit</a><?php endif; ?>
    <?php if ($canWrite && can('records.delete') && ($name !== 'products' || can('products.edit'))) render('_delete', ['name' => $name, 'id' => $row['id'], 'label' => $entity['label']]); ?>
  </div>
</div>
<div class="card">
  <dl class="details">
    <?php foreach ($entity['fields'] + ($entity['computed'] ?? []) as $field => $def): if (!field_enabled($def) || !empty($def['virtual'])) continue;
        if (!empty($def['section'])): ?><dt class="details-section"><?= h($def['section']) ?></dt><dd class="details-section"></dd><?php endif; ?>
      <dt><?= h($def['label']) ?></dt>
      <dd><?= display_value($entity, $field, $row) ?: '<span class="muted">—</span>' ?></dd>
    <?php endforeach; ?>
  </dl>
</div>
<?php if ($name === 'services') render('_service_login', ['service' => $row]); ?>
<?php if ($name === 'products' && can('suppliers.view') && can('costs.view')): $prices = product_supplier_prices((int)$row['id']); ?>
<section class="card">
  <div class="card-head"><h2>Suppliers</h2>
    <?php if (can('suppliers.edit')): ?><a class="btn btn-sm" href="<?= h(url('supplier_products', ['action' => 'new', 'product_id' => $row['id'], 'description' => $row['name'], 'billing_frequency' => $row['billing_frequency'], 'return' => current_url()])) ?>">+ Add supplier price</a><?php endif; ?></div>
  <?php if ($prices): ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Supplier</th><th>Their code</th><th class="num">Cost</th><th class="num">Setup</th><th>Lead time</th><th>Price changed</th></tr></thead>
      <tbody>
      <?php foreach ($prices as $p): ?>
        <tr class="<?= $p['active'] ? '' : 'muted' ?>">
          <td><a class="row-link" href="<?= h(url('supplier_products', ['action' => 'view', 'id' => $p['id']])) ?>"><?= h($p['supplier_name']) ?></a><?= $p['preferred'] ? ' <span class="badge badge-active">Preferred</span>' : '' ?><?= $p['active'] ? '' : ' <span class="badge">Unavailable</span>' ?></td>
          <td class="small"><?= h((string)$p['supplier_sku']) ?></td>
          <td class="num"><?= h(money($p['cost_price'])) ?> <span class="muted small"><?= h(strtolower(BILLING_FREQUENCIES[$p['billing_frequency']] ?? '')) ?></span></td>
          <td class="num"><?= $p['setup_cost'] !== null ? h(money($p['setup_cost'])) : '<span class="muted">—</span>' ?></td>
          <td class="small"><?= $p['lead_time_days'] !== null ? (int)$p['lead_time_days'] . ' days' : '<span class="muted">—</span>' ?></td>
          <td class="small"><?= h(fmt_date($p['price_updated_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="help">The preferred supplier's price sets this product's cost price, and keeps it up to date when their prices change.</p>
  <?php else: ?>
    <p class="muted">No supplier prices yet. Link a supplier's product to this one to track what it costs you.</p>
  <?php endif; ?>
</section>
<?php endif; ?>
