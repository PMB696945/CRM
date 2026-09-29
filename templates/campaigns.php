<div class="page-head">
  <h1>Service alerts &amp; marketing</h1>
  <div class="actions">
    <a class="btn" href="<?= h(url('campaigns', ['action' => 'new', 'kind' => 'marketing'])) ?>">+ Marketing email</a>
    <a class="btn btn-primary" href="<?= h(url('campaigns', ['action' => 'new', 'kind' => 'service_alert'])) ?>">+ Service alert</a>
  </div>
</div>
<p class="lead">Email customers based on the services they have with you: planned maintenance, outages, price changes or offers. Service alerts go to people who haven't opted out; marketing only to people who opted in.</p>
<nav class="tabs">
  <a href="<?= h(url('campaigns')) ?>" class="<?= $kind === '' ? 'active' : '' ?>">All</a>
  <?php foreach (CAMPAIGN_KINDS as $k => $label): ?><a href="<?= h(url('campaigns', ['kind' => $k])) ?>" class="<?= $kind === $k ? 'active' : '' ?>"><?= h($label) ?>s</a><?php endforeach; ?>
</nav>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Ref</th><th>Subject</th><th>Type</th><th>Status</th><th class="num">Recipients</th><th>Sent</th><th>By</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): ?>
    <tr>
      <td><a class="row-link" href="<?= h(url('campaigns', ['action' => 'view', 'id' => $c['id']])) ?>"><?= h($c['reference']) ?></a></td>
      <td><?= h($c['subject']) ?></td>
      <td><?= badge($c['kind']) ?><?= $c['channel'] === 'mailchimp' ? ' <span class="muted small">via Mailchimp</span>' : '' ?></td>
      <td><?= badge($c['status']) ?></td>
      <td class="num"><?= $c['status'] === 'draft' ? '<span class="muted">—</span>' : (int)$c['sent_count'] . ' / ' . (int)$c['recipients'] . ($c['failed_count'] ? ' <span class="text-danger small">' . (int)$c['failed_count'] . ' failed</span>' : '') ?></td>
      <td><?= h(fmt_datetime($c['sent_at'])) ?></td>
      <td><?= h($c['created_by_name'] ?? '—') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty-row">Nothing sent yet.</td></tr><?php endif; ?>
  </tbody>
</table></div>
