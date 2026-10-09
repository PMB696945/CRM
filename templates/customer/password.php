<div class="page-head"><div><h1><?= $forced ? 'Choose your password' : 'Change your password' ?></h1>
  <?php if ($forced): ?><p class="muted">You signed in with a temporary password. Please choose your own to carry on.</p><?php endif; ?></div></div>
<?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>
<form method="post" action="<?= h(portal_url('password')) ?>" class="card stack" style="max-width:32rem">
  <?= csrf_field() ?>
  <?php if (!$forced): ?><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><?php endif; ?>
  <label>New password<input type="password" name="new_password" required autocomplete="new-password" minlength="10">
    <span class="help">At least 10 characters. A short phrase is easy to remember and hard to guess.</span></label>
  <label>New password again<input type="password" name="confirm_password" required autocomplete="new-password"></label>
  <div><button class="btn btn-primary">Save password</button></div>
</form>
