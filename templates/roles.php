<?php $roles = array_diff_key(roles(), ['super_admin' => 1]); $custom = custom_roles(); ?>
<div class="page-head"><h1>Roles &amp; permissions</h1></div>
<p class="lead">Choose what each role can see and do. Give each person a role on the <a href="<?= h(url('users')) ?>">Users</a> page. Super admins can always do everything, including viewing the audit trail and changing this page.</p>

<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="table-wrap"><table class="table perm-table">
    <thead><tr><th>Permission</th><?php foreach ($roles as $role => $label): ?><th id="role-<?= h($role) ?>" title="<?= h(role_description($role)) ?>"><?= h($label) ?><?= isset($custom[$role]) ? '<br><span class="badge">Custom</span>' : '' ?><br><small class="muted"><?= (int)($counts[$role] ?? 0) ?> user<?= ($counts[$role] ?? 0) === 1 ? '' : 's' ?></small></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach (PERMISSIONS as $group => $perms): ?>
      <tr class="perm-group"><td colspan="<?= count($roles) + 1 ?>"><?= h($group) ?></td></tr>
      <?php foreach ($perms as $perm => $label): ?>
        <tr>
          <td><?= h($label) ?></td>
          <?php foreach ($roles as $role => $_): ?>
            <td><input type="checkbox" name="perm[<?= h($role) ?>][<?= h($perm) ?>]" value="1" aria-label="<?= h($roles[$role] . ': ' . $label) ?>" <?= in_array($perm, $matrix[$role], true) ? 'checked' : '' ?>></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="help">Everyone who can sign in can look up customers, services and tickets. "Close/delete without approval" lets someone act straight away; anyone else who can edit customers sends a request to the approvers instead.</p>
  <div class="form-actions"><button class="btn btn-primary">Save permissions</button></div>
</form>
<form method="post" data-confirm="Put the built-in roles back to their standard permissions? Custom roles keep theirs."><?= csrf_field() ?><input type="hidden" name="reset" value="1"><button class="btn btn-ghost">Reset to defaults</button></form>

<div class="grid-2" style="margin-top:1.5rem">
  <section class="card">
    <div class="card-head"><h2>Add a role</h2></div>
    <p class="help">For a job that doesn't fit the roles above, e.g. "Provisioning" or "Account manager". It appears as a new column in the grid and on the Users page.</p>
    <form method="post" action="<?= h(url('roles', ['action' => 'add_role'])) ?>" class="stack">
      <?= csrf_field() ?>
      <label>Name<input name="label" maxlength="40" required placeholder="e.g. Provisioning"></label>
      <label>What it's for (optional)<input name="description" maxlength="200"></label>
      <label>Start with the permissions of<select name="copy_from"><option value="">Nothing (tick them afterwards)</option>
        <?php foreach ($roles as $role => $label): ?><option value="<?= h($role) ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
      <button class="btn btn-primary">Add role</button>
    </form>
  </section>

  <section class="card">
    <div class="card-head"><h2>About the roles</h2></div>
    <dl class="details details-stack">
      <?php foreach (roles() as $role => $label): ?>
        <dt><?= h($label) ?><?= isset($custom[$role]) ? ' <span class="badge">Custom</span>' : '' ?> · <?= (int)($counts[$role] ?? 0) ?> active user<?= ($counts[$role] ?? 0) === 1 ? '' : 's' ?></dt>
        <dd><?= h(role_description($role)) ?>
          <?php if (isset($custom[$role])): ?>
            <details class="changes"><summary>Rename or delete</summary>
              <form method="post" action="<?= h(url('roles', ['action' => 'edit_role'])) ?>" class="stack" style="margin-top:.5rem">
                <?= csrf_field() ?><input type="hidden" name="role" value="<?= h($role) ?>">
                <label>Name<input name="label" maxlength="40" required value="<?= h($label) ?>"></label>
                <label>What it's for<input name="description" maxlength="200" value="<?= h($custom[$role]['description']) ?>"></label>
                <button class="btn btn-sm">Save</button>
              </form>
              <form method="post" action="<?= h(url('roles', ['action' => 'delete_role'])) ?>" data-confirm="Delete the <?= h($label) ?> role?" style="margin-top:.5rem">
                <?= csrf_field() ?><input type="hidden" name="role" value="<?= h($role) ?>"><button class="btn btn-sm btn-danger">Delete role</button>
              </form>
            </details>
          <?php endif; ?>
        </dd>
      <?php endforeach; ?>
    </dl>
  </section>
</div>
