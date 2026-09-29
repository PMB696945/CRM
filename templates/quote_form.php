<?php
$entity = entity('quotes');
$accountId = $values['account_id'] ?? null;
$rows = $lines ?: [['product_id' => null, 'service_type' => 'mobile', 'description' => '', 'quantity' => 1, 'monthly_price' => '', 'setup_fee' => '0.00', 'term_months' => 24]];
$cancel = $quote ? url('quotes', ['action' => 'view', 'id' => $quote['id']]) : ($accountId ? url('accounts', ['action' => 'view', 'id' => $accountId]) : url('quotes'));
?>
<div class="page-head"><h1><?= $quote ? 'Edit quote ' . h($quote['reference']) : 'New quote' ?></h1></div>
<?php if ($errors): ?><div class="flash flash-error"><?= h(implode(' ', array_filter($errors, 'is_string'))) ?></div><?php endif; ?>

<form method="post" class="quote-form" data-entity="quotes">
  <?= csrf_field() ?>
  <section class="card form-grid">
    <div class="field <?= isset($errors['account_id']) ? 'has-error' : '' ?>">
      <label for="q_account">Customer <span class="req">*</span></label>
      <select id="q_account" name="account_id" required>
        <option value="">—</option>
        <?php foreach (ref_options('accounts') as $k => $label): ?><option value="<?= (int)$k ?>" <?= (string)$accountId === (string)$k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field <?= isset($errors['title']) ? 'has-error' : '' ?>">
      <label for="q_title">Title <span class="req">*</span></label>
      <input id="q_title" name="title" value="<?= h($values['title']) ?>" required placeholder="e.g. Office move – leased line and VoIP">
    </div>
    <div class="field">
      <label for="q_valid">Valid until</label>
      <input id="q_valid" type="date" name="valid_until" value="<?= h($values['valid_until']) ?>">
    </div>
    <div class="field">
      <label for="q_opp">Opportunity</label>
      <select id="q_opp" name="opportunity_id" data-scoped="opportunities">
        <option value="">—</option>
        <?php foreach ($accountId ? ref_options('opportunities', (int)$accountId) : [] as $k => $label): ?><option value="<?= (int)$k ?>" <?= (string)($values['opportunity_id'] ?? '') === (string)$k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
      </select>
      <div class="help">Marked as won when the quote is accepted</div>
    </div>
    <div class="field wide">
      <label for="q_intro">Message to the customer</label>
      <textarea id="q_intro" name="intro" rows="3" placeholder="Shown at the top of the quote page"><?= h($values['intro']) ?></textarea>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Services</h2><button type="button" class="btn btn-sm" data-add-line>+ Add line</button></div>
    <?php if (!empty($errors['_lines'])): ?><div class="flash flash-error"><?= h($errors['_lines']) ?></div><?php endif; ?>
    <div class="table-wrap">
      <table class="table lines-table">
        <thead><tr><th>Product</th><th>Type</th><th>Description</th><th class="num">Qty</th><th class="num">Monthly £</th><th class="num">One-off £</th><th class="num">Term</th><th></th></tr></thead>
        <tbody data-lines>
        <?php foreach ($rows as $l): ?>
          <tr>
            <td><select name="line_product_id[]" data-product aria-label="Product">
              <option value="">Custom</option>
              <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p['id'] ?>" data-type="<?= h($p['category']) ?>" data-name="<?= h($p['name']) ?>" data-monthly="<?= h($p['monthly_price']) ?>" data-setup="<?= h($p['setup_fee']) ?>" data-term="<?= (int)$p['term_months'] ?>" <?= (string)$l['product_id'] === (string)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
              <?php endforeach; ?>
            </select></td>
            <td><select name="line_service_type[]" aria-label="Service type">
              <?php foreach (SERVICE_TYPES as $k => $label): ?><option value="<?= h($k) ?>" <?= $l['service_type'] === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
            </select></td>
            <td><input name="line_description[]" value="<?= h($l['description']) ?>" aria-label="Description" placeholder="Description"></td>
            <td><input name="line_quantity[]" type="number" min="1" value="<?= (int)$l['quantity'] ?>" class="w-20" aria-label="Quantity"></td>
            <td><input name="line_monthly_price[]" type="number" step="0.01" min="0" value="<?= h($l['monthly_price']) ?>" class="w-28" aria-label="Monthly price"></td>
            <td><input name="line_setup_fee[]" type="number" step="0.01" min="0" value="<?= h($l['setup_fee']) ?>" class="w-28" aria-label="One-off price"></td>
            <td><input name="line_term_months[]" type="number" min="0" max="120" value="<?= (int)$l['term_months'] ?>" class="w-20" aria-label="Term in months"></td>
            <td><button type="button" class="btn btn-sm btn-ghost" data-remove-line aria-label="Remove line">✕</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="quote-totals">Monthly <b data-total-monthly>£0.00</b> · One-off <b data-total-setup>£0.00</b> · Contract value <b data-total-tcv>£0.00</b> <span class="muted">(excl. VAT)</span></p>
  </section>

  <div class="form-actions"><button class="btn btn-primary">Save quote</button><a class="btn btn-ghost" href="<?= h($cancel) ?>">Cancel</a></div>
</form>
