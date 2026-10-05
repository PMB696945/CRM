<?php $ok = abillity_configured(); ?>
<div class="page-head"><h1>aBILLity <?= $ok ? badge('active') : '' ?></h1>
  <?php if ($ok): ?><div class="actions"><a class="btn" href="<?= h(url('abillity', ['test' => 1])) ?>">Test connection</a>
    <form method="post" action="<?= h(url('abillity', ['do' => 'sync'])) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-primary">Send now</button></form></div><?php endif; ?></div>
<p class="lead">Customers, products and services are set up here in the CRM and sent to aBILLity for billing. New customers are created in Xero at the same time.</p>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2><span class="step <?= $ok ? 'step-done' : '' ?>">1</span> API login</h2></div>
    <p class="help">Use an aBILLity portal user set up for the API. What it can do follows that user's permissions in aBILLity. The details are checked before they're saved, and the password is stored encrypted.</p>
    <form method="post" class="stack" action="<?= h(url('abillity')) ?>">
      <?= csrf_field() ?>
      <label>System name<input name="system" value="<?= h(setting('abillity_system')) ?>" autocomplete="off" spellcheck="false"><span class="help">The "SystemInformation" (branding) name Giacom gave you.</span></label>
      <label>Username<input name="username" value="<?= h(setting('abillity_username')) ?>" autocomplete="off" spellcheck="false"></label>
      <label>Password<input type="password" name="password" autocomplete="new-password" <?= setting('abillity_password') ? 'placeholder="•••••••• saved (leave blank to keep)"' : '' ?>></label>
      <label class="check"><input type="checkbox" name="auto" value="1" <?= setting('abillity_auto', '1') === '1' ? 'checked' : '' ?>> Send changes as soon as they're saved</label>
      <span class="help">Otherwise they're sent by the cron job, or with <b>Send now</b>.</span>
      <label>Provisional start for services not yet live (days ahead)<input name="provisional_days" inputmode="numeric" value="<?= h((string)abillity_provisional_days()) ?>">
        <span class="help">A pending service goes into aBILLity with this start date, so it isn't billed before it's live. When it goes live, the real start date replaces it.</span></label>
      <details <?= setting('abillity_url') ? 'open' : '' ?>><summary>Advanced</summary>
        <label>API address<input name="api_url" value="<?= h(setting('abillity_url')) ?>" placeholder="<?= h(ABILLITY_DEFAULT_URL) ?>"><span class="help">Only change this for a test (beta) system Giacom gives you.</span></label>
      </details>
      <button class="btn btn-primary">Save and test</button>
    </form>
    <?php if ($ok): ?>
      <form method="post" action="<?= h(url('abillity', ['do' => 'remove'])) ?>" data-confirm="Remove the aBILLity login? Nothing more will be sent until it's added again." class="mt-4"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Remove login</button></form>
    <?php endif; ?>
    <?php if ($test): ?><p class="text-ok mt-3">✔ Connected<?= is_array($test) && !empty($test['Name']) ? ' as ' . h($test['Name']) : '' ?>.</p><?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2><span class="step <?= $counts['customers'] ? 'step-done' : '' ?>">2</span> What's sent</h2></div>
    <ul class="feed">
      <li><b>Customers</b> when they become active (or their first service is sent): the company and site, with the account number as the account reference, the address, and the accounts contact with their invoice email. Also created in Xero<?= xero_connected() ? '' : ' (once Xero is connected)' ?>. Later changes are sent too.</li>
      <li><b>Products</b> as service charge types, with their price, cost, billing cycle and nominal code.</li>
      <li><b>Services</b> as service charges on the customer, with a one-off charge for any setup fee. When a service goes live (or Giacom completes its order), its real start date is sent; when it's ceased, billing ends.
        <?= setting('abillity_connected_at') ? '<br><span class="help">Only services added since ' . h(fmt_date(setting('abillity_connected_at'))) . ' are sent automatically, since older ones are already billed. Send an older one with <b>Send to aBILLity</b> on the service.</span>' : '' ?></li>
    </ul>
    <dl class="details mt-3">
      <dt>Customers linked</dt><dd><?= $counts['customers'] ?><?= $counts['unsent_customers'] ? ' · <span class="text-warn">' . $counts['unsent_customers'] . ' active customer' . ($counts['unsent_customers'] === 1 ? '' : 's') . ' not sent yet</span>' : '' ?></dd>
      <dt>Services sent</dt><dd><?= $counts['services'] ?><?= $counts['provisional'] ? ' · ' . $counts['provisional'] . ' with a provisional start date' : '' ?></dd>
      <dt>Products linked</dt><dd><?= $counts['products'] ?></dd>
      <dt>Last sync</dt><dd><?= setting('abillity_last_sync_at') ? h(fmt_datetime(setting('abillity_last_sync_at'))) : '<span class="muted">never</span>' ?></dd>
    </dl>
    <?php if ($ok && $counts['unsent_customers']): ?>
      <form method="post" action="<?= h(url('abillity', ['do' => 'send_customers'])) ?>" class="mt-3" data-confirm="Send all <?= $counts['unsent_customers'] ?> active customers to aBILLity (and Xero, where they're not there yet)? Any already in aBILLity with the same account number are linked rather than duplicated.">
        <?= csrf_field() ?><button class="btn">Send all active customers</button></form>
    <?php endif; ?>
  </section>
</div>

<?php if ($problems['accounts'] || $problems['services'] || $problems['products']): ?>
<section class="card">
  <div class="card-head"><h2>Waiting or needing attention</h2></div>
  <p class="help">These are tried again by the cron job. Fix the problem in the CRM (or aBILLity) and press <b>Send now</b>.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>What</th><th>Problem</th></tr></thead>
    <tbody>
      <?php foreach ($problems['accounts'] as $r): ?><tr><td>Customer <a href="<?= h(url('accounts', ['action' => 'view', 'id' => $r['id']])) ?>"><?= h($r['name']) ?></a> <span class="muted"><?= h($r['account_number']) ?></span></td>
        <td class="small"><?= $r['abillity_error'] ? h($r['abillity_error']) : '<span class="muted">Waiting to be sent</span>' ?></td></tr><?php endforeach; ?>
      <?php foreach ($problems['services'] as $r): ?><tr><td>Service <a href="<?= h(url('services', ['action' => 'view', 'id' => $r['id']])) ?>"><?= h($r['identifier']) ?></a> <span class="muted"><?= h($r['account_name']) ?></span></td>
        <td class="small"><?= $r['abillity_error'] ? h($r['abillity_error']) : '<span class="muted">Waiting to be sent</span>' ?></td></tr><?php endforeach; ?>
      <?php foreach ($problems['products'] as $r): ?><tr><td>Product <a href="<?= h(url('products', ['action' => 'view', 'id' => $r['id']])) ?>"><?= h($r['name']) ?></a></td>
        <td class="small"><?= h($r['abillity_error']) ?></td></tr><?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
