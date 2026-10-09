<?php
$id = (int)$account['id'];
$here = url('accounts', ['action' => 'view', 'id' => $id]);
$new = fn(string $entity, array $extra = []) => url($entity, ['action' => 'new', 'account_id' => $id, 'return' => $here] + $extra);
$contactEntity = entity('contacts');
$fmtAddress = fn(array $r) => implode(', ', array_filter([$r['address'] ?? null, $r['address2'] ?? null, $r['city'] ?? null, $r['county'] ?? null, $r['postcode'] ?? null]));
$prefs = function (array $c): string {
    $on = array_filter(['Email' => $c['marketing_email'], 'Phone' => $c['marketing_phone'], 'Text' => $c['marketing_sms'], 'Post' => $c['marketing_post']]);
    return $on ? implode(', ', array_keys($on)) : 'No marketing';
};
$canEdit = can('customers.edit');
$tabUrl = fn(string $t) => url('accounts', ['action' => 'view', 'id' => $id] + ($t === 'overview' ? [] : ['tab' => $t]));
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url('accounts')) ?>">Customers</a> · <?= h($account['account_number']) ?></div>
    <h1><?= h($account['name']) ?> <?= badge($account['status']) ?> <?= badge($account['type']) ?><?= $account['is_dealer'] ? ' <span class="badge badge-dealer">Dealer</span>' : '' ?><?= $supplier ? ' <span class="badge">Supplier</span>' : '' ?></h1>
    <?php if ($account['parent_id']): ?>
      <p class="dealer-line">
        <?= $account['parent_relationship'] === 'billed_via_dealer' ? 'Billed via dealer' : 'Referred by dealer' ?>
        <?= display_value($entity, 'parent_id', $account) ?>
        <?php if ($account['msa_covered']): ?> · <span class="badge badge-msa">Covered by dealer's MSA</span><?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
  <div class="actions">
    <?php if (can('marketing.send') && $activeCount): ?><a class="btn" href="<?= h(url('campaigns', ['action' => 'new', 'kind' => 'service_alert', 'account_id' => $id])) ?>">Send service alert</a><?php endif; ?>
    <?php
    // Things to create for this customer, whichever tab is open.
    $newItems = array_filter([
        'New contract' . ($account['is_dealer'] ? ' / MSA' : '') => can('sales.edit') ? url('contracts', ['action' => 'new', 'account_id' => $id]) : null,
        'Opportunity' => can('sales.edit') ? $new('opportunities') : null,
        'Support ticket' => can('tickets.edit') ? $new('tickets') : null,
        'Order a service' => can('sales.edit') ? url('new_order', ['account_id' => $id]) : null,
        'Check broadband' => !can('sales.edit') && can('orders.check') && giacom_configured() ? url('giacom', ['action' => 'check', 'account_id' => $id]) : null,
        'Record an existing service' => can('services.edit') ? $new('services', ['status' => 'active']) : null,
        'Contact' => $canEdit ? $new('contacts') : null,
        'Site' => $canEdit ? $new('sites') : null,
        'Customer under this dealer' => $canEdit && $account['is_dealer'] ? url('accounts', ['action' => 'new', 'parent_id' => $id, 'parent_relationship' => 'referral', 'return' => $here]) : null,
    ]);
    ?>
    <?php if (can('sales.edit')): ?><a class="btn btn-primary" href="<?= h(url('quotes', ['action' => 'new', 'account_id' => $id])) ?>">+ New quote</a><?php endif; ?>
    <?php if ($newItems): ?>
      <details class="dropdown">
        <summary class="btn">New…</summary>
        <div class="dropdown-panel card new-menu">
          <?php foreach ($newItems as $label => $href): ?><a href="<?= h($href) ?>"><?= h($label) ?></a><?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
    <?php if ($canEdit): ?><a class="btn" href="<?= h(url('accounts', ['action' => 'edit', 'id' => $id])) ?>">Edit</a><?php endif; ?>
    <?php if ($canEdit && !$pendingRequest): ?>
      <details class="dropdown">
        <summary class="btn btn-danger-ghost">Close or delete…</summary>
        <div class="dropdown-panel card">
          <?php foreach (APPROVAL_TYPES as $type => $typeLabel):
              if ($type === 'close_account' && $account['status'] === 'churned') continue;
              $direct = can(approval_permission($type)); ?>
            <form method="post" action="<?= h(url('approvals', ['action' => $direct ? 'now' : 'request'])) ?>" class="stack"
                  <?= $direct ? 'data-confirm="' . h($type === 'delete_account' ? 'Permanently delete ' . $account['name'] . ' and everything under it? This can\'t be undone.' : 'Close ' . $account['name'] . ' now?') . '"' : '' ?>>
              <?= csrf_field() ?>
              <input type="hidden" name="account_id" value="<?= $id ?>"><input type="hidden" name="type" value="<?= h($type) ?>">
              <h3><?= h($typeLabel) ?></h3>
              <p class="help"><?= $type === 'delete_account'
                  ? 'Removes the customer and all their contacts, sites, services, tickets and quotes (the audit trail is kept). Usually closing is better.'
                  : 'Marks the customer as closed. Their records are kept.' ?>
                <?= $direct ? '' : '<br><b>An approver will review your request before anything changes.</b>' ?></p>
              <label>Reason<textarea name="reason" rows="2" required></textarea></label>
              <?php if ($type === 'close_account' && $activeCount): ?><label class="check"><input type="checkbox" name="cease_services" value="1"> Also mark the <?= (int)$activeCount ?> live service<?= $activeCount === 1 ? '' : 's' ?> as ceased</label><?php endif; ?>
              <button class="btn <?= $type === 'delete_account' ? 'btn-danger' : '' ?>"><?= $direct ? h($typeLabel) : 'Request ' . h(strtolower($typeLabel === 'Close customer' ? 'closure' : 'deletion')) ?></button>
            </form>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
  </div>
</div>

<?php if ($pendingRequest): ?>
  <div class="flash flash-warning" role="status">
    <b><?= h(APPROVAL_TYPES[$pendingRequest['type']] ?? 'Change') ?></b> requested by <?= h($pendingRequest['requested_by_name'] ?? 'someone') ?> on <?= h(fmt_datetime($pendingRequest['created_at'])) ?>: “<?= h($pendingRequest['reason']) ?>”. Waiting for approval.
    <a href="<?= h(url('approvals', ['action' => 'view', 'id' => $pendingRequest['id']])) ?>">View request →</a>
  </div>
<?php endif; ?>
<?php if ($account['status'] === 'churned' && $account['closed_at']): ?>
  <div class="flash flash-info" role="status">Closed on <?= h(fmt_date($account['closed_at'])) ?><?= $account['closed_reason'] ? ': ' . h($account['closed_reason']) : '' ?></div>
<?php endif; ?>

<nav class="tabs">
  <?php foreach ($tabs as $k => $label): ?><a href="<?= h($tabUrl($k)) ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= h($label) ?></a><?php endforeach; ?>
</nav>

<?php if ($tab === 'overview' || $tab === 'customer'): ?>
<div class="kpis kpis-sm">
  <?php if (can('revenue.view')): ?><div class="kpi"><span class="kpi-label">MRR</span><span class="kpi-value"><?= h(money($mrr)) ?></span></div><?php endif; ?>
  <div class="kpi"><span class="kpi-label">Active services</span><span class="kpi-value"><?= (int)$activeCount ?></span></div>
  <div class="kpi <?= $openTickets ? 'kpi-warn' : '' ?>"><span class="kpi-label">Open tickets</span><span class="kpi-value"><?= (int)$openTickets ?></span></div>
  <div class="kpi"><span class="kpi-label">Account manager</span><span class="kpi-value kpi-text"><?= h($account['owner_id__label'] ?? '—') ?></span></div>
  <?php if (xero_connected() && can('finance.view')): ?>
    <?php if ($xero):
        $overLimit = $account['credit_limit'] !== null && (float)$xero['outstanding'] > (float)$account['credit_limit'];
        $inCredit = (float)$xero['outstanding'] < 0; ?>
      <?php $openXero = can('xero.open'); ?>
      <<?= $openXero ? 'a' : 'div' ?> class="kpi <?= (float)$xero['overdue'] > 0 ? 'kpi-alert' : '' ?>"<?= $openXero ? ' href="' . h(xero_contact_url($xero['contact_id'])) . '" target="_blank" rel="noopener" title="Open in Xero"' : '' ?>>
        <span class="kpi-label">Xero balance<?= $openXero ? ' ↗' : '' ?></span>
        <span class="kpi-value"><?= $inCredit ? h(money(-$xero['outstanding'])) . ' <small>credit</small>' : h(money($xero['outstanding'])) ?></span>
        <span class="kpi-sub">
          <?php if ((float)$xero['overdue'] > 0): ?><span class="text-danger"><?= h(money($xero['overdue'])) ?> overdue</span> · since <?= h(fmt_date($xero['oldest_due_date'])) ?><?php else: ?>Nothing overdue<?php endif; ?>
          · <?= (int)$xero['open_invoices'] ?> unpaid invoice<?= (int)$xero['open_invoices'] === 1 ? '' : 's' ?>
          <?php if ($overLimit): ?><br><span class="text-danger">Over credit limit (<?= h(money($account['credit_limit'])) ?>)</span><?php endif; ?>
          <br><small>Synced <?= h(fmt_datetime($xero['synced_at'])) ?></small>
        </span>
      </<?= $openXero ? 'a' : 'div' ?>>
    <?php else: ?>
      <a class="kpi" href="<?= h(url('accounts', ['action' => 'edit', 'id' => $id])) ?>">
        <span class="kpi-label">Xero balance</span>
        <span class="kpi-value kpi-text muted">Not linked</span>
        <span class="kpi-sub">Edit the customer to choose their Xero contact</span>
      </a>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
<div class="grid-side">
  <div>
    <section class="card">
      <div class="card-head"><h2>Contact type</h2></div>
      <?php $canSupplier = can('suppliers.edit'); ?>
      <form method="post" action="<?= h(url('account_types', ['id' => $id])) ?>">
        <?= csrf_field() ?>
        <ul class="contact-list">
          <li><label class="check"><input type="checkbox" name="is_customer" value="1" <?= $account['is_customer'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
              <strong>Customer</strong></label> <?= $account['is_customer'] ? badge($account['status']) : '' ?>
            <div class="small muted"><?php if ($account['is_customer']): ?><a href="<?= h($tabUrl('customer')) ?>"><?= (int)$activeCount ?> live service<?= $activeCount === 1 ? '' : 's' ?></a><?= can('revenue.view') ? ' · ' . h(money($mrr)) . '/mo' : '' ?> · <?= (int)$openTickets ?> open ticket<?= $openTickets === 1 ? '' : 's' ?><?php else: ?>Not a customer<?php endif; ?></div></li>
          <li><label class="check"><input type="checkbox" name="is_supplier" value="1" <?= $supplier ? 'checked' : '' ?> <?= $canEdit && $canSupplier ? '' : 'disabled' ?>>
              <strong>Supplier</strong></label> <?= $supplier && !$supplier['active'] ? badge('disabled') : '' ?>
            <div class="small muted"><?php if ($supplier): ?><?= isset($tabs['supplier']) ? '<a href="' . h($tabUrl('supplier')) . '">' . h(SUPPLIER_CATEGORIES[$supplier['category']] ?? 'Supplier') . '</a>' : h(SUPPLIER_CATEGORIES[$supplier['category']] ?? 'Supplier') ?><?= $supplier['account_number'] ? ' · our account ' . h($supplier['account_number']) : '' ?>
              <?php else: ?>Ticking this adds a Supplier tab for their products, purchase orders and invoices<?php endif; ?>
              <?php if ($canEdit && !$canSupplier): ?><br>Only users allowed to add and edit suppliers can change this (Admin → Roles &amp; permissions).<?php endif; ?></div></li>
          <li><label class="check"><input type="checkbox" name="is_dealer" value="1" <?= $account['is_dealer'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
              <strong>Dealer</strong></label>
            <div class="small muted"><?php if ($account['is_dealer']): ?><a href="<?= h($tabUrl('dealer')) ?>"><?= count($children) ?> customer<?= count($children) === 1 ? '' : 's' ?> under them</a><?= $account['dealer_commission_pct'] !== null ? ' · ' . h(rtrim(rtrim(number_format((float)$account['dealer_commission_pct'], 2), '0'), '.')) . '% commission' : '' ?>
              <?php else: ?>Ticking this lets other customers sit under them, and adds a Dealer tab<?php endif; ?></div></li>
        </ul>
        <?php if ($canEdit): ?><div style="margin-top:.75rem"><button class="btn btn-sm">Save contact type</button></div><?php endif; ?>
      </form>
    </section>

    <section class="card" data-collapsible="account-activity">
      <div class="card-head"><h2>Activity <span class="count"><?= count($activities) ?></span></h2></div>
      <?php if ($canEdit): ?>
      <form method="post" action="<?= h(url('activities', ['action' => 'new'])) ?>" class="quick-log">
        <?= csrf_field() ?>
        <input type="hidden" name="account_id" value="<?= $id ?>">
        <input type="hidden" name="_return" value="<?= h($here) ?>">
        <select name="type" aria-label="Type"><option value="call">Call</option><option value="email">Email</option><option value="meeting">Meeting</option><option value="note" selected>Note</option><option value="task">Task</option></select>
        <input name="subject" placeholder="Log a call, note or task…" required>
        <input type="date" name="due_date" aria-label="Due date" title="Due date (tasks)">
        <button class="btn btn-primary">Log</button>
      </form>
      <?php endif; ?>
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

    <?php if (can('audit.view')): ?>
    <section class="card" data-collapsible="account-history">
      <div class="card-head"><h2>History</h2><a href="<?= h(url('audit', ['account_id' => $id])) ?>">Full audit trail →</a></div>
      <?php if ($history): ?>
        <ul class="feed">
          <?php foreach ($history as $e): $changes = audit_changes($e['changes']); ?>
            <li>
              <b><?= h($e['user_name'] ?? 'System') ?></b> <?= h($e['summary']) ?>
              <span class="muted">· <?= h(fmt_datetime($e['created_at'])) ?></span>
              <?php if ($changes): ?>
                <details class="changes"><summary><?= count($changes) ?> change<?= count($changes) === 1 ? '' : 's' ?></summary>
                  <?php render('_changes', ['changes' => $changes]); ?>
                </details>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?><p class="muted">Nothing recorded yet.</p><?php endif; ?>
    </section>
    <?php endif; ?>
  </div>

  <aside>
    <section class="card">
      <div class="card-head"><h2>Head office</h2><?php if ($canEdit): ?><a class="btn btn-sm" href="<?= h(url('accounts', ['action' => 'edit', 'id' => $id])) ?>">Edit</a><?php endif; ?></div>
      <?php if ($fmtAddress($account)): ?><p class="address"><?= nl2br(h(implode("\n", array_filter([$account['address'], $account['address2'], $account['city'], $account['county'], $account['postcode']])))) ?></p><?php else: ?><p class="muted">No address yet.</p><?php endif; ?>
      <dl class="details details-stack">
        <?php foreach (['phone', 'email', 'account_number', 'industry', 'company_number', 'credit_limit', 'created_at'] as $f):
            if ($f === 'created_at') { $v = h(fmt_date($account['created_at'])); $label = 'Customer since'; }
            elseif (!isset($entity['fields'][$f]) || !field_enabled($entity['fields'][$f])) { continue; }
            else { $v = display_value($entity, $f, $account); $label = $entity['fields'][$f]['label']; }
            if ($v === '' || $v === '<span class="muted">—</span>') continue; ?>
          <dt><?= h($label) ?></dt><dd><?= $v ?></dd>
        <?php endforeach; ?>
      </dl>
      <?php if ($account['notes']): ?><div class="notes"><?= nl2br(h($account['notes'])) ?></div><?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Key contacts</h2></div>
      <?php foreach (['Main contact' => $mainContact, 'Accounts contact' => $billingContact] as $role => $c): ?>
        <div class="key-contact">
          <div class="muted small"><?= h($role) ?><?= $role === 'Accounts contact' ? ' · invoices & statements' : '' ?></div>
          <?php if ($c): ?>
            <a href="<?= h(url('contacts', ['action' => 'view', 'id' => $c['id']])) ?>"><strong><?= h($c['name']) ?></strong></a>
            <?php if ($role === 'Accounts contact' && $mainContact && (int)$c['id'] === (int)$mainContact['id']): ?><span class="muted">(same as main)</span><?php endif; ?>
            <div><?= display_value($contactEntity, 'email', $c) ?></div>
            <div><?= display_value($contactEntity, 'phone', $c) ?> <?= display_value($contactEntity, 'mobile', $c) ?></div>
          <?php elseif ($role === 'Accounts contact' && $mainContact): ?>
            <span class="muted">Same as main contact</span>
          <?php else: ?>
            <span class="text-warning">Not set</span><?php if ($canEdit): ?> · <a href="<?= h(url('accounts', ['action' => 'edit', 'id' => $id])) ?>">add</a><?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (xero_connected() && $account['xero_contact_id'] && ($billingContact || $mainContact) && $canEdit && xero_can_write_contacts()): ?>
        <form method="post" action="<?= h(url('xero', ['action' => 'push', 'id' => $id])) ?>" class="inline" data-confirm="Set this customer's invoice email in Xero to <?= h(($billingContact ?: $mainContact)['email'] ?? '') ?>?">
          <?= csrf_field() ?><button class="btn btn-sm">Send accounts contact to Xero</button>
        </form>
        <?php if (setting('xero_push_contacts') === '1'): ?><p class="help">Xero is updated automatically when the accounts contact changes.</p><?php endif; ?>
      <?php endif; ?>
    </section>
    <?php render('_billing_link', ['account' => $account, 'canEdit' => $canEdit]); ?>

    <section class="card">
      <div class="card-head"><h2>Address book</h2><?php if ($canEdit): ?><a class="btn btn-sm" href="<?= h($new('sites')) ?>">+ Add site</a><?php endif; ?></div>
      <ul class="contact-list">
        <li>
          <strong>Head office</strong> <span class="badge badge-active">Main</span>
          <div class="muted"><?= h($fmtAddress($account) ?: 'No address yet') ?></div>
          <div class="small">Contact: <?= h($mainContact['name'] ?? '—') ?></div>
        </li>
        <?php foreach ($sites as $site): ?>
          <li>
            <a href="<?= h(url('sites', ['action' => 'view', 'id' => $site['id']])) ?>"><strong><?= h($site['name']) ?></strong></a>
            <?php if ($site['_services']): ?><span class="muted small"><?= (int)$site['_services'] ?> live service<?= (int)$site['_services'] === 1 ? '' : 's' ?></span><?php endif; ?>
            <div class="muted"><?= h($fmtAddress($site) ?: '—') ?></div>
            <div class="small">Contact: <?= $site['contact_id'] ? h($site['contact_id__label']) : h(($mainContact['name'] ?? '—')) . ' <span class="muted">(head office)</span>' ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <section class="card">
      <div class="card-head"><h2>Contacts</h2><?php if ($canEdit): ?><a class="btn btn-sm" href="<?= h($new('contacts')) ?>">+ Add</a><?php endif; ?></div>
      <ul class="contact-list">
        <?php foreach ($contacts as $c): ?>
          <li>
            <a href="<?= h(url('contacts', ['action' => 'view', 'id' => $c['id']])) ?>"><strong><?= h($c['name']) ?></strong></a>
            <?php if ($c['is_primary']): ?><span class="badge badge-active">Main</span><?php endif; ?>
            <?php if ($c['is_billing']): ?><span class="badge badge-billing">Accounts</span><?php endif; ?>
            <?php if ($c['job_title']): ?><div class="muted"><?= h($c['job_title']) ?></div><?php endif; ?>
            <div><?= display_value($contactEntity, 'email', $c) ?></div>
            <div><?= display_value($contactEntity, 'phone', $c) ?> <?= display_value($contactEntity, 'mobile', $c) ?></div>
          </li>
        <?php endforeach; ?>
        <?php if (!$contacts): ?><li class="muted">No contacts yet.</li><?php endif; ?>
      </ul>
    </section>

    <?php render('_files', ['docs' => $files, 'where' => ['account_id' => $id]]); ?>

    <section class="card">
      <div class="card-head"><h2>Marketing &amp; alerts</h2></div>
      <?php if ($contacts): ?>
        <ul class="contact-list">
          <?php foreach ($contacts as $c): ?>
            <li>
              <strong><?= h($c['name']) ?></strong>
              <?php if ($canEdit): ?><a class="small" href="<?= h(url('contacts', ['action' => 'edit', 'id' => $c['id'], 'return' => $here])) ?>">change</a><?php endif; ?>
              <div class="small"><?= h($prefs($c)) ?><?= $c['marketing_topics'] ? ' · ' . h(checkbox_labels($contactEntity['fields']['marketing_topics'], $c['marketing_topics'])) : '' ?></div>
              <div class="small <?= $c['service_alerts'] ? '' : 'text-warning' ?>"><?= $c['service_alerts'] ? 'Gets service alerts' : 'Opted out of service alerts' ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?><p class="muted">Add a contact to record their preferences.</p><?php endif; ?>
    </section>
  </aside>
</div>

<?php elseif ($tab === 'customer'): ?>
<div class="grid-side">
  <div>
    <section class="card">
      <div class="card-head"><h2>Services &amp; lines</h2><?php if (can('services.edit')): ?><a class="btn btn-sm" href="<?= h($new('services', ['status' => 'active'])) ?>">+ Add service</a><?php endif; ?></div>
      <?php render('_table', ['entity' => entity('services'), 'name' => 'services', 'rows' => $services, 'columns' => array_values(array_filter(['identifier', 'service_type', $sites ? 'site_id' : null, 'carrier', 'status', 'monthly_price', can('costs.view') ? 'cost_price' : null, can('costs.view') ? '_margin' : null, 'contract_end_date']))]); ?>
      <?php if (can('costs.view')):
          $live = array_filter($services, fn($s) => $s['status'] === 'active');
          $costed = array_filter($live, fn($s) => $s['cost_price'] !== null);
          $rev = array_sum(array_map(fn($s) => (float)$s['monthly_price'], $costed));
          $cost = array_sum(array_map(fn($s) => (float)$s['cost_price'], $costed));
          $unknown = count($live) - count($costed); ?>
        <?php if ($costed): ?><p class="small muted" style="margin-top:.5rem">Live services with a recorded cost: <?= h(money($rev)) ?>/mo, cost <?= h(money($cost)) ?>/mo, margin <b><?= h(money($rev - $cost)) ?>/mo</b><?= $rev > 0 ? ' (' . number_format(($rev - $cost) / $rev * 100, 1) . '%)' : '' ?><?= $unknown ? ' · ' . $unknown . ' more without a recorded cost' : '' ?>.</p><?php endif; ?>
      <?php endif; ?>
      <?php $withLogin = array_filter($services, 'service_has_login'); if ($withLogin): ?>
        <h3 class="small muted" style="margin-top:1rem">Logins &amp; IP addresses</h3>
        <div class="table-wrap"><table class="table compact">
          <thead><tr><th>Service</th><th>Username</th><th>Password</th><th>IP address(es)</th></tr></thead>
          <tbody>
          <?php foreach ($withLogin as $s): $l = service_login($s); ?>
            <tr>
              <td><a href="<?= h(url('services', ['action' => 'view', 'id' => $s['id']])) ?>#login"><?= h($s['identifier']) ?></a></td>
              <td><?= $l['username'] !== '' ? '<code>' . h($l['username']) . '</code>' : '<span class="muted">—</span>' ?></td>
              <td><?= $l['password'] !== '' ? '<details><summary class="small">Show</summary><code>' . h($l['password']) . '</code></details>' : '<span class="muted">—</span>' ?></td>
              <td><?= $l['ip'] !== '' ? h($l['ip']) : '<span class="muted">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </section>

    <?php if (can('orders.check') && (giacom_configured() || $giacomOrders)): ?>
    <section class="card">
      <div class="card-head"><h2>Broadband orders <span class="muted small">Giacom</span></h2>
        <?php if (giacom_configured()): ?><a class="btn btn-sm" href="<?= h(url('giacom', ['action' => 'check', 'account_id' => $id])) ?>">Check broadband</a><?php endif; ?></div>
      <?php if ($giacomOrders): ?>
        <div class="table-wrap"><table class="table table-compact">
          <thead><tr><th>Order</th><th>Product</th><th>Address</th><th>Required by</th><th>Status</th></tr></thead>
          <tbody><?php foreach ($giacomOrders as $o): ?>
            <tr><td><a href="<?= h(url('giacom', ['action' => 'view', 'id' => $o['id']])) ?>"><?= h($o['giacom_order_id']) ?></a></td><td><?= h($o['product_name']) ?></td>
              <td class="small"><?= h($o['address_label']) ?></td><td><?= h(fmt_date($o['crd'])) ?></td>
              <td><span class="badge <?= $o['completed_at'] ? 'badge-active' : (giacom_is_cancelled((string)$o['status']) ? 'badge-failed' : 'badge-pending') ?>"><?= h($o['status'] ?: 'Placed') ?></span></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
      <?php endif; ?>
      <?php if ($giacomChecks): ?>
        <p class="small muted" style="margin-top:.75rem">Recent checks:
          <?php foreach ($giacomChecks as $i => $c): ?><?= $i ? ' · ' : '' ?><a href="<?= h(url('giacom', ['action' => 'result', 'id' => $c['id']])) ?>"><?= h(mb_strimwidth((string)$c['address_label'], 0, 40, '…')) ?></a> (<?= h(fmt_date($c['created_at'])) ?>)<?php endforeach; ?></p>
      <?php elseif (!$giacomOrders): ?><p class="muted">Check what broadband is available at the head office or any site, and order it from here.</p><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($orders): ?>
    <section class="card">
      <div class="card-head"><h2>Orders</h2></div>
      <?php render('_table', ['entity' => entity('customer_orders'), 'name' => 'customer_orders', 'rows' => $orders, 'columns' => ['reference', 'title', 'status', 'assigned_to', 'monthly_total', 'created_at']]); ?>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-head"><h2>Quotes</h2><?php if (can('sales.edit')): ?><a class="btn btn-sm" href="<?= h(url('quotes', ['action' => 'new', 'account_id' => $id])) ?>">+ New quote</a><?php endif; ?></div>
      <?php render('_table', ['entity' => entity('quotes'), 'name' => 'quotes', 'rows' => $quotes, 'columns' => ['reference', 'title', 'status', '_monthly', 'valid_until', 'sent_at']]); ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Contracts</h2><?php if (can('sales.edit')): ?><a class="btn btn-sm" href="<?= h(url('contracts', ['action' => 'new', 'account_id' => $id])) ?>">+ New contract<?= $account['is_dealer'] ? ' / MSA' : '' ?></a><?php endif; ?></div>
      <?php render('_table', ['entity' => entity('contracts'), 'name' => 'contracts', 'rows' => $contracts, 'columns' => ['reference', 'title', 'kind', 'status', 'sent_at', 'signed_at']]); ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Support tickets</h2><?php if (can('tickets.edit')): ?><a class="btn btn-sm" href="<?= h($new('tickets')) ?>">+ Raise ticket</a><?php endif; ?></div>
      <?php render('_table', ['entity' => entity('tickets'), 'name' => 'tickets', 'rows' => $tickets, 'columns' => ['reference', 'subject', 'category', 'priority', 'status', 'sla_due_at']]); ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Opportunities</h2><?php if (can('sales.edit')): ?><a class="btn btn-sm" href="<?= h($new('opportunities')) ?>">+ Add opportunity</a><?php endif; ?></div>
      <?php render('_table', ['entity' => entity('opportunities'), 'name' => 'opportunities', 'rows' => $opps, 'columns' => ['title', 'opp_type', 'stage', 'monthly_value', '_tcv', 'expected_close']]); ?>
    </section>
  </div>
  <aside>
    <?php if ($dd !== null) render('_direct_debit', ['account' => $account, 'dd' => $dd]); ?>
    <section class="card">
      <div class="card-head"><h2>Key contacts</h2></div>
      <?php foreach (['Main contact' => $mainContact, 'Accounts contact' => $billingContact] as $role => $c): ?>
        <div class="key-contact">
          <div class="muted small"><?= h($role) ?><?= $role === 'Accounts contact' ? ' · invoices & statements' : '' ?></div>
          <?php if ($c): ?>
            <a href="<?= h(url('contacts', ['action' => 'view', 'id' => $c['id']])) ?>"><strong><?= h($c['name']) ?></strong></a>
            <?php if ($role === 'Accounts contact' && $mainContact && (int)$c['id'] === (int)$mainContact['id']): ?><span class="muted">(same as main)</span><?php endif; ?>
            <div><?= display_value($contactEntity, 'email', $c) ?></div>
            <div><?= display_value($contactEntity, 'phone', $c) ?> <?= display_value($contactEntity, 'mobile', $c) ?></div>
          <?php elseif ($role === 'Accounts contact' && $mainContact): ?>
            <span class="muted">Same as main contact</span>
          <?php else: ?>
            <span class="text-warning">Not set</span><?php if ($canEdit): ?> · <a href="<?= h(url('accounts', ['action' => 'edit', 'id' => $id])) ?>">add</a><?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (xero_connected() && $account['xero_contact_id'] && ($billingContact || $mainContact) && $canEdit && xero_can_write_contacts()): ?>
        <form method="post" action="<?= h(url('xero', ['action' => 'push', 'id' => $id])) ?>" class="inline" data-confirm="Set this customer's invoice email in Xero to <?= h(($billingContact ?: $mainContact)['email'] ?? '') ?>?">
          <?= csrf_field() ?><button class="btn btn-sm">Send accounts contact to Xero</button>
        </form>
        <?php if (setting('xero_push_contacts') === '1'): ?><p class="help">Xero is updated automatically when the accounts contact changes.</p><?php endif; ?>
      <?php endif; ?>
    </section>
    <?php render('_billing_link', ['account' => $account, 'canEdit' => $canEdit]); ?>
    <?php render('_customer_portal', ['account' => $account, 'canEdit' => $canEdit]); ?>
  </aside>
</div>

<?php elseif ($tab === 'supplier'): ?>
<?php render('_supplier_body', $supplierData + ['inAccount' => true]); ?>

<?php elseif ($tab === 'dealer'): ?>
    <?php
        $groupMrr = array_sum(array_map(fn($c) => (float)($c['_mrr'] ?? 0), $children));
        $commission = $account['dealer_commission_pct'] !== null ? $groupMrr * (float)$account['dealer_commission_pct'] / 100 : null; ?>
    <section class="card">
      <div class="card-head"><h2>Dealer's customers <span class="count"><?= count($children) ?></span></h2>
        <?php if ($canEdit): ?><a class="btn btn-sm" href="<?= h(url('accounts', ['action' => 'new', 'parent_id' => $id, 'parent_relationship' => 'referral', 'return' => $here])) ?>">+ Add customer under this dealer</a><?php endif; ?></div>
      <?php if (can('revenue.view')): ?><p class="muted">Customers' MRR <b><?= h(money($groupMrr)) ?></b> · with this dealer's own services <b><?= h(money($groupMrr + $mrr)) ?></b>
        <?php if ($commission !== null): ?> · commission at <?= h(rtrim(rtrim(number_format((float)$account['dealer_commission_pct'], 2), '0'), '.')) ?>%: <b><?= h(money($commission)) ?>/mo</b><?php endif; ?></p><?php endif; ?>
      <?php if ($children): ?>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Customer</th><th>Status</th><th>Relationship</th><th>MSA</th><?php if (can('revenue.view')): ?><th class="num">MRR</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach ($children as $c): ?>
            <tr>
              <td><a class="row-link" href="<?= h(url('accounts', ['action' => 'view', 'id' => $c['id']])) ?>"><?= h($c['name']) ?></a> <span class="muted"><?= h($c['account_number']) ?></span></td>
              <td><?= badge($c['status']) ?></td>
              <td><?= $c['parent_relationship'] === 'billed_via_dealer' ? 'Billed via dealer' : 'Referral' ?></td>
              <td><?= $c['msa_covered'] ? '✔' : '<span class="muted">—</span>' ?></td>
              <?php if (can('revenue.view')): ?><td class="num"><?= h(money($c['_mrr'] ?? 0)) ?></td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?><p class="muted">No customers under this dealer yet.</p><?php endif; ?>
    </section>
    <?php render('_dealer_portal', ['account' => $account, 'canEdit' => $canEdit, 'portal' => dealer_portal_summary((int)$account['id'])]); ?>

<?php endif; ?>
