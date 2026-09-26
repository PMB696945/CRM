<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= h(config('app_name')) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-page">
<form class="login-card" method="post" action="<?= h(url('login')) ?>">
  <div class="brand"><span class="brand-mark">☎</span> <?= h(config('app_name')) ?></div>
  <?php if ($flash = flash()): ?><div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>
  <?= csrf_field() ?>
  <label>Email<input type="email" name="email" value="<?= h($email) ?>" required autofocus autocomplete="username"></label>
  <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn btn-primary btn-block">Sign in</button>
</form>
</body>
</html>
