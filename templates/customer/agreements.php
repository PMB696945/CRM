<div class="page-head"><div><h1>Agreements</h1><p class="muted">Your agreements with <?= h(company('name', config('app_name'))) ?>, and their documents.</p></div></div>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Agreement</th><th>Status</th><th>Documents</th></tr></thead>
  <tbody>
  <?php foreach ($contracts as $c): $docs = contract_documents($c); ?>
    <tr>
      <td><b><?= h((string)$c['reference']) ?></b><div class="muted small"><?= h($c['title']) ?></div></td>
      <td><?php if ($c['status'] === 'signed'): ?><span class="badge badge-signed">Signed</span><div class="small muted"><?= h(fmt_date($c['signed_at'])) ?> by <?= h((string)($c['signed_name'] ?? $c['signer_name'])) ?></div>
        <?php elseif ($c['status'] === 'sent'): ?><span class="badge badge-sent">To sign</span><div><a class="btn btn-sm btn-primary" href="<?= h(esign_url($c)) ?>" target="_blank" rel="noopener">Review and sign</a></div>
        <?php else: ?><span class="badge"><?= h(ucfirst($c['status'])) ?></span><?php endif; ?></td>
      <td class="small"><?php foreach ($docs as $d): ?><div><a href="<?= h(portal_url('document', ['id' => $c['id'], 'file' => $d['file']])) ?>"><?= h($d['title']) ?></a></div><?php endforeach; ?>
        <?php if ($c['signed_file']): ?><div><a href="<?= h(portal_url('document', ['id' => $c['id'], 'file' => $c['signed_file']])) ?>">Signed copy</a></div><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$contracts): ?><tr><td colspan="3" class="empty-row">No agreements yet.</td></tr><?php endif; ?>
  </tbody>
</table></div>
