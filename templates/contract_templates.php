<div class="page-head">
  <h1>Contract templates</h1>
  <a class="btn" href="<?= h(url('contract_templates', ['action' => 'example'])) ?>">⬇ Download example template</a>
</div>
<p class="lead">Upload a Word (.docx) contract for each service type. When a quote is accepted, the CRM fills in the customer's details and the quoted services, then sends it for signature through Signable. Services without their own template use the <b>General</b> template. Customers covered by their dealer's MSA get the <b>Service schedule under a dealer MSA</b> template, if you've uploaded one.</p>

<div class="grid-side">
  <div>
    <section class="card">
      <div class="card-head"><h2>Templates</h2></div>
      <?php if (!$templates): ?><p class="muted">No templates yet. Start from the example template if you like.</p><?php else: ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Name</th><th>Used for</th><th>File</th><th>Uploaded</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($templates as $t): ?>
          <tr>
            <td class="text-gray-800 dark:text-white/90 font-medium"><?= h($t['name']) ?></td>
            <td><?= h(contract_template_types()[$t['service_type']] ?? $t['service_type']) ?></td>
            <td><a href="<?= h(url('contract_templates', ['action' => 'download', 'id' => $t['id']])) ?>"><?= h($t['file_name']) ?></a></td>
            <td><?= h(fmt_date($t['created_at'])) ?><div class="muted"><?= h($t['uploaded_by_name'] ?? '') ?></div></td>
            <td><?= $t['active'] ? badge('active') : badge('disabled') ?></td>
            <td class="right whitespace-nowrap">
              <form method="post" action="<?= h(url('contract_templates', ['action' => 'toggle', 'id' => $t['id']])) ?>" class="inline"><?= csrf_field() ?><button class="btn btn-sm"><?= $t['active'] ? 'Disable' : 'Enable' ?></button></form>
              <form method="post" action="<?= h(url('contract_templates', ['action' => 'delete', 'id' => $t['id']])) ?>" class="inline" data-confirm="Delete this template? Contracts already created are not affected."><?= csrf_field() ?><button class="btn btn-sm btn-danger">Delete</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="help mt-3">If there are several active templates for the same type, the newest is used.</p>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Upload a template</h2></div>
      <form method="post" action="<?= h(url('contract_templates', ['action' => 'upload'])) ?>" enctype="multipart/form-data" class="form-grid">
        <?= csrf_field() ?>
        <div class="field"><label for="t_name">Name <span class="req">*</span></label><input id="t_name" name="name" required placeholder="e.g. Leased line agreement v3"></div>
        <div class="field"><label for="t_type">Used for <span class="req">*</span></label>
          <select id="t_type" name="service_type" required><option value="">—</option>
            <?php foreach (contract_template_types() as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field wide"><label for="t_file">Word document (.docx) <span class="req">*</span></label><input id="t_file" type="file" name="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required class="py-2"></div>
        <div class="form-actions wide"><button class="btn btn-primary">Upload</button></div>
      </form>
    </section>
  </div>

  <aside>
    <section class="card">
      <div class="card-head"><h2>Merge fields</h2></div>
      <p class="help">Type these anywhere in your Word document:</p>
      <dl class="details details-stack">
        <?php foreach (contract_merge_field_help() as $k => $label): ?><dt><?= h($label) ?></dt><dd><code>{{<?= h($k) ?>}}</code></dd><?php endforeach; ?>
      </dl>
    </section>
    <section class="card">
      <div class="card-head"><h2>Signature boxes</h2></div>
      <p class="help">Add <a href="https://help.signable.app/article/160-what-are-signable-tags" target="_blank" rel="noopener">Signable tags</a> where the customer should sign and date. Keep each tag on one line:</p>
      <p><code>{signature:signer1:Customer+Signature}</code></p>
      <p><code>{date:signer1:Date+Signed}</code></p>
      <p class="help">Signable replaces them with signature and date fields.</p>
    </section>
  </aside>
</div>
