<?php
$waitingAge = function (string $created): string {
    $mins = max(0, (int)round((time() - strtotime($created)) / 60));
    return $mins < 60 ? $mins . 'm' : ($mins < 2880 ? intdiv($mins, 60) . 'h ' . ($mins % 60) . 'm' : intdiv($mins, 1440) . 'd');
};
$overdueIds = array_flip(array_map(fn($t) => (int)$t['id'], tickets_overdue_pickup()));
$groupName = $groupId ? (array_values(array_filter($groups, fn($g) => (int)$g['id'] === $groupId))[0]['name'] ?? '') : null;
?>
<div class="page-head">
  <div><h1>Ticket queue<?= $groupName ? ' · ' . h($groupName) : '' ?> <span class="count"><?= count($queue) ?></span></h1></div>
  <?php if ($queue): ?>
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="next" value="1"><button class="btn btn-primary">Take next ticket</button></form>
  <?php endif; ?>
</div>
<p class="lead">Tickets waiting for someone in your groups, oldest first. <b>Take next ticket</b> gives you the one that's been waiting longest. Picking one up assigns it to you and takes it out of everyone else's queue.</p>

<?php if (!$mine && !can('tickets.all')): ?>
  <div class="card empty"><p>You're not in any ticket groups yet.</p><p class="muted">Ask an admin to add you under Admin → Ticket groups.</p></div>
<?php else: ?>
<nav class="tabs">
  <a href="<?= h(url('queue')) ?>" class="<?= !$groupId ? 'active' : '' ?>"><?= can('tickets.all') ? 'All groups' : 'All my groups' ?></a>
  <?php foreach ($groups as $g): ?>
    <a href="<?= h(url('queue', ['group' => $g['id']])) ?>" class="<?= $groupId === (int)$g['id'] ? 'active' : '' ?>"><?= h($g['name']) ?> <span class="count"><?= (int)($counts[(int)$g['id']] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>

<div class="table-wrap"><table class="table">
  <thead><tr><th>#</th><th>Ticket</th><th>Customer</th><th>Group</th><th>Priority</th><th>Waiting</th><th>SLA</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($queue as $i => $t): ?>
    <tr>
      <td class="muted"><?= $i + 1 ?></td>
      <td><a class="row-link" href="<?= h(url('tickets', ['action' => 'view', 'id' => $t['id']])) ?>"><?= h($t['reference']) ?></a> <?= h($t['subject']) ?><div class="muted small"><?= h(humanize($t['category'])) ?></div></td>
      <td><?= h($t['account_name']) ?></td>
      <td><?= h($t['group_name']) ?></td>
      <td><?= badge($t['priority']) ?></td>
      <td class="<?= isset($overdueIds[(int)$t['id']]) ? 'text-danger' : '' ?>"><?= h($waitingAge($t['created_at'])) ?><?= isset($overdueIds[(int)$t['id']]) ? ' · too long' : '' ?><div class="muted small"><?= h(fmt_datetime($t['created_at'])) ?></div></td>
      <td><?= sla_html($t['sla_due_at'], $t['status'], null) ?></td>
      <td class="right"><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-sm">Pick up</button></form></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$queue): ?><tr><td colspan="8" class="empty-row">Nothing waiting. 🎉</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php endif; ?>

<section class="card" style="margin-top:1.5rem">
  <div class="card-head"><h2>My open tickets <span class="count"><?= count($myOpen) ?></span></h2></div>
  <?php if ($myOpen): ?>
    <ul class="feed"><?php foreach ($myOpen as $t): ?>
      <li><a href="<?= h(url('tickets', ['action' => 'view', 'id' => $t['id']])) ?>"><?= h($t['reference']) ?> <?= h($t['subject']) ?></a> <?= badge($t['priority']) ?> <?= badge($t['status']) ?>
        <span class="muted">· <?= h($t['account_name']) ?><?= $t['group_name'] ? ' · ' . h($t['group_name']) : '' ?> · SLA <?= sla_html($t['sla_due_at'], $t['status'], null) ?></span></li>
    <?php endforeach; ?></ul>
  <?php else: ?><p class="muted">Nothing assigned to you.</p><?php endif; ?>
</section>
