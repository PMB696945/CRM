<?php
$configured = gc_configured();
$lastSync = setting('gocardless_last_sync_at');
$lastError = setting('gocardless_last_sync_error');
?>
<div class="page-head">
  <h1>GoCardless <?= $configured ? badge('active') : '' ?></h1>
  <?php if ($configured): ?>
    <form method="post" action="<?= h(url('gocardless', ['action' => 'sync'])) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-primary">Sync now</button></form>
  <?php endif; ?>
</div>
<p class="muted lead">Shows whether each customer has a Direct Debit mandate. When they don't, you can create a personal setup link (GoCardless's secure hosted page, with their details filled in) and email it to them. Once they complete it, the CRM links them automatically.</p>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2><span class="step <?= $configured ? 'step-done' : '' ?>">1</span> Add an access token</h2></div>
    <ol class="steps">
      <li>In GoCardless, go to <b>Developers → Create → Access token</b> (<a href="https://manage.gocardless.com/developers/access-tokens/create" target="_blank" rel="noopener">live</a> or <a href="https://manage-sandbox.gocardless.com/developers/access-tokens/create" target="_blank" rel="noopener">sandbox</a>).</li>
      <li>Give it a name (e.g. “Telecom CRM”) and choose <b>Read-write access</b>. A read-only token can check mandates but can't create setup links.</li>
      <li>Paste it below. It's only shown once in GoCardless.</li>
    </ol>
    <form method="post" action="<?= h(url('gocardless', ['action' => 'settings'])) ?>" class="stack">
      <?= csrf_field() ?>
      <label>Access token<input type="password" name="access_token" <?= $configured ? 'placeholder="•••••••• saved (leave blank to keep)"' : 'required' ?> autocomplete="new-password" spellcheck="false"></label>
      <label>Environment
        <select name="environment">
          <option value="live" <?= !gc_sandbox() ? 'selected' : '' ?>>Live</option>
          <option value="sandbox" <?= gc_sandbox() ? 'selected' : '' ?>>Sandbox (testing)</option>
        </select>
      </label>
      <details <?= setting('gocardless_return_url') || setting('gocardless_scheme') ? 'open' : '' ?>>
        <summary>Advanced</summary>
        <label>Return page after setup (optional)<input name="return_url" value="<?= h(setting('gocardless_return_url')) ?>" placeholder="https://www.yourcompany.co.uk/thank-you" spellcheck="false"></label>
        <div class="help">Where customers go when they finish or leave the setup page. Leave blank to use GoCardless's own confirmation screen.</div>
        <label>Scheme<input name="scheme" value="<?= h(setting('gocardless_scheme') ?: 'bacs') ?>" spellcheck="false"></label>
        <div class="help"><code>bacs</code> for UK Direct Debit. Change only if you collect in another country (e.g. <code>sepa_core</code>).</div>
      </details>
      <button class="btn">Save &amp; test</button>
    </form>
    <?php if ($configured): ?>
      <form method="post" action="<?= h(url('gocardless', ['action' => 'remove'])) ?>" data-confirm="Remove the GoCardless access token?" style="margin-top:.75rem"><?= csrf_field() ?><button class="btn btn-sm btn-danger">Remove token</button></form>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2><span class="step <?= $lastSync ? 'step-done' : '' ?>">2</span> Sync</h2></div>
    <dl class="details">
      <dt>Account</dt><dd><?= $configured ? h(setting('gocardless_creditor', '—')) . (gc_sandbox() ? ' ' . badge('sandbox') : '') : '<span class="muted">Not set up</span>' ?></dd>
      <dt>Last sync</dt><dd><?= $lastSync ? h(fmt_datetime($lastSync)) : '<span class="muted">Never</span>' ?>
        <?php if ($summary): ?><div class="muted"><?= (int)$summary['customers'] ?> customers, <?= (int)$summary['mandates'] ?> mandates, <?= h($summary['seconds']) ?>s</div><?php endif; ?></dd>
      <?php if ($lastError): ?><dt>Last error</dt><dd class="text-danger"><?= h($lastError) ?></dd><?php endif; ?>
      <dt>GoCardless customers</dt><dd><?= number_format($stats['customers']) ?> (<?= number_format($stats['active']) ?> with an active mandate)</dd>
      <dt>Linked CRM customers</dt><dd><?= number_format($stats['linked']) ?></dd>
      <dt>Open setup links</dt><dd><?= number_format($stats['open_links']) ?></dd>
    </dl>
    <p class="help">Customers are linked automatically when they complete a setup link from the CRM. Existing GoCardless customers are matched on sync by email (account or any contact) or company name, only when the match is unambiguous. You can also choose one by editing the customer.</p>
    <p class="help">To keep statuses current, add a cron job (cPanel → <i>Cron Jobs</i>), e.g. hourly. It syncs Xero and GoCardless:</p>
    <div class="copy-row"><input readonly value="php <?= h(APP_ROOT) ?>/cron/sync.php" data-select-all aria-label="Cron command"></div>
    <p class="help">Each customer page also has a <b>Check now</b> button for an instant update.</p>
  </section>
</div>

<?php if ($configured): ?>
<p><a href="<?= h(url('accounts', ['preset' => 'no_dd'])) ?>">View active customers without Direct Debit →</a></p>
<?php endif; ?>
