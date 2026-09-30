<?php $manage = can('documents.manage'); $editFolder = $manage && query('edit_folder') === '1' && $folder; ?>
<div class="page-head">
  <div>
    <?php if ($folder): ?><div class="crumbs"><a href="<?= h(url('documents')) ?>">Documents</a></div><?php endif; ?>
    <h1><?= h($folder['name'] ?? 'Documents') ?></h1>
    <p class="muted"><?= $folder ? h($folder['description'] ?? '') : 'Spec sheets, brochures and other documents to share with customers. Pick them when you email a quote.' ?></p>
  </div>
  <?php if ($folder && $manage): ?><div class="actions"><a class="btn" href="<?= h(url('documents', ['folder' => $folder['id'], 'edit_folder' => 1])) ?>">Rename folder</a>
    <?php if (!(int)$folder['documents']): ?><form method="post" action="<?= h(url('documents', ['action' => 'folder_delete'])) ?>" class="inline" data-confirm="Delete the <?= h($folder['name']) ?> folder?"><?= csrf_field() ?><input type="hidden" name="folder_id" value="<?= (int)$folder['id'] ?>"><button class="btn btn-danger">Delete folder</button></form><?php endif; ?>
  </div><?php endif; ?>
</div>

<div class="grid-side">
  <div>
    <form class="filters" method="get">
      <input type="hidden" name="page" value="documents"><?php if ($folder): ?><input type="hidden" name="folder" value="<?= (int)$folder['id'] ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search <?= $folder ? 'this folder' : 'documents' ?>…" aria-label="Search documents">
      <button class="btn">Search</button>
    </form>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Document</th><?php if (!$folder): ?><th>Folder</th><?php endif; ?><th>File</th><th>Added</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($docs as $d): ?>
        <tr>
          <td><a class="row-link" href="<?= h(url('documents', ['action' => 'open', 'id' => $d['id']])) ?>" target="_blank" rel="noopener"><?= h($d['title']) ?></a>
            <?php if ($d['description']): ?><div class="muted small"><?= h($d['description']) ?></div><?php endif; ?></td>
          <?php if (!$folder): ?><td class="small"><a href="<?= h(url('documents', ['folder' => $d['folder_id']])) ?>"><?= h($d['folder_name']) ?></a></td><?php endif; ?>
          <td class="small"><?= h(strtoupper(pathinfo($d['file_name'], PATHINFO_EXTENSION))) ?> · <?= h(file_size_label($d['size'])) ?></td>
          <td class="small"><?= h(fmt_date($d['created_at'])) ?><div class="muted"><?= h($d['uploaded_by_name'] ?? '') ?></div></td>
          <td class="right" style="white-space:nowrap">
            <a class="btn btn-sm" href="<?= h(url('documents', ['action' => 'download', 'id' => $d['id']])) ?>">Download</a>
            <?php if ($manage): ?>
              <details class="dropdown inline"><summary class="btn btn-sm btn-ghost">Edit</summary>
                <form method="post" action="<?= h(url('documents', ['action' => 'edit', 'id' => $d['id']])) ?>" class="dropdown-panel card stack text-left">
                  <?= csrf_field() ?><input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
                  <label>Name<input name="title" value="<?= h($d['title']) ?>" required maxlength="190"></label>
                  <label>Description<input name="description" value="<?= h($d['description'] ?? '') ?>" maxlength="500"></label>
                  <label>Folder<select name="folder_id"><?php foreach ($folders as $f): ?><option value="<?= (int)$f['id'] ?>" <?= (int)$f['id'] === (int)$d['folder_id'] ? 'selected' : '' ?>><?= h($f['name']) ?></option><?php endforeach; ?></select></label>
                  <button class="btn btn-primary btn-sm">Save</button>
                </form>
              </details>
              <form method="post" action="<?= h(url('documents', ['action' => 'delete', 'id' => $d['id']])) ?>" class="inline" data-confirm="Delete &quot;<?= h($d['title']) ?>&quot;? It will also be removed from any quotes it was picked for."><?= csrf_field() ?><input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>"><button class="btn btn-sm btn-danger-ghost" aria-label="Delete">✕</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$docs): ?><tr><td colspan="5" class="empty-row"><?= $q !== '' ? 'Nothing matches that search.' : ($folder ? 'No documents in this folder yet.' : 'No documents yet.') ?></td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>

  <aside>
    <section class="card">
      <div class="card-head"><h2>Folders</h2></div>
      <ul class="folder-list">
        <li><a href="<?= h(url('documents')) ?>" class="<?= $folder ? '' : 'active' ?>">All documents</a></li>
        <?php foreach ($folders as $f): ?>
          <li><a href="<?= h(url('documents', ['folder' => $f['id']])) ?>" class="<?= (int)($folder['id'] ?? 0) === (int)$f['id'] ? 'active' : '' ?>"><?= icon('folder', 'size-4') ?><?= h($f['name']) ?><span class="count"><?= (int)$f['documents'] ?></span></a></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($manage): ?>
        <form method="post" action="<?= h(url('documents', ['action' => 'folder_save'])) ?>" class="stack mt-4">
          <?= csrf_field() ?>
          <?php if ($editFolder): ?><input type="hidden" name="folder_id" value="<?= (int)$folder['id'] ?>"><?php endif; ?>
          <label><?= $editFolder ? 'Rename folder' : 'New folder' ?><input name="name" value="<?= h($editFolder ? $folder['name'] : '') ?>" maxlength="80" required placeholder="e.g. Case studies"></label>
          <label>Description (optional)<input name="description" value="<?= h($editFolder ? ($folder['description'] ?? '') : '') ?>" maxlength="255"></label>
          <button class="btn btn-sm"><?= $editFolder ? 'Save folder' : '+ Add folder' ?></button>
        </form>
      <?php endif; ?>
    </section>

    <?php if ($manage && $folders): ?>
    <section class="card">
      <div class="card-head"><h2>Upload</h2></div>
      <form method="post" action="<?= h(url('documents', ['action' => 'upload'])) ?>" enctype="multipart/form-data" class="stack">
        <?= csrf_field() ?><input type="hidden" name="_return" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
        <label>Folder<select name="folder_id" required><?php foreach ($folders as $f): ?><option value="<?= (int)$f['id'] ?>" <?= (int)($folder['id'] ?? 0) === (int)$f['id'] ? 'selected' : '' ?>><?= h($f['name']) ?></option><?php endforeach; ?></select></label>
        <label>File(s)<input type="file" name="files[]" multiple required accept="<?= h(implode(',', array_map(fn($e) => ".$e", array_keys(DOC_TYPES)))) ?>"></label>
        <label>Name (optional)<input name="title" maxlength="190" placeholder="Defaults to the file name"></label>
        <label>Description (optional)<input name="description" maxlength="500"></label>
        <button class="btn btn-primary">Upload</button>
        <p class="help">PDF, Office documents, images, CSV, text or zip, up to <?= h(file_size_label(min(DOC_MAX_BYTES, ini_bytes('upload_max_filesize')))) ?> each.</p>
      </form>
    </section>
    <?php endif; ?>
  </aside>
</div>
