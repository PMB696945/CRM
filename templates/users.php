<div class="page-head"><h1>Users</h1><div class="actions"><?php if (is_super_admin()): ?><a class="btn" href="<?= h(url('roles')) ?>">Roles &amp; permissions</a><?php endif; ?><?php if (can('audit.view')): ?><a class="btn" href="<?= h(url('audit')) ?>">Audit trail</a><?php endif; ?><a class="btn btn-primary" href="<?= h(url('users', ['action' => 'new'])) ?>">+ New user</a></div></div>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Two-factor</th><th>Last sign-in</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?php if ($u['role'] !== 'super_admin' || is_super_admin()): ?><a class="row-link" href="<?= h(url('users', ['action' => 'edit', 'id' => $u['id']])) ?>"><?= h($u['name']) ?></a><?php else: ?><?= h($u['name']) ?><?php endif; ?>
        <?php if (can('audit.view')): ?><a class="small muted" href="<?= h(url('audit', ['user_id' => $u['id']])) ?>">activity</a><?php endif; ?></td>
      <td><?= h($u['email']) ?></td>
      <td><span class="badge badge-<?= h($u['role']) ?>"><?= h(role_label($u['role'])) ?></span></td>
      <td><?= $u['active'] ? badge('active') : badge('disabled') ?></td>
      <td><?= $u['totp_enabled'] ? badge('active') : '<span class="muted">Off</span>' ?></td>
      <td><?= $u['last_login_at'] ? h(fmt_datetime($u['last_login_at'])) : '<span class="muted">Never</span>' ?></td>
      <td class="right"><?php if ($u['totp_enabled'] && ($u['role'] !== 'super_admin' || is_super_admin())): ?>
        <form method="post" action="<?= h(url('users', ['action' => 'reset_2fa', 'id' => $u['id']])) ?>" class="inline" data-confirm="Reset two-factor sign-in for <?= h($u['name']) ?>? Use this if they've lost their phone and recovery codes."><?= csrf_field() ?><button class="btn btn-sm">Reset 2FA</button></form>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
