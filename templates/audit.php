<?php $pages = max(1, (int)ceil($total / 50)); ?>
<div class="page-head"><h1>Audit log <span class="count"><?= number_format($total) ?></span></h1></div>
<p class="lead">Sign-ins, failed sign-ins, changes, deletions, exports and settings changes. Entries can't be edited or deleted from the CRM.</p>
<form class="filters" method="get" action="index.php">
  <input type="hidden" name="page" value="audit">
  <input type="search" name="q" value="<?= h(query('q')) ?>" placeholder="Search text or IP…">
  <select name="user_id" data-autosubmit><option value="">User: any</option>
    <?php foreach (ref_options('users') as $id => $name): ?><option value="<?= (int)$id ?>" <?= query_int('user_id') === (int)$id ? 'selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?></select>
  <select name="action_type" data-autosubmit><option value="">Action: any</option>
    <?php foreach ($actions as $a): ?><option value="<?= h($a) ?>" <?= query('action_type') === $a ? 'selected' : '' ?>><?= h(humanize($a)) ?></option><?php endforeach; ?></select>
  <button class="btn">Filter</button>
</form>
<div class="table-wrap"><table class="table compact">
  <thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th><th>IP address</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="whitespace-nowrap"><?= h(fmt_datetime($r['created_at'])) ?></td>
      <td><?= h($r['user_name'] ?? '—') ?></td>
      <td><span class="badge <?= str_contains($r['action'], 'fail') || str_contains($r['action'], 'locked') || $r['action'] === 'delete' ? 'badge-p1' : '' ?>"><?= h(humanize($r['action'])) ?></span></td>
      <td class="text-gray-800 dark:text-white/90"><?php if ($r['entity'] && $r['entity_id'] && entity($r['entity']) && $r['action'] !== 'delete'): ?><a href="<?= h(url($r['entity'], ['action' => 'view', 'id' => $r['entity_id']])) ?>"><?= h($r['summary']) ?></a><?php else: ?><?= h($r['summary']) ?><?php endif; ?></td>
      <td class="font-mono text-theme-xs"><?= h($r['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="empty-row">Nothing recorded yet.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php if ($pages > 1): ?>
<nav class="pager">
  <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= h(url('audit', array_merge($_GET, ['page' => 'audit', 'p' => $page - 1]))) ?>">← Newer</a><?php endif; ?>
  <span>Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= h(url('audit', array_merge($_GET, ['page' => 'audit', 'p' => $page + 1]))) ?>">Older →</a><?php endif; ?>
</nav>
<?php endif; ?>
