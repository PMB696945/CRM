<?php
$pages = max(1, (int)ceil($result['total'] / PER_PAGE));
$current = max(1, min($pages, (int)$opts['page']));
$presets = $entity['presets'] ?? [];
$exportParams = array_merge($_GET, ['page' => $name, 'action' => 'export', 'p' => null]);
?>
<div class="page-head">
  <h1><?= h($entity['plural']) ?> <small class="count"><?= (int)$result['total'] ?></small></h1>
  <div class="actions">
    <a class="btn" href="<?= h(url($name, $exportParams)) ?>">Export CSV</a>
    <?php if ($canWrite): ?><a class="btn btn-primary" href="<?= h(url($name, ['action' => 'new'])) ?>">+ New <?= h(strtolower($entity['label'])) ?></a><?php endif; ?>
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
          'ref'    => ref_options($def['ref']),
          'bool'   => ['1' => 'Yes', '0' => 'No'],
          default  => [],
      };
      if ($def['type'] === 'ref' && $def['ref'] === 'accounts' && $opts['filters'][$f] === '') continue; ?>
    <select name="<?= h($f) ?>" onchange="this.form.submit()">
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

<?php render('_table', ['entity' => $entity, 'name' => $name, 'rows' => $result['rows'], 'columns' => $entity['list'], 'sortable' => true, 'opts' => $opts]); ?>

<?php if ($pages > 1): ?>
<nav class="pager">
  <?php if ($current > 1): ?><a class="btn btn-sm" href="<?= h(url($name, array_merge($_GET, ['page' => $name, 'p' => $current - 1]))) ?>">← Prev</a><?php endif; ?>
  <span>Page <?= $current ?> of <?= $pages ?></span>
  <?php if ($current < $pages): ?><a class="btn btn-sm" href="<?= h(url($name, array_merge($_GET, ['page' => $name, 'p' => $current + 1]))) ?>">Next →</a><?php endif; ?>
</nav>
<?php endif; ?>
