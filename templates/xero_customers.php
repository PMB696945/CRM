<?php
$synced = setting('xero_last_sync_at');
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url('accounts')) ?>">Customers</a></div>
    <h1>Add customers from Xero <small class="count"><?= count($contacts) ?></small></h1>
    <p class="muted">Contacts in Xero that aren't customers in the CRM yet. Tick the ones to add: each becomes a customer with its company details, address and people, already linked to Xero for balances.</p>
  </div>
  <div class="actions">
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><button class="btn">Refresh from Xero</button></form>
  </div>
</div>

<nav class="tabs">
  <a href="<?= h(url('xero_customers', ['q' => $q ?: null])) ?>" class="<?= $all ? '' : 'active' ?>">Xero customers</a>
  <a href="<?= h(url('xero_customers', ['show' => 'all', 'q' => $q ?: null])) ?>" class="<?= $all ? 'active' : '' ?>">All contacts</a>
</nav>

<form method="get" class="filters">
  <input type="hidden" name="page" value="xero_customers"><?php if ($all): ?><input type="hidden" name="show" value="all"><?php endif; ?>
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search name, email or account number" aria-label="Search">
  <button class="btn">Search</button>
</form>

<?php if (!$all): ?><p class="help">Xero marks a contact as a customer once you've raised a sales invoice to them. New contacts without one are under <a href="<?= h(url('xero_customers', ['show' => 'all'])) ?>">All contacts</a>.<?= $synced ? ' Last refreshed ' . h(fmt_datetime($synced)) . '.' : '' ?></p><?php endif; ?>

<?php if ($contacts): ?>
<section class="card">
  <form method="post" id="bulk-form" class="bulk-bar">
    <?= csrf_field() ?>
    <span data-selected-count data-empty="Tick the contacts to add">Tick the contacts to add</span>
    <button class="btn btn-primary" data-needs-selection disabled>Add as customers</button>
  </form>
  <div class="table-wrap"><table class="table">
    <thead><tr><th class="check-col"><input type="checkbox" data-check-all aria-label="Select all"></th><th>Name</th><th>Contact</th><th>Phone</th><th>Address</th><th>Xero account no.</th><th class="num">Owes</th></tr></thead>
    <tbody>
    <?php foreach ($contacts as $c): $d = json_decode((string)$c['details'], true) ?: []; $a = $d['address'] ?? []; ?>
      <tr>
        <td class="check-col"><input type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>" form="bulk-form" aria-label="Add <?= h($c['name']) ?>"></td>
        <td><a href="<?= h(xero_contact_url($c['contact_id'])) ?>" target="_blank" rel="noopener"><?= h($c['name']) ?> ↗</a>
          <?= $c['is_supplier'] ? ' <span class="badge">Supplier</span>' : '' ?></td>
        <td class="small"><?= h((string)($d['contact_name'] ?? '')) ?><?php if ($c['email']): ?><div class="muted"><?= h($c['email']) ?></div><?php endif; ?></td>
        <td class="small"><?= h((string)($d['phone'] ?? '')) ?></td>
        <td class="small"><?= h(implode(', ', array_filter([$a['address'] ?? '', $a['city'] ?? '', $a['postcode'] ?? '']))) ?></td>
        <td class="small"><?= h((string)$c['account_number']) ?></td>
        <td class="num"><?= (float)$c['outstanding'] ? h(money($c['outstanding'])) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php else: ?>
  <section class="card"><p class="muted"><?= $q !== '' ? 'No contacts match that search.' : ($all ? 'Every contact in Xero is already a customer in the CRM.' : 'Every Xero customer is already in the CRM. Contacts without a sales invoice yet are under All contacts.') ?>
    <?= $synced ? '' : ' Press Refresh from Xero to bring in your contacts.' ?></p></section>
<?php endif; ?>
