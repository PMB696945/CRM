<div class="page-head"><h1>Users</h1><div class="actions"><?php if (is_super_admin()): ?><a class="btn" href="<?= h(url('roles')) ?>">Roles &amp; permissions</a><?php endif; ?><?php if (can('audit.view')): ?><a class="btn" href="<?= h(url('audit')) ?>">Audit trail</a><?php endif; ?><a class="btn btn-primary" href="<?= h(url('users', ['action' => 'new'])) ?>">+ New user</a></div></div>
<?php if ($disabledCount || $showDisabled): ?>
<nav class="tabs">
  <a href="<?= h(url('users')) ?>" class="<?= $showDisabled ? '' : 'active' ?>">Active</a>
  <a href="<?= h(url('users', ['show' => 'disabled'])) ?>" class="<?= $showDisabled ? 'active' : '' ?>">Disabled <span class="count"><?= (int)$disabledCount ?></span></a>
</nav>
<?php endif; ?>
<?php if (!$users): ?><p class="muted"><?= $showDisabled ? 'No disabled users.' : 'No active users.' ?></p><?php endif; ?>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Two-factor</th><th>Last sign-in</th><th></th></tr></thead>
  <tbody>
  <?php $activeUsers = array_filter($users, fn($x) => $x['active']); ?>
  <?php foreach ($users as $u): $canManage = (int)$u['id'] !== (int)current_user()['id'] && ($u['role'] !== 'super_admin' || is_super_admin()); ?>
    <tr>
      <td><?php if ($u['role'] !== 'super_admin' || is_super_admin()): ?><a class="row-link" href="<?= h(url('users', ['action' => 'edit', 'id' => $u['id']])) ?>"><?= h($u['name']) ?></a><?php else: ?><?= h($u['name']) ?><?php endif; ?>
        <?php if (can('audit.view')): ?><a class="small muted" href="<?= h(url('audit', ['user_id' => $u['id']])) ?>">activity</a><?php endif; ?></td>
      <td><?= h($u['email']) ?></td>
      <td><span class="badge badge-<?= h($u['role']) ?>"><?= h(role_label($u['role'])) ?></span></td>
      <td><?= $u['active'] ? badge('active') : badge('disabled') ?>
        <?php if ($u['must_change_password']): ?><div class="small <?= $u['temp_password_expires_at'] && strtotime($u['temp_password_expires_at']) < time() ? 'text-danger' : 'muted' ?>">
          <?= $u['temp_password_expires_at'] && strtotime($u['temp_password_expires_at']) < time() ? 'Temporary password expired' : 'Waiting to choose a password' ?></div><?php endif; ?>
        <?php if ($u['ip_anywhere'] && ip_restriction_on()): ?><div class="small muted">Any location</div><?php endif; ?></td>
      <td><?= $u['totp_enabled'] ? badge('active') : '<span class="muted">Off</span>' ?></td>
      <td><?= $u['last_login_at'] ? h(fmt_datetime($u['last_login_at'])) : '<span class="muted">Never</span>' ?></td>
      <td class="right user-actions"><?php if ((int)$u['id'] !== (int)current_user()['id'] && $u['active'] && ($u['role'] !== 'super_admin' || is_super_admin())): ?>
        <form method="post" action="<?= h(url('users', ['action' => 'send_password', 'id' => $u['id']])) ?>" class="inline" data-confirm="Email <?= h($u['name']) ?> a new temporary password? Their current password stops working."><?= csrf_field() ?><button class="btn btn-sm"><?= $u['last_login_at'] ? 'Send new password' : 'Resend welcome email' ?></button></form>
      <?php endif; ?>
      <?php if ($canManage && $u['active']): ?>
        <details class="dropdown inline-block text-left">
          <summary class="btn btn-sm btn-danger-ghost">Disable…</summary>
          <form method="post" action="<?= h(url('users', ['action' => 'disable', 'id' => $u['id']])) ?>" class="dropdown-panel card stack">
            <?= csrf_field() ?>
            <h3>Disable <?= h($u['name']) ?></h3>
            <p class="help">They're signed out straight away and can't sign in. Their name stays on everything they've done, and you can enable them again later.</p>
            <label>Hand their open tickets, customers, opportunities and orders to
              <select name="reassign_to"><option value="">Nobody (leave as they are)</option>
                <?php foreach ($activeUsers as $o): if ((int)$o['id'] === (int)$u['id']) continue; ?><option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?></option><?php endforeach; ?>
              </select></label>
            <button class="btn btn-danger">Disable <?= h(explode(' ', $u['name'])[0]) ?></button>
          </form>
        </details>
      <?php elseif ($canManage): ?>
        <form method="post" action="<?= h(url('users', ['action' => 'enable', 'id' => $u['id']])) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm">Enable</button></form>
      <?php endif; ?>
      <?php if ($canManage && !$u['last_login_at']): ?>
        <form method="post" action="<?= h(url('users', ['action' => 'delete', 'id' => $u['id']])) ?>" class="inline" data-confirm="Delete <?= h($u['name']) ?>? They've never signed in, so nothing is lost."><?= csrf_field() ?><button class="btn btn-sm btn-ghost">Delete</button></form>
      <?php endif; ?>
      <?php if ($u['totp_enabled'] && ($u['role'] !== 'super_admin' || is_super_admin())): ?>
        <form method="post" action="<?= h(url('users', ['action' => 'reset_2fa', 'id' => $u['id']])) ?>" class="inline" data-confirm="Reset two-factor sign-in for <?= h($u['name']) ?>? Use this if they've lost their phone and recovery codes."><?= csrf_field() ?><button class="btn btn-sm">Reset 2FA</button></form>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
