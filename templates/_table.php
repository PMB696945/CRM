<?php
/** Reusable table. Vars: $entity, $name, $rows, $columns, optional $sortable, $opts */
$sortable ??= false;
?>
<div class="table-wrap">
<table class="table">
  <thead><tr>
    <?php foreach ($columns as $col): $def = column_def($entity, $col); ?>
      <th class="<?= in_array($def['type'], ['money', 'int'], true) ? 'num' : '' ?>">
        <?php if ($sortable):
            $dir = ($opts['sort'] === $col && $opts['dir'] !== 'desc') ? 'desc' : 'asc';
            $link = url($name, array_merge($_GET, ['page' => $name, 'sort' => $col, 'dir' => $dir, 'p' => null])); ?>
          <a href="<?= h($link) ?>"><?= h($def['label']) ?><?= $opts['sort'] === $col ? ($opts['dir'] === 'desc' ? ' ↓' : ' ↑') : '' ?></a>
        <?php else: ?><?= h($def['label']) ?><?php endif; ?>
      </th>
    <?php endforeach; ?>
  </tr></thead>
  <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <?php foreach ($columns as $i => $col): $def = column_def($entity, $col); ?>
          <td class="<?= in_array($def['type'], ['money', 'int'], true) ? 'num' : '' ?>">
            <?php if ($i === 0): ?>
              <a class="row-link" href="<?= h(url($name, ['action' => 'view', 'id' => $row['id']])) ?>"><?= display_value($entity, $col, $row, false) ?: '#' . (int)$row['id'] ?></a>
            <?php else: ?>
              <?= display_value($entity, $col, $row) ?>
            <?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr><td colspan="<?= count($columns) ?>" class="empty-row">Nothing here yet.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>
