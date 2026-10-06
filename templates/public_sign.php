<?php
$company = company('name', config('app_name'));
$base = 'sign.php?t=' . rawurlencode($token);
$maskEmail = function (string $email): string {
    [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($user, 0, 2) . str_repeat('•', max(1, mb_strlen($user) - 2)) . '@' . $domain;
};
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $contract ? h('Sign ' . $contract['reference'] . ' · ' . $company) : 'Agreement not available' ?></title>
<?= theme_head() ?>
</head>
<body class="bg-gray-50 dark:bg-gray-900">
<div class="mx-auto max-w-3xl px-4 py-8 sm:py-12">
  <div class="mb-6 flex items-center justify-between gap-4">
    <?php if ($logoUri = brand_logo_data_uri()): ?><img class="public-logo" src="<?= h($logoUri) ?>" alt="<?= h($company) ?>"><?php else: ?><span class="brand"><span class="brand-mark"><?= icon('phone') ?></span><?= h($company) ?></span><?php endif; ?>
    <?php if ($contract): ?><span class="muted text-sm">Agreement <?= h($contract['reference']) ?></span><?php endif; ?>
  </div>

<?php if (!$contract): ?>
  <div class="card empty"><h1 class="justify-center">This link isn't valid</h1>
    <p class="muted mt-2">The agreement may have been updated or withdrawn. Please contact <?= h($company) ?><?= company('email') ? ' at <a href="mailto:' . h(company('email')) . '">' . h(company('email')) . '</a>' : '' ?>.</p></div>
<?php elseif ($contract['status'] === 'signed'): ?>
  <div class="flash flash-success">✔ Signed by <?= h($contract['signed_name'] ?: $contract['signer_name']) ?> on <?= h(fmt_datetime($contract['signed_at'])) ?>. Thank you.
    <?= $contract['signed_ip'] ? 'A copy of the agreement and its signature certificate has been emailed to ' . h($contract['signer_email']) . '.' : '' ?></div>
  <section class="card">
    <h1><?= h($contract['title']) ?></h1>
    <p class="muted mt-1">For <b class="text-gray-800 dark:text-white/90"><?= h($account['name']) ?></b></p>
    <ul class="mt-4 space-y-2">
      <?php foreach ($docs as $i => $d): ?><li><a href="<?= h($base . '&doc=' . $i) ?>"><?= icon('document', 'inline size-5 mr-1 text-gray-400') ?><?= h($d['title']) ?> (Word)</a></li><?php endforeach; ?>
      <?php if ($contract['signed_ip']): ?><li><a href="<?= h($base . '&cert=1') ?>"><?= icon('document', 'inline size-5 mr-1 text-gray-400') ?>Signature certificate (PDF)</a></li><?php endif; ?>
    </ul>
  </section>
<?php elseif ($contract['status'] !== 'sent'): ?>
  <div class="card empty"><h1 class="justify-center"><?= $contract['status'] === 'rejected' ? 'You declined this agreement' : 'This agreement is no longer available to sign' ?></h1>
    <p class="muted mt-2">If you have any questions, please contact <?= h($company) ?><?= company('email') ? ' at <a href="mailto:' . h(company('email')) . '">' . h(company('email')) . '</a>' : '' ?>.</p></div>
<?php else: ?>
  <?php if ($error): ?><div class="flash flash-error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <?php if ($notice): ?><div class="flash flash-info" role="status"><?= h($notice) ?></div><?php endif; ?>

<?php
    $summary = contract_summary_document($contract);
    $agreements = array_filter($docs, fn($d) => ($d['kind'] ?? '') !== 'summary');
    $n = 0;
    $step = function (string $title, bool $done = false) use (&$n) { $n++; return '<span class="step ' . ($done ? 'step-done' : '') . '">' . $n . '</span> ' . h($title); };
  ?>
  <section class="card">
    <h1><?= h($contract['title']) ?></h1>
    <p class="muted mt-1">Between <b class="text-gray-800 dark:text-white/90"><?= h($company) ?></b> and <b class="text-gray-800 dark:text-white/90"><?= h($account['name']) ?></b> · to be signed by <?= h($contract['signer_name']) ?></p>
    <p class="mt-3"><?= $summary ? 'Please read the Contract Summary first, then the agreement, then sign at the bottom of the page. Copies of both were attached to the email we sent you.' : 'Please read the agreement below, then sign at the bottom of the page.' ?></p>
  </section>

  <?php if ($summary): ?>
  <section class="card" id="summary">
    <div class="card-head"><h2><?= $step('Contract Summary', $summaryConfirmed) ?></h2><a class="btn btn-sm" href="<?= h($base . '&doc=' . $summary['index']) ?>">Download (Word)</a></div>
    <?php if ($summaryConfirmed): ?>
      <p class="text-ok">✔ You confirmed you'd received and read it on <?= h(fmt_datetime($contract['summary_ack_at'])) ?>.</p>
      <details class="mt-2"><summary class="cursor-pointer text-sm">Show it again</summary><div class="doc-preview mt-2"><?= $previews[$summary['index']] ?: '' ?></div></details>
    <?php else: ?>
      <p class="help">The main points of this agreement: the services, prices, how long it lasts and how to end it.</p>
      <div class="doc-preview"><?= $previews[$summary['index']] ?: '<p class="muted">This document can\'t be shown here. Please download it to read it.</p>' ?></div>
      <form method="post" action="<?= h($base) ?>#summary" class="stack mt-4">
        <?= csrf_field() ?><input type="hidden" name="action" value="confirm_summary">
        <label class="check flex-row! items-start gap-2 font-normal!"><input type="checkbox" name="received" value="1" required class="mt-0.5"> <?= h(esign_summary_statement($account['name'])) ?></label>
        <div><button class="btn btn-primary">Confirm and continue</button></div>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php foreach ($agreements as $i => $d): ?>
  <section class="card">
    <div class="card-head"><h2><?= $i === array_key_first($agreements) ? $step($d['title']) : h($d['title']) ?></h2><a class="btn btn-sm" href="<?= h($base . '&doc=' . $i) ?>">Download (Word)</a></div>
    <?php if ($summaryConfirmed): ?>
      <div class="doc-preview"><?= $previews[$i] ?: '<p class="muted">This document can\'t be shown here. Please download it to read it.</p>' ?></div>
    <?php else: ?>
      <p class="muted">Shown here once you've confirmed the Contract Summary above.</p>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>

  <?php if ($summaryConfirmed): ?>
  <section class="card stack" id="sign">
    <h2>Sign the agreement</h2>
    <?php if (!$verified): ?>
      <p><b><?= $step('Confirm it\'s you') ?>.</b> We'll email a 6-digit code to <b><?= h($maskEmail((string)$contract['signer_email'])) ?></b>.</p>
      <?php if ($codeSent): ?>
        <form method="post" action="<?= h($base) ?>#sign" class="stack">
          <?= csrf_field() ?><input type="hidden" name="action" value="verify">
          <label>Code from the email<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus class="max-w-48 text-center font-mono text-lg tracking-widest"></label>
          <div><button class="btn btn-primary">Confirm</button></div>
        </form>
      <?php endif; ?>
      <form method="post" action="<?= h($base) ?>#sign">
        <?= csrf_field() ?><input type="hidden" name="action" value="send_code">
        <button class="btn <?= $codeSent ? 'btn-ghost btn-sm' : 'btn-primary' ?>"><?= $codeSent ? 'Send a new code' : 'Email me a code' ?></button>
      </form>
      <p class="help">Then: type your name and sign.</p>
    <?php else: $n++; ?>
      <p class="text-ok">✔ Email confirmed. <b><?= $step('Sign') ?>.</b></p>
      <form method="post" action="<?= h($base) ?>#sign" class="stack" data-confirm="Sign the agreement now?">
        <?= csrf_field() ?><input type="hidden" name="action" value="sign">
        <div class="grid gap-4 sm:grid-cols-2">
          <label>Your full name<input name="name" required autocomplete="name" value="<?= h($contract['signer_name']) ?>" data-signature-source></label>
          <label>Your position (optional)<input name="position" autocomplete="organization-title" placeholder="e.g. Director"></label>
        </div>
        <div class="signature-preview" aria-hidden="true" data-signature-preview><?= h($contract['signer_name']) ?></div>
        <label class="check flex-row! items-start gap-2 font-normal!"><input type="checkbox" name="agree" value="1" required class="mt-0.5"> <?= h(esign_statement($contract, $account['name'])) ?></label>
        <button class="btn btn-primary btn-block py-3">Sign the agreement</button>
        <p class="help">We'll record each step with the time, your IP address and device, and a fingerprint of the documents, and email you a copy with a signature certificate.</p>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <details class="card">
    <summary class="cursor-pointer font-semibold">I don't want to sign this</summary>
    <form method="post" action="<?= h($base) ?>" class="stack mt-3" data-confirm="Decline this agreement?">
      <?= csrf_field() ?><input type="hidden" name="action" value="decline">
      <label>Tell us why (optional)<textarea name="reason" rows="3"></textarea></label>
      <div><button class="btn">Decline the agreement</button></div>
    </form>
  </details>
<?php endif; ?>
  <p class="help mt-6 text-center"><?= h(implode(' · ', array_filter([$company, company('phone'), company('email')]))) ?></p>
</div>
</body>
</html>
