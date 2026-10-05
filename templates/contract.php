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
      <form method="post" action="<?= h($act('send')) ?>" class="inline"><?= csrf_field() ?><button class="btn">Email the signing link again</button></form>
    <?php endif; ?>
    <?php if (in_array($status, ['draft', 'sent', 'failed'], true)): ?>
      <form method="post" action="<?= h($act('cancel')) ?>" class="inline" data-confirm="Cancel this contract?<?= $status === 'sent' ? ' Its signing link will stop working.' : '' ?>"><?= csrf_field() ?><button class="btn btn-danger">Cancel</button></form>
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
          <li class="flex items-center justify-between gap-3"><span class="text-ok">✔ <?= $contract['signed_ip'] ? 'Signature certificate (PDF)' : 'Signed copy (PDF)' ?></span>
            <a class="btn btn-sm btn-primary" href="<?= h(url('contracts', ['action' => 'download', 'id' => $id, 'file' => $contract['signed_file']])) ?>">Download</a></li>
        <?php endif; ?>
        <?php if (!$docs): ?><li class="muted">No documents.</li><?php endif; ?>
      </ul>
    </section>

    <?php if ($contract['sent_at'] || $contract['signed_ip']): $hashes = json_decode((string)$contract['document_hashes'], true) ?: []; ?>
    <section class="card">
      <div class="card-head"><h2>Signing record</h2></div>
      <ul class="timeline">
        <li><div class="timeline-meta"><?= h(fmt_datetime($contract['sent_at'])) ?></div><b>Sent to sign</b><div class="small">Emailed to <?= h($contract['signer_email']) ?><?= (int)$contract['reminders_sent'] ? ' · ' . (int)$contract['reminders_sent'] . ' reminder' . ((int)$contract['reminders_sent'] === 1 ? '' : 's') . ', last ' . h(fmt_datetime($contract['last_reminded_at'])) : '' ?></div></li>
        <?php if ($contract['viewed_at']): ?><li><div class="timeline-meta"><?= h(fmt_datetime($contract['viewed_at'])) ?></div><b>Opened</b><?= $contract['viewed_ip'] ? '<div class="small">from ' . h($contract['viewed_ip']) . '</div>' : '' ?></li><?php endif; ?>
        <?php if ($contract['verified_at']): ?><li><div class="timeline-meta"><?= h(fmt_datetime($contract['verified_at'])) ?></div><b>Email confirmed</b><div class="small">with a one-time code sent to <?= h($contract['signer_email']) ?></div></li><?php endif; ?>
        <?php if ($contract['signed_ip']): ?><li><div class="timeline-meta"><?= h(fmt_datetime($contract['signed_at'])) ?></div><b>Signed by <?= h($contract['signed_name']) ?><?= $contract['signed_position'] ? ', ' . h($contract['signed_position']) : '' ?></b>
          <div class="small">IP <?= h($contract['signed_ip']) ?> · <?= h((string)$contract['signed_user_agent']) ?></div>
          <?php foreach ($hashes as $title => $hash): ?><div class="small muted">“<?= h($title) ?>” fingerprint <span class="font-mono"><?= h(substr($hash, 0, 16)) ?>…</span></div><?php endforeach; ?></li><?php endif; ?>
        <?php if ($status === 'rejected'): ?><li><b>Declined</b><?= $contract['declined_reason'] ? '<div class="small">' . nl2br(h($contract['declined_reason'])) . '</div>' : '' ?></li><?php endif; ?>
      </ul>
      <?php if ($status === 'sent' && $contract['sign_token']): ?>
        <div class="copy-row mt-3"><input id="sign-link" readonly value="<?= h(esign_url($contract)) ?>" data-select-all aria-label="Signing link"><button type="button" class="btn btn-sm" data-copy="#sign-link">Copy link</button></div>
        <p class="help">The customer's private signing link, if you'd like to send it another way.</p>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($status === 'signed' && $quote): $services = contract_services($contract); ?>
    <section class="card">
      <div class="card-head"><h2>Services</h2><?php if ($services): ?><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $account['id']])) ?>">Open the customer →</a><?php endif; ?></div>
      <?php if ($services): ?>
        <p class="help">Added to the customer as <b>pending</b> when the agreement was signed. Fill in numbers and circuit IDs as they're provisioned.</p>
        <ul class="feed">
          <?php foreach ($services as $sv): ?><li class="flex items-center justify-between gap-3"><a href="<?= h(url('services', ['action' => 'view', 'id' => $sv['id']])) ?>"><?= h($sv['identifier']) ?></a> <?= badge($sv['status']) ?></li><?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p>Add the contracted services to the customer as <b>pending</b>, ready to fill in numbers and circuit IDs as they're provisioned.</p>
        <form method="post" action="<?= h($act('services')) ?>"><?= csrf_field() ?><button class="btn btn-primary">Create pending services</button></form>
      <?php endif; ?>
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
        <button class="btn btn-primary">Email it to sign online</button>
        <p class="help">They'll get a private link to read and sign it, confirming their email with a code.</p>
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
      </dl>
    </section>
  </aside>
</div>
