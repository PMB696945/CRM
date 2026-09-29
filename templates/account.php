<?php
$id = (int)$account['id'];
$here = url('accounts', ['action' => 'view', 'id' => $id]);
$new = fn(string $entity, array $extra = []) => url($entity, ['action' => 'new', 'account_id' => $id, 'return' => $here] + $extra);
$primary = array_values(array_filter($contacts, fn($c) => $c['is_primary']))[0] ?? ($contacts[0] ?? null);
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url('accounts')) ?>">Customers</a> · <?= h($account['account_number']) ?></div>
    <h1><?= h($account['name']) ?> <?= badge($account['status']) ?> <?= badge($account['type']) ?><?= $account['is_dealer'] ? ' <span class="badge badge-dealer">Dealer</span>' : '' ?></h1>
    <?php if ($account['parent_id']): ?>
      <p class="dealer-line">
        <?= $account['parent_relationship'] === 'billed_via_dealer' ? 'Billed via dealer' : 'Referred by dealer' ?>
        <?= display_value($entity, 'parent_id', $account) ?>
        <?php if ($account['msa_covered']): ?> · <span class="badge badge-msa">Covered by dealer's MSA</span><?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
  <div class="actions">
    <a class="btn" href="<?= h(url('accounts', ['action' => 'edit', 'id' => $id])) ?>">Edit</a>
    <?php render('_delete', ['name' => 'accounts', 'id' => $id, 'label' => 'customer and all its records']); ?>
  </div>
</div>

<div class="kpis kpis-sm">
  <div class="kpi"><span class="kpi-label">MRR</span><span class="kpi-value"><?= h(money($mrr)) ?></span></div>
  <div class="kpi"><span class="kpi-label">Active services</span><span class="kpi-value"><?= (int)$activeCount ?></span></div>
  <div class="kpi <?= $openTickets ? 'kpi-warn' : '' ?>"><span class="kpi-label">Open tickets</span><span class="kpi-value"><?= (int)$openTickets ?></span></div>
  <div class="kpi"><span class="kpi-label">Account manager</span><span class="kpi-value kpi-text"><?= h($account['owner_id__label'] ?? '—') ?></span></div>
  <?php if (xero_connected()): ?>
    <?php if ($xero):
        $overLimit = $account['credit_limit'] !== null && (float)$xero['outstanding'] > (float)$account['credit_limit'];
        $inCredit = (float)$xero['outstanding'] < 0; ?>
      <a class="kpi <?= (float)$xero['overdue'] > 0 ? 'kpi-alert' : '' ?>" href="<?= h(xero_contact_url($xero['contact_id'])) ?>" target="_blank" rel="noopener" title="Open in Xero">
        <span class="kpi-label">Xero balance ↗</span>
        <span class="kpi-value"><?= $inCredit ? h(money(-$xero['outstanding'])) . ' <small>credit</small>' : h(money($xero['outstanding'])) ?></span>
        <span class="kpi-sub">
          <?php if ((float)$xero['overdue'] > 0): ?><span class="text-danger"><?= h(money($xero['overdue'])) ?> overdue</span> · since <?= h(fmt_date($xero['oldest_due_date'])) ?><?php else: ?>Nothing overdue<?php endif; ?>
          · <?= (int)$xero['open_invoices'] ?> unpaid invoice<?= (int)$xero['open_invoices'] === 1 ? '' : 's' ?>
          <?php if ($overLimit): ?><br><span class="text-danger">Over credit limit (<?= h(money($account['credit_limit'])) ?>)</span><?php endif; ?>
          <br><small>Synced <?= h(fmt_datetime($xero['synced_at'])) ?></small>
        </span>
      </a>
    <?php else: ?>
      <a class="kpi" href="<?= h(url('accounts', ['action' => 'edit', 'id' => $id])) ?>">
        <span class="kpi-label">Xero balance</span>
        <span class="kpi-value kpi-text muted">Not linked</span>
        <span class="kpi-sub">Edit the customer to choose their Xero contact</span>
      </a>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="grid-side">
  <div>
    <?php if ($account['is_dealer']):
        $groupMrr = array_sum(array_map(fn($c) => (float)$c['_mrr'], $children));
        $commission = $account['dealer_commission_pct'] !== null ? $groupMrr * (float)$account['dealer_commission_pct'] / 100 : null; ?>
    <section class="card">
      <div class="card-head"><h2>Dealer's customers <span class="count"><?= count($children) ?></span></h2>
        <a class="btn btn-sm" href="<?= h(url('accounts', ['action' => 'new', 'parent_id' => $id, 'parent_relationship' => 'referral', 'return' => $here])) ?>">+ Add customer under this dealer</a></div>
      <p class="muted">Customers' MRR <b><?= h(money($groupMrr)) ?></b> · with this dealer's own services <b><?= h(money($groupMrr + $mrr)) ?></b>
        <?php if ($commission !== null): ?> · commission at <?= h(rtrim(rtrim(number_format((float)$account['dealer_commission_pct'], 2), '0'), '.')) ?>%: <b><?= h(money($commission)) ?>/mo</b><?php endif; ?></p>
      <?php if ($children): ?>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Customer</th><th>Status</th><th>Relationship</th><th>MSA</th><th class="num">MRR</th></tr></thead>
          <tbody>
          <?php foreach ($children as $c): ?>
            <tr>
              <td><a class="row-link" href="<?= h(url('accounts', ['action' => 'view', 'id' => $c['id']])) ?>"><?= h($c['name']) ?></a> <span class="muted"><?= h($c['account_number']) ?></span></td>
              <td><?= badge($c['status']) ?></td>
              <td><?= $c['parent_relationship'] === 'billed_via_dealer' ? 'Billed via dealer' : 'Referral' ?></td>
              <td><?= $c['msa_covered'] ? '✔' : '<span class="muted">—</span>' ?></td>
              <td class="num"><?= h(money($c['_mrr'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-head"><h2>Services &amp; lines</h2><a class="btn btn-sm" href="<?= h($new('services', ['status' => 'active'])) ?>">+ Add service</a></div>
      <?php render('_table', ['entity' => entity('services'), 'name' => 'services', 'rows' => $services, 'columns' => ['identifier', 'service_type', 'carrier', 'status', 'monthly_price', 'contract_end_date']]); ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Quotes</h2><a class="btn btn-sm" href="<?= h(url('quotes', ['action' => 'new', 'account_id' => $id])) ?>">+ New quote</a></div>
      <?php render('_table', ['entity' => entity('quotes'), 'name' => 'quotes', 'rows' => $quotes, 'columns' => ['reference', 'title', 'status', '_monthly', 'valid_until', 'sent_at']]); ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Contracts</h2><a class="btn btn-sm" href="<?= h(url('contracts', ['action' => 'new', 'account_id' => $id])) ?>">+ New contract<?= $account['is_dealer'] ? ' / MSA' : '' ?></a></div>
      <?php render('_table', ['entity' => entity('contracts'), 'name' => 'contracts', 'rows' => $contracts, 'columns' => ['reference', 'title', 'kind', 'status', 'sent_at', 'signed_at']]); ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Support tickets</h2><a class="btn btn-sm" href="<?= h($new('tickets')) ?>">+ Raise ticket</a></div>
      <?php render('_table', ['entity' => entity('tickets'), 'name' => 'tickets', 'rows' => $tickets, 'columns' => ['reference', 'subject', 'category', 'priority', 'status', 'sla_due_at']]); ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Opportunities</h2><a class="btn btn-sm" href="<?= h($new('opportunities')) ?>">+ Add opportunity</a></div>
      <?php render('_table', ['entity' => entity('opportunities'), 'name' => 'opportunities', 'rows' => $opps, 'columns' => ['title', 'opp_type', 'stage', 'monthly_value', '_tcv', 'expected_close']]); ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Activity</h2></div>
      <form method="post" action="<?= h(url('activities', ['action' => 'new'])) ?>" class="quick-log">
        <?= csrf_field() ?>
        <input type="hidden" name="account_id" value="<?= $id ?>">
        <input type="hidden" name="_return" value="<?= h($here) ?>">
        <select name="type" aria-label="Type"><option value="call">Call</option><option value="email">Email</option><option value="meeting">Meeting</option><option value="note" selected>Note</option><option value="task">Task</option></select>
        <input name="subject" placeholder="Log a call, note or task…" required>
        <input type="date" name="due_date" aria-label="Due date" title="Due date (tasks)">
        <button class="btn btn-primary">Log</button>
      </form>
      <ul class="feed">
        <?php foreach ($activities as $a): ?>
          <li>
            <?= badge($a['type']) ?> <a href="<?= h(url('activities', ['action' => 'view', 'id' => $a['id']])) ?>"><?= h($a['subject']) ?></a>
            <?php if ($a['type'] === 'task'): ?><?= $a['done'] ? '<span class="text-ok">✔ done</span>' : '<span class="text-warning">open' . ($a['due_date'] ? ', due ' . h(fmt_date($a['due_date'])) : '') . '</span>' ?><?php endif; ?>
            <span class="muted">· <?= h($a['user_id__label'] ?? 'System') ?>, <?= h(fmt_datetime($a['created_at'])) ?></span>
            <?php if ($a['body']): ?><div class="feed-body"><?= nl2br(h($a['body'])) ?></div><?php endif; ?>
          </li>
        <?php endforeach; ?>
        <?php if (!$activities): ?><li class="muted">No activity logged yet.</li><?php endif; ?>
      </ul>
    </section>
  </div>

  <aside>
    <?php if ($dd !== null) render('_direct_debit', ['account' => $account, 'dd' => $dd]); ?>
    <section class="card">
      <div class="card-head"><h2>Details</h2></div>
      <dl class="details details-stack">
        <?php foreach (['account_number', 'industry', 'company_number', 'email', 'phone', 'address', 'city', 'postcode', 'credit_limit', 'created_at'] as $f):
            if ($f === 'created_at') { $v = h(fmt_date($account['created_at'])); $label = 'Customer since'; }
            else { $v = display_value($entity, $f, $account); $label = $entity['fields'][$f]['label']; }
            if ($v === '' || $v === '<span class="muted">—</span>') continue; ?>
          <dt><?= h($label) ?></dt><dd><?= $v ?></dd>
        <?php endforeach; ?>
      </dl>
      <?php if ($account['notes']): ?><div class="notes"><?= nl2br(h($account['notes'])) ?></div><?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Contacts</h2><a class="btn btn-sm" href="<?= h($new('contacts')) ?>">+ Add</a></div>
      <ul class="contact-list">
        <?php foreach ($contacts as $c): ?>
          <li>
            <a href="<?= h(url('contacts', ['action' => 'view', 'id' => $c['id']])) ?>"><strong><?= h($c['name']) ?></strong></a>
            <?php if ($c['is_primary']): ?><span class="badge badge-active">Primary</span><?php endif; ?>
            <?php if ($c['is_billing']): ?><span class="badge badge-billing">Billing</span><?php endif; ?>
            <?php if ($c['job_title']): ?><div class="muted"><?= h($c['job_title']) ?></div><?php endif; ?>
            <div><?= display_value(entity('contacts'), 'email', $c) ?></div>
            <div><?= display_value(entity('contacts'), 'phone', $c) ?> <?= display_value(entity('contacts'), 'mobile', $c) ?></div>
          </li>
        <?php endforeach; ?>
        <?php if (!$contacts): ?><li class="muted">No contacts yet.</li><?php endif; ?>
      </ul>
    </section>
  </aside>
</div>
