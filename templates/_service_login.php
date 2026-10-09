<?php
/** Vars: $service. The login and IP details card on a service's page. */
$l = service_login($service);
$canEdit = can('services.edit');
$account = db_one('SELECT a.name, a.email, c.name AS contact_name, c.email AS contact_email FROM accounts a LEFT JOIN contacts c ON c.id = a.main_contact_id WHERE a.id = ?', [$service['account_id']]);
?>
<section class="card" id="login">
  <div class="card-head"><h2>Login &amp; IP</h2></div>
  <dl class="details">
    <dt>Username</dt><dd><?= $l['username'] !== '' ? '<code>' . h($l['username']) . '</code>' : '<span class="muted">—</span>' ?></dd>
    <dt>Password</dt><dd><?= $l['password'] !== '' ? '<details><summary class="small">Show</summary><code>' . h($l['password']) . '</code></details>' : '<span class="muted">Not set</span>' ?></dd>
    <dt>IP address(es)</dt><dd><?= $l['ip'] !== '' ? h($l['ip']) : '<span class="muted">—</span>' ?></dd>
  </dl>
  <?php if ($canEdit): ?>
    <div class="grid-2" style="margin-top:1.25rem">
      <form method="post" action="<?= h(url('service_login', ['id' => $service['id'], 'do' => 'password'])) ?>" class="stack">
        <?= csrf_field() ?>
        <h3 class="small muted">Change the password</h3>
        <input type="text" name="password" required minlength="6" maxlength="64" autocomplete="off" aria-label="New password" placeholder="New password">
        <input type="text" name="confirm" required autocomplete="off" aria-label="New password again" placeholder="New password again">
        <div><button class="btn btn-sm">Save password</button></div>
        <p class="help">Changes the CRM's record. Change it with the supplier too<?= $service['carrier'] === 'Giacom' ? ' (in the Giacom portal)' : '' ?> so the line keeps working.</p>
      </form>
      <?php if ($l['username'] !== '' || $l['password'] !== '' || $l['ip'] !== ''): ?>
      <form method="post" action="<?= h(url('service_login', ['id' => $service['id'], 'do' => 'email'])) ?>" class="stack">
        <?= csrf_field() ?>
        <h3 class="small muted">Email the setup details to the customer</h3>
        <input type="email" name="email" required value="<?= h((string)(($account['contact_email'] ?? '') ?: ($account['email'] ?? ''))) ?>" aria-label="Email to">
        <input type="hidden" name="name" value="<?= h((string)($account['contact_name'] ?? '')) ?>">
        <div><button class="btn btn-sm">Email username, password and IP</button></div>
        <p class="help">Useful once the service is live and its IP addresses are known.</p>
      </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
