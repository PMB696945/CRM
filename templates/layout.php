<?php
$user = current_user();
$current = query('page', 'dashboard');
$nav = [
    'Menu' => [
        'dashboard'     => ['dashboard', 'Dashboard'],
        'accounts'      => ['building', 'Customers'],
        'contacts'      => ['user', 'Contacts'],
        'services'      => ['signal', 'Services & lines'],
        'tickets'       => ['ticket', 'Support tickets'],
    ],
    'Sales' => [
        'quotes'        => ['document', 'Quotes'],
        'contracts'     => ['signature', 'Contracts'],
        'pipeline'      => ['pipeline', 'Pipeline'],
        'opportunities' => ['pound', 'Opportunities'],
        'activities'    => ['clipboard', 'Activities'],
        'products'      => ['cube', 'Products & tariffs'],
    ],
];
if (is_admin()) {
    $nav['Admin'] = [
        'settings'   => ['cog', 'Settings'],
        'users'      => ['key', 'Users'],
        'contract_templates' => ['template', 'Contract templates'],
        'signable'   => ['signature', 'Signable'],
        'audit'      => ['clipboard', 'Audit log'],
        'xero'       => ['link', 'Xero'],
        'gocardless' => ['bank', 'GoCardless'],
    ];
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
            <a href="<?= h(url($key)) ?>" class="menu-item <?= $current === $key ? 'active' : '' ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><?= h($label) ?></a>
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
        <a class="btn hidden sm:inline-flex" href="<?= h(url('tickets', ['action' => 'new'])) ?>"><?= icon('plus', 'size-4') ?>Ticket</a>
        <a class="btn btn-primary" href="<?= h(url('accounts', ['action' => 'new'])) ?>"><?= icon('plus', 'size-4') ?><span class="hidden sm:inline">Customer</span></a>
      </div>
    </header>
    <main class="content">
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
