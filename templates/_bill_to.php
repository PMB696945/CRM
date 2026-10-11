<?php
/**
 * Who an order is billed to, for a customer under a dealer. Expects $account and $toDealer (current choice);
 * optional $form (the form id, for a field outside its form). Shows nothing for customers without a dealer.
 */
$dealer = account_dealer($account);
if (!$dealer) {
    return;
}
$formAttr = !empty($form) ? ' form="' . h($form) . '"' : '';
?>
<div class="field wide">
  <label>Bill this order to</label>
  <div class="choice-cards">
    <label><input type="radio" name="bill_to" value="customer"<?= $formAttr ?> <?= $toDealer ? '' : 'checked' ?>><span><b>The customer</b><span class="help"><?= h($account['name']) ?> pays us directly.</span></span></label>
    <label><input type="radio" name="bill_to" value="dealer"<?= $formAttr ?> <?= $toDealer ? 'checked' : '' ?>><span><b>The dealer</b><span class="help"><?= h($dealer['name']) ?> is billed for it.</span></span></label>
  </div>
  <div class="help">This customer is <?= ($account['parent_relationship'] ?? '') === 'billed_via_dealer' ? 'normally billed via the dealer' : 'referred by the dealer and normally billed directly' ?>. The services from this order are billed the way chosen here.</div>
</div>
