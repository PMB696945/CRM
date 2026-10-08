<div class="page-head"><div><div class="crumbs"><a href="<?= h(portal_url('customers')) ?>">Customers</a></div><h1>Add a customer</h1></div></div>
<?php if ($errors): ?><div class="flash flash-error">Please fix the highlighted fields.</div><?php endif; ?>
<form method="post" action="<?= h(portal_url('customer_new')) ?>" class="card form-grid">
  <?= csrf_field() ?>
  <?= portal_field($v, $errors, 'name', 'Customer name', 'text', 'The business, or the person for a home connection', true) ?>
  <div class="field"><label for="p_type">Type</label><select id="p_type" name="type"><option value="business">Business</option><option value="residential" <?= $v['type'] === 'residential' ? 'selected' : '' ?>>Residential</option></select></div>
  <?= portal_field($v, $errors, 'company_number', 'Company number (optional)') ?>
  <div class="form-section wide"><h2>Contact</h2></div>
  <?= portal_field($v, $errors, 'contact_name', 'Contact name', 'text', '', true) ?>
  <?= portal_field($v, $errors, 'email', 'Email', 'email') ?>
  <?= portal_field($v, $errors, 'phone', 'Phone', 'tel') ?>
  <div class="form-section wide"><h2>Address</h2></div>
  <?= portal_field($v, $errors, 'address', 'Address line 1') ?>
  <?= portal_field($v, $errors, 'address2', 'Address line 2') ?>
  <?= portal_field($v, $errors, 'city', 'Town') ?>
  <?= portal_field($v, $errors, 'postcode', 'Postcode', 'text', '', true) ?>
  <div class="form-actions wide"><button class="btn btn-primary">Add customer</button> <a class="btn btn-ghost" href="<?= h(portal_url('customers')) ?>">Cancel</a></div>
</form>
