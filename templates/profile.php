<div class="page-head"><h1>My profile</h1></div>
<div class="card"><dl class="details"><dt>Name</dt><dd><?= h($user['name']) ?></dd><dt>Email</dt><dd><?= h($user['email']) ?></dd><dt>Role</dt><dd><?= badge($user['role']) ?></dd></dl></div>
<form method="post" class="card form-grid">
  <h2 class="wide">Change password</h2>
  <?= csrf_field() ?>
  <?php foreach (['current_password' => 'Current password', 'new_password' => 'New password', 'confirm_password' => 'Confirm new password'] as $f => $label): ?>
    <div class="field <?= isset($errors[$f]) ? 'has-error' : '' ?>">
      <label for="p_<?= $f ?>"><?= $label ?></label>
      <input id="p_<?= $f ?>" type="password" name="<?= $f ?>" required autocomplete="<?= $f === 'current_password' ? 'current-password' : 'new-password' ?>">
      <?php if (isset($errors[$f])): ?><div class="error"><?= h($errors[$f]) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <div class="form-actions wide"><button class="btn btn-primary">Change password</button></div>
</form>
