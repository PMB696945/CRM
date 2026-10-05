<?php $company = company('name', config('app_name')); ?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $order ? h('Order ' . $order['reference'] . ' · ' . $company) : 'Order not found' ?></title>
<?= theme_head() ?>
</head>
<body class="bg-gray-50 dark:bg-gray-900">
<div class="mx-auto max-w-3xl px-4 py-8 sm:py-12">
  <div class="mb-6 flex items-center justify-between gap-4">
    <?php if ($logoUri = brand_logo_data_uri()): ?><img class="public-logo" src="<?= h($logoUri) ?>" alt="<?= h($company) ?>"><?php else: ?><span class="brand"><span class="brand-mark"><?= icon('phone') ?></span><?= h($company) ?></span><?php endif; ?>
    <?php if ($order): ?><span class="muted text-sm">Order <?= h($order['reference']) ?></span><?php endif; ?>
  </div>
<?php if (!$order): ?>
  <div class="card empty"><h1 class="justify-center">We couldn't find that order</h1>
    <p class="muted mt-2">Please check the link, or contact <?= h($company) ?><?= company('email') ? ' at <a href="mailto:' . h(company('email')) . '">' . h(company('email')) . '</a>' : '' ?>.</p></div>
<?php else: ?>
  <section class="card">
    <h1><?= h($order['title']) ?></h1>
    <p class="muted mt-1">For <b class="text-gray-800 dark:text-white/90"><?= h($account['name']) ?></b> · ordered <?= h(fmt_date($order['created_at'])) ?></p>
    <?php if ($order['status'] === 'cancelled'): ?>
      <div class="flash flash-error mt-4">This order has been cancelled.</div>
    <?php else: ?>
      <ol class="order-steps mt-4 <?= count($progress) > 4 ? 'order-steps-6' : '' ?>">
        <?php foreach ($progress as $n => $s): ?>
          <li class="<?= h($s['state']) ?>"><span><?= in_array($s['state'], ['done', 'current'], true) ? '✓' : $n + 1 ?></span><?= h($s['label']) ?></li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2 class="mb-3 text-base font-semibold">Updates</h2>
    <ul class="timeline">
      <?php foreach (array_reverse($events) as $e): ?>
        <li><div class="timeline-meta"><?= h(fmt_datetime($e['created_at'])) ?></div><b><?= h(order_status_label($e['status'])) ?></b>
          <?php if ($e['message']): ?><div class="small"><?= nl2br(h($e['message'])) ?></div><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <p class="muted small text-center"><?= h($company) ?><?= company('phone') ? ' · ' . h(company('phone')) : '' ?><?= company('email') ? ' · <a href="mailto:' . h(company('email')) . '">' . h(company('email')) . '</a>' : '' ?></p>
<?php endif; ?>
</div>
</body>
</html>
