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
  <?php $welcome = !$id && (is_post() ? !empty($_POST['send_welcome']) : true); ?>
  <?php if (!$id): ?>
  <div class="field wide field-check"><label><input type="checkbox" name="send_welcome" value="1" <?= $welcome ? 'checked' : '' ?> data-toggle-password> Email them a welcome email with a temporary password</label>
    <div class="help">They'll choose their own password when they first sign in. The temporary one stops working after <?= TEMP_PASSWORD_DAYS ?> days.</div></div>
  <?php endif; ?>
  <div class="field <?= isset($errors['password']) ? 'has-error' : '' ?>" data-password-field <?= $welcome ? 'hidden' : '' ?>>
    <label for="u_password"><?= $id ? 'Set a new password' : 'Password' ?></label>
    <input id="u_password" type="password" name="password" autocomplete="new-password">
    <?php if (isset($errors['password'])): ?><div class="error"><?= h($errors['password']) ?></div><?php elseif ($id): ?><div class="help">Leave blank to keep the current password. To email them a new one, use <i>Send new password</i> on the Users list.</div><?php endif; ?>
    <?php if (!$id || $id !== (int)current_user()['id']): ?><label class="check mt-2"><input type="checkbox" name="must_change" value="1" <?= !is_post() || !empty($_POST['must_change']) ? 'checked' : '' ?>> They must change it when they next sign in</label><?php endif; ?>
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
  <div class="field field-check"><label><input type="checkbox" name="ip_anywhere" value="1" <?= !empty($values['ip_anywhere']) ? 'checked' : '' ?>> Can use the CRM from any location</label>
    <div class="help"><?= ip_restriction_on() ? 'The CRM is limited to approved IP addresses (Settings → Security). Tick this for someone who works remotely.' : 'Only matters if you limit the CRM to approved IP addresses (Settings → Security).' ?></div></div>
  <div class="form-actions wide"><button class="btn btn-primary">Save</button><a class="btn btn-ghost" href="<?= h(url('users')) ?>">Cancel</a></div>
</form>
