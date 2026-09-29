<?php $configured = signable_configured(); ?>
<div class="page-head">
  <h1>Signable <?= $configured ? badge('active') : '' ?></h1>
  <?php if ($configured): ?><form method="post" action="<?= h(url('signable', ['action' => 'sync'])) ?>" class="inline"><?= csrf_field() ?><button class="btn">↻ Check contracts now</button></form><?php endif; ?>
</div>
<p class="lead">Contracts are sent to customers through <a href="https://www.signable.co.uk" target="_blank" rel="noopener">Signable</a> for e-signature. When they sign, the CRM marks the contract signed and keeps the signed PDF.</p>
<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2><span class="step <?= $configured ? 'step-done' : '' ?>">1</span> API key</h2></div>
    <ol class="steps">
      <li>In Signable, go to <b>Company Settings → API &amp; Webhooks</b>.</li>
      <li>Click <b>Add API Key</b>, name it (e.g. “Telecom CRM”) and save.</li>
      <li>Paste the key below.</li>
    </ol>
    <form method="post" action="<?= h(url('signable', ['action' => 'save'])) ?>" class="stack">
      <?= csrf_field() ?>
      <label>API key<input type="password" name="api_key" <?= $configured ? 'placeholder="•••••••• saved (leave blank to keep)"' : 'required' ?> autocomplete="new-password" spellcheck="false"></label>
      <label class="check"><input type="checkbox" name="auto_send" value="1" <?= setting('signable_auto_send', '1') === '1' ? 'checked' : '' ?>> Send contracts for signature automatically when a quote is accepted</label>
      <label>Auto-remind every (hours, optional)<input name="remind_hours" value="<?= h(setting('signable_remind_hours')) ?>" inputmode="numeric" placeholder="e.g. 48"></label>
      <label>Message to the signer (optional)<textarea name="message" rows="2"><?= h(setting('signable_message')) ?></textarea></label>
      <label>Page to show after signing (optional)<input name="redirect_url" value="<?= h(setting('signable_redirect_url')) ?>" placeholder="https://www.yourcompany.co.uk/thank-you"></label>
      <button class="btn">Save &amp; test</button>
    </form>
  </section>
  <section class="card">
    <div class="card-head"><h2><span class="step <?= setting('signable_webhook_registered') ? 'step-done' : '' ?>">2</span> Instant updates</h2></div>
    <p>Add a webhook so Signable tells the CRM the moment a contract is signed. Without it, contracts are checked by the cron job or the <b>Check status</b> button.</p>
    <?php if ($webhookUrl): ?>
      <div class="copy-row"><input id="wh" readonly value="<?= h($webhookUrl) ?>" data-select-all><button type="button" class="btn btn-sm" data-copy="#wh">Copy</button></div>
      <form method="post" action="<?= h(url('signable', ['action' => 'webhook'])) ?>" class="mt-3"><?= csrf_field() ?><button class="btn btn-primary">Add webhook in Signable</button></form>
      <?php if ($w = setting('signable_webhook_registered')): ?><p class="help mt-2">Added <?= h(fmt_datetime($w)) ?>.</p><?php endif; ?>
      <p class="help mt-2">Or add it yourself in Signable under API &amp; Webhooks (type “signed envelope”). The web address must be reachable from the internet.</p>
    <?php else: ?><p class="muted">Save your API key first.</p><?php endif; ?>
  </section>
</div>
