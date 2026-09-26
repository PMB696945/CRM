<?php $maxMrr = max(1, ...array_map(fn($r) => (float)$r['mrr'], $mrrByType ?: [['mrr' => 1]])); ?>
<div class="page-head"><h1>Dashboard</h1><span class="muted"><?= h(date('l j F Y')) ?></span></div>

<div class="kpis">
  <a class="kpi" href="<?= h(url('accounts', ['status' => 'active'])) ?>">
    <span class="kpi-label">Active customers</span>
    <span class="kpi-value"><?= number_format($stats['customers']) ?></span>
    <span class="kpi-sub"><?= number_format($stats['prospects']) ?> prospects</span>
  </a>
  <a class="kpi" href="<?= h(url('services', ['status' => 'active'])) ?>">
    <span class="kpi-label">Monthly recurring revenue</span>
    <span class="kpi-value"><?= h(money($stats['mrr'])) ?></span>
    <span class="kpi-sub"><?= number_format($stats['lines']) ?> active services · ARR <?= h(money($stats['mrr'] * 12)) ?></span>
  </a>
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
      <thead><tr><th>Customer</th><th class="num">Services</th><th class="num">MRR</th><th>First ends</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($renewals as $r): ?>
        <tr>
          <td><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $r['account_id']])) ?>"><?= h($r['name']) ?></a></td>
          <td class="num"><?= (int)$r['services'] ?></td>
          <td class="num"><?= h(money($r['mrr'])) ?></td>
          <td><?= contract_end_html($r['first_end']) ?></td>
          <td class="right">
            <?php if ($r['open_renewals']): ?><span class="badge badge-qualified">In progress</span>
            <?php else: ?><a class="btn btn-sm" href="<?= h(url('opportunities', ['action' => 'new', 'account_id' => $r['account_id'], 'opp_type' => 'renewal', 'stage' => 'qualified', 'title' => 'Contract renewal', 'monthly_value' => $r['mrr']])) ?>">Start renewal</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>Revenue by service type</h2></div>
    <?php if (!$mrrByType): ?><p class="muted">No active services yet.</p><?php endif; ?>
    <div class="bars">
      <?php foreach ($mrrByType as $r): ?>
        <div class="bar-row">
          <span class="bar-label"><?= h(SERVICE_TYPES[$r['service_type']] ?? $r['service_type']) ?> <small class="muted">(<?= (int)$r['n'] ?>)</small></span>
          <span class="bar"><span style="width: <?= round((float)$r['mrr'] / $maxMrr * 100, 1) ?>%"></span></span>
          <span class="bar-value"><?= h(money($r['mrr'])) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

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
