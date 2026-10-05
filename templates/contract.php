<?php
$id = (int)$contract['id'];
$act = fn(string $a) => url('contracts', ['action' => $a, 'id' => $id]);
$docs = contract_documents($contract);
$status = $contract['status'];
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url('contracts')) ?>">Contracts</a> · <?= h($contract['reference']) ?></div>
    <h1><?= h($contract['title']) ?> <?= badge($status) ?><?= $contract['kind'] === 'msa' ? ' <span class="badge badge-msa">MSA</span>' : '' ?></h1>
    <p class="dealer-line">For <a href="<?= h(url('accounts', ['action' => 'view', 'id' => $account['id']])) ?>"><?= h($account['name']) ?></a>
      <?php if ($quote): ?> · from quote <a href="<?= h(url('quotes', ['action' => 'view', 'id' => $quote['id']])) ?>"><?= h($quote['reference']) ?></a><?php endif; ?></p>
  </div>
  <div class="actions">
    <?php if ($status === 'sent'): ?>
      <form method="post" action="<?= h($act('check')) ?>" class="inline"><?= csrf_field() ?><button class="btn">↻ Check status</button></form>
      <form method="post" action="<?= h($act('remind')) ?>" class="inline"><?= csrf_field() ?><button class="btn">Send reminder</button></form>
    <?php endif; ?>
    <?php if (in_array($status, ['draft', 'sent', 'failed'], true)): ?>
      <form method="post" action="<?= h($act('cancel')) ?>" class="inline" data-confirm="Cancel this contract?<?= $status === 'sent' ? ' The signing request in Signable will be cancelled too.' : '' ?>"><?= csrf_field() ?><button class="btn btn-danger">Cancel</button></form>
    <?php endif; ?>
  </div>
</div>

<?php if ($contract['last_error'] && in_array($status, ['draft', 'failed'], true)): ?>
  <div class="flash flash-error">Last attempt failed: <?= h($contract['last_error']) ?></div>
<?php endif; ?>

<div class="grid-side">
  <div>
    <section class="card">
      <div class="card-head"><h2>Documents</h2></div>
      <ul class="feed">
        <?php foreach ($docs as $d): ?>
          <li class="flex items-center justify-between gap-3"><span><?= icon('document', 'inline size-5 mr-1 text-gray-400') ?><?= h($d['title']) ?></span>
            <a class="btn btn-sm" href="<?= h(url('contracts', ['action' => 'download', 'id' => $id, 'file' => $d['file']])) ?>">Download .docx</a></li>
        <?php endforeach; ?>
        <?php if ($contract['signed_file']): ?>
          <li class="flex items-center justify-between gap-3"><span class="text-ok">✔ Signed copy (PDF)</span>
            <a class="btn btn-sm btn-primary" href="<?= h(url('contracts', ['action' => 'download', 'id' => $id, 'file' => $contract['signed_file']])) ?>">Download signed PDF</a></li>
        <?php elseif ($status === 'signed'): ?>
          <li class="muted">Signed in Signable. The signed PDF couldn't be downloaded automatically; get it from your Signable account.</li>
        <?php endif; ?>
        <?php if (!$docs): ?><li class="muted">No documents.</li><?php endif; ?>
      </ul>
    </section>

    <?php if ($status === 'signed' && $quote): ?>
    <section class="card">
      <div class="card-head"><h2>Next step</h2></div>
      <p>Add the contracted services to the customer as <b>pending</b>, ready to fill in numbers and circuit IDs as they're provisioned.</p>
      <form method="post" action="<?= h($act('services')) ?>"><?= csrf_field() ?><button class="btn btn-primary">Create pending services</button></form>
    </section>
    <?php endif; ?>
  </div>

  <aside>
    <?php if (in_array($status, ['draft', 'failed'], true)): ?>
    <section class="card">
      <div class="card-head"><h2>Send for signature</h2></div>
      <form method="post" action="<?= h($act('send')) ?>" class="stack">
        <?= csrf_field() ?>
        <label>Signer's name<input name="signer_name" value="<?= h($contract['signer_name']) ?>" required></label>
        <label>Signer's email<input type="email" name="signer_email" value="<?= h($contract['signer_email']) ?>" required></label>
        <button class="btn btn-primary" <?= signable_configured() ? '' : 'disabled' ?>>Send via Signable</button>
        <?php if (!signable_configured()): ?><p class="help text-warning">Signable isn't set up yet. <?= is_admin() ? '<a href="' . h(url('signable')) . '">Add your API key</a>.' : 'Ask an admin.' ?></p><?php endif; ?>
      </form>
    </section>
    <?php endif; ?>
    <?php if (in_array($status, ['draft', 'sent', 'failed'], true) && can('sales.edit')): ?>
    <section class="card">
      <div class="card-head"><h2>Signed another way?</h2></div>
      <p class="help">If the customer signed on paper or returned it by email, record it here. The order moves on, and their tracking page shows it.</p>
      <form method="post" action="<?= h($act('mark_signed')) ?>" enctype="multipart/form-data" class="stack" data-confirm="Mark <?= h($contract['reference']) ?> as signed?">
        <?= csrf_field() ?>
        <label>Signed by<input name="signer_name" value="<?= h($contract['signer_name']) ?>"></label>
        <label>Signed copy (PDF, optional)<input type="file" name="signed_copy" accept="application/pdf"></label>
        <button class="btn">Mark as signed</button>
      </form>
    </section>
    <?php endif; ?>
    <section class="card">
      <dl class="details details-stack">
        <dt>Signer</dt><dd><?= h($contract['signer_name']) ?><br><span class="muted"><?= h($contract['signer_email']) ?></span></dd>
        <dt>Created</dt><dd><?= h(fmt_datetime($contract['created_at'])) ?></dd>
        <?php if ($contract['sent_at']): ?><dt>Sent</dt><dd><?= h(fmt_datetime($contract['sent_at'])) ?></dd><?php endif; ?>
        <?php if ($contract['signed_at']): ?><dt>Signed</dt><dd><?= h(fmt_datetime($contract['signed_at'])) ?></dd><?php endif; ?>
        <?php if ($contract['signable_fingerprint']): ?><dt>Signable envelope</dt><dd class="font-mono text-theme-xs"><?= h($contract['signable_fingerprint']) ?></dd><?php endif; ?>
      </dl>
    </section>
  </aside>
</div>
