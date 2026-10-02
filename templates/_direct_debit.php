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

  <?php if (!$gc && can('customers.edit') && ($candidates = array_values(array_filter(gc_unlinked_customers($account), fn($c) => $c['likely'] || $c['mandate_status'])))): ?>
    <form method="post" action="<?= h(url('gocardless', ['action' => 'attach', 'id' => $id])) ?>" class="stack mt-4">
      <?= csrf_field() ?>
      <label>Already set up in GoCardless? Link them:
        <select name="gocardless_customer_id" required>
          <option value="">Choose the GoCardless customer…</option>
          <?php foreach ($candidates as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= $c['likely'] ? '★ ' : '' ?><?= h($c['name']) ?><?= $c['email'] ? ' · ' . h($c['email']) : '' ?> · <?= h($c['mandate_status'] ? gc_mandate_label($c['mandate_status']) : 'no mandate') ?></option>
          <?php endforeach; ?>
        </select></label>
      <p class="help"><?= array_filter(array_column($candidates, 'likely')) ? '★ = same email or name as this customer. ' : '' ?>The CRM links automatically when the GoCardless customer's email matches this customer's (or one of their contacts') email, or the company name matches exactly.</p>
      <div><button class="btn btn-sm">Link</button></div>
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
