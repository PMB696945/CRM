<?php
$sel = fn(string $key, $v) => in_array((string)$v, array_map('strval', $filters[$key] ?? []), true) ? 'selected' : '';
$cancel = $campaign ? url('campaigns', ['action' => 'view', 'id' => $campaign['id']]) : url('campaigns');
?>
<div class="page-head"><h1><?= h($campaign ? 'Edit ' . $campaign['reference'] : 'New email to customers') ?></h1></div>
<?php if ($errors): ?><div class="flash flash-error">Please fix the highlighted fields.</div><?php endif; ?>

<form method="post" class="card form-grid">
  <?= csrf_field() ?>
  <div class="field wide">
    <div class="choice-cards">
      <label><input type="radio" name="kind" value="service_alert" <?= $values['kind'] === 'service_alert' ? 'checked' : '' ?>>
        <span><b>Service alert</b><span class="help">Faults, planned maintenance, outages, changes to a service. Goes to customers with matching live services unless they've opted out of alerts.</span></span></label>
      <label><input type="radio" name="kind" value="marketing" <?= $values['kind'] === 'marketing' ? 'checked' : '' ?>>
        <span><b>Marketing</b><span class="help">News and offers. Only goes to contacts who opted in to email marketing (and the chosen topic).</span></span></label>
    </div>
  </div>

  <div class="form-section wide"><h2>Who gets it</h2><p class="help">Leave a list empty to include everything. Use Ctrl/⌘-click to pick several. You'll see the exact recipients before anything is sent.</p></div>
  <div class="field">
    <label for="c_types">Customers with live services of type</label>
    <select id="c_types" name="service_types[]" multiple size="6">
      <?php foreach (SERVICE_TYPES as $k => $label): ?><option value="<?= h($k) ?>" <?= $sel('service_types', $k) ?>><?= h($label) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="c_carriers">On carrier / network</label>
    <select id="c_carriers" name="carriers[]" multiple size="6">
      <?php foreach (CARRIERS as $c): ?><option value="<?= h($c) ?>" <?= $sel('carriers', $c) ?>><?= h($c) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="c_products">On product / tariff</label>
    <select id="c_products" name="product_ids[]" multiple size="6">
      <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $sel('product_ids', $p['id']) ?>><?= h($p['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="c_postcodes">Site postcodes starting with</label>
    <input id="c_postcodes" name="postcodes" value="<?= h($filters['postcodes']) ?>" placeholder="e.g. M1, M2, SK4">
    <div class="help">For local outages. Uses the installation site's postcode, or head office when a service has no site.</div>
    <label class="check" style="margin-top:.75rem" for="c_status">Customer status</label>
    <select id="c_status" name="statuses[]" multiple size="3">
      <?php foreach (['active' => 'Active', 'suspended' => 'Suspended', 'prospect' => 'Prospect'] as $k => $label): ?><option value="<?= $k ?>" <?= $sel('statuses', $k) ?>><?= $label ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="c_type">Customer type</label>
    <select id="c_type" name="account_type"><option value="">Business and residential</option>
      <option value="business" <?= $filters['account_type'] === 'business' ? 'selected' : '' ?>>Business only</option>
      <option value="residential" <?= $filters['account_type'] === 'residential' ? 'selected' : '' ?>>Residential only</option></select>
  </div>
  <div class="field">
    <label for="c_dealer">Dealer</label>
    <select id="c_dealer" name="dealer_id"><option value="">Any</option>
      <?php foreach ($dealers as $id => $name): ?><option value="<?= (int)$id ?>" <?= (int)$filters['dealer_id'] === (int)$id ? 'selected' : '' ?>><?= h($name) ?> and their customers</option><?php endforeach; ?></select>
    <?php if ($filters['account_id']): ?>
      <input type="hidden" name="account_id" value="<?= (int)$filters['account_id'] ?>">
      <div class="help">Only for <b><?= h($accountName) ?></b>.</div>
    <?php endif; ?>
  </div>
  <div class="field" data-when="kind=service_alert">
    <label for="c_who">Who at each customer</label>
    <select id="c_who" name="who">
      <?php foreach (ALERT_AUDIENCES as $k => $label): ?><option value="<?= $k ?>" <?= ($filters['who'] ?? 'main_site') === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field" data-when="kind=marketing">
    <label for="c_topic">Topic</label>
    <select id="c_topic" name="topic"><option value="">General (everyone opted in to email marketing)</option>
      <?php foreach (marketing_topics() as $k => $label): ?><option value="<?= h($k) ?>" <?= ($filters['topic'] ?? '') === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
    </select>
    <div class="help">Contacts who haven't picked topics get every topic.</div>
  </div>

  <div class="form-section wide"><h2>Message</h2></div>
  <div class="field wide" data-when="kind=marketing">
    <label>Send using</label>
    <div class="choice-cards">
      <label><input type="radio" name="channel" value="email" <?= $values['channel'] !== 'mailchimp' ? 'checked' : '' ?>><span><b>The CRM's email</b><span class="help">Sent one by one from your email settings.</span></span></label>
      <label><input type="radio" name="channel" value="mailchimp" <?= $values['channel'] === 'mailchimp' ? 'checked' : '' ?> <?= mailchimp_configured() ? '' : 'disabled' ?>><span><b>Mailchimp</b><span class="help"><?= mailchimp_configured() ? 'Recipients are added to your Mailchimp audience and sent a Mailchimp campaign, with Mailchimp\'s reports and unsubscribe handling.' : 'Connect Mailchimp under Admin → Mailchimp first.' ?></span></span></label>
    </div>
    <?php if (isset($errors['channel'])): ?><div class="error"><?= h($errors['channel']) ?></div><?php endif; ?>
  </div>
  <div class="field wide <?= isset($errors['subject']) ? 'has-error' : '' ?>">
    <label for="c_subject">Subject <span class="req">*</span></label>
    <input id="c_subject" name="subject" value="<?= h($values['subject']) ?>" maxlength="200" required>
    <?php if (isset($errors['subject'])): ?><div class="error"><?= h($errors['subject']) ?></div><?php endif; ?>
  </div>
  <div class="field wide <?= isset($errors['body']) ? 'has-error' : '' ?>">
    <label for="c_body">Message <span class="req">*</span></label>
    <textarea id="c_body" name="body" rows="14" required><?= h($values['body']) ?></textarea>
    <?php if (isset($errors['body'])): ?><div class="error"><?= h($errors['body']) ?></div><?php endif; ?>
    <div class="help">Plain text; leave a blank line between paragraphs. Links are clickable. Personalise with
      <code>{{first_name}}</code> <code>{{name}}</code> <code>{{company}}</code> <code>{{account_number}}</code>, and <code>{{services}}</code> to list each customer's affected services (not available through Mailchimp).
      An unsubscribe link is added automatically.</div>
  </div>
  <div class="form-actions wide">
    <button class="btn btn-primary">Save and check recipients</button>
    <a class="btn btn-ghost" href="<?= h($cancel) ?>">Cancel</a>
  </div>
</form>
