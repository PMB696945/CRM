<?php
// Where this customer is set up for billing and accounts: aBILLity and Xero. $account, $canEdit.
if (!abillity_configured()) {
    return;
}
$linked = (bool)$account['abillity_site_id'];
$billable = abillity_account_billable($account);
?>
<section class="card">
  <div class="card-head"><h2>Billing</h2></div>
  <dl class="details details-stack">
    <dt>aBILLity</dt>
    <dd><?php if ($linked): ?>✔ Company <?= (int)$account['abillity_company_id'] ?>, site <?= (int)$account['abillity_site_id'] ?>
        <?= $account['abillity_synced_at'] ? '<br><span class="small muted">Updated ' . h(fmt_datetime($account['abillity_synced_at'])) . '</span>' : '' ?>
      <?php elseif ($account['abillity_pending']): ?><span class="text-warning">Waiting to be sent</span>
      <?php else: ?><span class="muted"><?= $billable ? 'Not sent yet' : 'Sent when they become a customer or order' ?></span><?php endif; ?></dd>
    <?php if (xero_connected()): ?><dt>Xero</dt><dd><?= $account['xero_contact_id'] ? '✔ Linked' : '<span class="muted">Not set up yet</span>' ?></dd><?php endif; ?>
  </dl>
  <?php if ($account['abillity_error']): ?><p class="small text-danger mt-2"><?= h($account['abillity_error']) ?></p><?php endif; ?>
  <?php if ($canEdit && can('sales.edit')): ?>
    <form method="post" action="<?= h(url('abillity_send', ['type' => 'account', 'id' => $account['id']])) ?>" class="mt-3"
      <?= $linked ? '' : 'data-confirm="Set up ' . h($account['name']) . ' (' . h($account['account_number']) . ') in aBILLity' . (xero_connected() && !$account['xero_contact_id'] ? ' and Xero' : '') . '?"' ?>>
      <?= csrf_field() ?><button class="btn btn-sm"><?= $linked ? 'Send details to aBILLity again' : 'Set up in aBILLity' . (xero_connected() && !$account['xero_contact_id'] ? ' & Xero' : '') ?></button></form>
  <?php endif; ?>
</section>
