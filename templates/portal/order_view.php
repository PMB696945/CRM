<?php
$visits = ['NO_SITE_VISIT' => 'No engineer visit', 'STANDARD' => 'Standard install', 'PREMIUM' => 'Premium install'];
$appt = !empty($v['appointment']) ? explode('|', (string)$v['appointment']) : null;
[$label, $detail] = $progress;
?>
<div class="page-head"><div>
  <div class="crumbs"><a href="<?= h(portal_url('orders')) ?>">Orders</a></div>
  <h1>Order <?= h($d['reference']) ?> <span class="badge badge-<?= h(['Live' => 'active', 'Cancelled' => 'cancelled', 'Not accepted' => 'rejected', 'Withdrawn' => 'cancelled'][$label] ?? 'pending') ?>"><?= h($label) ?></span></h1>
  <?php if ($detail !== ''): ?><p class="muted"><?= h($detail) ?></p><?php endif; ?></div>
  <?php if ($d['status'] === 'submitted'): ?><div class="actions">
    <form method="post" class="inline" data-confirm="Withdraw this order? It won't go ahead."><?= csrf_field() ?><input type="hidden" name="action" value="withdraw"><button class="btn btn-danger">Withdraw order</button></form></div><?php endif; ?>
</div>

<?php if ($d['status'] === 'submitted'): ?>
  <?php if ($agreement && $agreement['status'] === 'sent'): ?>
    <div class="flash flash-warning">Please sign this order's agreement. We'll check and place the order once it's signed. We've emailed it to <?= h((string)$agreement['signer_email']) ?>.
      <a href="<?= h(esign_url($agreement)) ?>" target="_blank" rel="noopener"><b>Review and sign</b></a></div>
  <?php elseif ($agreement && $agreement['status'] === 'signed'): ?>
    <div class="flash flash-info">Agreement signed <?= h(fmt_date($agreement['signed_at'])) ?>. We're checking your order and will email you when it's placed.</div>
  <?php else: ?>
    <div class="flash flash-info">We'll email you this order's agreement to sign shortly.</div>
  <?php endif; ?>
<?php endif; ?>

<div class="grid-side">
  <section class="card">
    <dl class="details">
      <dt>Customer</dt><dd><?= h($d['account_name']) ?></dd>
      <dt>Product</dt><dd><?= h((string)$d['product_name']) ?></dd>
      <dt>Order type</dt><dd><?= ($v['order_type'] ?? '') === 'migrate' ? 'Take over an existing service' : 'New service' ?></dd>
      <?php if (!empty($v['cli'])): ?><dt>Line</dt><dd><?= h($v['cli']) ?></dd><?php endif; ?>
      <dt>Engineer visit</dt><dd><?= h($visits[$v['site_visit_reason'] ?? ''] ?? '—') ?></dd>
      <dt><?= $appt ? 'Appointment requested' : 'Required by' ?></dt><dd><?= h(fmt_date((string)($v['crd'] ?? ''))) ?><?= $appt && !empty($appt[1]) ? ' ' . h($appt[1]) : '' ?></dd>
      <dt>Customer contact</dt><dd><?= h(trim(($v['forename'] ?? '') . ' ' . ($v['surname'] ?? ''))) ?><br><span class="muted"><?= h(implode(' · ', array_filter([$v['telephone'] ?? '', $v['email'] ?? '']))) ?></span></dd>
      <dt>Site contact</dt><dd><?= h(trim(($v['site_forename'] ?? '') . ' ' . ($v['site_surname'] ?? ''))) ?><br><span class="muted"><?= h(implode(' · ', array_filter([$v['site_telephone'] ?? '', $v['site_email'] ?? '']))) ?></span></dd>
      <?php if (!empty($v['client_ref'])): ?><dt>Your reference</dt><dd><?= h($v['client_ref']) ?></dd><?php endif; ?>
      <dt>Placed</dt><dd><?= h(fmt_datetime($d['created_at'])) ?> by <?= h((string)$d['user_name']) ?></dd>
      <?php if ($d['status'] === 'rejected' && $d['decision_note']): ?><dt>Reason</dt><dd><?= h($d['decision_note']) ?></dd><?php endif; ?>
    </dl>
  </section>
  <aside>
    <section class="card">
      <div class="card-head"><h2>Agreement</h2></div>
      <?php if ($agreement): ?>
        <p><b><?= h($agreement['reference']) ?></b> <span class="badge badge-<?= h($agreement['status']) ?>"><?= h(['sent' => 'To sign', 'signed' => 'Signed', 'cancelled' => 'Cancelled', 'draft' => 'Being prepared', 'failed' => 'Being prepared'][$agreement['status']] ?? ucfirst($agreement['status'])) ?></span></p>
        <p class="muted small">Between <?= h($user['dealer_name']) ?> and <?= h(company('name', config('app_name'))) ?>, signed by <?= h((string)$agreement['signer_name']) ?>.</p>
        <?php if ($agreement['status'] === 'sent'): ?><a class="btn btn-primary" href="<?= h(esign_url($agreement)) ?>" target="_blank" rel="noopener">Review and sign</a><?php endif; ?>
      <?php else: ?><p class="muted">Not sent yet.</p><?php endif; ?>
    </section>
  </aside>
</div>
