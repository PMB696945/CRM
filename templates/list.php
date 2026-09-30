<?php
$pages = max(1, (int)ceil($result['total'] / PER_PAGE));
$current = max(1, min($pages, (int)$opts['page']));
$presets = $entity['presets'] ?? [];
$exportParams = array_merge($_GET, ['page' => $name, 'action' => 'export', 'p' => null]);
// Tick boxes to send several products to Xero at once.
$bulk = $name === 'products' && xero_connected() && can('products.edit');
?>
<div class="page-head">
  <h1><?= h($entity['plural']) ?> <small class="count"><?= (int)$result['total'] ?></small></h1>
  <div class="actions">
    <?php if (can('export')): ?><a class="btn" href="<?= h(url($name, $exportParams)) ?>">Export CSV</a><?php endif; ?>
    <?php if ($canWrite && ($name !== 'products' || can('products.edit'))): ?><a class="btn btn-primary" href="<?= h(url($name, ['action' => 'new'])) ?>">+ New <?= h(strtolower($entity['label'])) ?></a><?php endif; ?>
  </div>
</div>

<?php if ($presets): ?>
<div class="tabs">
  <a href="<?= h(url($name)) ?>" class="<?= $opts['preset'] === '' ? 'active' : '' ?>">All</a>
  <?php foreach ($presets as $key => $preset): ?>
    <a href="<?= h(url($name, ['preset' => $key])) ?>" class="<?= $opts['preset'] === $key ? 'active' : '' ?>"><?= h($preset['label']) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<form class="filters" method="get" action="index.php">
  <input type="hidden" name="page" value="<?= h($name) ?>">
  <?php if ($opts['preset'] !== ''): ?><input type="hidden" name="preset" value="<?= h($opts['preset']) ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= h($opts['q']) ?>" placeholder="Search…">
  <?php foreach ($entity['filters'] ?? [] as $f):
      $def = $entity['fields'][$f];
      $choices = match ($def['type']) {
          'select' => $def['options'],
          'ref'    => ref_options($def['ref'], null, $def['ref_where'] ?? null),
          'bool'   => ['1' => 'Yes', '0' => 'No'],
          default  => [],
      };
      if ($def['type'] === 'ref' && $def['ref'] === 'accounts' && empty($def['ref_where']) && $opts['filters'][$f] === '') continue; ?>
    <select name="<?= h($f) ?>" data-autosubmit>
      <option value=""><?= h($def['label']) ?>: any</option>
      <?php foreach ($choices as $val => $label): ?>
        <option value="<?= h($val) ?>" <?= (string)$opts['filters'][$f] === (string)$val ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  <?php endforeach; ?>
  <button class="btn">Filter</button>
  <?php if ($opts['q'] !== '' || array_filter($opts['filters'], fn($v) => $v !== '')): ?>
    <a class="btn btn-ghost" href="<?= h(url($name, ['preset' => $opts['preset']])) ?>">Clear</a>
  <?php endif; ?>
</form>

<?php if ($bulk): ?>
<form method="post" action="<?= h(url($name, ['action' => 'xero_push'])) ?>" id="bulk-form" class="bulk-bar">
  <?= csrf_field() ?>
  <input type="hidden" name="_return" value="<?= h(url($name, array_diff_key($_GET, ['page' => 1]))) ?>">
  <span class="muted small" data-selected-count>Tick products to send them to Xero</span>
  <button class="btn btn-sm" data-needs-selection disabled>Send selected to Xero</button>
  <?php if (!xero_can_write_items()): ?><span class="small text-warning">Switch on "Send products to Xero" under <a href="<?= h(url('xero')) ?>">Admin → Xero</a> first.</span><?php endif; ?>
</form>
<?php endif; ?>
<?php render('_table', ['entity' => $entity, 'name' => $name, 'rows' => $result['rows'], 'columns' => $entity['list'], 'sortable' => true, 'opts' => $opts, 'selectable' => $bulk]); ?>

<?php if ($pages > 1): ?>
<nav class="pager">
  <?php if ($current > 1): ?><a class="btn btn-sm" href="<?= h(url($name, array_merge($_GET, ['page' => $name, 'p' => $current - 1]))) ?>">← Prev</a><?php endif; ?>
  <span>Page <?= $current ?> of <?= $pages ?></span>
  <?php if ($current < $pages): ?><a class="btn btn-sm" href="<?= h(url($name, array_merge($_GET, ['page' => $name, 'p' => $current + 1]))) ?>">Next →</a><?php endif; ?>
</nav>
<?php endif; ?>
