<div class="page-head"><h1>Search<?= $q !== '' ? ': “' . h($q) . '”' : '' ?></h1></div>
<?php if ($q === ''): ?>
  <p class="muted">Search by customer name, account number, postcode, phone number, MSISDN, circuit ID or ticket reference.</p>
<?php elseif (!$results): ?>
  <div class="card empty"><p>No matches for “<?= h($q) ?>”.</p></div>
<?php endif; ?>
<?php foreach ($results as $name => $found): $entity = entity($name); ?>
  <section class="card">
    <div class="card-head"><h2><?= h($entity['plural']) ?> <small class="count"><?= (int)$found['total'] ?></small></h2>
      <?php if ($found['total'] > count($found['rows'])): ?><a href="<?= h(url($name, ['q' => $q])) ?>">See all →</a><?php endif; ?></div>
    <?php render('_table', ['entity' => $entity, 'name' => $name, 'rows' => $found['rows'], 'columns' => array_slice($entity['list'], 0, 6)]); ?>
  </section>
<?php endforeach; ?>
