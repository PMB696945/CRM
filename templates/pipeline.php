<div class="page-head">
  <h1>Sales pipeline</h1>
  <div class="actions">
    <form method="get" action="index.php" class="inline">
      <input type="hidden" name="page" value="pipeline">
      <select name="owner_id" data-autosubmit>
        <option value="">All owners</option>
        <?php foreach ($users as $uid => $uname): ?><option value="<?= (int)$uid ?>" <?= $owner === (int)$uid ? 'selected' : '' ?>><?= h($uname) ?></option><?php endforeach; ?>
      </select>
    </form>
    <a class="btn btn-primary" href="<?= h(url('opportunities', ['action' => 'new', 'return' => url('pipeline')])) ?>">+ New opportunity</a>
  </div>
</div>
<div class="board">
  <?php foreach ($columns as $stage => $cards):
      $total = array_sum(array_map(fn($c) => (float)$c['monthly_value'], $cards)); ?>
    <div class="board-col board-<?= h($stage) ?>">
      <div class="board-head"><?= h(humanize($stage)) ?> <span class="count"><?= count($cards) ?></span>
        <div class="board-total"><?= h(money($total)) ?>/mo</div></div>
      <?php foreach ($cards as $c): ?>
        <a class="board-card" href="<?= h(url('opportunities', ['action' => 'view', 'id' => $c['id']])) ?>">
          <strong><?= h($c['title']) ?></strong>
          <span class="muted"><?= h($c['account_id__label']) ?></span>
          <span class="board-meta"><?= h(money($c['monthly_value'])) ?>/mo · <?= (int)$c['probability'] ?>%<?= $c['expected_close'] ? ' · ' . h(fmt_date($c['expected_close'])) : '' ?></span>
          <span><?= badge($c['opp_type']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
