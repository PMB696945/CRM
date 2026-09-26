<?php
$configured = xero_configured();
$connected = xero_connected();
$lastSync = setting('xero_last_sync_at');
$lastError = setting('xero_last_sync_error');
$scopes = setting('xero_scopes') ?: XERO_DEFAULT_SCOPES;
?>
<div class="page-head">
  <h1>Xero <?= $connected ? badge('active') : '' ?></h1>
  <?php if ($connected): ?>
    <form method="post" action="<?= h(url('xero', ['action' => 'sync'])) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-primary">Sync now</button></form>
  <?php endif; ?>
</div>
<p class="muted lead">Pulls each customer's outstanding and overdue balance from Xero, calculated from unpaid sales invoices less unused credit notes. Nothing is ever written to Xero.</p>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2><span class="step <?= $configured ? 'step-done' : '' ?>">1</span> Create a Xero app</h2></div>
    <ol class="steps">
      <li>Go to <a href="https://developer.xero.com/app/manage" target="_blank" rel="noopener">developer.xero.com/app/manage</a> and sign in with your Xero login.</li>
      <li>Click <b>New app</b>. Choose <b>Web app</b>, give it a name (e.g. “Telecom CRM”), and enter your website as the company URL.</li>
      <li>For <b>Redirect URI</b>, paste exactly:
        <div class="copy-row"><input readonly value="<?= h($redirectUri) ?>" onclick="this.select()" aria-label="Redirect URI"></div>
        <?php if (str_starts_with($redirectUri, 'http://') && !preg_match('#^http://(localhost|127\.0\.0\.1)#', $redirectUri)): ?>
          <div class="error">Xero only accepts <b>https://</b> redirect URIs (except localhost). Open the CRM over https, or enable SSL on your hosting first.</div>
        <?php endif; ?>
      </li>
      <li>Open the app's <b>Configuration</b> page, generate a secret, and copy the <b>Client ID</b> and <b>Client secret</b> here:</li>
    </ol>
    <form method="post" action="<?= h(url('xero', ['action' => 'credentials'])) ?>" class="stack">
      <?= csrf_field() ?>
      <label>Client ID<input name="client_id" value="<?= h(setting('xero_client_id')) ?>" required autocomplete="off" spellcheck="false"></label>
      <label>Client secret<input type="password" name="client_secret" <?= setting('xero_client_secret') ? 'placeholder="•••••••• saved (leave blank to keep)"' : 'required' ?> autocomplete="new-password"></label>
      <details <?= setting('xero_scopes') || setting('xero_redirect_uri') ? 'open' : '' ?>>
        <summary>Advanced</summary>
        <label>Scopes<input name="scopes" value="<?= h($scopes) ?>" spellcheck="false"></label>
        <div class="help">Default suits Xero apps created since March 2026. Older apps that haven't moved to granular scopes can use <code>offline_access accounting.contacts.read accounting.transactions.read</code>.</div>
        <label>Redirect URI override<input name="redirect_uri" value="<?= h(setting('xero_redirect_uri')) ?>" placeholder="<?= h($redirectUri) ?>" spellcheck="false"></label>
        <div class="help">Only needed if the address above is wrong (e.g. behind a proxy).</div>
      </details>
      <button class="btn">Save app details</button>
    </form>
  </section>

  <div>
    <section class="card">
      <div class="card-head"><h2><span class="step <?= $connected ? 'step-done' : '' ?>">2</span> Connect your organisation</h2></div>
      <?php if ($connected): ?>
        <p>Connected to <b><?= h(setting('xero_tenant_name')) ?></b>.</p>
        <?php if (count($tenants) > 1): ?>
          <form method="post" action="<?= h(url('xero', ['action' => 'tenant'])) ?>" class="inline-form" data-confirm="Switch organisation? Customer links will be reset and re-matched on the next sync.">
            <?= csrf_field() ?>
            <select name="tenant_id" aria-label="Organisation">
              <?php foreach ($tenants as $t): ?><option value="<?= h($t['id']) ?>" <?= $t['id'] === setting('xero_tenant_id') ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-sm">Switch</button>
          </form>
        <?php endif; ?>
        <div class="actions" style="margin-top:.75rem">
          <form method="post" action="<?= h(url('xero', ['action' => 'connect'])) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm">Reconnect</button></form>
          <form method="post" action="<?= h(url('xero', ['action' => 'disconnect'])) ?>" class="inline" data-confirm="Disconnect from Xero?"><?= csrf_field() ?><button class="btn btn-sm btn-danger">Disconnect</button></form>
        </div>
      <?php elseif ($configured): ?>
        <p>You'll be sent to Xero to log in and choose which organisation to share. The CRM asks for <b>read-only</b> access to contacts and invoices.</p>
        <form method="post" action="<?= h(url('xero', ['action' => 'connect'])) ?>"><?= csrf_field() ?><button class="btn btn-primary">Connect to Xero</button></form>
      <?php else: ?>
        <p class="muted">Save your Xero app details first.</p>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2><span class="step <?= $lastSync ? 'step-done' : '' ?>">3</span> Sync</h2></div>
      <dl class="details">
        <dt>Last sync</dt><dd><?= $lastSync ? h(fmt_datetime($lastSync)) : '<span class="muted">Never</span>' ?>
          <?php if ($summary): ?><div class="muted"><?= (int)$summary['contacts'] ?> contacts, <?= (int)$summary['invoices'] ?> unpaid invoices, <?= h($summary['seconds']) ?>s</div><?php endif; ?></dd>
        <?php if ($lastError): ?><dt>Last error</dt><dd class="text-danger"><?= h($lastError) ?></dd><?php endif; ?>
        <dt>Xero contacts</dt><dd><?= number_format($stats['contacts']) ?></dd>
        <dt>Linked customers</dt><dd><?= number_format($stats['linked']) ?></dd>
      </dl>
      <p class="help">Balances refresh when you press <b>Sync now</b>. To keep them up to date automatically, add a cron job in cPanel (<i>Cron Jobs</i>), e.g. every hour:</p>
      <div class="copy-row"><input readonly value="php <?= h(APP_ROOT) ?>/cron/sync.php" onclick="this.select()" aria-label="Cron command"></div>
      <p class="help">Running at least every few weeks also keeps the connection alive. Xero expires it after 60 days without use.</p>
    </section>
  </div>
</div>

<?php if ($connected && $stats['unlinked_total']): ?>
<section class="card">
  <div class="card-head"><h2>Customers not linked to Xero <small class="count"><?= (int)$stats['unlinked_total'] ?></small></h2>
    <a href="<?= h(url('accounts', ['preset' => 'no_xero'])) ?>">All →</a></div>
  <p class="help">These active customers couldn't be matched automatically. Matching uses the Xero contact's <b>account number</b> (set it to the CRM account number, e.g. ACC-10001), then <b>email</b>, then <b>company name</b>. Or choose the contact yourself by editing the customer.</p>
  <ul class="link-list">
    <?php foreach ($stats['unlinked'] as $a): ?>
      <li><a href="<?= h(url('accounts', ['action' => 'edit', 'id' => $a['id'], 'return' => url('xero')])) ?>"><?= h($a['name']) ?></a> <span class="muted"><?= h($a['account_number']) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
