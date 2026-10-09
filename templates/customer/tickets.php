<div class="page-head"><div><h1>Support tickets</h1><p class="muted">Report a fault or ask us anything<?= company('phone') ? '. For something urgent you can also call us on ' . h(company('phone')) : '' ?>.</p></div>
  <div class="actions"><a class="btn btn-primary" href="<?= h(portal_url('ticket_new')) ?>">Raise a ticket</a></div></div>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Ticket</th><th>Subject</th><th>Type</th><th>Raised</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($tickets as $t): ?>
    <tr><td><a href="<?= h(portal_url('ticket', ['id' => $t['id']])) ?>"><b><?= h((string)$t['reference']) ?></b></a></td><td><?= h($t['subject']) ?></td><td><?= h(ucfirst($t['category'])) ?></td>
      <td class="whitespace-nowrap"><?= h(fmt_date($t['created_at'])) ?></td>
      <td><?= h(CUSTOMER_TICKET_STATUSES[$t['status']] ?? $t['status']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$tickets): ?><tr><td colspan="5" class="empty-row">No support tickets. <a href="<?= h(portal_url('ticket_new')) ?>">Raise one</a>.</td></tr><?php endif; ?>
  </tbody>
</table></div>
