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
  <?= $f('crd', 'Required by', 'date', $leadSource === 'lead time'
      ? 'Giacom\'s earliest date for this product: ' . fmt_date($lead) . ($product['leadtime']['days'] ? ' (' . (int)$product['leadtime']['days'] . ' working days)' : '')
      : ($leadSource === 'appointment' ? 'Giacom\'s first engineer appointment: ' . fmt_date($lead) : 'Giacom didn\'t give an earliest date for this product, so this is a guess. Check the lead time with Giacom.'), true) ?>
  <?php if ($appointments): ?>
    <div class="field"><span class="help"><b>Engineer appointments offered:</b> <?= h(implode(', ', array_map(fn($a) => fmt_date($a['date']) . ($a['slot'] ? ' ' . $a['slot'] : ''), array_slice($appointments, 0, 8)))) ?></span></div>
  <?php endif; ?>
  <div class="field <?= isset($errors['care_level']) ? 'has-error' : '' ?>"><label for="g_care">Care level</label>
    <select id="g_care" name="care_level"><?php foreach ($levels as $l): ?><option value="<?= h($l) ?>" <?= $values['care_level'] === $l ? 'selected' : '' ?>><?= h(GIACOM_CARE_LEVELS[$l] ?? ucfirst($l)) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="g_visit">Engineer visit</label>
    <select id="g_visit" name="site_visit_reason"><?php foreach (['NO_SITE_VISIT' => 'Not needed', 'STANDARD_INSTALL' => 'Standard install', 'PREMIUM_INSTALL' => 'Premium install'] as $k => $l): ?><option value="<?= $k ?>" <?= $values['site_visit_reason'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <div class="help">Giacom may still require a visit for some orders.</div></div>
  <?php if (str_contains($product['technology'], 'fttp')): ?>
  <div class="field"><label for="g_ont">FTTP ONT</label>
    <select id="g_ont" name="force_new_ont"><option value="">Giacom decides</option><option value="Y" <?= $values['force_new_ont'] === 'Y' ? 'selected' : '' ?>>New ONT</option><option value="N" <?= $values['force_new_ont'] === 'N' ? 'selected' : '' ?>>Use existing ONT</option></select></div>
  <?php endif; ?>

  <div class="form-section wide"><h2>Broadband login</h2></div>
  <?= $f('bb_username', 'Username', 'text', 'Without the realm – that\'s sent separately', true) ?>
  <?= $f('bb_password', 'Password', 'text', 'Saved with Giacom only. Give it to the customer or put it on the router.', true) ?>
  <?= $f('realm', 'Realm', 'text', 'Usually the part after @ in the username') ?>
  <?= $f('client_ref', 'Your reference (optional)', 'text', 'Added after the account number, e.g. a PO or quote reference') ?>

  <div class="form-section wide"><h2>Contact at the address</h2><p class="help">Giacom and the carrier use this for access and appointments. Filled in from the <?= $site ? 'site' : 'main' ?> contact.</p></div>
  <?= $f('title', 'Title') ?>
  <?= $f('forename', 'First name', 'text', '', true) ?>
  <?= $f('surname', 'Surname', 'text', '', true) ?>
  <?= $f('telephone', 'Phone', 'tel', '', true) ?>
  <?= $f('email', 'Email', 'email') ?>

  <div class="form-section wide"><h2>In the CRM</h2><p class="help">A pending service is added to <?= h($account['name']) ?><?= $site ? ' at ' . h($site['name']) : '' ?>, and made active when Giacom completes the order.</p></div>
  <div class="field"><label for="g_prod">Your product / tariff (optional)</label>
    <select id="g_prod" name="crm_product_id"><option value="">—</option><?php foreach ($crmProducts as $id => $l): ?><option value="<?= (int)$id ?>" <?= (string)$values['crm_product_id'] === (string)$id ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
    <div class="help">Sets the price and term on the new service.</div></div>

  <div class="form-actions wide">
    <button class="btn btn-primary">Place order with Giacom</button>
    <a class="btn btn-ghost" href="<?= h(url('giacom', ['action' => 'result', 'id' => $check['id']])) ?>">Cancel</a>
  </div>
</form>
