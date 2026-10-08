<div class="stack">
  <div>
    <h1>Forgotten password</h1>
    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Enter your email and we'll send you a new temporary password.</p>
  </div>
  <?php if ($sent): ?>
    <div class="flash flash-success mb-0">If that email has portal access, a new temporary password is on its way. It can take a few minutes to arrive.</div>
    <a class="btn btn-block" href="<?= h(portal_url('login')) ?>">Back to sign in</a>
  <?php else: ?>
    <form class="stack" method="post" action="<?= h(portal_url('forgot')) ?>">
      <?= csrf_field() ?>
      <label>Email <input type="email" name="email" required autofocus autocomplete="username"></label>
      <button class="btn btn-primary btn-block py-3">Send a new password</button>
      <a class="text-center text-sm" href="<?= h(portal_url('login')) ?>">Back to sign in</a>
    </form>
  <?php endif; ?>
</div>
