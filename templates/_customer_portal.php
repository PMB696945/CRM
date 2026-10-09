<?php
/** Vars: $account, $canEdit. Who at the customer can sign in to the customer portal. */
$users = db_all('SELECT * FROM customer_users WHERE account_id = ? ORDER BY active DESC, name', [$account['id']]);
$contacts = db_all("SELECT name, email FROM contacts WHERE account_id = ? AND email IS NOT NULL AND email <> '' ORDER BY is_primary DESC, name", [$account['id']]);
?>
<section class="card" id="customer-portal">
  <div class="card-head"><h2>Customer portal</h2><a class="btn btn-sm" href="<?= h(customer_portal_public_url('login')) ?>" target="_blank" rel="noopener">Open ↗</a></div>
  <p class="help">People here can sign in to see this account: services with their logins and IPs, orders, agreements and tickets. Read-only.
    <?php if ($account['parent_id'] && $account['parent_relationship'] === 'billed_via_dealer'): ?><br><span class="text-warning">This customer is billed via a dealer: the portal shows your company's name.</span><?php endif; ?></p>
  <?php if ($users): ?>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Person</th><th>Last signed in</th><th>Status</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $base = ['account_id' => $account['id'], 'user_id' => $u['id']]; ?>
      <tr>
        <td><?= h($u['name']) ?><div class="muted small"><?= h($u['email']) ?></div></td>
        <td><?= $u['last_login_at'] ? h(fmt_datetime($u['last_login_at'])) : '<span class="muted">Never</span>' ?></td>
        <td><?= $u['active'] ? ($u['locked_until'] && strtotime($u['locked_until']) > time() ? '<span class="badge badge-suspended">Locked for now</span>' : badge('active')) : badge('disabled') ?></td>
        <?php if ($canEdit): ?><td class="right whitespace-nowrap">
          <?php if ($u['active']): ?><form method="post" class="inline" action="<?= h(url('customer_portal_users', $base + ['do' => 'reset'])) ?>" data-confirm="Email <?= h($u['name']) ?> a new temporary password?"><?= csrf_field() ?><button class="btn btn-sm">New password</button></form><?php endif; ?>
          <form method="post" class="inline" action="<?= h(url('customer_portal_users', $base + ['do' => 'toggle'])) ?>"><?= csrf_field() ?><button class="btn btn-sm <?= $u['active'] ? 'btn-danger' : '' ?>"><?= $u['active'] ? 'Remove access' : 'Restore access' ?></button></form>
        </td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <?php if ($canEdit): ?>
    <form method="post" action="<?= h(url('customer_portal_users', ['account_id' => $account['id'], 'do' => 'add'])) ?>" class="stack" style="margin-top:.75rem">
      <?= csrf_field() ?>
      <input name="name" placeholder="Name" required aria-label="Name" value="<?= h((string)($contacts[0]['name'] ?? '')) ?>">
      <input type="email" name="email" placeholder="Email" required aria-label="Email" value="<?= h((string)($contacts[0]['email'] ?? '')) ?>">
      <div><button class="btn btn-sm btn-primary">Give portal access</button></div>
      <span class="help">They're emailed a temporary password, changed when they first sign in.</span>
    </form>
  <?php endif; ?>
</section>
