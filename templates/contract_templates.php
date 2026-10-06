<div class="page-head">
  <h1>Contract templates</h1>
  <div class="actions">
    <a class="btn" href="<?= h(url('contract_templates', ['action' => 'example'])) ?>">⬇ Example agreement</a>
    <a class="btn" href="<?= h(url('contract_templates', ['action' => 'example', 'kind' => 'summary'])) ?>">⬇ Example Contract Summary</a>
  </div>
</div>
<p class="lead">Upload a Word (.docx) contract for each service type. When a quote is accepted, the CRM fills in the customer's details and the quoted services, then emails it to the customer to sign online. Services without their own template use the <b>General</b> template. Customers covered by their dealer's MSA get the <b>Service schedule under a dealer MSA</b> template, if you've uploaded one.</p>
<p class="lead">A <b>Contract Summary</b> goes first. It's attached to the signing email with the agreement, and shown first on the signing page. The customer must confirm they've received it before they can see the agreement or sign.</p>
<?php if (!contract_summary_template()): ?>
  <div class="flash flash-error">There's no Contract Summary template yet, so agreements for customers can't be prepared. Upload one, choosing "Contract Summary". The example is laid out under Ofcom's standard headings; have your solicitor complete it.</div>
<?php endif; ?>

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
      <div class="card-head"><h2>Signing</h2></div>
      <p class="help">You don't need signature boxes. The customer reads the agreement online, confirms their email with a code and signs by typing their name. A signature certificate is issued with it, recording who signed, when, from where, and a fingerprint of each document.</p>
      <p class="help">You might like to end the document with a line such as <i>"This agreement is signed electronically."</i></p>
    </section>
  </aside>
</div>
