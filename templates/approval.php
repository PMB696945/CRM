<?php
$user = current_user();
$mine = (int)$request['requested_by'] === (int)$user['id'];
$canDecide = can('approvals.decide') && (!$mine || is_super_admin());
$options = json_decode((string)$request['options'], true) ?: [];
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url('approvals')) ?>">Approvals</a> · #<?= (int)$request['id'] ?></div>
    <h1><?= h(APPROVAL_TYPES[$request['type']] ?? $request['type']) ?>: <?= h($request['account_label']) ?> <?= badge($request['status']) ?></h1>
  </div>
</div>

<div class="grid-side">
  <div>
    <section class="card">
      <dl class="details">
        <dt>Requested by</dt><dd><?= h($request['requested_by_name'] ?? '—') ?>, <?= h(fmt_datetime($request['created_at'])) ?></dd>
        <dt>Reason</dt><dd><?= nl2br(h($request['reason'])) ?></dd>
        <?php if ($request['type'] === 'close_account'): ?><dt>Live services</dt><dd><?= !empty($options['cease_services']) ? 'Mark as ceased' : 'Leave as they are' ?></dd><?php endif; ?>
        <?php if ($request['status'] !== 'pending'): ?>
          <dt>Decision</dt><dd><?= badge($request['status']) ?> <?= h($request['decided_by_name'] ?? '') ?><?= $request['decided_at'] ? ', ' . h(fmt_datetime($request['decided_at'])) : '' ?></dd>
          <?php if ($request['decision_note']): ?><dt>Note</dt><dd><?= nl2br(h($request['decision_note'])) ?></dd><?php endif; ?>
        <?php endif; ?>
      </dl>
    </section>

    <?php if ($request['status'] === 'pending'): ?>
      <?php if ($canDecide): ?>
        <section class="card">
          <div class="card-head"><h2>Decide</h2></div>
          <form method="post" class="stack" action="<?= h(url('approvals', ['action' => 'approve', 'id' => $request['id']])) ?>"
                data-confirm="<?= h($request['type'] === 'delete_account' ? 'Approve and permanently delete this customer and all their records?' : 'Approve and close this customer?') ?>">
            <?= csrf_field() ?>
            <label>Note (optional)<input name="note" maxlength="500"></label>
            <button class="btn <?= $request['type'] === 'delete_account' ? 'btn-danger' : 'btn-primary' ?>">Approve and <?= $request['type'] === 'delete_account' ? 'delete' : 'close' ?></button>
          </form>
          <form method="post" class="stack" style="margin-top:1.5rem" action="<?= h(url('approvals', ['action' => 'reject', 'id' => $request['id']])) ?>">
            <?= csrf_field() ?>
            <label>Why are you rejecting it?<input name="note" maxlength="500" required></label>
            <button class="btn">Reject</button>
          </form>
        </section>
      <?php elseif ($mine && can('approvals.decide')): ?>
        <p class="help">Someone else needs to approve your own request.</p>
      <?php endif; ?>
      <?php if ($mine || can('approvals.decide')): ?>
        <form method="post" action="<?= h(url('approvals', ['action' => 'cancel', 'id' => $request['id']])) ?>" data-confirm="Withdraw this request?"><?= csrf_field() ?><button class="btn btn-ghost">Withdraw request</button></form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <aside>
    <section class="card">
      <div class="card-head"><h2>Customer</h2></div>
      <?php if ($account): ?>
        <p><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $account['id']])) ?>"><strong><?= h($account['name']) ?></strong></a> <?= badge($account['status']) ?></p>
        <dl class="details details-stack">
          <dt>Services not yet ceased</dt><dd><?= (int)$impact['services'] ?> (<?= h(money($impact['mrr'])) ?>/mo live)</dd>
          <dt>Open tickets</dt><dd><?= (int)$impact['tickets'] ?></dd>
          <?php if ($impact['contracts']): ?><dt>Contracts awaiting signature</dt><dd><?= (int)$impact['contracts'] ?></dd><?php endif; ?>
          <?php if ($impact['children']): ?><dt>Customers under this dealer</dt><dd class="text-warning"><?= (int)$impact['children'] ?> (they'll lose their dealer link if deleted)</dd><?php endif; ?>
        </dl>
        <?php if (can('audit.view')): ?><p><a href="<?= h(url('audit', ['account_id' => $account['id']])) ?>">Customer history →</a></p><?php endif; ?>
      <?php else: ?>
        <p class="muted">This customer has been deleted.</p>
      <?php endif; ?>
    </section>
  </aside>
</div>
