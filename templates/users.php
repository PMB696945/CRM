<div class="page-head"><h1>Users</h1><a class="btn btn-primary" href="<?= h(url('users', ['action' => 'new'])) ?>">+ New user</a></div>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><a class="row-link" href="<?= h(url('users', ['action' => 'edit', 'id' => $u['id']])) ?>"><?= h($u['name']) ?></a></td>
      <td><?= h($u['email']) ?></td>
      <td><?= badge($u['role']) ?></td>
      <td><?= $u['active'] ? badge('active') : badge('disabled') ?></td>
      <td><?= h(fmt_date($u['created_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
