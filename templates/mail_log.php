<div class="page-head">
  <h1>Email log</h1>
  <div class="actions"><a class="btn" href="<?= h(url('settings')) ?>">Email settings</a></div>
</div>
<p class="lead">Every email the CRM has sent or tried to send, newest first (kept for 180 days). "Sent" means your email service accepted it: if it still didn't arrive, check the recipient's spam folder and your email service's own logs.</p>
<?php if ($failures && !$failed): ?>
  <div class="flash flash-error"><?= (int)$failures ?> email<?= $failures === 1 ? '' : 's' ?> failed in the last 7 days. <a href="<?= h(url('mail_log', ['status' => 'failed'])) ?>">Show failures</a></div>
<?php endif; ?>
<form class="filters" method="get" action="index.php">
  <input type="hidden" name="page" value="mail_log">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search address or subject…">
  <select name="status" data-autosubmit aria-label="Result"><option value="">Result: any</option><option value="failed" <?= $failed ? 'selected' : '' ?>>Failed only</option></select>
  <button class="btn">Filter</button>
</form>
<div class="table-wrap"><table class="table compact">
  <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Result</th><th>Sent by</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="whitespace-nowrap"><?= h(fmt_datetime($r['created_at'])) ?></td>
      <td><?= h((string)$r['to_name']) ?> <span class="muted">&lt;<?= h($r['to_email']) ?>&gt;</span></td>
      <td><?= h($r['subject']) ?></td>
      <td><?php if ($r['status'] === 'sent'): ?><span class="badge badge-sent">Sent</span><?php else: ?><span class="badge badge-failed">Failed</span>
        <div class="small text-danger"><?= h((string)$r['error']) ?></div><?php endif; ?></td>
      <td class="muted"><?= h(['php' => 'Server mail', 'smtp' => 'SMTP', 'mandrill' => 'Mailchimp Transactional'][$r['transport']] ?? $r['transport']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="empty-row"><?= $q !== '' || $failed ? 'No emails match.' : 'No emails have been sent since the log was added.' ?></td></tr><?php endif; ?>
  </tbody>
</table></div>
