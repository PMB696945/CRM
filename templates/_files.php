<?php
/* Files kept against a customer or supplier: $docs, $where (['account_id' => n] or ['supplier_id' => n]). */
$canAdd = document_can('add', $where);
$return = $_SERVER['REQUEST_URI'] ?? '';
?>
<section class="card" id="files">
  <div class="card-head"><h2>Files <span class="count"><?= count($docs) ?></span></h2></div>
  <?php if ($docs): ?>
  <ul class="file-list">
    <?php foreach ($docs as $d): ?>
      <li>
        <?= icon('paperclip', 'size-4 shrink-0 text-gray-400') ?>
        <div class="file-main">
          <a href="<?= h(url('documents', ['action' => 'open', 'id' => $d['id']])) ?>" target="_blank" rel="noopener"><?= h($d['title']) ?></a>
          <div class="muted small"><?= h(strtoupper(pathinfo($d['file_name'], PATHINFO_EXTENSION))) ?> · <?= h(file_size_label($d['size'])) ?> · <?= h(fmt_date($d['created_at'])) ?><?= $d['uploaded_by_name'] ? ' · ' . h($d['uploaded_by_name']) : '' ?><?= $d['description'] ? ' · ' . h($d['description']) : '' ?></div>
        </div>
        <a class="btn btn-sm btn-ghost" href="<?= h(url('documents', ['action' => 'download', 'id' => $d['id']])) ?>" aria-label="Download <?= h($d['title']) ?>">↓</a>
        <?php if (document_can('delete', $d)): ?>
          <form method="post" action="<?= h(url('documents', ['action' => 'delete', 'id' => $d['id']])) ?>" class="inline" data-confirm="Delete &quot;<?= h($d['title']) ?>&quot;?"><?= csrf_field() ?><input type="hidden" name="_return" value="<?= h($return) ?>#files"><button class="btn btn-sm btn-danger-ghost" aria-label="Delete">✕</button></form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
    <p class="muted small">No files yet.</p>
  <?php endif; ?>
  <?php if ($canAdd): ?>
  <form method="post" action="<?= h(url('documents', ['action' => 'upload'])) ?>" enctype="multipart/form-data" class="stack mt-4">
    <?= csrf_field() ?><input type="hidden" name="_return" value="<?= h($return) ?>#files">
    <?php foreach ($where as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= (int)$v ?>"><?php endforeach; ?>
    <label>Add files<input type="file" name="files[]" multiple required accept="<?= h(implode(',', array_map(fn($e) => ".$e", array_keys(DOC_TYPES)))) ?>"></label>
    <label>Note (optional)<input name="description" maxlength="500" placeholder="e.g. Signed order form"></label>
    <button class="btn btn-sm">Upload</button>
  </form>
  <?php endif; ?>
</section>
