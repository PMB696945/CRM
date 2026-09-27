<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= h(config('app_name')) ?></title>
<?= theme_head() ?>
</head>
<body>
<div class="auth-page">
  <div class="auth-form">
    <form class="login-card" method="post" action="<?= h(url('login')) ?>">
      <a class="brand lg:hidden" href="<?= h(url('login')) ?>"><span class="brand-mark"><?= icon('phone') ?></span><?= h(config('app_name')) ?></a>
      <div>
        <h1>Sign in</h1>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Enter your email and password to sign in.</p>
      </div>
      <?php if ($flash = flash()): ?><div class="flash flash-<?= h($flash['type']) ?> mb-0"><?= h($flash['message']) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="flash flash-error mb-0"><?= h($error) ?></div><?php endif; ?>
      <?= csrf_field() ?>
      <label>Email <input type="email" name="email" value="<?= h($email) ?>" required autofocus autocomplete="username" placeholder="you@company.co.uk"></label>
      <label>Password <input type="password" name="password" required autocomplete="current-password" placeholder="Enter your password"></label>
      <button class="btn btn-primary btn-block py-3">Sign in</button>
    </form>
  </div>
  <?php render('_auth_panel'); ?>
</div>
</body>
</html>
