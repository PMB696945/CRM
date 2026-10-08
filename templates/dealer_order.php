<?php
$visits = GIACOM_VISITS;
$appt = !empty($v['appointment']) ? explode('|', (string)$v['appointment']) : null;
$waiting = $d['status'] === 'submitted';
$signed = $agreement && $agreement['status'] === 'signed';
$canPlace = can('orders.place');
?>
<div class="page-head"><div>
  <div class="crumbs"><a href="<?= h(url('dealer_orders')) ?>">Dealer orders</a> · <a href="<?= h(url('accounts', ['action' => 'view', 'id' => $d['dealer_id'], 'tab' => 'dealer'])) ?>"><?= h($d['dealer_name']) ?></a></div>
  <h1>Dealer order <?= h($d['reference']) ?> <span class="badge badge-<?= h(['submitted' => 'pending', 'placed' => 'active', 'rejected' => 'rejected', 'withdrawn' => 'cancelled'][$d['status']] ?? 'pending') ?>"><?= h(DEALER_ORDER_STATUSES[$d['status']] ?? $d['status']) ?></span></h1>
  <p class="muted"><?= h((string)$d['product_name']) ?> for <a href="<?= h(url('accounts', ['action' => 'view', 'id' => $d['account_id']])) ?>"><?= h($d['account_name']) ?></a></p></div>
</div>
<?php if ($d['last_error']): ?><div class="flash flash-error"><?= h($d['last_error']) ?></div><?php endif; ?>
<?php if ($waiting && !$msa): ?><div class="flash flash-warning"><?= h($d['dealer_name']) ?> hasn't signed their master terms, so this order can't be approved yet. <a href="<?= h(url('contracts', ['action' => 'new', 'account_id' => $d['dealer_id']])) ?>">Send an MSA</a></div><?php endif; ?>

<div class="grid-side">
  <div>
    <section class="card">
      <dl class="details">
        <dt>Dealer</dt><dd><?= h($d['dealer_name']) ?> · sent by <?= h((string)$d['user_name']) ?> <span class="muted">&lt;<?= h((string)$d['user_email']) ?>&gt;</span></dd>
        <dt>Customer</dt><dd><?= h($d['account_name']) ?> <span class="muted"><?= h($d['account_number']) ?></span></dd>
        <dt>Product</dt><dd><?= h((string)$d['product_name']) ?> <span class="muted small">supplier product <?= h((string)$d['supplier_product']) ?></span></dd>
        <dt>Address</dt><dd><?= h((string)($check['address_label'] ?? '—')) ?><?php if ($check): ?> · <a href="<?= h(url('giacom', ['action' => 'result', 'id' => $check['id']])) ?>">availability</a><?php endif; ?></dd>
        <dt>Order type</dt><dd><?= ($v['order_type'] ?? '') === 'migrate' ? 'Migrate (take over)' : 'Provide (new)' ?><?= !empty($v['cli']) ? ' · line ' . h($v['cli']) : '' ?></dd>
        <dt>Engineer visit</dt><dd><?= h($visits[$v['site_visit_reason'] ?? ''] ?? '—') ?></dd>
        <dt><?= $appt ? 'Appointment wanted' : 'Required by' ?></dt><dd><?= h(fmt_date((string)($v['crd'] ?? ''))) ?><?= $appt && !empty($appt[1]) ? ' ' . h($appt[1]) : '' ?></dd>
        <dt>Customer contact</dt><dd><?= h(trim(($v['title'] ?? '') . ' ' . ($v['forename'] ?? '') . ' ' . ($v['surname'] ?? ''))) ?><br><span class="muted"><?= h(implode(' · ', array_filter([$v['telephone'] ?? '', $v['email'] ?? '']))) ?></span></dd>
        <dt>Site contact</dt><dd><?= h(trim(($v['site_forename'] ?? '') . ' ' . ($v['site_surname'] ?? ''))) ?><br><span class="muted"><?= h(implode(' · ', array_filter([$v['site_telephone'] ?? '', $v['site_email'] ?? '']))) ?></span>
          <?php foreach (['site_passphrase' => 'Pass phrase', 'site_notes' => 'Access', 'hazard_notes' => 'Hazards'] as $k => $l): if (!empty($v[$k])): ?><br><span class="small"><?= $l ?>: <?= h($v[$k]) ?></span><?php endif; endforeach; ?></dd>
        <?php if (!empty($v['client_ref'])): ?><dt>Dealer's reference</dt><dd><?= h($v['client_ref']) ?></dd><?php endif; ?>
        <dt>Sent</dt><dd><?= h(fmt_datetime($d['created_at'])) ?></dd>
        <?php if ($d['decided_at']): ?><dt>Decided</dt><dd><?= h(fmt_datetime($d['decided_at'])) ?><?= $d['decision_note'] ? ' · ' . h($d['decision_note']) : '' ?></dd><?php endif; ?>
        <?php if ($d['giacom_order_id']): ?><dt>Broadband order</dt><dd><a href="<?= h(url('giacom', ['action' => 'view', 'id' => $d['giacom_order_id']])) ?>">Open the broadband order</a> · <?= h(implode(' – ', array_filter($progress))) ?></dd><?php endif; ?>
      </dl>
    </section>
  </div>
  <aside>
    <section class="card">
      <div class="card-head"><h2>Agreement with the dealer</h2></div>
      <p class="small muted">Master terms: <?= $msa ? '<a href="' . h(url('contracts', ['action' => 'view', 'id' => $msa['id']])) . '">' . h($msa['reference']) . '</a> signed ' . h(fmt_date($msa['signed_at'])) : '<span class="text-danger">not signed</span>' ?></p>
      <?php if ($agreement): ?>
        <p><a href="<?= h(url('contracts', ['action' => 'view', 'id' => $agreement['id']])) ?>"><b><?= h($agreement['reference']) ?></b></a> <?= badge($agreement['status']) ?></p>
        <p class="small muted">To <?= h((string)$agreement['signer_name']) ?> &lt;<?= h((string)$agreement['signer_email']) ?>&gt;<?= $agreement['signed_at'] ? ' · signed ' . h(fmt_datetime($agreement['signed_at'])) : '' ?></p>
      <?php else: ?><p class="muted">Not created yet.</p><?php endif; ?>
      <?php if ($waiting && $canPlace && !$signed): ?>
        <form method="post" action="<?= h(url('dealer_orders', ['action' => 'view', 'id' => $d['id'], 'do' => 'agreement'])) ?>"><?= csrf_field() ?>
          <button class="btn btn-sm"><?= $agreement && $agreement['status'] === 'sent' ? 'Send a reminder' : 'Create and send the agreement' ?></button></form>
      <?php endif; ?>
    </section>
    <?php if ($waiting && $canPlace): ?>
      <section class="card">
        <div class="card-head"><h2>Approve</h2></div>
        <form method="post" action="<?= h(url('dealer_orders', ['action' => 'view', 'id' => $d['id'], 'do' => 'approve'])) ?>" class="stack" data-confirm="Place this order with the supplier now? It's a real order and may incur charges.">
          <?= csrf_field() ?>
          <button class="btn btn-primary" <?= $signed && $msa ? '' : 'disabled' ?>>Approve and place order</button>
          <p class="help"><?= $signed && $msa ? 'Places it with the supplier using the broadband settings under Admin → Giacom, books the appointment if it\'s still free, and emails the dealer.' : 'Available once the dealer has signed their master terms and this order\'s agreement.' ?></p>
        </form>
      </section>
      <section class="card">
        <div class="card-head"><h2>Turn down</h2></div>
        <form method="post" action="<?= h(url('dealer_orders', ['action' => 'view', 'id' => $d['id'], 'do' => 'reject'])) ?>" class="stack" data-confirm="Turn this order down? The dealer will be emailed the reason.">
          <?= csrf_field() ?>
          <label>Reason (the dealer sees this)<textarea name="reason" rows="2" required maxlength="500"></textarea></label>
          <button class="btn btn-danger">Turn down</button>
        </form>
      </section>
    <?php endif; ?>
  </aside>
</div>
