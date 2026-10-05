<?php
$label = date('F Y', strtotime($month . '-01'));
$link = fn(array $change) => url('billing_diary', array_filter(array_merge(['month' => $month], $filters, $change), fn($v) => $v !== '' && $v !== null));
$prev = date('Y-m', strtotime($month . '-01 -1 month'));
$next = date('Y-m', strtotime($month . '-01 +1 month'));
$abillity = abillity_configured();
$signed = fn($v) => $v === null ? '<span class="muted">—</span>' : '<span class="nowrap ' . ((float)$v < 0 ? 'text-danger' : ((float)$v > 0 ? 'text-ok' : '')) . '">' . ((float)$v > 0 ? '+' : ((float)$v < 0 ? '−' : '')) . h(money(abs((float)$v))) . '</span>';
?>
<div class="page-head">
  <div><h1>Billing diary</h1>
    <p class="muted">Every change to a service, month by month. Work through the list when checking the billing, and tick each one off once it's allocated and accounted for.</p></div>
  <div class="actions">
    <a class="btn btn-ghost" href="<?= h($link(['month' => $prev])) ?>" aria-label="Previous month">‹</a>
    <form method="get" class="inline"><input type="hidden" name="page" value="billing_diary">
      <?php foreach (array_filter($filters) as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>"><?php endforeach; ?>
      <select name="month" data-autosubmit aria-label="Month"><?php foreach ($months as $m): ?><option value="<?= h($m) ?>" <?= $m === $month ? 'selected' : '' ?>><?= h(date('F Y', strtotime($m . '-01'))) ?></option><?php endforeach; ?></select></form>
    <a class="btn btn-ghost" href="<?= h($link(['month' => $next])) ?>" aria-label="Next month">›</a>
    <?php if (can('export')): ?><a class="btn" href="<?= h($link(['export' => 'csv'])) ?>">Export CSV</a><?php endif; ?>
  </div>
</div>

<div class="kpis kpis-sm">
  <div class="kpi"><span class="kpi-label">Changes in <?= h($label) ?></span><span class="kpi-value"><?= $summary['total'] ?></span>
    <span class="kpi-sub"><?= h(implode(' · ', array_map(fn($t, $n) => $n . ' ' . strtolower(SERVICE_CHANGE_TYPES[$t] ?? $t), array_keys($summary['by_type']), $summary['by_type'])) ?: 'None yet') ?></span></div>
  <div class="kpi <?= $summary['unchecked'] ? 'kpi-warn' : '' ?>"><span class="kpi-label">Still to check</span><span class="kpi-value"><?= $summary['unchecked'] ?></span>
    <span class="kpi-sub"><?= $summary['total'] && !$summary['unchecked'] ? '✔ All checked' : '<a href="' . h($link(['checked' => 'no'])) . '">Show them</a>' ?></span></div>
  <div class="kpi"><span class="kpi-label">Change in monthly billing</span><span class="kpi-value"><?= $signed($summary['monthly']) ?></span><span class="kpi-sub">from services added, going live, ceased and repriced</span></div>
  <div class="kpi"><span class="kpi-label">One-off charges</span><span class="kpi-value"><?= h(money($summary['one_off'])) ?></span><span class="kpi-sub">setup fees on services going live</span></div>
</div>

<nav class="tabs">
  <a href="<?= h($link(['checked' => ''])) ?>" class="<?= $filters['checked'] === '' ? 'active' : '' ?>">All <span class="count"><?= $summary['total'] ?></span></a>
  <a href="<?= h($link(['checked' => 'no'])) ?>" class="<?= $filters['checked'] === 'no' ? 'active' : '' ?>">To check <span class="count"><?= $summary['unchecked'] ?></span></a>
  <a href="<?= h($link(['checked' => 'yes'])) ?>" class="<?= $filters['checked'] === 'yes' ? 'active' : '' ?>">Checked <span class="count"><?= $summary['total'] - $summary['unchecked'] ?></span></a>
</nav>
<form method="get" class="inline-form mb-3"><input type="hidden" name="page" value="billing_diary"><input type="hidden" name="month" value="<?= h($month) ?>">
  <?php if ($filters['checked'] !== ''): ?><input type="hidden" name="checked" value="<?= h($filters['checked']) ?>"><?php endif; ?>
  <?php if ($account): ?><input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>"><?php endif; ?>
  <label>Change<select name="type" data-autosubmit><option value="">All changes</option>
    <?php foreach (SERVICE_CHANGE_TYPES as $k => $l): ?><option value="<?= h($k) ?>" <?= $filters['type'] === $k ? 'selected' : '' ?>><?= h($l) ?><?= isset($summary['by_type'][$k]) ? ' (' . $summary['by_type'][$k] . ')' : '' ?></option><?php endforeach; ?></select></label>
  <?php if ($account): ?><span>Customer: <b><?= h($account['name']) ?></b> <a href="<?= h($link(['account_id' => null])) ?>">× show all</a></span><?php endif; ?>
</form>

<form method="post" action="<?= h($link(['do' => 'check'])) ?>">
  <?= csrf_field() ?>
  <button class="sr-only" tabindex="-1" aria-hidden="true">Mark selected as checked</button><?php /* pressing Enter in the note marks the selection, not the first row */ ?>
  <div class="table-wrap"><table class="table">
    <thead><tr>
      <th class="w-8"><input type="checkbox" data-toggle-boxes aria-label="Select all"></th>
      <th>When</th><th>Customer</th><th>Change</th><th>From → to</th><th class="num">Monthly</th><th class="num">One-off</th><th>Effective</th>
      <?php if ($abillity): ?><th>aBILLity</th><?php endif; ?><th>Checked</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['checked_at'] ? 'row-done' : '' ?>">
        <td><?php if (!$r['checked_at']): ?><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select"><?php endif; ?></td>
        <td class="small nowrap"><?= h(fmt_date($r['created_at'])) ?> <span class="muted"><?= h(date('H:i', strtotime($r['created_at']))) ?></span><div class="muted"><?= h($r['user_name'] ?: 'Automatic') ?></div></td>
        <td><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $r['account_id']])) ?>"><?= h($r['account_name']) ?></a>
          <div class="small muted nowrap"><?= h($r['account_number']) ?><?php if (!$account): ?> · <a href="<?= h($link(['account_id' => $r['account_id']])) ?>" title="Show only this customer's changes">filter</a><?php endif; ?></div></td>
        <td><b><?= h(SERVICE_CHANGE_TYPES[$r['change_type']] ?? $r['change_type']) ?></b>
          <div class="small"><?= $r['service_id'] ? '<a href="' . h(url('services', ['action' => 'view', 'id' => $r['service_id']])) . '">' . h($r['summary']) . '</a>' : h($r['summary']) ?></div></td>
        <td class="small"><?= $r['from_value'] !== null || $r['to_value'] !== null ? h((string)($r['from_value'] ?? '—')) . ' → <b>' . h((string)($r['to_value'] ?? '—')) . '</b>' : '' ?></td>
        <td class="num"><?= $signed($r['monthly_change']) ?></td>
        <td class="num nowrap"><?= $r['one_off'] !== null ? h(money($r['one_off'])) : '<span class="muted">—</span>' ?></td>
        <td class="small nowrap"><?= h(fmt_date($r['effective_date'])) ?></td>
        <?php if ($abillity): ?><td class="small"><?php
          if (!$r['service_id']): ?><span class="muted">—</span><?php
          elseif ($r['abillity_error']): ?><span class="text-danger" title="<?= h($r['abillity_error']) ?>">Problem</span><?php
          elseif ($r['abillity_pending']): ?><span class="text-warning">Waiting</span><?php
          elseif ($r['abillity_charge_id'] !== null): ?>✔ <?= (int)$r['abillity_charge_id'] ? 'Charge ' . (int)$r['abillity_charge_id'] : 'Sent' ?><?= $r['abillity_provisional'] ? '<div class="muted">provisional start</div>' : '' ?><?php
          else: ?><span class="muted">Not sent</span><?php endif; ?></td><?php endif; ?>
        <td class="small">
          <?php if ($r['checked_at']): ?>✔ <?= h($r['checked_by_name'] ?? '') ?><div class="muted"><?= h(fmt_datetime($r['checked_at'])) ?></div>
            <?php if ($r['check_note']): ?><div><?= h($r['check_note']) ?></div><?php endif; ?>
            <button class="link-button small" formaction="<?= h($link(['do' => 'uncheck'])) ?>" name="id" value="<?= (int)$r['id'] ?>">Undo</button>
          <?php else: ?>
            <button class="btn btn-sm" name="id" value="<?= (int)$r['id'] ?>">Check</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= $abillity ? 10 : 9 ?>" class="empty-row">No service changes <?= $filters['checked'] === 'no' ? 'left to check' : '' ?> in <?= h($label) ?>.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if (array_filter($rows, fn($r) => !$r['checked_at'])): ?>
    <div class="inline-form mt-3">
      <label class="grow">Note (optional)<input name="note" maxlength="500" placeholder="e.g. Agreed with aBILLity invoice 1043"></label>
      <button class="btn btn-primary">Mark selected as checked</button>
    </div>
  <?php endif; ?>
</form>
