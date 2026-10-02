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
<p class="muted lead">Pulls each customer's outstanding and overdue balance from Xero, calculated from unpaid sales invoices less unused credit notes. The only thing the CRM ever changes in Xero is a contact's invoice email, and only if you switch that on below.</p>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2><span class="step <?= $configured ? 'step-done' : '' ?>">1</span> Create a Xero app</h2></div>
    <ol class="steps">
      <li>Go to <a href="https://developer.xero.com/app/manage" target="_blank" rel="noopener">developer.xero.com/app/manage</a> and sign in with your Xero login.</li>
      <li>Click <b>New app</b>. Choose <b>Web app</b>, give it a name (e.g. “Telecom CRM”), and enter your website as the company URL.</li>
      <li>For <b>Redirect URI</b>, paste exactly:
        <div class="copy-row"><input readonly value="<?= h($redirectUri) ?>" data-select-all aria-label="Redirect URI"></div>
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
        <?php if ($pending = xero_scopes_pending()): ?>
          <div class="flash flash-warning" role="status"><b>Press Reconnect to finish.</b> The CRM now asks Xero for more permissions (<?= h(implode(', ', $pending)) ?>), but this connection was approved before that.
            Reconnect, approve the permissions Xero lists, and choose the same organisation.</div>
        <?php elseif (setting('xero_granted_scopes')): ?>
          <p class="help">Permissions approved in Xero: <?= h(implode(', ', array_diff(explode(' ', (string)setting('xero_granted_scopes')), ['openid', 'profile', 'email']))) ?></p>
        <?php endif; ?>
        <div class="actions" style="margin-top:.75rem">
          <a class="btn btn-sm <?= xero_scopes_pending() ? 'btn-primary' : '' ?>" href="<?= h(url('xero', ['action' => 'connect', 'token' => csrf_token()])) ?>">Reconnect</a>
          <form method="post" action="<?= h(url('xero', ['action' => 'disconnect'])) ?>" class="inline" data-confirm="Disconnect from Xero?"><?= csrf_field() ?><button class="btn btn-sm btn-danger">Disconnect</button></form>
        </div>
      <?php elseif ($configured): ?>
        <p>You'll be sent to Xero to log in and choose which organisation to share. The CRM asks for <b>read-only</b> access to contacts and invoices<?= xero_can_write_contacts() ? ', plus permission to update contact email addresses' : '' ?>.</p>
        <p><a class="btn btn-primary" href="<?= h(url('xero', ['action' => 'connect', 'token' => csrf_token()])) ?>">Connect to Xero</a></p>
      <?php else: ?>
        <p class="muted">Save your Xero app details first.</p>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2><span class="step <?= $lastSync ? 'step-done' : '' ?>">3</span> Sync</h2></div>
      <dl class="details">
        <dt>Last sync</dt><dd><?= $lastSync ? h(fmt_datetime($lastSync)) : '<span class="muted">Never</span>' ?>
          <?php if ($summary): ?><div class="muted"><?= (int)$summary['contacts'] ?> contacts, <?= (int)$summary['invoices'] ?> unpaid invoices<?= !empty($summary['suppliers']) ? ', ' . (int)$summary['suppliers'] . ' suppliers brought in' : '' ?>, <?= h($summary['seconds']) ?>s</div><?php endif; ?></dd>
        <?php if ($lastError): ?><dt>Last error</dt><dd class="text-danger"><?= h($lastError) ?></dd><?php endif; ?>
        <dt>Xero contacts</dt><dd><?= number_format($stats['contacts']) ?></dd>
        <dt>Linked customers</dt><dd><?= number_format($stats['linked']) ?></dd>
      </dl>
      <p class="help">Balances refresh when you press <b>Sync now</b>. To keep them up to date automatically, add a cron job in cPanel (<i>Cron Jobs</i>), e.g. every hour:</p>
      <div class="copy-row"><input readonly value="php <?= h(APP_ROOT) ?>/cron/sync.php" data-select-all aria-label="Cron command"></div>
      <p class="help">Running at least every few weeks also keeps the connection alive. Xero expires it after 60 days without use.</p>
    </section>
  </div>
</div>

<?php if ($connected): ?>
<section class="card">
  <div class="card-head"><h2>Products</h2><?php if (setting('xero_push_items') === '1' && xero_can_write_items()): ?>
    <form method="post" action="<?= h(url('products', ['action' => 'xero_push'])) ?>" class="inline" data-confirm="Send every product that's available to sell to Xero?"><?= csrf_field() ?><input type="hidden" name="all" value="1"><input type="hidden" name="_return" value="<?= h(url('xero')) ?>"><button class="btn btn-sm">Send all products now</button></form><?php endif; ?></div>
  <p>Send products &amp; tariffs to Xero as <b>items</b>, so they can be picked on invoices. The SKU becomes the item code; the sale price and cost price become the item's sales and purchase prices. Sending again updates the same item.</p>
  <form method="post" action="<?= h(url('xero', ['action' => 'items_setting'])) ?>" class="stack">
    <?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="push_products" value="1" <?= setting('xero_push_items') === '1' ? 'checked' : '' ?>> Send products to Xero</label>
    <label class="check"><input type="checkbox" name="push_on_save" value="1" <?= setting('xero_push_products') === '1' ? 'checked' : '' ?>> Also send a product automatically whenever it's created or saved</label>
    <?php if (setting('xero_push_items') === '1' && !xero_can_write_items()): ?><p class="text-warning">Xero hasn't been given permission to create items. Press <b>Reconnect</b> above.</p><?php endif; ?>
    <p class="help">Each product has its own sales and purchases nominal codes (on the product form). The codes below are the defaults for new products and for any product without its own.</p>
    <div class="form-grid">
      <label>Sales account code<input name="sales_account" value="<?= h(setting('xero_item_sales_account')) ?>" placeholder="e.g. 200" list="xero_sales_codes" autocomplete="off"></label>
      <label>Purchases account code<input name="purchase_account" value="<?= h(setting('xero_item_purchase_account')) ?>" placeholder="e.g. 310" list="xero_purchase_codes" autocomplete="off"></label>
      <label>Tax rate (Xero tax type)<input name="tax_type" value="<?= h(setting('xero_item_tax_type')) ?>" placeholder="e.g. OUTPUT2 (20% VAT on income)" list="xero_tax_types" autocomplete="off"></label>
    </div>
    <p class="help">Optional. They're used as the item's default account and VAT rate. The codes are on your Chart of accounts in Xero. This needs permission to manage items (the <code>accounting.settings</code> scope): after switching it on, press <b>Reconnect</b> and approve it.</p>
    <button class="btn">Save</button>
  </form>
  <?php if (xero_connected()): ?>
    <form method="post" action="<?= h(url('xero', ['action' => 'accounts'])) ?>" class="inline" style="margin-top:1rem"><?= csrf_field() ?>
      <button class="btn btn-sm">Load nominal codes from Xero</button>
      <span class="help"><?= ($n = count(nominal_codes())) ? "$n codes loaded. Product forms offer them and check codes against them." : 'Loads your chart of accounts and VAT rates, so the codes can be picked from a list and are checked.' ?></span>
    </form>
  <?php endif; ?>
</section>

<section class="card">
  <div class="card-head"><h2>Supplier bills</h2></div>
  <p>When a supplier invoice is approved to pay (Purchasing → Supplier invoices), send it to Xero as a <b>bill</b>, with the uploaded invoice attached. The lines come from its purchase order when the amounts agree, otherwise from the invoice.</p>
  <form method="post" action="<?= h(url('xero', ['action' => 'bills_setting'])) ?>" class="stack">
    <?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="push_bills" value="1" <?= setting('xero_push_bills') === '1' ? 'checked' : '' ?>> Send approved supplier invoices to Xero as bills</label>
    <label class="check"><input type="checkbox" name="bill_attach" value="1" <?= setting('xero_bill_attach', '1') === '1' ? 'checked' : '' ?>> Attach the uploaded invoice to the bill</label>
    <?php if (setting('xero_push_bills') === '1' && !xero_can_write_bills()): ?><p class="text-warning">Xero hasn't been given permission to create bills. Press <b>Reconnect</b> above.</p><?php endif; ?>
    <div class="form-grid">
      <label>Bills arrive in Xero as
        <select name="bill_status"><?php foreach (['DRAFT' => 'Draft', 'SUBMITTED' => 'Awaiting approval', 'AUTHORISED' => 'Awaiting payment'] as $k => $l): ?><option value="<?= $k ?>" <?= (setting('xero_bill_status') ?: 'DRAFT') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <label>Purchases account code<input name="bill_account" value="<?= h(setting('xero_bill_account')) ?>" placeholder="<?= h(setting('xero_item_purchase_account') ?: '310') ?>" list="xero_purchase_codes" autocomplete="off"></label>
      <label>20% VAT (standard rate)<input name="bill_tax_type" value="<?= h(setting('xero_bill_tax_type')) ?>" placeholder="INPUT2 (20% VAT on expenses)" list="xero_tax_types" autocomplete="off"></label>
    </div>
    <?php $rateMap = xero_bill_rate_map(); ?>
    <h3>VAT rates on bills</h3>
    <p class="help">Each line gets its own VAT: the product's <i>VAT on purchases</i>, else the rate printed on that line of the invoice, else the supplier's <i>Default VAT</i>, else the 20% rate above. These are the Xero tax types used for the rates printed on invoices:</p>
    <div class="form-grid">
      <label>5% (reduced rate)<input name="rate_5" value="<?= h($rateMap['5']) ?>" list="xero_tax_types" autocomplete="off"></label>
      <label>0% (zero rated)<input name="rate_0" value="<?= h($rateMap['0']) ?>" list="xero_tax_types" autocomplete="off"></label>
      <label>Exempt<input name="rate_exempt" value="<?= h($rateMap['exempt']) ?>" list="xero_tax_types" autocomplete="off"></label>
      <label>Reverse charge<input name="rate_rc" value="<?= h($rateMap['RC']) ?>" list="xero_tax_types" autocomplete="off" placeholder="Pick your reverse charge rate"></label>
    </div>
    <p class="help">Reverse charge: when an invoice or line says the reverse charge applies (e.g. wholesale telecoms, or services from abroad), this tax type is used, so you account for the VAT. Pick the matching rate from your Xero VAT rates: Load nominal codes from Xero fills the lists.</p>
    <p class="help">Each line is coded with, in order: the product's purchases nominal code, the supplier's default nominal code (on the supplier), or the code above. Bills go to the supplier's linked Xero contact, or Xero matches (or adds) one by name. This needs permission to create invoices and attachments: after switching it on, press <b>Reconnect</b> and approve.</p>
    <button class="btn">Save</button>
  </form>
</section>

<section class="card">
  <div class="card-head"><h2>Suppliers</h2>
    <form method="post" action="<?= h(url('suppliers', ['action' => 'xero_import'])) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="_return" value="<?= h(url('xero')) ?>"><button class="btn btn-sm">Bring in suppliers now</button></form></div>
  <p>Contacts Xero marks as <b>suppliers</b> (anyone you've entered a bill for) can be brought into the CRM's Suppliers. A supplier with the same name is linked rather than duplicated, and blank details (email, phone, address, website, payment terms) are filled in from Xero. Anything already typed in the CRM is kept.
    <?= ($n = (int)db_value('SELECT COUNT(*) FROM xero_contacts WHERE is_supplier = 1')) ? "Xero has $n supplier" . ($n === 1 ? '' : 's') . ' at the last sync.' : '' ?></p>
  <form method="post" action="<?= h(url('xero', ['action' => 'suppliers_setting'])) ?>" class="stack">
    <?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="import_suppliers" value="1" <?= setting('xero_import_suppliers') === '1' ? 'checked' : '' ?>> Bring in new suppliers from Xero on every sync</label>
    <button class="btn">Save</button>
  </form>
</section>

<section class="card">
  <div class="card-head"><h2>Invoice emails</h2></div>
  <p>Each customer's <b>accounts contact</b> in the CRM is who should get invoices and statements. The CRM can tell Xero, by setting the Xero contact's email address.</p>
  <form method="post" action="<?= h(url('xero', ['action' => 'push_setting'])) ?>" class="stack">
    <?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="push_contacts" value="1" <?= setting('xero_push_contacts') === '1' ? 'checked' : '' ?>> Update Xero automatically when a customer's accounts contact changes</label>
    <?php if (setting('xero_push_contacts') === '1' && !xero_can_write_contacts()): ?><p class="text-warning">Xero hasn't been given permission to update contacts. Press <b>Reconnect</b> above.</p><?php endif; ?>
    <p class="help">This needs permission to update contacts in Xero (the <code>accounting.contacts</code> scope instead of read-only). After switching it on, press <b>Reconnect</b> and approve the new permission. Nothing else in Xero is changed.</p>
    <button class="btn">Save</button>
  </form>
</section>
<?php endif; ?>

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

<?php foreach (['xero_sales_codes' => nominal_codes('sales'), 'xero_purchase_codes' => nominal_codes('purchases'), 'xero_tax_types' => xero_tax_rates()] as $listId => $options): if (!$options) continue; ?>
<datalist id="<?= $listId ?>"><?php foreach ($options as $code => $label): ?><option value="<?= h((string)$code) ?>"><?= h($label) ?></option><?php endforeach; ?></datalist>
<?php endforeach; ?>
