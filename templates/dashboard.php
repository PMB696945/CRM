<?php $revenue = can('revenue.view'); $barKey = $revenue ? 'mrr' : 'n'; $maxMrr = max(1, ...array_map(fn($r) => (float)$r[$barKey], $mrrByType ?: [[$barKey => 1]])); ?>
<div class="page-head"><h1>Dashboard</h1><span class="muted"><?= h(date('l j F Y')) ?></span></div>

<div class="kpis">
  <a class="kpi" href="<?= h(url('accounts', ['status' => 'active'])) ?>">
    <span class="kpi-label">Active customers</span>
    <span class="kpi-value"><?= number_format($stats['customers']) ?></span>
    <span class="kpi-sub"><?= number_format($stats['prospects']) ?> prospects</span>
  </a>
  <?php if ($revenue): ?>
  <a class="kpi" href="<?= h(url('services', ['status' => 'active'])) ?>">
    <span class="kpi-label">Monthly recurring revenue</span>
    <span class="kpi-value"><?= h(money($stats['mrr'])) ?></span>
    <span class="kpi-sub"><?= number_format($stats['lines']) ?> active services · ARR <?= h(money($stats['mrr'] * 12)) ?></span>
  </a>
  <?php else: ?>
  <a class="kpi" href="<?= h(url('services', ['status' => 'active'])) ?>">
    <span class="kpi-label">Active services</span>
    <span class="kpi-value"><?= number_format($stats['lines']) ?></span>
  </a>
  <?php endif; ?>
  <a class="kpi <?= $stats['breached'] ? 'kpi-alert' : '' ?>" href="<?= h(url('tickets', ['preset' => $stats['breached'] ? 'breached' : 'open'])) ?>">
    <span class="kpi-label">Open tickets</span>
    <span class="kpi-value"><?= number_format($stats['open_tickets']) ?></span>
    <span class="kpi-sub"><?= number_format($stats['breached']) ?> SLA breached</span>
  </a>
  <a class="kpi" href="<?= h(url('pipeline')) ?>">
    <span class="kpi-label">Open pipeline (monthly)</span>
    <span class="kpi-value"><?= h(money($stats['pipeline'])) ?></span>
    <span class="kpi-sub">Weighted <?= h(money($stats['weighted'])) ?> · won this month <?= h(money($stats['won_mrr_month'])) ?></span>
  </a>
  <?php if (isset($stats['xero_overdue'])): ?>
  <a class="kpi <?= $stats['xero_overdue'] > 0 ? 'kpi-alert' : '' ?>" href="<?= h(url('accounts', ['preset' => 'arrears'])) ?>">
    <span class="kpi-label">Overdue debt (Xero)</span>
    <span class="kpi-value"><?= h(money($stats['xero_overdue'])) ?></span>
    <span class="kpi-sub"><?= number_format($stats['xero_debtors']) ?> in arrears · <?= h(money($stats['xero_outstanding'])) ?> outstanding</span>
  </a>
  <?php endif; ?>
  <?php if (isset($stats['no_dd'])): ?>
  <a class="kpi <?= $stats['no_dd'] ? 'kpi-warn' : '' ?>" href="<?= h(url('accounts', ['preset' => 'no_dd'])) ?>">
    <span class="kpi-label">No Direct Debit</span>
    <span class="kpi-value"><?= number_format($stats['no_dd']) ?></span>
    <span class="kpi-sub">active customers · <?= number_format($stats['dd_pending']) ?> being set up</span>
  </a>
  <?php endif; ?>
  <a class="kpi <?= $stats['expiring'] ? 'kpi-warn' : '' ?>" href="<?= h(url('services', ['preset' => 'expiring'])) ?>">
    <span class="kpi-label">Up for renewal</span>
    <span class="kpi-value"><?= number_format($stats['expiring']) ?></span>
    <span class="kpi-sub">services ending within <?= (int)$window ?> days</span>
  </a>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Renewals due</h2><a href="<?= h(url('services', ['preset' => 'expiring'])) ?>">All →</a></div>
    <?php if (!$renewals): ?><p class="muted">No contracts ending in the next <?= (int)$window ?> days.</p><?php else: ?>
    <div class="table-wrap">
    <table class="table compact">
      <thead><tr><th>Customer</th><th class="num">Services</th><?php if ($revenue): ?><th class="num">MRR</th><?php endif; ?><th>First ends</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($renewals as $r): ?>
        <tr>
          <td><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $r['account_id']])) ?>"><?= h($r['name']) ?></a></td>
          <td class="num"><?= (int)$r['services'] ?></td>
          <?php if ($revenue): ?><td class="num"><?= h(money($r['mrr'])) ?></td><?php endif; ?>
          <td><?= contract_end_html($r['first_end']) ?></td>
          <td class="right">
            <?php if ($r['open_renewals']): ?><span class="badge badge-qualified">In progress</span>
            <?php else: ?><a class="btn btn-sm" href="<?= h(url('opportunities', ['action' => 'new', 'account_id' => $r['account_id'], 'opp_type' => 'renewal', 'stage' => 'qualified', 'title' => 'Contract renewal'] + ($revenue ? ['monthly_value' => $r['mrr']] : []))) ?>">Start renewal</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= $revenue ? 'Revenue by service type' : 'Live services by type' ?></h2></div>
    <?php if (!$mrrByType): ?><p class="muted">No active services yet.</p><?php endif; ?>
    <div class="bars">
      <?php foreach ($mrrByType as $r): ?>
        <div class="bar-row">
          <span class="bar-label"><?= h(SERVICE_TYPES[$r['service_type']] ?? $r['service_type']) ?> <small class="muted">(<?= (int)$r['n'] ?>)</small></span>
          <span class="bar"><span style="width: <?= round((float)$r[$barKey] / $maxMrr * 100, 1) ?>%"></span></span>
          <span class="bar-value"><?= $revenue ? h(money($r['mrr'])) : (int)$r['n'] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<?php if ($debtors): ?>
<section class="card">
  <div class="card-head"><h2>Customers in arrears</h2>
    <span class="muted"><?php $last = setting('xero_last_sync_at'); ?>Xero synced <?= h($last ? fmt_datetime($last) : 'never') ?>
      <form method="post" action="<?= h(url('xero', ['action' => 'sync'])) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="_return" value="<?= h(url('dashboard')) ?>"><button class="btn btn-sm">Sync now</button></form>
      <a href="<?= h(url('accounts', ['preset' => 'arrears'])) ?>">All →</a></span></div>
  <div class="table-wrap">
  <table class="table compact">
    <thead><tr><th>Customer</th><th>Status</th><th class="num">Overdue</th><th class="num">Balance</th><th>Oldest overdue</th></tr></thead>
    <tbody>
    <?php foreach ($debtors as $d): ?>
      <tr>
        <td><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $d['id']])) ?>"><?= h($d['name']) ?></a></td>
        <td><?= badge($d['status']) ?></td>
        <td class="num text-danger"><?= h(money($d['overdue'])) ?></td>
        <td class="num"><?= h(money($d['outstanding'])) ?><?= $d['credit_limit'] !== null && (float)$d['outstanding'] > (float)$d['credit_limit'] ? ' <span class="badge badge-p1">Over limit</span>' : '' ?></td>
        <td><?= h(fmt_date($d['oldest_due_date'])) ?> <small class="muted">(<?= -days_until($d['oldest_due_date']) ?>d)</small></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Open tickets</h2><a href="<?= h(url('tickets', ['preset' => 'open'])) ?>">All →</a></div>
    <?php render('_table', ['entity' => entity('tickets'), 'name' => 'tickets', 'rows' => $tickets, 'columns' => ['reference', 'subject', 'account_id', 'priority', 'sla_due_at']]); ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>My tasks</h2><a href="<?= h(url('activities', ['preset' => 'tasks'])) ?>">All →</a></div>
    <?php if (!$tasks): ?><p class="muted">No open tasks. 🎉</p><?php endif; ?>
    <ul class="feed">
      <?php foreach ($tasks as $t): $d = days_until($t['due_date']); ?>
        <li>
          <a href="<?= h(url('activities', ['action' => 'view', 'id' => $t['id']])) ?>"><?= h($t['subject']) ?></a>
          <span class="muted">· <?= h($t['account_name']) ?></span>
          <?php if ($t['due_date']): ?><span class="<?= $d < 0 ? 'text-danger' : ($d === 0 ? 'text-warning' : 'muted') ?>">· due <?= h(fmt_date($t['due_date'])) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <h3 class="subhead">Recent activity</h3>
    <ul class="feed">
      <?php foreach ($recent as $a): ?>
        <li><?= badge($a['type']) ?> <a href="<?= h(url('accounts', ['action' => 'view', 'id' => $a['account_id']])) ?>"><?= h($a['account_name']) ?></a> — <?= h($a['subject']) ?>
          <span class="muted">· <?= h($a['user_name'] ?? 'System') ?>, <?= h(fmt_datetime($a['created_at'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>
