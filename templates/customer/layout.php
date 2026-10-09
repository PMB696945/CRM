<?php
$go = (string)($_GET['go'] ?? 'home');
$flash = flash();
$logo = brand_logo_data_uri();
$nav = ['home' => ['dashboard', 'Overview'], 'services' => ['signal', 'Services'], 'orders' => ['inbox', 'Orders'], 'agreements' => ['signature', 'Agreements'], 'tickets' => ['ticket', 'Support tickets'], 'password' => ['key', 'Your password']];
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h(($title ? $title . ' · ' : '') . customer_portal_name()) ?></title>
<?= theme_head(portal_asset_url('app.css')) ?>
</head>
<body>
<?php if (!$user): ?>
<div class="auth-page">
  <div class="auth-form">
    <div class="login-card">
      <span class="brand"><?php if ($logo): ?><img src="<?= h($logo) ?>" alt="" style="max-height:40px;max-width:180px"><?php else: ?><span class="brand-mark"><?= icon('phone') ?></span><?php endif; ?><?= h(company('name', config('app_name'))) ?></span>
      <?php if ($flash): ?><div class="flash flash-<?= h($flash['type']) ?> mb-0"><?= h($flash['message']) ?></div><?php endif; ?>
      <?= $content ?>
    </div>
  </div>
  <div class="auth-panel">
    <div class="relative z-1 flex max-w-xs flex-col items-center text-center">
      <span class="brand-mark mb-4 size-14! rounded-2xl"><?= icon('signal', 'size-8!') ?></span>
      <p class="text-2xl font-semibold text-white">Your account</p>
      <p class="mt-2 text-gray-400">Your services and their setup details, orders, agreements and support tickets, in one place.</p>
    </div>
  </div>
</div>
<?php else: ?>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <a class="brand" href="<?= h(portal_url('home')) ?>"><?php if ($logo): ?><img src="<?= h($logo) ?>" alt="" style="max-height:32px;max-width:150px"><?php else: ?><span class="brand-mark"><?= icon('phone') ?></span><?= h(company('name', config('app_name'))) ?><?php endif; ?></a>
    </div>
    <nav aria-label="Main">
      <h3 class="menu-group-title">Your account</h3>
      <div class="menu">
        <?php foreach ($nav as $key => [$ico, $label]): $active = $go === $key || ($key === 'tickets' && in_array($go, ['ticket', 'ticket_new'], true)); ?>
          <a href="<?= h(portal_url($key)) ?>" class="menu-item <?= $active ? 'active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>
    </nav>
    <div class="sidebar-foot">
      <span class="avatar"><?= h(initials($user['name'])) ?></span>
      <span class="me"><?= h($user['name']) ?><small><?= h($user['account_name']) ?></small></span>
      <a class="icon-btn" href="<?= h(portal_url('logout')) ?>" title="Sign out" aria-label="Sign out"><?= icon('logout') ?></a>
    </div>
  </aside>
  <div class="overlay" data-sidebar-close></div>
  <div class="main">
    <header class="topbar">
      <button type="button" class="icon-btn burger" data-sidebar-toggle aria-label="Open menu" aria-controls="sidebar"><?= icon('menu') ?></button>
      <span class="muted"><?= h(customer_portal_name()) ?></span>
      <div class="quick-add">
        <button type="button" class="icon-btn round theme-toggle" data-theme-toggle aria-label="Toggle dark mode" title="Toggle dark mode"><?= icon('moon', 'icon-moon') ?><?= icon('sun', 'icon-sun') ?></button>
      </div>
    </header>
    <main class="content">
      <?php if ($flash): ?><div class="flash flash-<?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<?php endif; ?>
<script src="<?= h(portal_asset_url('app.js')) ?>"></script>
</body>
</html>
