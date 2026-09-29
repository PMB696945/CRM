<?php
$pages = max(1, (int)ceil($total / 50));
$filtered = array_filter(array_intersect_key($_GET, array_flip(['q', 'user_id', 'action_type', 'entity', 'entity_id', 'account_id', 'from', 'to'])), fn($v) => $v !== '');
?>
<div class="page-head">
  <h1>Audit trail <span class="count"><?= number_format($total) ?></span></h1>
  <div class="actions"><a class="btn" href="<?= h(url('audit', $filtered + ['export' => 1])) ?>">Export CSV</a></div>
</div>
<p class="lead">Every sign-in, change, approval, email sent, export and settings change, with who did it, from where, and what changed. Entries can't be edited or deleted from the CRM.</p>
<?php if ($accountName || query('entity') !== ''): ?>
  <div class="flash flash-info">Showing
    <?= $accountName ? 'everything about <b>' . h($accountName) . '</b>' : h(humanize(query('entity'))) . (query_int('entity_id') ? ' #' . (int)query_int('entity_id') : '') ?>.
    <a href="<?= h(url('audit')) ?>">Show all</a></div>
<?php endif; ?>
<form class="filters" method="get" action="index.php">
  <input type="hidden" name="page" value="audit">
  <?php foreach (['entity', 'entity_id', 'account_id'] as $k): if (query($k) !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= h(query($k)) ?>"><?php endif; endforeach; ?>
  <input type="search" name="q" value="<?= h(query('q')) ?>" placeholder="Search text or IP…">
  <select name="user_id" data-autosubmit aria-label="User"><option value="">Who: anyone</option>
    <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= query_int('user_id') === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?></select>
  <select name="action_type" data-autosubmit aria-label="Action"><option value="">Action: any</option>
    <?php foreach ($actions as $a): ?><option value="<?= h($a) ?>" <?= query('action_type') === $a ? 'selected' : '' ?>><?= h(humanize($a)) ?></option><?php endforeach; ?></select>
  <label class="check">From <input type="date" name="from" value="<?= h(query('from')) ?>"></label>
  <label class="check">To <input type="date" name="to" value="<?= h(query('to')) ?>"></label>
  <button class="btn">Filter</button>
</form>
<div class="table-wrap"><table class="table compact">
  <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th><th>IP address</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $changes = audit_changes($r['changes']); ?>
    <tr>
      <td class="whitespace-nowrap"><?= h(fmt_datetime($r['created_at'])) ?></td>
      <td><?php if ($r['user_id']): ?><a href="<?= h(url('audit', ['user_id' => $r['user_id']])) ?>"><?= h($r['user_name'] ?? '—') ?></a><?php else: ?><span class="muted">System / customer</span><?php endif; ?></td>
      <td><span class="badge <?= str_contains($r['action'], 'fail') || str_contains($r['action'], 'locked') || in_array($r['action'], ['delete', 'account_close', 'approval_reject'], true) ? 'badge-p1' : '' ?>"><?= h(humanize($r['action'])) ?></span></td>
      <td class="text-gray-800 dark:text-white/90">
        <?php if ($r['entity'] && $r['entity_id'] && entity($r['entity']) && $r['action'] !== 'delete'): ?><a href="<?= h(url($r['entity'], ['action' => 'view', 'id' => $r['entity_id']])) ?>"><?= h($r['summary']) ?></a>
        <?php elseif ($r['entity'] === 'approval_requests' && $r['entity_id']): ?><a href="<?= h(url('approvals', ['action' => 'view', 'id' => $r['entity_id']])) ?>"><?= h($r['summary']) ?></a>
        <?php elseif ($r['entity'] === 'campaigns' && $r['entity_id'] && can('marketing.send')): ?><a href="<?= h(url('campaigns', ['action' => 'view', 'id' => $r['entity_id']])) ?>"><?= h($r['summary']) ?></a>
        <?php else: ?><?= h($r['summary']) ?><?php endif; ?>
        <?php if ($r['account_id'] && !$accountName && $r['entity'] !== 'accounts'): ?><a class="small muted" href="<?= h(url('audit', ['account_id' => $r['account_id']])) ?>">· customer history</a><?php endif; ?>
        <?php if ($changes): ?>
          <details class="changes"><summary>What changed (<?= count($changes) ?>)</summary><?php render('_changes', ['changes' => $changes]); ?></details>
        <?php endif; ?>
      </td>
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
