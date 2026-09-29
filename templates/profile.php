<?php $err = fn($k) => isset($errors[$k]) ? '<div class="error">' . h($errors[$k]) . '</div>' : ''; ?>
<div class="page-head"><h1>My profile</h1></div>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Your account</h2></div>
    <dl class="details">
      <dt>Name</dt><dd><?= h($user['name']) ?></dd>
      <dt>Email</dt><dd><?= h($user['email']) ?></dd>
      <dt>Role</dt><dd><?= badge($user['role']) ?></dd>
      <dt>Last sign-in</dt><dd><?= h(fmt_datetime($row['last_login_at'])) ?></dd>
    </dl>
  </section>

  <section class="card">
    <div class="card-head"><h2>Two-factor sign-in</h2><?= $row['totp_enabled'] ? badge('active') : '<span class="badge badge-p1">Off</span>' ?></div>

    <?php if ($recoveryCodes): ?>
      <div class="dd-link">
        <b>Your recovery codes</b>
        <p class="help">Each code works once, if you lose your phone. Store them somewhere safe, such as a password manager. They won't be shown again.</p>
        <ul class="recovery-codes"><?php foreach ($recoveryCodes as $c): ?><li><?= h($c) ?></li><?php endforeach; ?></ul>
        <div class="copy-row"><input id="rc" readonly value="<?= h(implode(' ', $recoveryCodes)) ?>" data-select-all class="sr-only-input"><button type="button" class="btn btn-sm" data-copy="#rc">Copy all codes</button></div>
      </div>
    <?php endif; ?>

    <?php if ($row['totp_enabled']): ?>
      <p>Signing in needs your password and a code from your authenticator app. <span class="muted"><?= (int)$codesLeft ?> recovery code<?= $codesLeft === 1 ? '' : 's' ?> left.</span></p>
      <form method="post" action="<?= h(url('profile', ['action' => '2fa_codes'])) ?>" class="stack mt-4">
        <?= csrf_field() ?>
        <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><?= $err('current_password_codes') ?>
        <button class="btn">Create new recovery codes</button>
      </form>
      <?php if (setting('require_2fa') !== '1'): ?>
      <details class="mt-4"><summary>Turn off two-factor sign-in</summary>
        <form method="post" action="<?= h(url('profile', ['action' => '2fa_disable'])) ?>" class="stack mt-3" data-confirm="Turn off two-factor sign-in? Your account will be protected by your password only.">
          <?= csrf_field() ?>
          <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><?= $err('current_password_disable') ?>
          <button class="btn btn-danger">Turn off</button>
        </form>
      </details>
      <?php endif; ?>
    <?php elseif ($pendingSecret): ?>
      <ol class="steps">
        <li>Open an authenticator app on your phone (Microsoft Authenticator, Google Authenticator, 1Password…).</li>
        <li>Scan this code, or enter the key by hand:</li>
      </ol>
      <div class="qr-box"><div data-qr="<?= h($otpUri) ?>" aria-label="QR code for your authenticator app"></div>
        <code class="break-all"><?= h(trim(chunk_split($pendingSecret, 4, ' '))) ?></code></div>
      <form method="post" action="<?= h(url('profile', ['action' => '2fa_confirm'])) ?>" class="stack mt-4">
        <?= csrf_field() ?>
        <label>3. Enter the 6-digit code the app shows<input name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123456"></label><?= $err('code') ?>
        <div class="actions"><button class="btn btn-primary">Turn on</button></div>
      </form>
      <form method="post" action="<?= h(url('profile', ['action' => '2fa_cancel'])) ?>" class="mt-2"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Cancel</button></form>
      <script src="assets/vendor/qrcode.js"></script>
    <?php else: ?>
      <p>Protect your account with a code from your phone as well as your password. Even if someone learns your password, they can't sign in without your phone.</p>
      <form method="post" action="<?= h(url('profile', ['action' => '2fa_start'])) ?>" class="mt-4"><?= csrf_field() ?><button class="btn btn-primary">Set up two-factor sign-in</button></form>
    <?php endif; ?>
  </section>
</div>

<form method="post" class="card form-grid">
  <h2 class="wide">Change password</h2>
  <?= csrf_field() ?>
  <?php foreach (['current_password' => 'Current password', 'new_password' => 'New password', 'confirm_password' => 'Confirm new password'] as $f => $label): ?>
    <div class="field <?= isset($errors[$f]) ? 'has-error' : '' ?>">
      <label for="p_<?= $f ?>"><?= $label ?></label>
      <input id="p_<?= $f ?>" type="password" name="<?= $f ?>" required autocomplete="<?= $f === 'current_password' ? 'current-password' : 'new-password' ?>">
      <?= $err($f) ?: ($f === 'new_password' ? '<div class="help">At least 10 characters. A few random words works well.</div>' : '') ?>
    </div>
  <?php endforeach; ?>
  <div class="form-actions wide"><button class="btn btn-primary">Change password</button></div>
</form>
