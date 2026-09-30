<?php $ok = giacom_configured(); ?>
<div class="page-head"><h1>Giacom <?= $ok ? badge('active') : '' ?></h1>
  <?php if ($ok): ?><div class="actions"><a class="btn" href="<?= h(url('giacom', ['action' => 'settings', 'test' => 1])) ?>">Test connection</a></div><?php endif; ?></div>
<p class="lead">Check broadband availability and place and track Giacom orders from customer pages. The CRM uses Giacom's comms API with your API login.</p>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2><span class="step <?= $ok ? 'step-done' : '' ?>">1</span> API login</h2></div>
    <p class="help">Giacom issues API usernames and passwords to partners. Ask your Giacom account manager if you don't have one. The details are checked with Giacom before they're saved, and the password is stored encrypted.</p>
    <form method="post" class="stack" action="<?= h(url('giacom', ['action' => 'settings'])) ?>">
      <?= csrf_field() ?>
      <label>API username<input name="username" value="<?= h(setting('giacom_username')) ?>" autocomplete="off" spellcheck="false"></label>
      <label>API password<input type="password" name="password" autocomplete="new-password" <?= setting('giacom_password') ? 'placeholder="•••••••• saved (leave blank to keep)"' : '' ?>></label>
      <label>Client ID<input name="client_id" value="<?= h(setting('giacom_client_id')) ?>" autocomplete="off"><span class="help">If Giacom gave you one.</span></label>
      <label>Broadband realm<input name="realm" value="<?= h(setting('giacom_realm')) ?>" placeholder="e.g. yourisp.net"><span class="help">The default realm for new broadband logins, exactly as it's set up on your Giacom account (e.g. yourisp.net). Usernames are sent as user@realm.</span></label>
      <label>Default care level<select name="care_level"><option value="">Giacom's default</option>
        <?php foreach (GIACOM_CARE_LEVELS as $k => $l): ?><option value="<?= $k ?>" <?= setting('giacom_care_level') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <details <?= setting('giacom_url') ? 'open' : '' ?>><summary>Advanced</summary>
        <label>API address<input name="api_url" value="<?= h(setting('giacom_url')) ?>" placeholder="<?= h(GIACOM_DEFAULT_URL) ?>"><span class="help">Only change this if Giacom gives you a different (e.g. test) address.</span></label>
      </details>
      <button class="btn btn-primary">Save and test</button>
    </form>
    <?php if ($ok): ?>
      <form method="post" action="<?= h(url('giacom', ['action' => 'settings', 'do' => 'remove'])) ?>" data-confirm="Remove the Giacom login?" style="margin-top:1rem"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Remove login</button></form>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2><span class="step <?= setting('giacom_last_sync_at') ? 'step-done' : '' ?>">2</span> Keeping orders up to date</h2></div>
    <p>Order progress is fetched from Giacom by the hourly cron job (the same one used for Xero), and with <b>Check for updates</b> on the Broadband orders page. When Giacom marks an order complete, its service on the customer becomes <b>active</b>.</p>
    <p>Last checked: <?= setting('giacom_last_sync_at') ? h(fmt_datetime(setting('giacom_last_sync_at'))) : '<span class="muted">never</span>' ?></p>
    <?php if ($status !== null): ?>
      <h3 class="subhead">Giacom service status</h3>
      <ul class="feed"><?php foreach ($status as $s): ?><li><?= h($s['service'] ?? '') ?>: <b><?= h($s['status'] ?? '') ?></b></li><?php endforeach; ?><?php if (!$status): ?><li>Connected. Giacom reported no service issues.</li><?php endif; ?></ul>
    <?php endif; ?>
    <p class="help">Who can check availability and who can place orders is set on <?= is_super_admin() ? '<a href="' . h(url('roles')) . '">Roles &amp; permissions</a>' : 'Roles &amp; permissions' ?>.</p>
  </section>
</div>
