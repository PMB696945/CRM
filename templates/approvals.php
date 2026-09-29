<div class="page-head"><h1>Approvals</h1></div>
<p class="lead"><?= can('approvals.decide') ? 'Requests from staff to close or delete a customer. Nothing changes until someone approves.' : 'Your requests to close or delete customers.' ?></p>
<nav class="tabs">
  <?php foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Withdrawn', 'all' => 'All'] as $k => $label): ?>
    <a href="<?= h(url('approvals', ['status' => $k])) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>
<div class="table-wrap"><table class="table">
  <thead><tr><th>#</th><th>Request</th><th>Customer</th><th>Reason</th><th>Requested by</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a class="row-link" href="<?= h(url('approvals', ['action' => 'view', 'id' => $r['id']])) ?>">#<?= (int)$r['id'] ?></a></td>
      <td><?= h(APPROVAL_TYPES[$r['type']] ?? $r['type']) ?></td>
      <td><?php if ($r['account_id']): ?><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $r['account_id']])) ?>"><?= h($r['account_label']) ?></a><?php else: ?><?= h($r['account_label']) ?><?php endif; ?></td>
      <td><?= h(mb_strimwidth($r['reason'], 0, 80, '…')) ?></td>
      <td><?= h($r['requested_by_name'] ?? '—') ?><div class="muted small"><?= h(fmt_datetime($r['created_at'])) ?></div></td>
      <td><?= badge($r['status']) ?><?php if ($r['decided_by_name']): ?><div class="muted small"><?= h($r['decided_by_name']) ?></div><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty-row">Nothing here.</td></tr><?php endif; ?>
  </tbody>
</table></div>
