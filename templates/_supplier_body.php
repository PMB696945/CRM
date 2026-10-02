<?php
/** A supplier's products, purchase orders, invoices, details and files: the supplier page, or the Supplier tab of a customer. */
$id = (int)$supplier['id'];
$here = current_url();
$inAccount = $inAccount ?? false;
$xeroContact = !empty($supplier['xero_contact_id']) && xero_connected() ? db_one('SELECT contact_id, name FROM xero_contacts WHERE id = ?', [$supplier['xero_contact_id']]) : null;
$line = fn(...$parts) => implode(', ', array_filter(array_map(fn($v) => trim((string)$v), $parts)));
$link = fn($u) => $u ? '<a href="' . h(preg_match('#^https?://#i', $u) ? $u : 'https://' . $u) . '" target="_blank" rel="noopener">' . h(preg_replace('#^https?://#i', '', $u)) . ' ↗</a>' : '';
?>
<?php if ($inAccount): ?>
<div class="actions mb-4">
  <?php if (can('purchasing.edit')): ?><a class="btn btn-sm" href="<?= h(url('purchase_orders', ['action' => 'new', 'supplier_id' => $id])) ?>"><?= icon('cart', 'size-4') ?>New purchase order</a><?php endif; ?>
  <?php if ($canWrite): ?>
    <a class="btn btn-sm" href="<?= h(url('price_import', ['supplier_id' => $id])) ?>">Import price file</a>
    <a class="btn btn-sm" href="<?= h(url('suppliers', ['action' => 'edit', 'id' => $id, 'return' => $here])) ?>">Edit supplier details</a>
  <?php endif; ?>
  <?php if (can('audit.view')): ?><a class="btn btn-sm btn-ghost" href="<?= h(url('audit', ['entity' => 'suppliers', 'entity_id' => $id])) ?>">Supplier history</a><?php endif; ?>
</div>
<?php endif; ?>
<div class="grid-side">
  <div>
    <section class="card">
      <div class="card-head"><h2>Products &amp; prices <span class="count"><?= count($products) ?></span></h2>
        <?php if ($canWrite): ?><a class="btn btn-sm" href="<?= h(url('supplier_products', ['action' => 'new', 'supplier_id' => $id, 'return' => $here])) ?>">+ Add product</a><?php endif; ?></div>
      <?php if ($products): ?>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Code</th><th>Description</th><th>Our product</th><th class="num">Cost</th><th class="num">Setup</th><th>Term</th><th>Price changed</th></tr></thead>
          <tbody>
          <?php foreach ($products as $p): ?>
            <tr class="<?= $p['active'] ? '' : 'muted' ?>">
              <td class="small"><?= h((string)$p['supplier_sku']) ?: '<span class="muted">—</span>' ?></td>
              <td><a class="row-link" href="<?= h(url('supplier_products', ['action' => 'view', 'id' => $p['id']])) ?>"><?= h($p['description']) ?></a><?= $p['active'] ? '' : ' <span class="badge">Unavailable</span>' ?></td>
              <td class="small"><?= $p['product_id'] ? '<a href="' . h(url('products', ['action' => 'view', 'id' => $p['product_id']])) . '">' . h($p['product_id__label']) . '</a>' . ($p['preferred'] ? ' <span class="badge badge-active" title="Sets the product\'s cost price">Preferred</span>' : '') : '<span class="muted">—</span>' ?></td>
              <td class="num"><?= h(money($p['cost_price'])) ?><div class="muted small"><?= h(strtolower(BILLING_FREQUENCIES[$p['billing_frequency']] ?? '')) ?></div></td>
              <td class="num"><?= $p['setup_cost'] !== null ? h(money($p['setup_cost'])) : '<span class="muted">—</span>' ?></td>
              <td class="small"><?= h(term_label($p['term_months'])) ?: '<span class="muted">—</span>' ?></td>
              <td class="small"><?= h(fmt_date($p['price_updated_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <p class="muted">No products yet. Add them one at a time, or <?= $canWrite ? '<a href="' . h(url('price_import', ['supplier_id' => $id])) . '">import their price file</a>' : 'import their price file' ?>.</p>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Purchase orders</h2><?php if (can('purchasing.edit')): ?><a class="btn btn-sm" href="<?= h(url('purchase_orders', ['action' => 'new', 'supplier_id' => $id])) ?>">+ New</a><?php endif; ?></div>
      <?php if ($orders): render('_table', ['entity' => entity('purchase_orders'), 'name' => 'purchase_orders', 'rows' => $orders, 'columns' => ['reference', 'account_id', 'status', 'order_date', 'expected_date', 'total']]);
      else: ?><p class="muted">No purchase orders yet.</p><?php endif; ?>
    </section>
  </div>

  <aside>
    <section class="card">
      <div class="card-head"><h2>Details</h2></div>
      <dl class="details details-stack">
        <?php foreach ([
            'Account manager' => h((string)$supplier['contact_name']),
            'Orders email' => $supplier['email'] ? '<a href="mailto:' . h($supplier['email']) . '">' . h($supplier['email']) . '</a>' : '',
            'Phone' => h((string)$supplier['phone']),
            'Accounts email' => $supplier['accounts_email'] ? '<a href="mailto:' . h($supplier['accounts_email']) . '">' . h($supplier['accounts_email']) . '</a>' : '',
            'Support' => $line($supplier['support_phone'] ? h($supplier['support_phone']) : '', $supplier['support_email'] ? '<a href="mailto:' . h($supplier['support_email']) . '">' . h($supplier['support_email']) . '</a>' : ''),
            'Website' => $link($supplier['website']),
            'Partner portal' => $link($supplier['portal_url']),
            'Address' => h($line($supplier['address'], $supplier['address2'], $supplier['city'], $supplier['county'], $supplier['postcode'])),
            'Payment terms' => h((string)$supplier['payment_terms']),
            'Xero' => $xeroContact ? '<a href="' . h(xero_contact_url($xeroContact['contact_id'])) . '" target="_blank" rel="noopener">' . h($xeroContact['name']) . ' ↗</a>' : '',
            'Notes' => nl2br(h((string)$supplier['notes'])),
        ] as $label => $value): if ($value === '') continue; ?>
          <dt><?= h($label) ?></dt><dd><?= $value ?></dd>
        <?php endforeach; ?>
      </dl>
    </section>

    <?php if ($invoices): ?>
    <section class="card">
      <div class="card-head"><h2>Invoices</h2><a href="<?= h(url('supplier_invoices', ['status' => ''])) ?>">All →</a></div>
      <?php foreach ($invoices as $i): ?>
        <p class="small"><a href="<?= h(url('supplier_invoices', ['action' => 'view', 'id' => $i['id']])) ?>"><?= h($i['invoice_number'] ?: $i['file_name']) ?></a>
          <?= h(fmt_date($i['invoice_date'])) ?> · <?= $i['total'] !== null ? h(money($i['total'])) : '' ?> <?= invoice_status_badge($i['status']) ?></p>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php render('_files', ['docs' => $files, 'where' => ['supplier_id' => $id]]); ?>
  </aside>
</div>
