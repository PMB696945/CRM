<form method="post" action="<?= h(url($name, ['action' => 'delete', 'id' => $id])) ?>" class="inline" data-confirm="Delete this <?= h(strtolower($label)) ?>? This cannot be undone.">
  <?= csrf_field() ?>
  <?php if (!empty($return)): ?><input type="hidden" name="_return" value="<?= h($return) ?>"><?php endif; ?>
  <button class="btn btn-danger">Delete</button>
</form>
