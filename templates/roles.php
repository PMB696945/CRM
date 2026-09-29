<?php $roles = array_diff_key(ROLES, ['super_admin' => 1]); ?>
<div class="page-head"><h1>Roles &amp; permissions</h1></div>
<p class="lead">Choose what each role can see and do. Give each person a role on the <a href="<?= h(url('users')) ?>">Users</a> page. Super admins can always do everything, including viewing the audit trail and changing this page.</p>

<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="table-wrap"><table class="table perm-table">
    <thead><tr><th>Permission</th><?php foreach ($roles as $role => $label): ?><th title="<?= h(ROLE_DESCRIPTIONS[$role]) ?>"><?= h($label) ?><br><small class="muted"><?= (int)($counts[$role] ?? 0) ?> user<?= ($counts[$role] ?? 0) === 1 ? '' : 's' ?></small></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach (PERMISSIONS as $group => $perms): ?>
      <tr class="perm-group"><td colspan="<?= count($roles) + 1 ?>"><?= h($group) ?></td></tr>
      <?php foreach ($perms as $perm => $label): ?>
        <tr>
          <td><?= h($label) ?></td>
          <?php foreach ($roles as $role => $_): ?>
            <td><input type="checkbox" name="perm[<?= h($role) ?>][<?= h($perm) ?>]" value="1" aria-label="<?= h(ROLES[$role] . ': ' . $label) ?>" <?= in_array($perm, $matrix[$role], true) ? 'checked' : '' ?>></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="help">Everyone who can sign in can look up customers, services and tickets. "Close/delete without approval" lets someone act straight away; anyone else who can edit customers sends a request to the approvers instead.</p>
  <div class="form-actions"><button class="btn btn-primary">Save permissions</button></div>
</form>
<form method="post" data-confirm="Put every role back to the standard permissions?"><?= csrf_field() ?><input type="hidden" name="reset" value="1"><button class="btn btn-ghost">Reset to defaults</button></form>

<section class="card" style="margin-top:1.5rem">
  <div class="card-head"><h2>About the roles</h2></div>
  <dl class="details">
    <?php foreach (ROLES as $role => $label): ?><dt><?= h($label) ?></dt><dd><?= h(ROLE_DESCRIPTIONS[$role]) ?></dd><?php endforeach; ?>
  </dl>
</section>
