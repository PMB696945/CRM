<?php
$user = current_user();
$current = query('page', 'dashboard');
$nav = [
    'dashboard'     => ['📊', 'Dashboard'],
    'accounts'      => ['🏢', 'Customers'],
    'contacts'      => ['👤', 'Contacts'],
    'services'      => ['📶', 'Services & lines'],
    'tickets'       => ['🎫', 'Support tickets'],
    'pipeline'      => ['📈', 'Pipeline'],
    'opportunities' => ['💷', 'Opportunities'],
    'activities'    => ['🗒️', 'Activities'],
    'products'      => ['📦', 'Products & tariffs'],
];
if (is_admin()) {
    $nav['users'] = ['🔑', 'Users'];
}
$flash = flash();
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(($title ? $title . ' · ' : '') . config('app_name')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<input type="checkbox" id="nav-toggle" class="nav-toggle" aria-label="Toggle menu">
<aside class="sidebar">
  <a class="brand" href="<?= h(url('dashboard')) ?>"><span class="brand-mark">☎</span> <?= h(config('app_name')) ?></a>
  <nav>
    <?php foreach ($nav as $key => [$icon, $label]): ?>
      <a href="<?= h(url($key)) ?>" class="<?= $current === $key ? 'active' : '' ?>"><span class="nav-icon"><?= $icon ?></span><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-foot">
    <a href="<?= h(url('profile')) ?>" class="me"><?= h($user['name']) ?><small><?= h(humanize($user['role'])) ?></small></a>
    <form method="post" action="<?= h(url('logout')) ?>"><?= csrf_field() ?><button class="link">Sign out</button></form>
  </div>
</aside>
<div class="main">
  <header class="topbar">
    <label for="nav-toggle" class="burger" aria-hidden="true">☰</label>
    <form class="global-search" method="get" action="index.php">
      <input type="hidden" name="page" value="search">
      <input type="search" name="q" value="<?= h($current === 'search' ? query('q') : '') ?>" placeholder="Search customers, numbers, circuits, tickets…" aria-label="Search">
    </form>
    <div class="quick-add">
      <a class="btn btn-sm" href="<?= h(url('tickets', ['action' => 'new'])) ?>">+ Ticket</a>
      <a class="btn btn-sm btn-primary" href="<?= h(url('accounts', ['action' => 'new'])) ?>">+ Customer</a>
    </div>
  </header>
  <main class="content">
    <?php if ($flash): ?>
      <div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>
    <?= $content ?>
  </main>
</div>
<script src="assets/app.js"></script>
</body>
</html>
