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
  <div class="form-section wide"><h2>Install</h2><p class="help">Choose the engineer visit first: the install dates Giacom offers depend on it.</p></div>
  <div class="field"><label for="g_visit">Engineer visit</label>
    <?php $min = giacom_min_visit($result, $values['order_type']); $rank = array_flip(array_keys(GIACOM_VISITS)); ?>
    <select id="g_visit" name="site_visit_reason" data-min-provide="<?= h((string)giacom_min_visit($result, 'provide')) ?>" data-min-migrate="<?= h((string)giacom_min_visit($result, 'migrate')) ?>">
      <?php foreach (GIACOM_VISITS as $k => $l): $below = $min && $rank[$k] < $rank[$min]; ?><option value="<?= $k ?>" <?= $values['site_visit_reason'] === $k ? 'selected' : '' ?><?= $below ? ' hidden disabled' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <div class="help"><?php if ($min && $min !== 'NO_SITE_VISIT'): ?>Giacom says this address needs at least a <b><?= h(strtolower(GIACOM_VISITS[$min])) ?></b> for a <?= $values['order_type'] === 'migrate' ? 'take-over of the existing' : 'new' ?> line, so less isn't offered. <?php endif; ?><span data-dates-note>The dates below are Giacom's for this visit, and are fetched again when it's changed.</span> <button class="btn btn-sm" name="refresh" value="1" formnovalidate data-skip-confirm data-refresh-dates>Show dates for this</button></div></div>

  <?php $earliest = $appointments ? $appointments[0]['date'] : ($leadSource === 'lead time' ? $lead : date('Y-m-d', strtotime('+1 weekday'))); ?>
  <div class="field <?= isset($errors['crd']) ? 'has-error' : '' ?>"><label for="g_crd">Required by <span class="req">*</span></label>
    <input id="g_crd" type="date" name="crd" value="<?= h($values['crd']) ?>" min="<?= h($earliest) ?>" required data-appointment-date>
    <?php if (isset($errors['crd'])): ?><div class="error"><?= h($errors['crd']) ?></div><?php endif; ?>
    <div class="help"><?= $appointments
        ? 'Giacom\'s earliest appointment here is <b>' . h(date('D j M Y', strtotime($earliest))) . '</b>. Choose a later date if the customer needs it; earlier dates aren\'t available.'
        : ($leadSource === 'lead time' ? 'Giacom\'s earliest date for this product is <b>' . h(fmt_date($earliest)) . '</b>.' : 'Giacom didn\'t offer any install dates' . ($appointmentsError ? ' (' . h($appointmentsError) . ')' : '') . '. Enter the date you need.') ?></div></div>
  <?php if ($appointments): ?>
    <div class="field wide <?= isset($errors['appointment']) ? 'has-error' : '' ?>" data-appointment-slots>
      <label>Appointment</label>
      <div class="appointment-grid">
        <?php foreach ($appointments as $i => $a): $key = giacom_appointment_key($a); ?>
          <label class="check" data-slot-date="<?= h($a['date']) ?>"><input type="radio" name="appointment" value="<?= h($key) ?>" <?= $values['appointment'] === $key ? 'checked' : '' ?>>
            <span><b><?= h(date('D j M', strtotime($a['date']))) ?></b> <?= h($a['slot']) ?><?= $i === 0 ? ' <span class="badge badge-active">Earliest</span>' : '' ?></span></label>
        <?php endforeach; ?>
        <label class="check" data-slot-none hidden><input type="radio" name="appointment" value="" <?= $values['appointment'] === '' ? 'checked' : '' ?>><span>No set slot</span></label>
      </div>
      <div class="help" data-slot-none-note hidden>Giacom hasn't offered a slot on this date, so it'll fit the install around it.</div>
      <?php if (isset($errors['appointment'])): ?><div class="error"><?= h($errors['appointment']) ?></div><?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="field <?= isset($errors['care_level']) ? 'has-error' : '' ?>"><label for="g_care">Care level</label>
    <select id="g_care" name="care_level"><?php foreach ($levels as $l): ?><option value="<?= h($l) ?>" <?= $values['care_level'] === $l ? 'selected' : '' ?>><?= h(GIACOM_CARE_LEVELS[$l] ?? ucfirst($l)) ?></option><?php endforeach; ?></select></div>
  <?php if (giacom_is_fttp($product)): ?>
  <div class="field"><label for="g_ont">FTTP ONT</label>
    <select id="g_ont" name="force_new_ont" data-ont-follows-order-type><option value="Y" <?= $values['force_new_ont'] === 'Y' ? 'selected' : '' ?>>New ONT</option><option value="N" <?= $values['force_new_ont'] === 'N' ? 'selected' : '' ?>>Use existing ONT</option></select>
    <div class="help">Set from the order type: a new service gets a new ONT, a take-over uses the existing one. Change it if needed.</div></div>
  <?php endif; ?>

  <div class="form-section wide"><h2>Broadband login</h2></div>
  <div class="field <?= isset($errors['ip_option']) ? 'has-error' : '' ?>"><label for="g_ip">IP address</label>
    <select id="g_ip" name="ip_option"><?php foreach (GIACOM_IP_OPTIONS as $k => [$l]): ?><option value="<?= $k ?>" <?= $values['ip_option'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
    <?php if (isset($errors['ip_option'])): ?><div class="error"><?= h($errors['ip_option']) ?></div><?php endif; ?>
    <div class="help">A block of static IPs is requested from Giacom as soon as the order is placed.</div></div>
  <?= $f('bb_username', 'Username', 'text', 'e.g. joebloggs', true) ?>
  <?= $f('bb_password', 'Password', 'text', 'Saved with Giacom only. Give it to the customer or put it on the router.', true) ?>
  <div class="field wide"><div class="help">Full username sent to Giacom: <b data-full-username data-suffix="<?= h($values['bb_suffix']) ?>" data-realm="<?= h($values['realm']) ?>"><?= h(giacom_full_username($values['bb_username'] ?: 'username', $values['bb_suffix'], $values['realm'] ?: 'realm')) ?></b>
    <br>The part after the username is set for your Giacom account<?= can('settings.manage') ? ' under <a href="' . h(url('giacom', ['action' => 'settings'])) . '">Admin → Giacom</a>' : '' ?>.</div></div>
  <?= $f('client_ref', 'Order reference (optional)', 'text', 'Giacom\'s reference for this order, e.g. a PO or quote reference. Leave blank to use the account number (' . $account['account_number'] . ')') ?>

  <div class="form-section wide"><h2>Customer</h2><p class="help">The end user Giacom registers the service to. Filled in from the main contact.</p></div>
  <?= $f('title', 'Title') ?>
  <?= $f('forename', 'First name', 'text', '', true) ?>
  <?= $f('surname', 'Surname', 'text', '', true) ?>
  <?= $f('telephone', 'Phone', 'tel', '', true) ?>
  <?= $f('email', 'Email', 'email', 'Giacom sends order updates here. It can\'t be added after the order is placed.', true) ?>

  <div class="form-section wide"><div class="card-head"><h2>Site contact</h2>
      <button type="button" class="btn btn-sm" data-copy-contact="title:site_title,forename:site_forename,surname:site_surname,telephone:site_telephone,email:site_email">Use the main contact</button></div>
    <p class="help">Who Giacom and the engineer contact for access and appointments at the address. Filled in from the <?= $site && $site['contact_id'] ? 'site' : 'main' ?> contact.</p></div>
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

  <div class="field field-check wide"><label><input type="checkbox" name="send_confirmation" value="1" <?= $values['send_confirmation'] === '1' ? 'checked' : '' ?>> Email the customer an order confirmation</label>
    <div class="help">Sent to the customer email above once the order is placed: the product, address, install date and broadband setup details (username, password and IP), in your company's name.</div></div>

  <div class="form-actions wide">
    <button class="btn btn-primary">Place order with Giacom</button>
    <a class="btn btn-ghost" href="<?= h(url('giacom', ['action' => 'result', 'id' => $check['id']])) ?>">Cancel</a>
  </div>
</form>
