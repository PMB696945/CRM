<div class="page-head"><div><h1>Support tickets</h1><p class="muted">To report a fault or ask a question, contact us<?= company('phone') ? ' on ' . h(company('phone')) : '' ?><?= company('email') ? ' or at ' . h(company('email')) : '' ?>.</p></div></div>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Ticket</th><th>Subject</th><th>Type</th><th>Raised</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($tickets as $t): ?>
    <tr><td><b><?= h((string)$t['reference']) ?></b></td><td><?= h($t['subject']) ?></td><td><?= h(ucfirst($t['category'])) ?></td>
      <td class="whitespace-nowrap"><?= h(fmt_date($t['created_at'])) ?></td>
      <td><?= h(['open' => 'Open', 'in_progress' => 'In progress', 'awaiting_customer' => 'Waiting for you', 'awaiting_carrier' => 'With the network', 'resolved' => 'Resolved', 'closed' => 'Closed'][$t['status']] ?? $t['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$tickets): ?><tr><td colspan="5" class="empty-row">No support tickets.</td></tr><?php endif; ?>
  </tbody>
</table></div>
