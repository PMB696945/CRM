<?php
declare(strict_types=1);

// Public page behind the unsubscribe link in service alerts and marketing emails.
// Also accepts one-click unsubscribes from email providers (RFC 8058).
require (require dirname(__DIR__) . '/app_root.php') . '/src/bootstrap.php';

security_headers();
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$contactId = (int)($_GET['c'] ?? 0);
$what = ($_GET['w'] ?? '') === 'alerts' ? 'alerts' : 'marketing';
$token = (string)($_GET['t'] ?? '');
$valid = $contactId > 0 && hash_equals(unsubscribe_token($contactId, $what), $token);
$contact = $valid ? db_one('SELECT id, email, marketing_email, service_alerts FROM contacts WHERE id = ?', [$contactId]) : null;
$done = false;

if ($contact && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    marketing_unsubscribe($contactId, $what, 'unsubscribe link');
    $done = true;
    if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
        http_response_code(200);
        exit('Unsubscribed');
    }
}
$already = $contact && ($what === 'alerts' ? !$contact['service_alerts'] : !$contact['marketing_email']);
$label = $what === 'alerts' ? 'service alert emails' : 'marketing emails';
$masked = $contact ? preg_replace('/(?<=.).(?=[^@]*@)/', '•', $contact['email']) : '';
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Email preferences · <?= h(company('name', config('app_name'))) ?></title>
<?= theme_head() ?>
</head>
<body class="public">
<main class="public-main" style="max-width:560px;margin:3rem auto;padding:0 1rem">
  <section class="card">
    <h1><?= h(company('name', config('app_name'))) ?></h1>
    <?php if (!$contact): ?>
      <p>This link isn't valid. It may have been copied incorrectly. Please reply to the email you received and we'll update your preferences.</p>
    <?php elseif ($done || $already): ?>
      <p><b>Done.</b> <?= h($masked) ?> won't receive <?= h($label) ?> from us any more.</p>
      <?php if ($what === 'alerts'): ?><p class="muted">We'll still contact you about your account and orders when we need to.</p><?php endif; ?>
    <?php else: ?>
      <p>Stop sending <?= h($label) ?> to <b><?= h($masked) ?></b>?</p>
      <?php if ($what === 'alerts'): ?><p class="muted">Service alerts tell you about faults, planned maintenance and outages affecting your services.</p><?php endif; ?>
      <form method="post"><button class="btn btn-primary">Unsubscribe</button></form>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
