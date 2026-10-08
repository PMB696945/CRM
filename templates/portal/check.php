<div class="page-head"><div><h1>Check availability</h1><p class="muted">Find what's available at your customer's address, then order it.</p></div></div>
<?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>
<div class="grid-side">
  <section class="card">
    <?php if ($addresses && $customer): ?>
      <div class="card-head"><h2>Choose the address</h2><span class="muted small"><?= count($addresses) ?> at <?= h(strtoupper($values['postcode'])) ?></span></div>
      <form method="post" action="<?= h(portal_url('check')) ?>" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="customer" value="<?= (int)$customer['id'] ?>">
        <?php foreach (['postcode', 'building'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= h($values[$k]) ?>"><?php endforeach; ?>
        <div class="address-list">
          <?php foreach ($addresses as $i => $a): ?>
            <label class="check"><input type="radio" name="address" value="<?= h(json_encode($a)) ?>" <?= count($addresses) === 1 ? 'checked' : '' ?> required> <?= h($a['label']) ?></label>
          <?php endforeach; ?>
        </div>
        <label>Existing phone number (optional)<input name="cli" value="<?= h($values['cli']) ?>" inputmode="tel" placeholder="e.g. 01614960000">
          <span class="help">If there's already a line or broadband at the address, include its number: it shows whether the service can be taken over.</span></label>
        <div><button class="btn btn-primary">Check availability</button> <a class="btn btn-ghost" href="<?= h(portal_url('check', ['customer' => $customer['id']])) ?>">Start again</a></div>
      </form>
    <?php else: ?>
      <div class="card-head"><h2>Find the address</h2></div>
      <form method="post" action="<?= h(portal_url('check')) ?>" class="stack">
        <?= csrf_field() ?>
        <label>Customer
          <select name="customer" required>
            <option value="">Choose…</option>
            <?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $customer && (int)$customer['id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
          </select>
          <span class="help">Not listed? <a href="<?= h(portal_url('customer_new')) ?>">Add a customer</a> first.</span></label>
        <label>Postcode<input name="postcode" value="<?= h($values['postcode']) ?>" required autocomplete="off"></label>
        <label>Building name or number (optional)<input name="building" value="<?= h($values['building']) ?>"></label>
        <div><button class="btn btn-primary">Find addresses</button></div>
      </form>
    <?php endif; ?>
  </section>
  <aside>
    <section class="card">
      <div class="card-head"><h2>For</h2></div>
      <?php if ($customer): ?>
        <p><b><?= h($customer['name']) ?></b></p>
        <p class="muted small"><?= h(implode(', ', array_filter([$customer['address'], $customer['city'], $customer['postcode']]))) ?></p>
      <?php else: ?><p class="muted">Choose the customer.</p><?php endif; ?>
    </section>
  </aside>
</div>
