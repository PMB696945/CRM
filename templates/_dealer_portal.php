<section class="card" id="portal-users">
  <div class="card-head"><h2>Partner portal</h2><a class="btn btn-sm" href="<?= h(portal_public_url('login')) ?>" target="_blank" rel="noopener">Open the portal ↗</a></div>
  <p>Master terms:
    <?php if ($portal['msa']): ?><a href="<?= h(url('contracts', ['action' => 'view', 'id' => $portal['msa']['id']])) ?>"><?= h($portal['msa']['reference']) ?></a> <?= badge('signed') ?> <?= h(fmt_date($portal['msa']['signed_at'])) ?>
    <?php elseif ($portal['msaWaiting']): ?><a href="<?= h(url('contracts', ['action' => 'view', 'id' => $portal['msaWaiting']['id']])) ?>"><?= h($portal['msaWaiting']['reference']) ?></a> <?= badge('sent') ?> <span class="muted">waiting to be signed</span>
    <?php else: ?><span class="text-danger">not signed</span><?php if (can('sales.edit')): ?> · <a href="<?= h(url('contracts', ['action' => 'new', 'account_id' => $account['id']])) ?>">send an MSA</a><?php endif; ?>
    <?php endif; ?></p>
  <p class="help">The dealer can sign in and check availability straight away, but can only send orders once their master terms are signed. Each order then has its own agreement with the dealer, emailed to whoever placed it to sign.</p>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Portal user</th><th>Last signed in</th><th>Status</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($portal['users'] as $u): $base = ['action' => 'users', 'account_id' => $account['id'], 'user_id' => $u['id']]; ?>
      <tr>
        <td><?= h($u['name']) ?><div class="muted small"><?= h($u['email']) ?></div></td>
        <td><?= $u['last_login_at'] ? h(fmt_datetime($u['last_login_at'])) : '<span class="muted">Never</span>' ?></td>
        <td><?= $u['active'] ? ($u['locked_until'] && strtotime($u['locked_until']) > time() ? '<span class="badge badge-suspended">Locked for now</span>' : badge('active')) : badge('disabled') ?></td>
        <?php if ($canEdit): ?><td class="right whitespace-nowrap">
          <?php if ($u['active']): ?><form method="post" class="inline" action="<?= h(url('dealer_orders', $base + ['do' => 'reset'])) ?>" data-confirm="Email <?= h($u['name']) ?> a new temporary password?"><?= csrf_field() ?><button class="btn btn-sm">New password</button></form><?php endif; ?>
          <form method="post" class="inline" action="<?= h(url('dealer_orders', $base + ['do' => 'toggle'])) ?>"><?= csrf_field() ?><button class="btn btn-sm <?= $u['active'] ? 'btn-danger' : '' ?>"><?= $u['active'] ? 'Remove access' : 'Restore access' ?></button></form>
        </td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$portal['users']): ?><tr><td colspan="4" class="empty-row">Nobody at this dealer has portal access yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($canEdit): ?>
    <form method="post" action="<?= h(url('dealer_orders', ['action' => 'users', 'account_id' => $account['id'], 'do' => 'add'])) ?>" class="filters">
      <?= csrf_field() ?>
      <input name="name" placeholder="Name" required aria-label="Name">
      <input type="email" name="email" placeholder="Email" required aria-label="Email">
      <button class="btn btn-primary">Give portal access</button>
      <span class="help">They're emailed a temporary password, changed when they first sign in.</span>
    </form>
  <?php endif; ?>
</section>
<?php if ($portal['orders']): ?>
<section class="card">
  <div class="card-head"><h2>Portal orders</h2><a href="<?= h(url('dealer_orders', ['show' => 'all'])) ?>">All dealer orders →</a></div>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Order</th><th>Customer</th><th>Product</th><th>Sent</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($portal['orders'] as $o): ?>
      <tr><td><a href="<?= h(url('dealer_orders', ['action' => 'view', 'id' => $o['id']])) ?>"><?= h($o['reference']) ?></a></td><td><?= h($o['account_name']) ?></td><td><?= h((string)$o['product_name']) ?></td>
        <td><?= h(fmt_date($o['created_at'])) ?></td><td><?= h(DEALER_ORDER_STATUSES[$o['status']] ?? $o['status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
