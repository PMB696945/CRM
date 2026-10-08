<div class="page-head">
  <h1>Customers <span class="count"><?= count($customers) ?></span></h1>
  <div class="actions"><a class="btn btn-primary" href="<?= h(portal_url('customer_new')) ?>">Add a customer</a></div>
</div>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Customer</th><th>Contact</th><th>Address</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($customers as $c): ?>
    <tr>
      <td><b><?= h($c['name']) ?></b><div class="muted small"><?= h($c['account_number']) ?></div></td>
      <td><?= h((string)$c['contact_name']) ?><div class="muted small"><?= h(implode(' · ', array_filter([(string)$c['contact_email'], (string)$c['contact_phone']]))) ?></div></td>
      <td class="small"><?= h(implode(', ', array_filter([$c['address'], $c['city'], $c['postcode']]))) ?></td>
      <td class="right"><a class="btn btn-sm" href="<?= h(portal_url('check', ['customer' => $c['id']])) ?>">Check &amp; order</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$customers): ?><tr><td colspan="4" class="empty-row">No customers yet. <a href="<?= h(portal_url('customer_new')) ?>">Add your first</a>.</td></tr><?php endif; ?>
  </tbody>
</table></div>
