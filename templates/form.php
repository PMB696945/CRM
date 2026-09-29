<?php
$accountId = isset($values['account_id']) && $values['account_id'] !== '' ? (int)$values['account_id'] : null;
$cancel = $return ?: ($existing ? url($name, ['action' => 'view', 'id' => $existing['id']]) : url($name));
?>
<div class="page-head">
  <h1><?= h($existing ? 'Edit ' . strtolower($entity['label']) : 'New ' . strtolower($entity['label'])) ?></h1>
</div>
<?php if (!empty($errors['_'])): ?><div class="flash flash-error"><?= h($errors['_']) ?></div><?php endif; ?>
<?php if ($errors && empty($errors['_'])): ?><div class="flash flash-error">Please fix the highlighted fields.</div><?php endif; ?>

<form method="post" class="card form-grid" data-entity="<?= h($name) ?>">
  <?= csrf_field() ?>
  <?php if ($return): ?><input type="hidden" name="_return" value="<?= h($return) ?>"><?php endif; ?>
  <?php foreach ($entity['fields'] as $field => $def):
      if (!empty($def['readonly']) || !field_enabled($def)) continue;
      $value = $values[$field] ?? null;
      $err = $errors[$field] ?? null;
      $wide = in_array($def['type'], ['textarea', 'checkboxes'], true);
      $id = 'f_' . $field;
      if (!empty($def['section'])): ?>
    <div class="form-section wide"><h2><?= h($def['section']) ?></h2><?php if (!empty($def['section_help'])): ?><p class="help"><?= h($def['section_help']) ?></p><?php endif; ?></div>
    <?php endif; ?>
    <div<?= !empty($def['show_if']) ? ' data-show-if="' . h($def['show_if']) . '"' : '' ?> class="field <?= $wide ? 'wide' : '' ?> <?= $err ? 'has-error' : '' ?> <?= $def['type'] === 'bool' ? 'field-check' : '' ?>">
      <?php if ($def['type'] === 'bool'): ?>
        <label><input type="checkbox" name="<?= h($field) ?>" value="1" <?= $value ? 'checked' : '' ?>> <?= h($def['label']) ?></label>
      <?php elseif ($def['type'] === 'checkboxes'):
        $picked = is_array($value) ? $value : array_filter(explode(',', (string)$value)); ?>
        <fieldset class="checkbox-group"><legend><?= h($def['label']) ?></legend>
          <?php foreach ($def['options'] as $k => $label): ?>
            <label class="check"><input type="checkbox" name="<?= h($field) ?>[]" value="<?= h($k) ?>" <?= in_array((string)$k, array_map('strval', $picked), true) ? 'checked' : '' ?>> <?= h($label) ?></label>
          <?php endforeach; ?>
        </fieldset>
      <?php else: ?>
        <label for="<?= h($id) ?>"><?= h($def['label']) ?><?= !empty($def['required']) ? ' <span class="req">*</span>' : '' ?></label>
        <?php switch ($def['type']):
          case 'textarea': ?>
            <textarea id="<?= h($id) ?>" name="<?= h($field) ?>" rows="4"><?= h($value) ?></textarea>
          <?php break;
          case 'select': ?>
            <select id="<?= h($id) ?>" name="<?= h($field) ?>" <?= !empty($def['required']) ? 'required' : '' ?>>
              <option value="">—</option>
              <?php foreach ($def['options'] as $k => $label): ?>
                <option value="<?= h($k) ?>" <?= (string)$value === (string)$k ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          <?php break;
          case 'ref':
            $scoped = !empty($def['scoped']);
            $choices = $scoped ? ($accountId ? ref_options($def['ref'], $accountId) : []) : ref_options($def['ref'], null, $def['ref_where'] ?? null);
            if ($name === 'accounts' && $field === 'parent_id' && $existing) unset($choices[$existing['id']]); ?>
            <select id="<?= h($id) ?>" name="<?= h($field) ?>" <?= !empty($def['required']) ? 'required' : '' ?> <?= $scoped ? 'data-scoped="' . h($def['ref']) . '"' : '' ?>>
              <option value="">—</option>
              <?php foreach ($choices as $k => $label): ?>
                <option value="<?= h($k) ?>" <?= (string)$value === (string)$k ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          <?php break;
          case 'money': ?>
            <input id="<?= h($id) ?>" type="number" step="0.01" min="0" name="<?= h($field) ?>" value="<?= h($value) ?>" <?= !empty($def['required']) ? 'required' : '' ?>>
          <?php break;
          case 'int': ?>
            <input id="<?= h($id) ?>" type="number" step="1" name="<?= h($field) ?>" value="<?= h($value) ?>" <?= isset($def['min']) ? 'min="' . (int)$def['min'] . '"' : '' ?> <?= isset($def['max']) ? 'max="' . (int)$def['max'] . '"' : '' ?> <?= !empty($def['required']) ? 'required' : '' ?>>
          <?php break;
          default: ?>
            <input id="<?= h($id) ?>" type="<?= h(in_array($def['type'], ['email', 'tel', 'date'], true) ? $def['type'] : 'text') ?>" name="<?= h($field) ?>" value="<?= h($value) ?>" <?= !empty($def['required']) ? 'required' : '' ?>>
        <?php endswitch; ?>
      <?php endif; ?>
      <?php if ($err): ?><div class="error"><?= h($err) ?></div><?php elseif (!empty($def['help'])): ?><div class="help"><?= h($def['help']) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <div class="form-actions wide">
    <button class="btn btn-primary">Save</button>
    <a class="btn btn-ghost" href="<?= h($cancel) ?>">Cancel</a>
  </div>
</form>
