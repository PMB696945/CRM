<div class="page-head"><h1><?= $id ? 'Edit user' : 'New user' ?></h1></div>
<form method="post" class="card form-grid">
  <?= csrf_field() ?>
  <?php foreach (['name' => ['Name', 'text'], 'email' => ['Email', 'email']] as $f => [$label, $type]): ?>
    <div class="field <?= isset($errors[$f]) ? 'has-error' : '' ?>">
      <label for="u_<?= $f ?>"><?= $label ?> <span class="req">*</span></label>
      <input id="u_<?= $f ?>" type="<?= $type ?>" name="<?= $f ?>" value="<?= h($values[$f]) ?>" required>
      <?php if (isset($errors[$f])): ?><div class="error"><?= h($errors[$f]) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <div class="field <?= isset($errors['role']) ? 'has-error' : '' ?>">
    <label for="u_role">Role</label>
    <select id="u_role" name="role">
      <option value="agent" <?= $values['role'] === 'agent' ? 'selected' : '' ?>>Agent</option>
      <option value="admin" <?= $values['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
    </select>
    <?php if (isset($errors['role'])): ?><div class="error"><?= h($errors['role']) ?></div><?php else: ?><div class="help">Admins can manage users and the product catalogue.</div><?php endif; ?>
  </div>
  <div class="field <?= isset($errors['password']) ? 'has-error' : '' ?>">
    <label for="u_password">Password<?= $id ? '' : ' <span class="req">*</span>' ?></label>
    <input id="u_password" type="password" name="password" autocomplete="new-password" <?= $id ? '' : 'required' ?>>
    <?php if (isset($errors['password'])): ?><div class="error"><?= h($errors['password']) ?></div><?php elseif ($id): ?><div class="help">Leave blank to keep the current password.</div><?php endif; ?>
  </div>
  <div class="field field-check"><label><input type="checkbox" name="active" value="1" <?= $values['active'] ? 'checked' : '' ?>> Active (can sign in)</label></div>
  <div class="form-actions wide"><button class="btn btn-primary">Save</button><a class="btn btn-ghost" href="<?= h(url('users')) ?>">Cancel</a></div>
</form>
