<?php $id = (int)$ticket['id']; $closed = in_array($ticket['status'], ['resolved', 'closed'], true); ?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url('tickets')) ?>">Support tickets</a> · <?= h($ticket['reference']) ?></div>
    <h1><?= h($ticket['subject']) ?> <?= badge($ticket['priority']) ?> <?= badge($ticket['status']) ?></h1>
  </div>
  <div class="actions">
    <a class="btn" href="<?= h(url('tickets', ['action' => 'edit', 'id' => $id])) ?>">Edit</a>
    <?php render('_delete', ['name' => 'tickets', 'id' => $id, 'label' => 'ticket']); ?>
  </div>
</div>

<div class="grid-side">
  <div>
    <?php if ($ticket['description']): ?>
      <section class="card"><div class="card-head"><h2>Description</h2></div><div class="prose"><?= nl2br(h($ticket['description'])) ?></div></section>
    <?php endif; ?>

    <section class="card">
      <div class="card-head"><h2>Updates</h2></div>
      <ul class="timeline">
        <?php foreach ($comments as $c): ?>
          <li class="<?= $c['is_internal'] ? 'internal' : 'external' ?>">
            <div class="timeline-meta"><strong><?= h($c['user_name'] ?? 'System') ?></strong> · <?= h(fmt_datetime($c['created_at'])) ?>
              <span class="badge <?= $c['is_internal'] ? 'badge-internal' : 'badge-customer' ?>"><?= $c['is_internal'] ? 'Internal' : 'Customer-facing' ?></span></div>
            <div><?= nl2br(h($c['body'])) ?></div>
          </li>
        <?php endforeach; ?>
        <?php if (!$comments): ?><li class="muted">No updates yet.</li><?php endif; ?>
      </ul>
      <form method="post" action="<?= h(url('tickets', ['action' => 'comment', 'id' => $id])) ?>" class="comment-form">
        <?= csrf_field() ?>
        <textarea name="body" rows="3" placeholder="Add an update…"></textarea>
        <div class="comment-actions">
          <label><input type="checkbox" name="is_internal" value="1" checked> Internal note</label>
          <label>Status
            <select name="status">
              <?php foreach ($entity['fields']['status']['options'] as $k => $label): ?>
                <option value="<?= h($k) ?>" <?= $ticket['status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button class="btn btn-primary">Save update</button>
        </div>
      </form>
    </section>
  </div>
  <aside>
    <section class="card">
      <dl class="details details-stack">
        <dt>Customer</dt><dd><?= display_value($entity, 'account_id', $ticket) ?></dd>
        <dt>Affected service</dt><dd><?= display_value($entity, 'service_id', $ticket) ?></dd>
        <dt>Reported by</dt><dd><?= display_value($entity, 'contact_id', $ticket) ?></dd>
        <dt>Category</dt><dd><?= h(humanize($ticket['category'])) ?></dd>
        <dt>Assigned to</dt><dd><?= display_value($entity, 'assigned_to', $ticket) ?></dd>
        <?php if ($ticket['carrier_ref']): ?><dt>Carrier fault ref</dt><dd><?= h($ticket['carrier_ref']) ?></dd><?php endif; ?>
        <dt>Opened</dt><dd><?= h(fmt_datetime($ticket['created_at'])) ?></dd>
        <dt>SLA (<?= h($ticket['priority']) ?>)</dt><dd><?= h(fmt_datetime($ticket['sla_due_at'])) ?><br><?= sla_html($ticket['sla_due_at'], $ticket['status'], $ticket['resolved_at']) ?></dd>
        <?php if ($closed && $ticket['resolved_at']): ?><dt>Resolved</dt><dd><?= h(fmt_datetime($ticket['resolved_at'])) ?></dd><?php endif; ?>
      </dl>
    </section>
  </aside>
</div>
