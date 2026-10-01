<?php $canEdit = can('purchasing.edit'); ?>
<div class="page-head"><div><h1>Supplier invoices</h1>
  <p class="muted">Upload invoices from suppliers. Each one is read, matched to its purchase order, and flagged if anything doesn't agree.</p></div></div>

<?php if ($canEdit): ?>
<form method="post" action="<?= h(url('supplier_invoices', ['action' => 'upload'])) ?>" enctype="multipart/form-data" class="card inline-form" style="margin-bottom:1.5rem">
  <?= csrf_field() ?>
  <label class="grow">Invoices (PDF or photo)<input type="file" name="files[]" multiple required accept=".pdf,.png,.jpg,.jpeg,.gif,.webp"></label>
  <button class="btn btn-primary">Upload and match</button>
  <span class="help">Read with <?= invoice_reader() === 'claude' ? 'Claude' : 'the built-in reader (PDFs with text). Scans and photos need Claude: see Settings' ?>.</span>
</form>
<?php endif; ?>

<nav class="tabs">
  <a href="<?= h(url('supplier_invoices', ['status' => 'needs_review'])) ?>" class="<?= $status === 'needs_review' ? 'active' : '' ?>">Needs checking <span class="count"><?= (int)($counts['needs_review'] ?? 0) ?></span></a>
  <a href="<?= h(url('supplier_invoices', ['status' => 'matched'])) ?>" class="<?= $status === 'matched' ? 'active' : '' ?>">Matched <span class="count"><?= (int)($counts['matched'] ?? 0) ?></span></a>
  <a href="<?= h(url('supplier_invoices', ['status' => 'approved'])) ?>" class="<?= $status === 'approved' ? 'active' : '' ?>">Approved <span class="count"><?= (int)($counts['approved'] ?? 0) ?></span></a>
  <a href="<?= h(url('supplier_invoices', ['status' => 'disputed'])) ?>" class="<?= $status === 'disputed' ? 'active' : '' ?>">Disputed <span class="count"><?= (int)($counts['disputed'] ?? 0) ?></span></a>
  <a href="<?= h(url('supplier_invoices', ['status' => ''])) ?>" class="<?= $status === '' ? 'active' : '' ?>">All</a>
</nav>

<div class="table-wrap"><table class="table">
  <thead><tr><th>Invoice</th><th>Supplier</th><th>Date</th><th>PO</th><th class="num">Net</th><th class="num">Total</th><th>Status</th><th>Xero</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $problems = json_decode((string)$r['problems'], true) ?: []; ?>
    <tr>
      <td><a class="row-link" href="<?= h(url('supplier_invoices', ['action' => 'view', 'id' => $r['id']])) ?>"><?= h($r['invoice_number'] ?: $r['file_name']) ?></a>
        <?php if ($problems && $r['status'] === 'needs_review'): ?><div class="small text-warning"><?= h($problems[0]) ?><?= count($problems) > 1 ? ' (+' . (count($problems) - 1) . ' more)' : '' ?></div><?php endif; ?></td>
      <td><?= $r['supplier'] ? h($r['supplier']) : '<span class="muted">' . h($r['supplier_name'] ?: 'Unknown') . '</span>' ?></td>
      <td class="small"><?= h(fmt_date($r['invoice_date'])) ?></td>
      <td class="small"><?= $r['po'] ? '<a href="' . h(url('purchase_orders', ['action' => 'view', 'id' => $r['po_id']])) . '">' . h($r['po']) . '</a>' : '<span class="muted">' . h($r['po_reference'] ?: '—') . '</span>' ?></td>
      <td class="num"><?= $r['net'] !== null ? h(money($r['net'])) : '<span class="muted">—</span>' ?></td>
      <td class="num"><?= $r['total'] !== null ? h(money($r['total'])) : '<span class="muted">—</span>' ?></td>
      <td><?= invoice_status_badge($r['status']) ?></td>
      <td class="small"><?= $r['xero_invoice_id'] ? '<a href="' . h(xero_bill_url($r['xero_invoice_id'])) . '" target="_blank" rel="noopener">Bill ↗</a>' : ($r['xero_error'] ? '<span class="text-danger">Problem</span>' : '<span class="muted">—</span>') ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="empty-row">No invoices here.</td></tr><?php endif; ?>
  </tbody>
</table></div>
