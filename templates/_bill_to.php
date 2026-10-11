<?php
/**
 * Who an order is billed to, for a customer under a dealer. Expects $account and $toDealer (current choice,
 * or null when not chosen yet); optional $form (the form id, for a field outside its form). Shows nothing for
 * customers without a dealer. A product-specific customer must choose: nothing is ticked to start with.
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
    <label><input type="radio" name="bill_to" value="customer"<?= $formAttr ?> <?= $toDealer === false ? 'checked' : '' ?> required><span><b>The customer</b><span class="help"><?= h($account['name']) ?> pays us directly.</span></span></label>
    <label><input type="radio" name="bill_to" value="dealer"<?= $formAttr ?> <?= $toDealer === true ? 'checked' : '' ?> required><span><b>The dealer</b><span class="help"><?= h($dealer['name']) ?> is billed for it.</span></span></label>
  </div>
  <div class="help"><?= ['billed_via_dealer' => 'This customer is normally billed via the dealer.', 'product_specific' => 'This customer is billed per product: some via the dealer, some directly. Choose for this order.']
      [$account['parent_relationship'] ?? ''] ?? 'This customer was referred by the dealer and is normally billed directly.' ?> The services from this order are billed the way chosen here.</div>
</div>
