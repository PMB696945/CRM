<table class="table table-compact changes-table">
  <thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead>
  <tbody>
  <?php foreach ($changes as [$field, $from, $to]): ?>
    <tr><td><?= h($field) ?></td><td class="muted"><?= $from === '' ? '—' : nl2br(h($from)) ?></td><td><?= $to === '' ? '—' : nl2br(h($to)) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
