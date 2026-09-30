<?php $for = $site ? $site['name'] . ' (' . ($account['name'] ?? '') . ')' : ($account['name'] ?? null); ?>
<div class="page-head"><div>
  <?php if ($account): ?><div class="crumbs"><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $account['id']])) ?>"><?= h($account['name']) ?></a></div><?php endif; ?>
  <h1>Check broadband availability</h1></div></div>
<?php if (!giacom_configured()): ?><div class="flash flash-error">Giacom isn't set up yet. <?= can('settings.manage') ? '<a href="' . h(url('giacom', ['action' => 'settings'])) . '">Add the API login</a>.' : 'Ask an admin to add the API login.' ?></div><?php endif; ?>
<?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>

<div class="grid-side">
  <section class="card">
    <?php if ($addresses): ?>
      <div class="card-head"><h2>Choose the address</h2><span class="muted small"><?= count($addresses) ?> at <?= h(strtoupper($values['postcode'])) ?></span></div>
      <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="check">
        <?php foreach (['postcode', 'building'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= h($values[$k]) ?>"><?php endforeach; ?>
        <?php if ($account): ?><input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>"><?php endif; ?>
        <?php if ($site): ?><input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>"><?php endif; ?>
        <div class="address-list">
          <?php foreach ($addresses as $i => $a): ?>
            <label class="check"><input type="radio" name="address" value="<?= h(json_encode($a)) ?>" <?= $i === 0 && count($addresses) === 1 ? 'checked' : '' ?> required> <?= h($a['label']) ?></label>
          <?php endforeach; ?>
        </div>
        <label>Existing phone number (optional)<input name="cli" value="<?= h($values['cli']) ?>" inputmode="tel" placeholder="e.g. 01614960000">
          <span class="help">Include it if there's already a line or broadband there. It shows what's on the line and whether a migrate is needed.</span></label>
        <button class="btn btn-primary">Check availability</button>
      </form>
    <?php else: ?>
      <div class="card-head"><h2>Find the address</h2></div>
      <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="search">
        <?php if ($account): ?><input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>"><?php endif; ?>
        <?php if ($site): ?><input type="hidden" name="site_id" value="<?= (int)$site['id'] ?>"><?php endif; ?>
        <label>Postcode<input name="postcode" value="<?= h($values['postcode']) ?>" required autocomplete="off"></label>
        <label>Building name or number (optional)<input name="building" value="<?= h($values['building']) ?>"></label>
        <label>Existing phone number (optional)<input name="cli" value="<?= h($values['cli']) ?>" inputmode="tel"></label>
        <button class="btn btn-primary" <?= giacom_configured() ? '' : 'disabled' ?>>Find addresses</button>
      </form>
    <?php endif; ?>
  </section>
  <aside>
    <section class="card">
      <div class="card-head"><h2>For</h2></div>
      <p><?= $for ? h($for) : '<span class="muted">No customer. Start from a customer\'s page to be able to order.</span>' ?></p>
      <?php if ($site || $account): $w = $site ?? $account; ?><p class="muted small"><?= h(implode(', ', array_filter([$w['address'] ?? null, $w['city'] ?? null, $w['postcode'] ?? null]))) ?></p><?php endif; ?>
    </section>
  </aside>
</div>
