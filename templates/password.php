<div class="page-head"><div><h1><?= $forced ? 'Choose your password' : 'Change your password' ?></h1>
  <?php if ($forced): ?><p class="muted">You signed in with a temporary password. Choose your own to carry on.</p><?php endif; ?></div></div>
<form method="post" class="card form-grid max-w-xl">
  <?= csrf_field() ?>
  <?php if ($error): ?><div class="flash flash-error wide" role="alert"><?= h($error) ?></div><?php endif; ?>
  <?php if (!$forced): ?>
    <div class="field wide"><label for="p_current">Current password</label><input id="p_current" type="password" name="current_password" autocomplete="current-password" required></div>
  <?php endif; ?>
  <div class="field wide"><label for="p_new">New password</label><input id="p_new" type="password" name="new_password" autocomplete="new-password" minlength="10" required <?= $forced ? 'autofocus' : '' ?>>
    <div class="help">At least 10 characters. A few random words works well, e.g. <i>copper-kettle-river-42</i>.</div></div>
  <div class="field wide"><label for="p_confirm">New password again</label><input id="p_confirm" type="password" name="confirm_password" autocomplete="new-password" required></div>
  <div class="form-actions wide"><button class="btn btn-primary">Save password</button>
    <?php if (!$forced): ?><a class="btn btn-ghost" href="<?= h(url('profile')) ?>">Cancel</a><?php endif; ?></div>
</form>
