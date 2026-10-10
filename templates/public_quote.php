<?php $company = company('name', config('app_name')); ?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $quote ? h('Quote ' . $quote['reference'] . ' · ' . $company) : 'Quote not available' ?></title>
<?= theme_head() ?>
</head>
<body class="bg-gray-50 dark:bg-gray-900">
<div class="mx-auto max-w-3xl px-4 py-8 sm:py-12">
  <div class="mb-6 flex items-center justify-between gap-4">
    <?php if ($logoUri = brand_logo_data_uri()): ?><img class="public-logo" src="<?= h($logoUri) ?>" alt="<?= h($company) ?>"><?php else: ?><span class="brand"><span class="brand-mark"><?= icon('phone') ?></span><?= h($company) ?></span><?php endif; ?>
    <?php if ($quote): ?><span class="muted text-sm">Quote <?= h($quote['reference']) ?></span><?php endif; ?>
  </div>

<?php if (!$quote): ?>
  <div class="card empty"><h1 class="justify-center">This link isn't valid any more</h1>
    <p class="muted mt-2">The quote may have been updated or withdrawn. Please contact <?= h($company) ?><?= company('email') ? ' at <a href="mailto:' . h(company('email')) . '">' . h(company('email')) . '</a>' : '' ?> for the latest version.</p></div>
<?php else: $totals = quote_totals($lines); ?>

  <?php if ($done === 'accepted' || $quote['status'] === 'accepted'): ?>
    <div class="flash flash-success">✔ Thank you<?= $quote['response_name'] ? ', ' . h(explode(' ', $quote['response_name'])[0]) : '' ?>. You <?= quote_acceptance_is_request() ? 'asked to go ahead with' : 'accepted' ?> this quote on <?= h(fmt_date($quote['responded_at'])) ?>.
      <?= quote_acceptance_is_request() ? 'This isn\'t a contract yet: ' . (esign_auto_send() ? 'your Contract Summary and agreement will arrive by email shortly, and the contract is made when you sign.' : 'we\'ll be in touch shortly with your Contract Summary and agreement to sign.') : '' ?>
      <?= $quote['confirmation_sent_at'] ? 'We\'ve emailed a confirmation to ' . h($quote['response_email'] ?: $quote['recipient_email']) . '.' : '' ?>
      <a href="?t=<?= h(rawurlencode($token)) ?>&amp;pdf=1">Download a copy (PDF)</a></div>
  <?php elseif ($done === 'declined' || $quote['status'] === 'declined'): ?>
    <div class="flash flash-error">You declined this quote. Thank you for letting us know. If anything changes, just get in touch.</div>
  <?php elseif ($quote['status'] === 'expired'): ?>
    <div class="flash flash-error">This quote expired on <?= h(fmt_date($quote['valid_until'])) ?>. Please contact us for an updated quote.</div>
  <?php endif; ?>
  <?php if ($error): ?><div class="flash flash-error"><?= h($error) ?></div><?php endif; ?>

  <section class="card">
    <h1><?= h($quote['title']) ?></h1>
    <p class="muted mt-1">Prepared for <b class="text-gray-800 dark:text-white/90"><?= h($account['name']) ?></b><?= $quote['recipient_name'] ? ' · ' . h($quote['recipient_name']) : '' ?><?= $quote['valid_until'] ? ' · valid until ' . h(fmt_date($quote['valid_until'])) : '' ?></p>
    <?php if ($quote['intro']): ?><div class="prose mt-4"><?= nl2br(h($quote['intro'])) ?></div><?php endif; ?>
  </section>

  <section class="card">
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Service</th><th class="num">Qty</th><th class="num">Monthly</th><th class="num">One-off</th><th class="num">Term</th></tr></thead>
      <tbody>
      <?php foreach ($lines as $l): ?>
        <tr><td class="text-gray-800 dark:text-white/90"><?= h($l['description']) ?><div class="muted text-theme-xs"><?= h(SERVICE_TYPES[$l['service_type']] ?? '') ?></div></td>
          <td class="num"><?= (int)$l['quantity'] ?></td><td class="num"><?= h(money($l['monthly_price'])) ?></td><td class="num"><?= h(money($l['setup_fee'])) ?></td><td class="num"><?= h(term_label($l['term_months'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-3">
      <div><div class="kpi-label">Monthly total</div><div class="text-xl font-bold text-gray-800 dark:text-white/90"><?= h(money($totals['monthly'])) ?></div><div class="kpi-sub">+ VAT</div></div>
      <div><div class="kpi-label">One-off total</div><div class="text-xl font-bold text-gray-800 dark:text-white/90"><?= h(money($totals['setup'])) ?></div><div class="kpi-sub">+ VAT</div></div>
      <div><div class="kpi-label">Minimum term</div><div class="text-xl font-bold text-gray-800 dark:text-white/90"><?= h(term_label($totals['term'])) ?></div></div>
    </div>
    <?php if ($terms = setting('quote_terms')): ?><p class="help mt-5"><?= nl2br(h($terms)) ?></p><?php endif; ?>
  </section>

  <?php if ($quote['status'] === 'sent'): ?>
  <div class="grid-2">
    <form method="post" class="card stack" action="quote.php?t=<?= h(rawurlencode($token)) ?>">
      <?= csrf_field() ?><input type="hidden" name="response" value="accept">
      <h2><?= quote_acceptance_is_request() ? 'Go ahead with this quote' : 'Accept this quote' ?></h2>
      <label>Your full name<input name="name" required autocomplete="name" value="<?= h($quote['recipient_name']) ?>"></label>
      <label>Your email<input type="email" name="email" required autocomplete="email" value="<?= h($quote['recipient_email']) ?>"></label>
      <?php if (esign_auto_send()): ?><p class="help -mt-2">We'll send the Contract Summary and agreement here, for you to read and sign online.</p><?php endif; ?>
      <label class="check flex-row! items-start gap-2 font-normal!"><input type="checkbox" name="agree" value="1" required class="mt-0.5"> <?= h(quote_acceptance_statement($account)) ?></label>
      <button class="btn btn-primary btn-block py-3"><?= quote_acceptance_is_request() ? 'Go ahead' : 'Accept quote' ?></button>
    </form>
    <form method="post" class="card stack" action="quote.php?t=<?= h(rawurlencode($token)) ?>" data-confirm="Decline this quote?">
      <?= csrf_field() ?><input type="hidden" name="response" value="decline">
      <h2>Not right for you?</h2>
      <label>Tell us why (optional)<textarea name="reason" rows="3"></textarea></label>
      <button class="btn btn-block py-3">Decline quote</button>
    </form>
  </div>
  <?php endif; ?>

  <p class="help mt-6 text-center"><?= h(implode(' · ', array_filter([$company, company('phone'), company('email')]))) ?></p>
<?php endif; ?>
</div>
<script src="<?= h(asset_url('app.js')) ?>"></script>
</body>
</html>
