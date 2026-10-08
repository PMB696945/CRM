<form class="stack" method="post" action="<?= h(portal_url('login')) ?>">
  <div>
    <h1>Partner sign in</h1>
    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Sign in to check availability and place orders for your customers.</p>
  </div>
  <?php if ($error): ?><div class="flash flash-error mb-0"><?= h($error) ?></div><?php endif; ?>
  <?= csrf_field() ?>
  <label>Email <input type="email" name="email" value="<?= h($email) ?>" required autofocus autocomplete="username" placeholder="you@company.co.uk"></label>
  <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn btn-primary btn-block py-3">Sign in</button>
  <a class="text-center text-sm" href="<?= h(portal_url('forgot')) ?>">Forgotten your password?</a>
</form>
