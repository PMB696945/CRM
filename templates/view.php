<?php $titleField = array_key_first(array_filter($entity['fields'], fn($d) => $d['type'] === 'text' && !empty($d['required']))) ?? 'id'; ?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url($name)) ?>"><?= h($entity['plural']) ?></a></div>
    <h1><?= h($row[$titleField] ?? $entity['label']) ?></h1>
  </div>
  <div class="actions">
    <?php if (can('audit.view')): ?><a class="btn btn-ghost" href="<?= h(url('audit', ['entity' => $name, 'entity_id' => $row['id']])) ?>">History</a><?php endif; ?>
    <?php if ($canWrite): ?><a class="btn" href="<?= h(url($name, ['action' => 'edit', 'id' => $row['id']])) ?>">Edit</a><?php endif; ?>
    <?php if ($canWrite && can('records.delete')) render('_delete', ['name' => $name, 'id' => $row['id'], 'label' => $entity['label']]); ?>
  </div>
</div>
<div class="card">
  <dl class="details">
    <?php foreach ($entity['fields'] + ($entity['computed'] ?? []) as $field => $def): if (!field_enabled($def) || !empty($def['virtual'])) continue;
        if (!empty($def['section'])): ?><dt class="details-section"><?= h($def['section']) ?></dt><dd class="details-section"></dd><?php endif; ?>
      <dt><?= h($def['label']) ?></dt>
      <dd><?= display_value($entity, $field, $row) ?: '<span class="muted">—</span>' ?></dd>
    <?php endforeach; ?>
  </dl>
</div>
