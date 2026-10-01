<?php $v = fn(string $k, string $d = '') => h(setting($k) ?? $d); ?>
<div class="page-head"><h1>Settings</h1></div>
<form method="post" action="<?= h(url('settings')) ?>">
  <?= csrf_field() ?>
  <div class="grid-2">
    <section class="card form-grid">
      <h2 class="wide">Your company</h2>
      <p class="help wide">Used in quote emails, the customer's quote page and contracts ({{our_company_name}} etc.).</p>
      <div class="field"><label for="s_cn">Company name</label><input id="s_cn" name="company_name" value="<?= $v('company_name') ?>"></div>
      <div class="field"><label for="s_cnum">Company number</label><input id="s_cnum" name="company_number" value="<?= $v('company_number') ?>"></div>
      <div class="field wide"><label for="s_ca">Address</label><textarea id="s_ca" name="company_address" rows="3"><?= $v('company_address') ?></textarea></div>
      <div class="field"><label for="s_cp">Phone</label><input id="s_cp" name="company_phone" value="<?= $v('company_phone') ?>"></div>
      <div class="field"><label for="s_ce">Email</label><input id="s_ce" type="email" name="company_email" value="<?= $v('company_email') ?>"></div>
      <div class="field wide"><label for="s_url">CRM web address</label><input id="s_url" name="app_url" value="<?= $v('app_url') ?>" placeholder="<?= h($detectedUrl) ?>">
        <div class="help">Used for links in emails, such as the customer's quote link. Leave blank to use the address you're using now, <?= h($detectedUrl) ?>. Only set it if emails sent by the cron job have the wrong link.</div>
        <?php if ($warn = app_url_mismatch()): ?><div class="error"><?= h($warn) ?></div><?php endif; ?></div>
    </section>

    <section class="card form-grid">
      <h2 class="wide">Email</h2>
      <div class="field"><label for="s_fe">Send from (email)</label><input id="s_fe" type="email" name="mail_from_email" value="<?= $v('mail_from_email') ?>" placeholder="sales@yourcompany.co.uk"></div>
      <div class="field"><label for="s_fn">Send from (name)</label><input id="s_fn" name="mail_from_name" value="<?= $v('mail_from_name') ?>"></div>
      <div class="field"><label for="s_rt">Reply-to (optional)</label><input id="s_rt" type="email" name="mail_reply_to" value="<?= $v('mail_reply_to') ?>"></div>
      <div class="field"><label for="s_tr">Send using</label>
        <select id="s_tr" name="mail_transport"><option value="php">This server's mail (PHP mail)</option><option value="smtp" <?= setting('mail_transport') === 'smtp' ? 'selected' : '' ?>>SMTP server</option><option value="mandrill" <?= setting('mail_transport') === 'mandrill' ? 'selected' : '' ?>>Mailchimp Transactional (Mandrill)</option></select>
        <div class="help">SMTP (e.g. Microsoft 365, Google Workspace, your host) is more reliable for reaching inboxes.</div></div>
      <div class="field"><label for="s_sh">SMTP server</label><input id="s_sh" name="smtp_host" value="<?= $v('smtp_host') ?>" placeholder="smtp.office365.com"></div>
      <div class="field"><label for="s_sp">Port</label><input id="s_sp" name="smtp_port" value="<?= $v('smtp_port', '587') ?>" inputmode="numeric"></div>
      <div class="field"><label for="s_se">Security</label>
        <select id="s_se" name="smtp_encryption"><?php foreach (['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>" <?= (setting('smtp_encryption') ?: 'tls') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="s_su">Username</label><input id="s_su" name="smtp_username" value="<?= $v('smtp_username') ?>" autocomplete="off"></div>
      <div class="field"><label for="s_sw">Password</label><input id="s_sw" type="password" name="smtp_password" placeholder="<?= setting('smtp_password') ? '•••••••• saved (leave blank to keep)' : '' ?>" autocomplete="new-password"></div>
      <div class="field wide"><label for="s_mk">Mailchimp Transactional API key</label><input id="s_mk" type="password" name="mandrill_api_key" placeholder="<?= setting('mandrill_api_key') ? '•••••••• saved (leave blank to keep)' : 'Only if sending using Mailchimp Transactional' ?>" autocomplete="new-password">
        <div class="help">A paid Mailchimp add-on for one-to-one emails (quotes, alerts, notifications). Create a key in Mandrill under Settings → SMTP &amp; API Info, and verify your sending domain there.</div></div>
    </section>
  </div>

  <section class="card form-grid">
    <h2 class="wide">Quotes &amp; contracts</h2>
    <div class="field"><label for="s_qv">Quotes valid for (days)</label><input id="s_qv" name="quote_validity_days" value="<?= $v('quote_validity_days', '30') ?>" inputmode="numeric"></div>
    <div class="field field-check"><label><input type="checkbox" name="contracts_auto_on_accept" value="1" <?= setting('contracts_auto_on_accept', '1') === '1' ? 'checked' : '' ?>> Create the contract automatically when a quote is accepted</label>
      <div class="help">It's also sent for signature straight away if that's switched on under Signable.</div></div>
    <div class="field wide"><label for="s_qt">Terms shown on quotes</label><textarea id="s_qt" name="quote_terms" rows="4" placeholder="e.g. All prices exclude VAT. Services are subject to survey and our standard terms and conditions."><?= $v('quote_terms') ?></textarea></div>
  </section>

  <section class="card form-grid">
    <h2 class="wide">Marketing &amp; service alerts</h2>
    <div class="field"><label for="s_mt">Marketing topics</label><textarea id="s_mt" name="marketing_topics" rows="4" placeholder="Newsletter&#10;Product news &amp; offers&#10;Events &amp; webinars"><?= $v('marketing_topics') ?></textarea>
      <div class="help">One per line. Contacts can choose which of these they want.</div></div>
    <div class="field"><label for="s_bs">Emails per batch</label><input id="s_bs" name="campaign_batch_size" value="<?= $v('campaign_batch_size', '50') ?>" inputmode="numeric">
      <div class="help">Large sends go out in batches so the server doesn't time out. The cron job carries on with any that are left. Check your email provider's hourly limit.</div></div>
  </section>

  <section class="card form-grid">
    <h2 class="wide">Security</h2>
    <div class="field"><label for="s_idle">Sign out after inactivity (minutes)</label><input id="s_idle" name="session_idle_minutes" value="<?= $v('session_idle_minutes', '60') ?>" inputmode="numeric">
      <div class="help">Between 5 and 720. Everyone also has to sign in again after <?= SESSION_MAX_HOURS ?> hours.</div></div>
    <div class="field field-check"><label><input type="checkbox" name="require_2fa" value="1" <?= setting('require_2fa') === '1' ? 'checked' : '' ?>> Require two-factor sign-in for all users</label>
      <div class="help">Users without it are asked to set it up when they next sign in. Set it up on your own profile first.</div></div>
    <div class="field field-check"><label><input type="checkbox" name="force_https" value="1" <?= setting('force_https') === '1' ? 'checked' : '' ?> <?= is_https() ? '' : 'disabled' ?>> Always use a secure connection (HTTPS)</label>
      <div class="help"><?= is_https() ? 'Visitors on http:// are redirected to https://.' : 'Open the CRM over https:// to switch this on. Enable SSL (e.g. AutoSSL) in your hosting first.' ?></div></div>
    <div class="field"><label>Stored passwords &amp; API keys</label><p class="text-sm">Encrypted (AES-256). The key is in <code><?= config('app_key') ? 'config.php' : 'app.key' ?></code>. Keep a copy with your backups, because without it the saved API keys can't be read.</p></div>
  </section>

  <div class="form-actions"><button class="btn btn-primary">Save settings</button></div>
</form>
<form method="post" action="<?= h(url('settings', ['action' => 'test_email'])) ?>" class="mt-4"><?= csrf_field() ?><button class="btn">✉ Send me a test email</button> <span class="help">Save first.</span></form>
