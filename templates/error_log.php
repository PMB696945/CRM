<div class="page-head"><div><h1>Error log</h1>
  <p class="muted">Newest first. The full file is <code><?= h(str_replace(APP_ROOT . '/', '', $path)) ?></code> (<?= h(file_size_label($size)) ?>). Customers who hit an error are shown a reference such as <b>A1B2C3D4</b>; search this page for it.</p></div>
  <?php if ($lines): ?><div class="actions"><form method="post" class="inline" data-confirm="Clear the error log?"><?= csrf_field() ?><button class="btn btn-danger">Clear log</button></form></div><?php endif; ?>
</div>
<?php if ($lines): ?>
  <div class="card"><ul class="file-list">
    <?php foreach ($lines as $l): $isError = str_contains($l, 'CRM error') || stripos($l, 'fatal') !== false; ?>
      <li><pre class="small <?= $isError ? 'text-danger' : '' ?>" style="white-space:pre-wrap;margin:0;font-family:ui-monospace,monospace"><?= h($l) ?></pre></li>
    <?php endforeach; ?>
  </ul></div>
<?php else: ?>
  <div class="card empty"><p>The error log is empty.</p></div>
<?php endif; ?>
