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
      <?php foreach (roles() as $role => $label): if ($role === 'super_admin' && !is_super_admin() && $values['role'] !== 'super_admin') continue; ?>
        <option value="<?= h($role) ?>" <?= $values['role'] === $role ? 'selected' : '' ?>><?= h($label) ?> – <?= h(role_description($role)) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (isset($errors['role'])): ?><div class="error"><?= h($errors['role']) ?></div><?php else: ?><div class="help">What each role can do is set on <?= is_super_admin() ? '<a href="' . h(url('roles')) . '">Roles &amp; permissions</a>' : 'the Roles &amp; permissions page (super admins only)' ?>.</div><?php endif; ?>
  </div>
  <div class="field <?= isset($errors['password']) ? 'has-error' : '' ?>">
    <label for="u_password">Password<?= $id ? '' : ' <span class="req">*</span>' ?></label>
    <input id="u_password" type="password" name="password" autocomplete="new-password" <?= $id ? '' : 'required' ?>>
    <?php if (isset($errors['password'])): ?><div class="error"><?= h($errors['password']) ?></div><?php elseif ($id): ?><div class="help">Leave blank to keep the current password.</div><?php endif; ?>
  </div>
  <?php if ($groups): ?>
  <div class="field wide">
    <fieldset class="checkbox-group"><legend>Ticket groups</legend>
      <?php foreach ($groups as $g): ?><label class="check"><input type="checkbox" name="groups[]" value="<?= (int)$g['id'] ?>" <?= in_array((int)$g['id'], $groupIds, true) ? 'checked' : '' ?>> <?= h($g['name']) ?></label><?php endforeach; ?>
    </fieldset>
    <div class="help">They'll see these groups' tickets and can pick them up from the Ticket queue.</div>
  </div>
  <?php endif; ?>
  <div class="field field-check"><label><input type="checkbox" name="active" value="1" <?= $values['active'] ? 'checked' : '' ?>> Active (can sign in)</label></div>
  <div class="form-actions wide"><button class="btn btn-primary">Save</button><a class="btn btn-ghost" href="<?= h(url('users')) ?>">Cancel</a></div>
</form>
