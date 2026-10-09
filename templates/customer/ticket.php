<?php
$status = CUSTOMER_TICKET_STATUSES[$t['status']] ?? ucfirst($t['status']);
$closed = in_array($t['status'], ['resolved', 'closed'], true);
$description = trim((string)preg_replace('/\n*\(Raised on the customer portal by [^)]*\)\s*$/', '', (string)$t['description']));
?>
<div class="page-head"><div>
  <div class="crumbs"><a href="<?= h(portal_url('tickets')) ?>">Support tickets</a></div>
  <h1><?= h($t['subject']) ?> <span class="badge badge-<?= $closed ? 'resolved' : ($t['status'] === 'awaiting_customer' ? 'awaiting' : 'open') ?>"><?= h($status) ?></span></h1>
  <p class="muted"><?= h((string)$t['reference']) ?> · raised <?= h(fmt_datetime($t['created_at'])) ?><?= $t['service_identifier'] ? ' · ' . h($t['service_identifier']) : '' ?></p></div></div>
<?php if ($t['status'] === 'awaiting_customer'): ?><div class="flash flash-warning">We're waiting for you on this one. Please add an update below.</div><?php endif; ?>
<section class="card">
  <div class="card-head"><h2>Updates</h2></div>
  <ul class="timeline">
    <?php if ($description !== ''): ?><li class="external"><div class="timeline-meta"><strong>You</strong> · <?= h(fmt_datetime($t['created_at'])) ?></div><div><?= nl2br(h($description)) ?></div></li><?php endif; ?>
    <?php foreach ($updates as $u): ?>
      <li class="external"><div class="timeline-meta"><strong><?= h($u['customer_user_id'] ? ($u['customer_name'] ?? 'You') : company('name', config('app_name'))) ?></strong> · <?= h(fmt_datetime($u['created_at'])) ?></div><div><?= nl2br(h($u['body'])) ?></div></li>
    <?php endforeach; ?>
  </ul>
  <form method="post" action="<?= h(portal_url('ticket', ['id' => $t['id']])) ?>" class="stack" style="margin-top:1rem">
    <?= csrf_field() ?>
    <label>Add an update<textarea name="body" rows="3" required placeholder="<?= $closed ? 'Still a problem? Tell us and we\'ll reopen it.' : 'Anything else we should know?' ?>"></textarea></label>
    <div><button class="btn btn-primary"><?= $closed ? 'Reopen with this update' : 'Send update' ?></button></div>
  </form>
</section>
