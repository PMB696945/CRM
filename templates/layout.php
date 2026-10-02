<?php
$user = current_user();
$current = query('page', 'dashboard');
$nav = [
    'Menu' => [
        'dashboard'     => ['dashboard', 'Dashboard'],
    ],
    'Customers' => [
        'accounts'      => ['building', 'Customers'],
        'contacts'      => ['user', 'Contacts'],
        'sites'         => ['map', 'Sites & addresses'],
        'services'      => ['signal', 'Services & lines'],
    ],
    'Support' => [
        'tickets'       => ['ticket', 'Support tickets'],
    ] + (can('tickets.edit') && ticket_queue_group_ids() ? ['queue' => ['inbox', 'Ticket queue']] : []),
    'Sales' => [
        'quotes'        => ['document', 'Quotes'],
        'customer_orders' => ['inbox', 'Orders'],
    ] + (can('orders.check') && giacom_configured() ? ['giacom' => ['bolt', 'Broadband orders']] : []) + [
        'contracts'     => ['signature', 'Contracts'],
        'pipeline'      => ['pipeline', 'Pipeline'],
        'opportunities' => ['pound', 'Opportunities'],
        'activities'    => ['clipboard', 'Activities'],
        'products'      => ['cube', 'Products & tariffs'],
        'documents'     => ['folder', 'Documents'],
    ],
];
if (can('suppliers.view') || can('suppliers.edit') || can('purchasing.edit')) {
    $nav['Purchasing'] = [
        'suppliers'         => ['truck', 'Suppliers'],
        'purchase_orders'   => ['cart', 'Purchase orders'],
        'supplier_invoices' => ['document', 'Supplier invoices'],
        'supplier_products' => ['pound', 'Supplier prices'],
    ];
}
if (can('marketing.send')) {
    $nav['Marketing'] = ['campaigns' => ['megaphone', 'Alerts & marketing']];
}
$pendingApprovals = can('approvals.decide') ? pending_approvals_count() : 0;
$admin = array_filter([
    'approvals'  => can('approvals.decide') ? ['check', 'Approvals'] : null,
    'settings'   => can('settings.manage') ? ['cog', 'Settings'] : null,
    'users'      => can('users.manage') ? ['key', 'Users'] : null,
    'ticket_groups' => can('users.manage') ? ['inbox', 'Ticket groups'] : null,
    'roles'      => is_super_admin() ? ['shield', 'Roles & permissions'] : null,
    'audit'      => can('audit.view') ? ['clipboard', 'Audit trail'] : null,
    'error_log'  => is_super_admin() ? ['shield', 'Error log'] : null,
    'contract_templates' => can('settings.manage') ? ['template', 'Contract templates'] : null,
    'signable'   => can('settings.manage') ? ['signature', 'Signable'] : null,
    'xero'       => can('settings.manage') ? ['link', 'Xero'] : null,
    'gocardless' => can('settings.manage') ? ['bank', 'GoCardless'] : null,
    'mailchimp'  => can('settings.manage') ? ['megaphone', 'Mailchimp'] : null,
    'giacom_settings' => can('settings.manage') ? ['bolt', 'Giacom'] : null,
]);
if ($admin) {
    $nav['Admin'] = $admin;
}
$flash = flash();
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(($title ? $title . ' · ' : '') . config('app_name')) ?></title>
<?= theme_head() ?>
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <a class="brand" href="<?= h(url('dashboard')) ?>"><span class="brand-mark"><?= icon('phone') ?></span><?= h(config('app_name')) ?></a>
    </div>
    <nav aria-label="Main">
      <?php foreach ($nav as $group => $items): ?>
        <h3 class="menu-group-title"><?= h($group) ?></h3>
        <div class="menu">
          <?php foreach ($items as $key => [$ico, $label]): ?>
            <a href="<?= h(url($key)) ?>" class="menu-item <?= $current === $key ? 'active' : '' ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><?= h($label) ?><?php if ($key === 'approvals' && $pendingApprovals): ?><span class="menu-count"><?= $pendingApprovals ?></span><?php endif; ?><?php if ($key === 'queue' && ($queued = ticket_queue_count())): ?><span class="menu-count"><?= $queued ?></span><?php endif; ?><?php if ($key === 'supplier_invoices' && ($toCheck = invoices_attention_count())): ?><span class="menu-count" title="Need checking"><?= $toCheck ?></span><?php endif; ?><?php if ($key === 'customer_orders' && can('onboarding.edit') && ($waiting = orders_waiting_count())): ?><span class="menu-count" title="Waiting to be picked up"><?= $waiting ?></span><?php endif; ?></a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <span class="avatar"><?= h(initials($user['name'])) ?></span>
      <a href="<?= h(url('profile')) ?>" class="me"><?= h($user['name']) ?><small><?= h($user['email']) ?></small></a>
      <form method="post" action="<?= h(url('logout')) ?>"><?= csrf_field() ?><button class="icon-btn" title="Sign out" aria-label="Sign out"><?= icon('logout') ?></button></form>
    </div>
  </aside>
  <div class="overlay" data-sidebar-close></div>

  <div class="main">
    <header class="topbar">
      <button type="button" class="icon-btn burger" data-sidebar-toggle aria-label="Open menu" aria-controls="sidebar"><?= icon('menu') ?></button>
      <form class="global-search" method="get" action="index.php" role="search">
        <?= icon('search') ?>
        <input type="hidden" name="page" value="search">
        <input type="search" name="q" value="<?= h($current === 'search' ? query('q') : '') ?>" placeholder="Search customers, numbers, circuits, tickets…" aria-label="Search">
      </form>
      <div class="quick-add">
        <button type="button" class="icon-btn round theme-toggle" data-theme-toggle aria-label="Toggle dark mode" title="Toggle dark mode"><?= icon('moon', 'icon-moon') ?><?= icon('sun', 'icon-sun') ?></button>
        <a class="btn hidden <?= can('tickets.edit') ? 'sm:inline-flex' : '' ?>" href="<?= h(url('tickets', ['action' => 'new'])) ?>"><?= icon('plus', 'size-4') ?>Ticket</a>
        <?php if (can('customers.edit')): ?><a class="btn btn-primary" href="<?= h(url('accounts', ['action' => 'new'])) ?>"><?= icon('plus', 'size-4') ?><span class="hidden sm:inline">Customer</span></a><?php endif; ?>
      </div>
    </header>
    <main class="content">
      <?php if (can('tickets.alerts') && ($overduePickup = count(tickets_overdue_pickup()))): ?>
        <div class="flash flash-warning" role="status"><?= $overduePickup ?> ticket<?= $overduePickup === 1 ? ' has' : 's have' ?> waited too long without being picked up. <a href="<?= h(url('queue')) ?>">See the queue</a></div>
      <?php endif; ?>
      <?php if ($flash): ?>
        <div class="flash flash-<?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></div>
      <?php endif; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<script src="assets/app.js"></script>
</body>
</html>
