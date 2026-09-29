<div class="page-head">
  <div><div class="crumbs"><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $account['id']])) ?>"><?= h($account['name']) ?></a></div>
  <h1>New contract</h1></div>
</div>
<?php if ($errors): ?><div class="flash flash-error"><?= h(implode(' ', $errors)) ?></div><?php endif; ?>
<?php if (!$templates): ?>
  <div class="card empty"><p>No contract templates yet.</p><?php if (is_admin()): ?><p><a class="btn btn-primary" href="<?= h(url('contract_templates')) ?>">Upload a template</a></p><?php endif; ?></div>
<?php else: ?>
<form method="post" action="<?= h(url('contracts', ['action' => 'new', 'account_id' => $account['id']])) ?>" class="card form-grid">
  <?= csrf_field() ?>
  <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
  <div class="field"><label for="c_tpl">Template <span class="req">*</span></label>
    <select id="c_tpl" name="template_id" required><option value="">—</option>
      <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (string)$values['template_id'] === (string)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?> (<?= h(contract_template_types()[$t['service_type']] ?? $t['service_type']) ?>)</option><?php endforeach; ?>
    </select></div>
  <div class="field"><label for="c_kind">Kind</label>
    <select id="c_kind" name="kind"><option value="services" <?= $values['kind'] === 'services' ? 'selected' : '' ?>>Services</option><option value="msa" <?= $values['kind'] === 'msa' ? 'selected' : '' ?>>Master services agreement</option></select>
    <div class="help">A signed MSA is referenced in contracts for this dealer's customers ({{msa_reference}})</div></div>
  <div class="field wide"><label for="c_title">Title <span class="req">*</span></label><input id="c_title" name="title" value="<?= h($values['title']) ?>" required></div>
  <div class="field"><label for="c_sn">Signer's name <span class="req">*</span></label><input id="c_sn" name="signer_name" value="<?= h($values['signer_name']) ?>" required></div>
  <div class="field"><label for="c_se">Signer's email <span class="req">*</span></label><input id="c_se" type="email" name="signer_email" value="<?= h($values['signer_email']) ?>" required></div>
  <p class="help wide">The customer's current active and pending services fill {{services_table}}. The contract is created as a draft so you can check it before sending.</p>
  <div class="form-actions wide"><button class="btn btn-primary">Create contract</button><a class="btn btn-ghost" href="<?= h(url('accounts', ['action' => 'view', 'id' => $account['id']])) ?>">Cancel</a></div>
</form>
<?php endif; ?>
