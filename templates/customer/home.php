<?php $addr = implode(', ', array_filter([$account['address'], $account['address2'] ?? null, $account['city'], $account['postcode']])); ?>
<div class="page-head"><div><h1><?= h($account['name']) ?></h1><p class="muted">Account <?= h($account['account_number']) ?></p></div></div>
<div class="kpis kpis-sm">
  <a class="kpi" href="<?= h(portal_url('services')) ?>"><span class="kpi-label">Live services</span><span class="kpi-value"><?= (int)$counts['live'] ?></span></a>
  <a class="kpi" href="<?= h(portal_url('orders')) ?>"><span class="kpi-label">Orders in progress</span><span class="kpi-value"><?= (int)$counts['orders'] ?></span></a>
  <a class="kpi" href="<?= h(portal_url('tickets')) ?>"><span class="kpi-label">Open support tickets</span><span class="kpi-value"><?= (int)$counts['tickets'] ?></span></a>
  <a class="kpi <?= $counts['to_sign'] ? 'kpi-alert' : '' ?>" href="<?= h(portal_url('agreements')) ?>"><span class="kpi-label">Agreements to sign</span><span class="kpi-value"><?= (int)$counts['to_sign'] ?></span></a>
</div>
<div class="grid-side">
  <section class="card">
    <div class="card-head"><h2>Your details</h2></div>
    <dl class="details">
      <dt>Company</dt><dd><?= h($account['name']) ?></dd>
      <?php if ($account['company_number']): ?><dt>Company number</dt><dd><?= h($account['company_number']) ?></dd><?php endif; ?>
      <dt>Address</dt><dd><?= $addr !== '' ? h($addr) : '<span class="muted">—</span>' ?></dd>
      <?php if ($account['phone']): ?><dt>Phone</dt><dd><?= h($account['phone']) ?></dd><?php endif; ?>
      <?php if ($account['email']): ?><dt>Email</dt><dd><?= h($account['email']) ?></dd><?php endif; ?>
    </dl>
    <p class="help">Something wrong or out of date? Let us know and we'll update it.</p>
  </section>
  <aside>
    <section class="card">
      <div class="card-head"><h2>Contacts</h2></div>
      <?php foreach ($contacts as $c): ?>
        <div class="key-contact"><strong><?= h($c['name']) ?></strong><?= $c['job_title'] ? ' <span class="muted small">' . h($c['job_title']) . '</span>' : '' ?>
          <div class="small muted"><?= h(implode(' · ', array_filter([(string)$c['email'], (string)$c['phone'], (string)$c['mobile']]))) ?></div></div>
      <?php endforeach; ?>
      <?php if (!$contacts): ?><p class="muted">None yet.</p><?php endif; ?>
    </section>
    <?php if ($sites): ?>
    <section class="card">
      <div class="card-head"><h2>Sites</h2></div>
      <?php foreach ($sites as $s): ?><div class="key-contact"><strong><?= h($s['name']) ?></strong><div class="small muted"><?= h(implode(', ', array_filter([$s['address'], $s['city'], $s['postcode']]))) ?></div></div><?php endforeach; ?>
    </section>
    <?php endif; ?>
  </aside>
</div>
