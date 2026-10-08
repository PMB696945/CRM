<?php $v = fn(string $k, string $d = '') => h(setting($k) ?? $d); ?>
<div class="page-head"><h1>Settings</h1></div>
<?php $logo = brand_logo_data_uri(); [$br, $bg, $bb] = brand_colour(); ?>
<form method="post" action="<?= h(url('settings', ['action' => 'branding'])) ?>" enctype="multipart/form-data" class="mb-6">
  <?= csrf_field() ?>
  <section class="card form-grid">
    <h2 class="wide">Branding</h2>
    <p class="help wide">Your logo and colour on quote PDFs and the pages customers see (their quote and order tracking).</p>
    <div class="field">
      <label for="s_logo">Logo</label>
      <?php if ($logo): ?><div class="brand-logo-preview"><img src="<?= h($logo) ?>" alt="Your logo"></div><?php endif; ?>
      <input id="s_logo" type="file" name="logo" accept="image/png,image/jpeg">
      <div class="help">PNG (a transparent background works well) or JPG, up to 2 MB. A wide logo around 600 pixels across looks best.</div>
      <?php if ($logo): ?><label class="check mt-2"><input type="checkbox" name="remove_logo" value="1"> Remove the logo</label><?php endif; ?>
    </div>
    <div class="field">
      <label for="s_colour">Brand colour</label>
      <input id="s_colour" type="color" name="brand_colour" value="<?= h(sprintf('#%02X%02X%02X', $br, $bg, $bb)) ?>">
      <div class="help">Used for the bar and headings on quote PDFs.</div>
    </div>
    <div class="wide"><button class="btn">Save branding</button></div>
  </section>
</form>
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
      <div class="help">Switch this off to create contracts by hand from the quote.</div></div>
    <div class="field field-check"><label><input type="checkbox" name="contracts_auto_send" value="1" <?= setting('contracts_auto_send', '1') === '1' ? 'checked' : '' ?>> Email the contract to the customer to sign straight away</label>
      <div class="help">Otherwise it waits on the contract page for you to check and send.</div></div>
    <div class="field"><label for="s_cs">Send a Contract Summary before the agreement</label>
      <select id="s_cs" name="contract_summary_for">
        <option value="all" <?= setting('contract_summary_for', 'all') === 'all' ? 'selected' : '' ?>>For every customer</option>
        <option value="protected" <?= setting('contract_summary_for') === 'protected' ? 'selected' : '' ?>>Only where Ofcom requires it (all but larger businesses)</option>
      </select>
      <div class="help">Uses the "Contract Summary" template under Contract templates. The customer confirms they've received it before they can see the agreement or sign. Customers with no size set get one.</div></div>
    <div class="field"><label for="s_rd">Remind unsigned contracts every (days)</label><input id="s_rd" name="esign_remind_days" value="<?= $v('esign_remind_days', '3') ?>" inputmode="numeric">
      <div class="help">Up to 3 reminders. 0 turns reminders off.</div></div>
    <div class="field wide"><label for="s_qas">Wording ticked to go ahead with a quote</label><textarea id="s_qas" name="quote_acceptance_statement" rows="2" placeholder="<?= h(QUOTE_DEFAULT_GO_AHEAD_STATEMENT) ?>"><?= $v('quote_acceptance_statement') ?></textarea>
      <div class="help">Leave blank for the wording shown. {customer} becomes the customer's name. Have your solicitor approve these three.</div></div>
    <div class="field wide"><label for="s_ess">Wording ticked to confirm the Contract Summary</label><textarea id="s_ess" name="esign_summary_statement" rows="2" placeholder="<?= h(ESIGN_DEFAULT_SUMMARY_STATEMENT) ?>"><?= $v('esign_summary_statement') ?></textarea></div>
    <div class="field wide"><label for="s_esg">Wording ticked to sign the agreement</label><textarea id="s_esg" name="esign_sign_statement" rows="2" placeholder="<?= h(ESIGN_DEFAULT_SIGN_STATEMENT) ?>"><?= $v('esign_sign_statement') ?></textarea></div>
    <div class="field wide"><label for="s_qt">Terms shown on quotes</label><textarea id="s_qt" name="quote_terms" rows="4" placeholder="e.g. All prices exclude VAT. Services are subject to survey and our standard terms and conditions."><?= $v('quote_terms') ?></textarea></div>
  </section>

  <section class="card form-grid">
    <h2 class="wide">Supplier invoices</h2>
    <p class="wide muted">Uploaded supplier invoices are read and matched to their purchase order. The built-in reader handles PDFs made by accounting software; Claude reads anything, including scans and phone photos, and is more accurate on unusual layouts.</p>
    <div class="field"><label for="s_ir">Read invoices with</label>
      <select id="s_ir" name="invoice_reader">
        <option value="builtin" <?= setting('invoice_reader') !== 'claude' ? 'selected' : '' ?>>Built-in reader (PDFs with text, free)</option>
        <option value="claude" <?= setting('invoice_reader') === 'claude' ? 'selected' : '' ?>>Claude (any PDF or photo; uses your Anthropic API key)</option>
      </select></div>
    <div class="field"><label for="s_ak">Anthropic API key</label><input id="s_ak" type="password" name="anthropic_api_key" autocomplete="new-password" placeholder="<?= setting('anthropic_api_key') ? '•••••••• saved (leave blank to keep)' : 'sk-ant-…' ?>">
      <div class="help">From <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>. Stored encrypted. Each invoice read costs a few pence.</div></div>
    <div class="field"><label for="s_im">Claude model</label><input id="s_im" name="invoice_model" value="<?= $v('invoice_model') ?>" placeholder="<?= h(INVOICE_DEFAULT_MODEL) ?>" spellcheck="false"></div>
    <div class="field"><label for="s_it">Allowed difference from the PO (£)</label><input id="s_it" name="invoice_tolerance" value="<?= h(setting('invoice_tolerance') ?? '1.00') ?>" inputmode="decimal">
      <div class="help">Invoices further than this from their purchase order (before VAT) are flagged.</div></div>
    <div class="field"><label for="s_ia">Send invoice warnings to</label><input id="s_ia" type="email" name="invoice_alert_email" value="<?= $v('invoice_alert_email') ?>" placeholder="Everyone who can raise purchase orders">
      <div class="help">Leave blank to email everyone whose role can raise purchase orders.</div></div>
  </section>

  <section class="card form-grid">
    <h2 class="wide">Orders</h2>
    <p class="wide muted">When a quote is accepted an order is created and the team below is alerted. As they move it through the steps, the customer is emailed with the message for that step (which can be changed each time).</p>
    <div class="field"><label for="s_og">Onboarding team</label>
      <select id="s_og" name="order_group_id"><option value="">Nobody (no alerts)</option>
        <?php foreach (ticket_groups() as $g): ?><option value="<?= (int)$g['id'] ?>" <?= (string)setting('order_group_id') === (string)$g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option><?php endforeach; ?>
      </select>
      <div class="help">Add people to it under <a href="<?= h(url('ticket_groups')) ?>">Ticket groups</a>. New orders go to its shared email if it has one, otherwise to each member.</div></div>
    <?php foreach (['processing', 'confirmed', 'completed', 'cancelled'] as $st): ?>
      <div class="field wide"><label for="s_om_<?= $st ?>">Message to the customer: <?= h(order_status_label($st)) ?></label>
        <textarea id="s_om_<?= $st ?>" name="order_message_<?= $st ?>" rows="2"><?= h(order_default_message($st)) ?></textarea></div>
    <?php endforeach; ?>
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
    <div class="field wide field-check"><label><input type="checkbox" name="ip_restrict" value="1" <?= setting('ip_restrict') === '1' ? 'checked' : '' ?>> Only allow the CRM to be used from these IP addresses</label>
      <?php if (config('ip_allowlist_off')): ?><div class="error">Switched off in config.php ('ip_allowlist_off'), so it isn't being applied.</div><?php endif; ?></div>
    <div class="field wide"><label for="s_ips">Allowed IP addresses</label>
      <textarea id="s_ips" name="allowed_ips" rows="4" spellcheck="false" placeholder="81.2.69.160  # office&#10;203.0.113.0/24  # head office range"><?= $v('allowed_ips') ?></textarea>
      <div class="help">One per line: a single address, or a range such as 81.2.69.0/24. Add notes after a #. You're on <b><?= h(client_ip()) ?></b> now, and it must be in the list.
        People marked <i>Can use the CRM from any location</i> (under Users) aren't limited. Customer quote and order pages, and contract signing pages and links from Xero and GoCardless keep working from anywhere.
        If you're ever locked out, add <code>'ip_allowlist_off' =&gt; true,</code> to config.php.</div></div>
    <div class="field"><label>Stored passwords &amp; API keys</label><p class="text-sm">Encrypted (AES-256). The key is in <code><?= config('app_key') ? 'config.php' : 'app.key' ?></code>. Keep a copy with your backups, because without it the saved API keys can't be read.</p></div>
  </section>

  <div class="form-actions"><button class="btn btn-primary">Save settings</button></div>
</form>
<form method="post" action="<?= h(url('settings', ['action' => 'test_email'])) ?>" class="mt-4"><?= csrf_field() ?><button class="btn">✉ Send me a test email</button> <span class="help">Save first.</span> <a class="btn btn-ghost" href="<?= h(url('mail_log')) ?>">Email log</a></form>
