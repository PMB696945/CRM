<?php $hasKey = (bool)setting('mailchimp_api_key'); ?>
<div class="page-head"><h1>Mailchimp <?= mailchimp_configured() ? badge('active') : '' ?></h1></div>
<p class="lead">Optional. Send marketing emails from the CRM through your Mailchimp account, so you get Mailchimp's delivery, reports and unsubscribe handling. The CRM adds each recipient to your audience (only people who opted in), then creates and sends a campaign to just those people. Unsubscribes made in Mailchimp are copied back into the CRM.</p>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2><span class="step <?= $hasKey ? 'step-done' : '' ?>">1</span> API key</h2></div>
    <ol class="steps">
      <li>In Mailchimp, open your profile → <b>Extras → API keys</b> and choose <b>Create A Key</b>.</li>
      <li>Paste it here. It's stored encrypted.</li>
    </ol>
    <form method="post" action="<?= h(url('mailchimp', ['action' => 'key'])) ?>" class="stack">
      <?= csrf_field() ?>
      <label>API key<input type="password" name="api_key" autocomplete="new-password" <?= $hasKey ? 'placeholder="•••••••• saved (leave blank to keep)"' : 'required placeholder="xxxxxxxxxxxxxxxx-us21"' ?>></label>
      <?php if ($lists): ?>
        <label>Audience<select name="list_id" required><option value="">Choose…</option>
          <?php foreach ($lists as $id => $name): ?><option value="<?= h($id) ?>" <?= setting('mailchimp_list_id') === $id ? 'selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?></select></label>
        <p class="help">Mailchimp recommends one audience with everyone in it. The CRM adds a Company and Account number field to it.</p>
      <?php endif; ?>
      <?php if ($error): ?><p class="text-danger"><?= h($error) ?></p><?php endif; ?>
      <button class="btn btn-primary">Save</button>
    </form>
  </section>

  <section class="card">
    <div class="card-head"><h2><span class="step <?= mailchimp_configured() ? 'step-done' : '' ?>">2</span> Unsubscribes</h2></div>
    <p>Last checked: <?= setting('mailchimp_last_sync_at') ? h(fmt_datetime(setting('mailchimp_last_sync_at'))) : '<span class="muted">never</span>' ?>. They're checked before every Mailchimp send and by the hourly cron job.</p>
    <?php if (mailchimp_configured()): ?>
      <form method="post" action="<?= h(url('mailchimp', ['action' => 'sync'])) ?>" class="inline"><?= csrf_field() ?><button class="btn">Check now</button></form>
    <?php endif; ?>
    <?php if ($hasKey): ?>
      <form method="post" action="<?= h(url('mailchimp', ['action' => 'remove'])) ?>" class="inline" data-confirm="Disconnect Mailchimp?"><?= csrf_field() ?><button class="btn btn-danger">Disconnect</button></form>
    <?php endif; ?>
    <p class="help" style="margin-top:1rem">Service alerts aren't marketing, so they're sent with the CRM's own email. To send those through Mailchimp too, add a <b>Mailchimp Transactional</b> key under <a href="<?= h(url('settings')) ?>">Settings → Email</a>.</p>
  </section>
</div>
