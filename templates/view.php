<?php $titleField = array_key_first(array_filter($entity['fields'], fn($d) => $d['type'] === 'text' && !empty($d['required']))) ?? 'id'; ?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url($name)) ?>"><?= h($entity['plural']) ?></a></div>
    <h1><?= h($row[$titleField] ?? $entity['label']) ?></h1>
  </div>
  <?php if ($canWrite): ?>
  <div class="actions">
    <a class="btn" href="<?= h(url($name, ['action' => 'edit', 'id' => $row['id']])) ?>">Edit</a>
    <?php render('_delete', ['name' => $name, 'id' => $row['id'], 'label' => $entity['label']]); ?>
  </div>
  <?php endif; ?>
</div>
<div class="card">
  <dl class="details">
    <?php foreach ($entity['fields'] + ($entity['computed'] ?? []) as $field => $def): if (!field_enabled($def)) continue; ?>
      <dt><?= h($def['label']) ?></dt>
      <dd><?= display_value($entity, $field, $row) ?: '<span class="muted">—</span>' ?></dd>
    <?php endforeach; ?>
  </dl>
</div>
