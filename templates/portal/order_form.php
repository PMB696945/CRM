<?php
$f = fn(string $name, string $label, string $type = 'text', string $help = '', bool $required = false) => portal_field($values, $errors, $name, $label, $type, $help, $required);
$p = $offer['product'];
$sp = $offer['supplier'];
$visits = ['NO_SITE_VISIT' => 'Not needed', 'STANDARD' => 'Standard install', 'PREMIUM' => 'Premium install'];
$rank = array_flip(array_keys($visits));
$earliest = $appointments ? $appointments[0]['date'] : $lead;
?>
<div class="page-head"><div>
  <div class="crumbs"><a href="<?= h(portal_url('result', ['check' => $check['id']])) ?>">Availability</a> · <?= h($customer['name']) ?></div>
  <h1>Order <?= h($p['name']) ?></h1>
  <p class="muted"><?= h($check['address_label']) ?> · <?= h(money($p['dealer_price'])) ?> a month<?= (float)($p['dealer_setup_fee'] ?? 0) > 0 ? ', ' . h(money($p['dealer_setup_fee'])) . ' setup' : '' ?> · <?= h(term_label((int)$p['term_months'])) ?></p></div></div>
<?php if (!empty($errors['_'])): ?><div class="flash flash-error"><?= h($errors['_']) ?></div><?php elseif ($errors): ?><div class="flash flash-error">Please fix the highlighted fields.</div><?php endif; ?>

<form method="post" action="<?= h(portal_url('order_new', ['check' => $check['id'], 'product' => $p['id']])) ?>" class="card form-grid" data-confirm="Send this order? We'll email you its agreement to sign, then check and place it.">
  <?= csrf_field() ?>
  <div class="field wide">
    <div class="choice-cards">
      <label><input type="radio" name="order_type" value="provide" <?= $values['order_type'] === 'provide' ? 'checked' : '' ?>><span><b>New service</b><span class="help">Nothing to take over at this address, or a new line.</span></span></label>
      <label><input type="radio" name="order_type" value="migrate" <?= $values['order_type'] === 'migrate' ? 'checked' : '' ?>><span><b>Take over existing</b><span class="help">Moving the customer's existing broadband or line.</span></span></label>
    </div>
  </div>
  <?= $f('cli', 'Phone number on the line', 'tel', 'Needed to take over a line. Leave blank for a new service with no line.') ?>
  <?= $f('client_ref', 'Your reference (optional)', 'text', 'e.g. your order or PO number') ?>

  <div class="form-section wide"><h2>Install</h2><p class="help">Choose the engineer visit first: the install dates depend on it.</p></div>
  <div class="field"><label for="p_visit">Engineer visit</label>
    <select id="p_visit" name="site_visit_reason" data-min-provide="<?= h((string)giacom_min_visit($result, 'provide')) ?>" data-min-migrate="<?= h((string)giacom_min_visit($result, 'migrate')) ?>">
      <?php foreach ($visits as $k => $l): $below = $minVisit && $rank[$k] < $rank[$minVisit]; ?><option value="<?= $k ?>" <?= $values['site_visit_reason'] === $k ? 'selected' : '' ?><?= $below ? ' hidden disabled' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <div class="help"><?php if ($minVisit && $minVisit !== 'NO_SITE_VISIT'): ?>This address needs at least a <b><?= h(strtolower($visits[$minVisit])) ?></b>, so less isn't offered. <?php endif; ?><span data-dates-note>The dates below are for this visit, and are fetched again when it's changed.</span>
      <button class="btn btn-sm" name="refresh" value="1" formnovalidate data-skip-confirm data-refresh-dates>Show dates for this</button></div></div>

  <div class="field <?= isset($errors['crd']) ? 'has-error' : '' ?>"><label for="p_crd">Required by <span class="req">*</span></label>
    <input id="p_crd" type="date" name="crd" value="<?= h($values['crd']) ?>" min="<?= h($earliest) ?>" required data-appointment-date>
    <?php if (isset($errors['crd'])): ?><div class="error"><?= h($errors['crd']) ?></div><?php endif; ?>
    <div class="help"><?= $appointments ? 'The earliest appointment is <b>' . h(date('D j M Y', strtotime($earliest))) . '</b>. Choose a later date if your customer needs it.'
        : 'The earliest date is <b>' . h(fmt_date($earliest)) . '</b>' . ($slotsError ? '. Appointments will be confirmed once the order is placed.' : '.') ?></div></div>
  <?php if ($appointments): ?>
    <div class="field wide" data-appointment-slots>
      <label>Appointment</label>
      <div class="appointment-grid">
        <?php foreach ($appointments as $i => $a): $key = giacom_appointment_key($a); ?>
          <label class="check" data-slot-date="<?= h($a['date']) ?>"><input type="radio" name="appointment" value="<?= h($key) ?>" <?= $values['appointment'] === $key ? 'checked' : '' ?>>
            <span><b><?= h(date('D j M', strtotime($a['date']))) ?></b> <?= h($a['slot']) ?><?= $i === 0 ? ' <span class="badge badge-active">Earliest</span>' : '' ?></span></label>
        <?php endforeach; ?>
        <label class="check" data-slot-none hidden><input type="radio" name="appointment" value="" <?= $values['appointment'] === '' ? 'checked' : '' ?>><span>No set slot</span></label>
      </div>
      <div class="help" data-slot-none-note hidden>There's no slot on this date, so the install will be fitted around it.</div>
      <div class="help">Appointments are requested when we place the order, and confirmed if still available.</div>
    </div>
  <?php endif; ?>
  <?php if (giacom_is_fttp($sp)): ?>
  <div class="field"><label for="p_ont">Fibre box (ONT)</label>
    <select id="p_ont" name="force_new_ont" data-ont-follows-order-type><option value="Y" <?= $values['force_new_ont'] === 'Y' ? 'selected' : '' ?>>New ONT</option><option value="N" <?= $values['force_new_ont'] === 'N' ? 'selected' : '' ?>>Use existing ONT</option></select>
    <div class="help">A new service gets a new ONT; a take-over uses the existing one.</div></div>
  <?php endif; ?>

  <div class="form-section wide"><h2>Customer contact</h2><p class="help">Who the service is for. Filled in from the customer's details.</p></div>
  <?= $f('title', 'Title') ?>
  <?= $f('forename', 'First name', 'text', '', true) ?>
  <?= $f('surname', 'Surname', 'text', '', true) ?>
  <?= $f('telephone', 'Phone', 'tel', '', true) ?>
  <?= $f('email', 'Email', 'email', 'Order updates are sent here.', true) ?>

  <div class="form-section wide"><div class="card-head"><h2>Site contact</h2>
      <button type="button" class="btn btn-sm" data-copy-contact="title:site_title,forename:site_forename,surname:site_surname,telephone:site_telephone,email:site_email">Use the customer contact</button></div>
    <p class="help">Who the engineer contacts for access and appointments at the address.</p></div>
  <?= $f('site_title', 'Title') ?>
  <?= $f('site_forename', 'First name', 'text', '', true) ?>
  <?= $f('site_surname', 'Surname', 'text', '', true) ?>
  <?= $f('site_telephone', 'Phone', 'tel', '', true) ?>
  <?= $f('site_email', 'Email', 'email') ?>
  <?= $f('site_passphrase', 'Pass phrase (optional)', 'text', 'For the engineer to quote on arrival') ?>
  <?= $f('site_notes', 'Access notes (optional)', 'text', 'e.g. parking, which entrance, opening hours') ?>
  <?= $f('hazard_notes', 'Hazards (optional)', 'text', 'Anything the engineer should know about safety') ?>

  <div class="form-actions wide">
    <button class="btn btn-primary">Send order</button>
    <a class="btn btn-ghost" href="<?= h(portal_url('result', ['check' => $check['id']])) ?>">Cancel</a>
    <p class="help">Orders are checked by our team before they're placed. You'll be emailed an agreement for this order to sign: it's between <?= h($user['dealer_name']) ?> and <?= h(company('name', config('app_name'))) ?>, under your master terms.</p>
  </div>
</form>
