<?php
$id = (int)$campaign['id'];
$draft = $campaign['status'] === 'draft';
$accounts = count(array_unique(array_column($audience, 'account_id')));
$labels = [];
if ($filters['service_types'] ?? []) $labels[] = 'Services: ' . implode(', ', array_map(fn($t) => SERVICE_TYPES[$t] ?? $t, $filters['service_types']));
if ($filters['carriers'] ?? []) $labels[] = 'Carrier: ' . implode(', ', $filters['carriers']);
if ($filters['product_ids'] ?? []) $labels[] = 'Products: ' . implode(', ', array_column(db_all('SELECT name FROM products WHERE id IN (' . implode(',', array_map('intval', $filters['product_ids'])) . ')'), 'name'));
if (($filters['postcodes'] ?? '') !== '') $labels[] = 'Postcodes: ' . $filters['postcodes'];
$labels[] = 'Customer status: ' . implode(', ', array_map('humanize', $filters['statuses'] ?? ['active']));
if ($filters['account_type'] ?? '') $labels[] = humanize($filters['account_type']) . ' customers only';
if ($filters['dealer_id'] ?? null) $labels[] = 'Dealer: ' . db_value('SELECT name FROM accounts WHERE id = ?', [$filters['dealer_id']]);
if ($filters['account_id'] ?? null) $labels[] = 'Customer: ' . db_value('SELECT name FROM accounts WHERE id = ?', [$filters['account_id']]);
if ($campaign['kind'] === 'marketing') $labels[] = 'Topic: ' . (($filters['topic'] ?? '') !== '' ? (marketing_topics()[$filters['topic']] ?? $filters['topic']) : 'general');
else $labels[] = ALERT_AUDIENCES[$filters['who'] ?? 'main_site'];
$problems = campaign_content_problems($campaign);
$done = (int)$campaign['sent_count'] + (int)$campaign['failed_count'];
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url('campaigns')) ?>">Alerts &amp; marketing</a> · <?= h($campaign['reference']) ?></div>
    <h1><?= h($campaign['subject']) ?> <?= badge($campaign['kind']) ?> <?= badge($campaign['status']) ?></h1>
  </div>
  <div class="actions">
    <?php if ($draft): ?>
      <a class="btn" href="<?= h(url('campaigns', ['action' => 'edit', 'id' => $id])) ?>">Edit</a>
      <form method="post" action="<?= h(url('campaigns', ['action' => 'delete', 'id' => $id])) ?>" class="inline" data-confirm="Delete this draft?"><?= csrf_field() ?><button class="btn btn-ghost">Delete</button></form>
    <?php endif; ?>
    <form method="post" action="<?= h(url('campaigns', ['action' => 'duplicate', 'id' => $id])) ?>" class="inline"><?= csrf_field() ?><button class="btn">Duplicate</button></form>
  </div>
</div>

<div class="grid-side">
  <div>
    <?php if ($draft): ?>
      <section class="card">
        <div class="card-head"><h2>Recipients <span class="count"><?= count($audience) ?></span></h2></div>
        <p><?= count($audience) ?> <?= count($audience) === 1 ? 'person' : 'people' ?> at <?= $accounts ?> customer<?= $accounts === 1 ? '' : 's' ?>.
          <?php if ($unreachable): ?><span class="text-warning"><?= count($unreachable) ?> matching customer<?= count($unreachable) === 1 ? ' has' : 's have' ?> nobody to email</span> (no contact with an email address who gets alerts).<?php endif; ?></p>
        <?php if ($unreachable): ?>
          <details><summary class="small">Customers nobody will hear from</summary>
            <ul class="link-list"><?php foreach ($unreachable as $aid => $name): ?><li><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $aid])) ?>"><?= h($name) ?></a></li><?php endforeach; ?></ul>
          </details>
        <?php endif; ?>
        <?php if ($audience): ?>
          <div class="table-wrap"><table class="table table-compact">
            <thead><tr><th>Name</th><th>Email</th><th>Customer</th><?php if ($campaign['kind'] === 'service_alert'): ?><th>Affected services</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach (array_slice($audience, 0, 200) as $r): ?>
              <tr><td><?= h($r['name']) ?></td><td><?= h($r['email']) ?></td>
                <td><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $r['account_id']])) ?>"><?= h($r['account_name']) ?></a></td>
                <?php if ($campaign['kind'] === 'service_alert'): ?><td class="small"><?= h(implode(', ', array_slice(array_column($r['services'], 'identifier'), 0, 4))) ?><?= count($r['services']) > 4 ? ' +' . (count($r['services']) - 4) : '' ?></td><?php endif; ?></tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <?php if (count($audience) > 200): ?><p class="help">Showing the first 200.</p><?php endif; ?>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <section class="card">
        <div class="card-head"><h2>Delivery</h2></div>
        <p><?= (int)$campaign['sent_count'] ?> sent<?= $campaign['failed_count'] ? ', <span class="text-danger">' . (int)$campaign['failed_count'] . ' failed</span>' : '' ?> of <?= (int)$campaign['recipients'] ?>
          <?= $campaign['channel'] === 'mailchimp' ? ' · via Mailchimp' . ($campaign['mailchimp_id'] ? ' (campaign ' . h($campaign['mailchimp_id']) . ') – see Mailchimp for opens and clicks' : '') : '' ?>.
          Sent by <?= h($campaign['sent_by_name'] ?? '—') ?>, <?= h(fmt_datetime($campaign['sent_at'])) ?>.</p>
        <?php if ($campaign['last_error']): ?><p class="text-danger"><?= h($campaign['last_error']) ?></p><?php endif; ?>
        <?php if ($campaign['status'] === 'sending'): ?>
          <div class="progress" role="progressbar" aria-valuenow="<?= $done ?>" aria-valuemax="<?= (int)$campaign['recipients'] ?>"><span style="width:<?= $campaign['recipients'] ? round(100 * $done / (int)$campaign['recipients']) : 0 ?>%"></span></div>
          <p class="help"><?= $pending ?> still to send. Keep this page open, or leave it to the cron job.</p>
          <div class="actions">
            <form method="post" action="<?= h(url('campaigns', ['action' => 'continue', 'id' => $id])) ?>" data-auto-continue><?= csrf_field() ?><button class="btn btn-primary">Send next batch</button></form>
            <form method="post" action="<?= h(url('campaigns', ['action' => 'cancel', 'id' => $id])) ?>" data-confirm="Stop sending? Emails already sent can't be recalled."><?= csrf_field() ?><button class="btn btn-ghost">Stop</button></form>
          </div>
        <?php endif; ?>
        <div class="table-wrap"><table class="table table-compact">
          <thead><tr><th>Email</th><th>Name</th><th>Customer</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recipients as $r): ?>
            <tr><td><?= h($r['email']) ?></td><td><?= h($r['name']) ?></td><td><?= h($r['account_name'] ?? '—') ?></td>
              <td><?= badge($r['status']) ?><?php if ($r['error']): ?> <span class="small text-danger"><?= h($r['error']) ?></span><?php endif; ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-head"><h2>Preview</h2><?php if ($draft && $audience): ?><span class="muted small">As <?= h($audience[0]['name']) ?> at <?= h($audience[0]['account_name']) ?> will see it</span><?php endif; ?></div>
      <iframe class="email-preview" sandbox src="<?= h(url('campaigns', ['action' => 'preview', 'id' => $id])) ?>" title="Email preview"></iframe>
    </section>
  </div>

  <aside>
    <section class="card">
      <div class="card-head"><h2>Audience</h2></div>
      <ul class="feed small"><?php foreach ($labels as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ul>
      <p class="small muted">Sending through <?= $campaign['channel'] === 'mailchimp' ? 'Mailchimp' : 'the CRM\'s email' ?>. Drafted by <?= h($campaign['created_by_name'] ?? '—') ?>.</p>
    </section>
    <?php if ($draft): ?>
      <section class="card">
        <div class="card-head"><h2>Send</h2></div>
        <?php foreach ($problems as $p): ?><p class="text-danger small"><?= h($p) ?></p><?php endforeach; ?>
        <form method="post" action="<?= h(url('campaigns', ['action' => 'test', 'id' => $id])) ?>"><?= csrf_field() ?><button class="btn">Send me a test</button></form>
        <form method="post" action="<?= h(url('campaigns', ['action' => 'send', 'id' => $id])) ?>" style="margin-top:1rem"
              data-confirm="Send &quot;<?= h($campaign['subject']) ?>&quot; to <?= count($audience) ?> <?= count($audience) === 1 ? 'person' : 'people' ?> now?"><?= csrf_field() ?>
          <button class="btn btn-primary" <?= !$audience || $problems ? 'disabled' : '' ?>>Send to <?= count($audience) ?> <?= count($audience) === 1 ? 'person' : 'people' ?></button>
        </form>
      </section>
    <?php endif; ?>
  </aside>
</div>
