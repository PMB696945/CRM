<?php
$f = function (string $name, string $label, string $type = 'text', string $help = '', bool $required = false) use ($values, $errors): string {
    $err = $errors[$name] ?? null;
    return '<div class="field ' . ($err ? 'has-error' : '') . '"><label for="g_' . $name . '">' . h($label) . ($required ? ' <span class="req">*</span>' : '') . '</label>'
        . '<input id="g_' . $name . '" type="' . $type . '" name="' . $name . '" value="' . h($values[$name]) . '"' . ($required ? ' required' : '') . ' autocomplete="off">'
        . ($err ? '<div class="error">' . h($err) . '</div>' : ($help ? '<div class="help">' . h($help) . '</div>' : '')) . '</div>';
};
$levels = $product['care_levels'] ?: array_keys(GIACOM_CARE_LEVELS);
?>
<div class="page-head"><div>
  <div class="crumbs"><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $account['id']])) ?>"><?= h($account['name']) ?></a> · <a href="<?= h(url('giacom', ['action' => 'result', 'id' => $check['id']])) ?>">Availability</a></div>
  <h1>Order <?= h($product['name']) ?></h1>
  <p class="muted"><?= h(strtoupper($product['technology'])) ?> · <?= h($check['address_label']) ?></p></div></div>
<?php if (!empty($errors['_'])): ?><div class="flash flash-error"><?= h($errors['_']) ?></div><?php elseif ($errors): ?><div class="flash flash-error">Please fix the highlighted fields.</div><?php endif; ?>

<form method="post" class="card form-grid" data-confirm="Place this order with Giacom now? It's a real order and may incur charges.">
  <?= csrf_field() ?>
  <div class="field wide">
    <div class="choice-cards">
      <label><input type="radio" name="order_type" value="provide" <?= $values['order_type'] === 'provide' ? 'checked' : '' ?>><span><b>New service (provide)</b><span class="help">Nothing to take over at this address, or a new line.</span></span></label>
      <label><input type="radio" name="order_type" value="migrate" <?= $values['order_type'] === 'migrate' ? 'checked' : '' ?>><span><b>Take over existing (migrate)</b><span class="help">Moving an existing broadband service or line to you.</span></span></label>
    </div>
  </div>
  <?= $f('cli', 'Phone number on the line', 'tel', 'Needed for a migrate. Leave blank for a new provide with no line.') ?>
  <div data-when="order_type=migrate"><?= $f('access_line_id', 'Access line ID (optional)', 'text', 'For SOGEA/FTTP take-overs, if you have it') ?></div>
  <div class="form-section wide"><h2>Install date</h2>
    <p class="help"><?= $appointments
        ? 'Giacom\'s earliest install date for this product here is <b>' . h(fmt_date($appointments[0]['date'])) . ($appointments[0]['slot'] ? ' (' . h($appointments[0]['slot']) . ')' : '') . '</b>. Choose it or another date below.'
        : ($leadSource === 'lead time' ? 'Giacom\'s earliest date for this product: <b>' . h(fmt_date($lead)) . '</b>.' : 'Giacom didn\'t offer any install dates' . ($appointmentsError ? ' (' . h($appointmentsError) . ')' : '') . '. Enter the date you need.') ?></p></div>
  <?php if ($appointments): ?>
    <div class="field wide <?= isset($errors['appointment']) ? 'has-error' : '' ?>">
      <div class="appointment-grid">
        <?php foreach ($appointments as $i => $a): $key = giacom_appointment_key($a); ?>
          <label class="check"><input type="radio" name="appointment" value="<?= h($key) ?>" <?= $values['appointment'] === $key ? 'checked' : '' ?>>
            <span><b><?= h(date('D j M', strtotime($a['date']))) ?></b> <?= h($a['slot']) ?><?= $i === 0 ? ' <span class="badge badge-active">Earliest</span>' : '' ?></span></label>
        <?php endforeach; ?>
        <label class="check"><input type="radio" name="appointment" value="" <?= $values['appointment'] === '' ? 'checked' : '' ?>><span>Another date (enter below)</span></label>
      </div>
      <?php if (isset($errors['appointment'])): ?><div class="error"><?= h($errors['appointment']) ?></div><?php endif; ?>
    </div>
    <div data-when="appointment="><?= $f('crd', 'Required by (if not one of the dates above)', 'date', 'Giacom will fit the install around this date') ?></div>
  <?php else: ?>
    <?= $f('crd', 'Required by', 'date', $leadSource === 'lead time' ? 'Earliest: ' . fmt_date($lead) : 'Check the lead time with Giacom', true) ?>
  <?php endif; ?>
  <div class="field"><label for="g_visit">Engineer visit</label>
    <select id="g_visit" name="site_visit_reason"><?php foreach (['NO_SITE_VISIT' => 'Not needed', 'STANDARD' => 'Standard install', 'PREMIUM' => 'Premium install'] as $k => $l): ?><option value="<?= $k ?>" <?= $values['site_visit_reason'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <div class="help"><?php $min = $result['min_visit'][$values['order_type'] === 'migrate' ? 'existing_line' : 'new_line'] ?? null; if ($min): ?>Giacom says this address needs at least a <b><?= h(['NO_SITE_VISIT' => 'no visit', 'STANDARD' => 'standard install', 'PREMIUM' => 'premium install'][$min]) ?></b> for a <?= $values['order_type'] === 'migrate' ? 'existing' : 'new' ?> line. <?php endif; ?>The dates on offer depend on this. <button class="btn btn-sm" name="refresh" value="1" formnovalidate data-skip-confirm>Show dates for this</button></div></div>
  <div class="field <?= isset($errors['care_level']) ? 'has-error' : '' ?>"><label for="g_care">Care level</label>
    <select id="g_care" name="care_level"><?php foreach ($levels as $l): ?><option value="<?= h($l) ?>" <?= $values['care_level'] === $l ? 'selected' : '' ?>><?= h(GIACOM_CARE_LEVELS[$l] ?? ucfirst($l)) ?></option><?php endforeach; ?></select></div>
  <?php if (str_contains($product['technology'], 'fttp') || ($product['tech_label'] ?? '') === 'FTTP'): ?>
  <div class="field"><label for="g_ont">FTTP ONT</label>
    <select id="g_ont" name="force_new_ont"><option value="">Giacom decides</option><option value="Y" <?= $values['force_new_ont'] === 'Y' ? 'selected' : '' ?>>New ONT</option><option value="N" <?= $values['force_new_ont'] === 'N' ? 'selected' : '' ?>>Use existing ONT</option></select></div>
  <?php endif; ?>

  <div class="form-section wide"><h2>Broadband login</h2></div>
  <?= $f('bb_username', 'Username', 'text', 'e.g. joebloggs', true) ?>
  <?= $f('bb_password', 'Password', 'text', 'Saved with Giacom only. Give it to the customer or put it on the router.', true) ?>
  <?= $f('bb_suffix', 'Added after the username', 'text', 'The part Giacom puts in front of the realm on your account, e.g. -Finn') ?>
  <?= $f('realm', 'Realm', 'text', !empty($product['realms']) ? 'Giacom offers: ' . implode(', ', $product['realms']) : 'e.g. surfdsluk (the part after the @)', true) ?>
  <div class="field wide"><div class="help">Full username sent to Giacom: <b data-full-username><?= h(giacom_full_username($values['bb_username'] ?: 'username', $values['bb_suffix'], $values['realm'] ?: 'realm')) ?></b></div></div>
  <?= $f('client_ref', 'Your reference (optional)', 'text', 'Added after the account number, e.g. a PO or quote reference') ?>

  <div class="form-section wide"><h2>Customer</h2><p class="help">The end user Giacom registers the service to. Filled in from the main contact.</p></div>
  <?= $f('title', 'Title') ?>
  <?= $f('forename', 'First name', 'text', '', true) ?>
  <?= $f('surname', 'Surname', 'text', '', true) ?>
  <?= $f('telephone', 'Phone', 'tel', '', true) ?>
  <?= $f('email', 'Email', 'email') ?>

  <div class="form-section wide"><h2>Site contact</h2><p class="help">Who Giacom and the engineer contact for access and appointments at the address. Filled in from the <?= $site && $site['contact_id'] ? 'site' : 'main' ?> contact.</p></div>
  <?= $f('site_title', 'Title') ?>
  <?= $f('site_forename', 'First name', 'text', '', true) ?>
  <?= $f('site_surname', 'Surname', 'text', '', true) ?>
  <?= $f('site_telephone', 'Phone', 'tel', '', true) ?>
  <?= $f('site_email', 'Email', 'email') ?>
  <?= $f('site_passphrase', 'Pass phrase (optional)', 'text', 'For the engineer to quote on arrival') ?>
  <?= $f('site_notes', 'Access notes (optional)', 'text', 'e.g. parking, which entrance, opening hours') ?>
  <?= $f('hazard_notes', 'Hazards (optional)', 'text', 'Anything the engineer should know about safety') ?>

  <div class="form-section wide"><h2>In the CRM</h2><p class="help">A pending service is added to <?= h($account['name']) ?><?= $site ? ' at ' . h($site['name']) : '' ?>, and made active when Giacom completes the order.</p></div>
  <div class="field"><label for="g_prod">Your product / tariff (optional)</label>
    <select id="g_prod" name="crm_product_id"><option value="">—</option><?php foreach ($crmProducts as $id => $l): ?><option value="<?= (int)$id ?>" <?= (string)$values['crm_product_id'] === (string)$id ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
    <div class="help">Sets the price and term on the new service.</div></div>

  <div class="form-actions wide">
    <button class="btn btn-primary">Place order with Giacom</button>
    <a class="btn btn-ghost" href="<?= h(url('giacom', ['action' => 'result', 'id' => $check['id']])) ?>">Cancel</a>
  </div>
</form>
