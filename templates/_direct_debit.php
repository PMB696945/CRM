<?php
/** Direct Debit card on the customer page. Vars: $account, $dd ['customer' => ?array, 'link' => ?array] */
$id = (int)$account['id'];
$gc = $dd['customer'];
$status = $gc['mandate_status'] ?? null;
$state = gc_mandate_state($status);
$link = $dd['link'];
$here = url('accounts', ['action' => 'view', 'id' => $id]);
?>
<section class="card dd-card dd-<?= h($state) ?>">
  <div class="card-head"><h2>Direct Debit</h2><?= gc_mandate_badge($status) ?></div>

  <?php if ($state === 'active'): ?>
    <p class="dd-summary">✔ Active <?= h(strtoupper((string)$gc['mandate_scheme'])) ?> mandate</p>
    <dl class="details details-stack">
      <?php if ($gc['mandate_reference']): ?><dt>Reference</dt><dd><?= h($gc['mandate_reference']) ?></dd><?php endif; ?>
      <?php if ($gc['next_charge_date']): ?><dt>Next possible charge date</dt><dd><?= h(fmt_date($gc['next_charge_date'])) ?></dd><?php endif; ?>
    </dl>
  <?php elseif ($state === 'pending'): ?>
    <p class="dd-summary">The customer has signed up and the mandate is being set up with their bank (<?= h(humanize($status)) ?>). This usually takes a few working days.</p>
  <?php elseif ($account['parent_relationship'] === 'billed_via_dealer' && !$link): ?>
    <p class="dd-summary">This customer is billed via their dealer, so Direct Debit is normally collected from the dealer.</p>
    <form method="post" action="<?= h(url('gocardless', ['action' => 'link', 'id' => $id])) ?>">
      <?= csrf_field() ?><button class="btn btn-sm btn-ghost">Create a setup link for this customer anyway</button>
    </form>
  <?php else: ?>
    <p class="dd-summary"><?= $state === 'inactive'
        ? 'The last mandate is <b>' . h(strtolower(humanize($status))) . '</b>. The customer needs to set up a new one.'
        : 'No Direct Debit mandate found for this customer.' ?></p>

    <?php if ($link): ?>
      <div class="dd-link">
        <label for="dd-url-<?= $id ?>">Setup link <small class="muted">· expires <?= h($link['expires_at'] ? fmt_datetime($link['expires_at']) : 'in 7 days') ?></small></label>
        <div class="copy-row">
          <input id="dd-url-<?= $id ?>" readonly value="<?= h($link['url']) ?>" data-select-all>
          <button type="button" class="btn btn-sm" data-copy="#dd-url-<?= $id ?>">Copy</button>
        </div>
        <div class="actions">
          <a class="btn btn-sm btn-primary" href="<?= h(gc_setup_mailto($account, $link['url'])) ?>">✉ Email to customer</a>
          <a class="btn btn-sm" href="<?= h($link['url']) ?>" target="_blank" rel="noopener">Open ↗</a>
        </div>
      </div>
    <?php endif; ?>
    <form method="post" action="<?= h(url('gocardless', ['action' => 'link', 'id' => $id])) ?>" <?= $link ? 'data-confirm="Create a new link? The current one will stop being shown here."' : '' ?>>
      <?= csrf_field() ?>
      <button class="btn btn-sm <?= $link ? 'btn-ghost' : 'btn-primary' ?>"><?= $link ? 'Create a new link' : 'Create Direct Debit setup link' ?></button>
    </form>
  <?php endif; ?>

  <div class="dd-foot">
    <form method="post" action="<?= h(url('gocardless', ['action' => 'check', 'id' => $id])) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm btn-ghost">↻ Check now</button></form>
    <span class="muted">
      <?php if ($gc): ?>
        <a href="<?= h(gc_customer_url($gc['customer_id'])) ?>" target="_blank" rel="noopener">GoCardless ↗</a> · checked <?= h(fmt_datetime($gc['synced_at'])) ?>
      <?php else: ?>
        Not linked to a GoCardless customer
      <?php endif; ?>
    </span>
  </div>
</section>
