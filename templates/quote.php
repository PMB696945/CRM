<?php
$id = (int)$quote['id'];
$totals = quote_totals($lines);
$status = $quote['status'];
$act = fn(string $a) => url('quotes', ['action' => $a, 'id' => $id]);
$activeContract = array_values(array_filter($contracts, fn($c) => !in_array($c['status'], ['cancelled', 'rejected', 'expired', 'failed'], true)))[0] ?? null;
?>
<div class="page-head">
  <div>
    <div class="crumbs"><a href="<?= h(url('quotes')) ?>">Quotes</a> · <?= h($quote['reference']) ?></div>
    <h1><?= h($quote['title']) ?> <?= badge($status) ?></h1>
    <p class="dealer-line">For <a href="<?= h(url('accounts', ['action' => 'view', 'id' => $account['id']])) ?>"><?= h($account['name']) ?></a>
      <?php if ($account['parent_id']): ?> · via dealer <?= h(db_value('SELECT name FROM accounts WHERE id = ?', [$account['parent_id']])) ?><?php endif; ?>
      <?php if ($quote['valid_until']): ?> · valid until <?= h(fmt_date($quote['valid_until'])) ?><?php endif; ?></p>
  </div>
  <div class="actions">
    <a class="btn btn-ghost" href="<?= h($act('pdf')) ?>" target="_blank" rel="noopener">PDF</a>
    <?php if ($order): ?><a class="btn" href="<?= h(url('customer_orders', ['action' => 'view', 'id' => $order['id']])) ?>">Order <?= h($order['reference']) ?> · <?= h(order_status_label($order['status'])) ?></a>
    <?php elseif ($status === 'accepted' && can('onboarding.edit')): ?><form method="post" action="<?= h(url('customer_orders', ['action' => 'create', 'quote_id' => $quote['id']])) ?>" class="inline"><?= csrf_field() ?><button class="btn">Create order</button></form><?php endif; ?>
    <?php if ($status === 'accepted' && can('sales.edit')): ?>
      <form method="post" action="<?= h($act('confirmation')) ?>" class="inline" data-confirm="Email the acceptance confirmation and quote PDF to <?= h($quote['response_email'] ?: $quote['recipient_email'] ?: 'the customer') ?>?"><?= csrf_field() ?><button class="btn"><?= $quote['confirmation_sent_at'] ? 'Resend confirmation' : 'Send confirmation' ?></button></form>
    <?php endif; ?>
    <?php if ($status === 'draft'): ?>
      <a class="btn" href="<?= h($act('edit')) ?>">Edit</a>
    <?php elseif (in_array($status, ['sent', 'expired', 'declined'], true)): ?>
      <form method="post" action="<?= h($act('revise')) ?>" class="inline" data-confirm="Put the quote back into draft to change it? The link you sent will stop working."><?= csrf_field() ?><button class="btn">Revise</button></form>
    <?php endif; ?>
    <form method="post" action="<?= h($act('duplicate')) ?>" class="inline"><?= csrf_field() ?><button class="btn">Duplicate</button></form>
    <?php if ($status === 'draft'): ?>
      <form method="post" action="<?= h($act('delete')) ?>" class="inline" data-confirm="Delete this draft quote?"><?= csrf_field() ?><button class="btn btn-danger">Delete</button></form>
    <?php elseif (in_array($status, ['sent', 'expired'], true)): ?>
      <form method="post" action="<?= h($act('cancel')) ?>" class="inline" data-confirm="Cancel this quote? The customer's link will stop working."><?= csrf_field() ?><button class="btn btn-danger">Cancel quote</button></form>
    <?php endif; ?>
  </div>
</div>

<div class="kpis kpis-sm">
  <div class="kpi"><span class="kpi-label">Monthly</span><span class="kpi-value"><?= h(money($totals['monthly'])) ?></span><span class="kpi-sub">excl. VAT</span></div>
  <div class="kpi"><span class="kpi-label">One-off</span><span class="kpi-value"><?= h(money($totals['setup'])) ?></span><span class="kpi-sub">excl. VAT</span></div>
  <div class="kpi"><span class="kpi-label">Contract value</span><span class="kpi-value"><?= h(money($totals['tcv'])) ?></span><span class="kpi-sub">over <?= h(term_label($totals['term'])) ?></span></div>
</div>

<div class="grid-side">
  <div>
    <?php if ($quote['intro']): ?><section class="card"><div class="prose"><?= nl2br(h($quote['intro'])) ?></div></section><?php endif; ?>
    <section class="card">
      <div class="card-head"><h2>Services</h2></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Service</th><th>Type</th><th class="num">Qty</th><th class="num">Monthly</th><th class="num">One-off</th><th class="num">Term</th><th class="num">Line total /mo</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
          <tr><td class="text-gray-800 dark:text-white/90"><?= h($l['description']) ?></td><td><?= h(SERVICE_TYPES[$l['service_type']] ?? $l['service_type']) ?></td>
            <td class="num"><?= (int)$l['quantity'] ?></td><td class="num"><?= h(money($l['monthly_price'])) ?></td><td class="num"><?= h(money($l['setup_fee'])) ?></td>
            <td class="num"><?= h(term_label($l['term_months'])) ?></td><td class="num"><?= h(money($l['quantity'] * $l['monthly_price'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>

    <?php if ($picked && $status !== 'draft'): ?>
    <section class="card">
      <div class="card-head"><h2>Documents sent with the quote</h2></div>
      <ul class="file-list">
        <?php foreach (array_merge(...array_values($library), ...[$customerFiles]) as $d): if (!in_array((int)$d['id'], $picked, true)) continue; ?>
          <li><?= icon('paperclip', 'size-4 shrink-0 text-gray-400') ?><div class="file-main"><a href="<?= h(url('documents', ['action' => 'open', 'id' => $d['id']])) ?>" target="_blank" rel="noopener"><?= h($d['title']) ?></a></div><span class="muted small"><?= h(file_size_label($d['size'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($contracts): ?>
    <section class="card">
      <div class="card-head"><h2>Contracts</h2></div>
      <?php render('_table', ['entity' => entity('contracts'), 'name' => 'contracts', 'rows' => $contracts, 'columns' => ['reference', 'title', 'status', 'signer_name', 'sent_at', 'signed_at']]); ?>
    </section>
    <?php endif; ?>
  </div>

  <aside>
    <?php if (in_array($status, ['draft', 'sent', 'expired'], true)): ?>
    <section class="card">
      <div class="card-head"><h2><?= $status === 'draft' ? 'Send to customer' : 'Send again' ?></h2></div>
      <form method="post" action="<?= h($act('send')) ?>" class="stack" data-recipient-form>
        <?= csrf_field() ?>
        <?php if ($recipients): ?>
          <label>Contact
            <select data-recipient-pick>
              <option value="">Choose…</option>
              <?php foreach ($recipients as $r): ?><option data-name="<?= h($r['name']) ?>" data-email="<?= h($r['email']) ?>"><?= h($r['label']) ?></option><?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <label>Name<input name="recipient_name" value="<?= h($quote['recipient_name'] ?? ($recipients[0]['name'] ?? '')) ?>" required></label>
        <label>Email<input type="email" name="recipient_email" value="<?= h($quote['recipient_email'] ?? ($recipients[0]['email'] ?? '')) ?>" required></label>
        <?php if ($library || $customerFiles): ?>
          <fieldset><legend class="small font-medium">Attach documents (optional)</legend>
            <div class="attach-list">
              <?php foreach ($library as $folderName => $docs): ?>
                <div class="attach-folder"><?= h($folderName) ?></div>
                <?php foreach ($docs as $d): ?><label><input type="checkbox" name="documents[]" value="<?= (int)$d['id'] ?>" <?= in_array((int)$d['id'], $picked, true) ? 'checked' : '' ?>><span><?= h($d['title']) ?> <span class="muted small"><?= h(file_size_label($d['size'])) ?></span></span></label><?php endforeach; ?>
              <?php endforeach; ?>
              <?php if ($customerFiles): ?>
                <div class="attach-folder">This customer's files</div>
                <?php foreach ($customerFiles as $d): ?><label><input type="checkbox" name="documents[]" value="<?= (int)$d['id'] ?>" <?= in_array((int)$d['id'], $picked, true) ? 'checked' : '' ?>><span><?= h($d['title']) ?> <span class="muted small"><?= h(file_size_label($d['size'])) ?></span></span></label><?php endforeach; ?>
              <?php endif; ?>
            </div>
            <p class="help">Sent as attachments with the quote email, up to <?= h(file_size_label(QUOTE_ATTACH_MAX_BYTES)) ?> in total.</p>
          </fieldset>
        <?php endif; ?>
        <button class="btn btn-primary">✉ Email quote</button>
        <?php if (!mail_configured()): ?><p class="help text-warning">Email isn't set up yet. <?= is_admin() ? '<a href="' . h(url('settings')) . '">Set it up in Settings</a>.' : 'Ask an admin to set it up.' ?></p><?php endif; ?>
      </form>
    </section>
    <?php endif; ?>

    <?php if ($status === 'sent' && $quote['token_hash']): ?>
    <section class="card">
      <div class="card-head"><h2>Customer link</h2></div>
      <div class="copy-row"><input id="quote-link" readonly value="<?= h(quote_public_url($quote)) ?>" data-select-all><button type="button" class="btn btn-sm" data-copy="#quote-link">Copy</button></div>
      <p class="help mt-2">Anyone with this link can view and respond to the quote. <a href="<?= h(quote_public_url($quote)) ?>" target="_blank" rel="noopener">Open it as the customer sees it ↗</a></p>
      <?php if ($warn = app_url_mismatch()): ?><p class="help text-warning mt-2"><?= h($warn) ?> <a href="<?= h(url('settings')) ?>">Settings</a></p><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if (in_array($status, ['draft', 'sent', 'expired'], true)): ?>
    <section class="card">
      <div class="card-head"><h2>Customer responded another way?</h2></div>
      <form method="post" action="<?= h($act('accept')) ?>" class="stack">
        <?= csrf_field() ?>
        <label>Accepted by<input name="accepted_by" value="<?= h($quote['recipient_name']) ?>" placeholder="Their name"></label>
        <label>Their email<input type="email" name="accepted_email" value="<?= h($quote['recipient_email']) ?>" placeholder="Contract is sent here to sign"></label>
        <label class="check"><input type="checkbox" name="send_confirmation" value="1" checked> Email them a confirmation with the quote PDF</label>
        <button class="btn">✔ Record acceptance</button>
      </form>
      <form method="post" action="<?= h($act('decline')) ?>" class="stack mt-4">
        <?= csrf_field() ?>
        <label>Reason (optional)<input name="reason"></label>
        <button class="btn btn-danger">Record decline</button>
      </form>
    </section>
    <?php endif; ?>

    <?php if ($status === 'accepted' && !$activeContract): ?>
    <section class="card">
      <div class="card-head"><h2>Contract</h2></div>
      <p class="muted">Create the contract from your templates. You can check it before sending for signature.</p>
      <form method="post" action="<?= h($act('contract')) ?>"><?= csrf_field() ?><button class="btn btn-primary">Create contract</button></form>
    </section>
    <?php elseif ($activeContract): ?>
    <section class="card">
      <div class="card-head"><h2>Contract</h2><?= badge($activeContract['status']) ?></div>
      <p><a href="<?= h(url('contracts', ['action' => 'view', 'id' => $activeContract['id']])) ?>"><?= h($activeContract['reference']) ?></a> · <?= h($activeContract['signer_name']) ?></p>
      <?php if ($activeContract['last_error']): ?><p class="text-danger"><?= h($activeContract['last_error']) ?></p><?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-head"><h2>History</h2></div>
      <ul class="feed">
        <li>Created <?= h(fmt_datetime($quote['created_at'])) ?></li>
        <?php if ($quote['sent_at']): ?><li>Sent to <?= h($quote['recipient_name']) ?> &lt;<?= h($quote['recipient_email']) ?>&gt; · <?= h(fmt_datetime($quote['sent_at'])) ?></li><?php endif; ?>
        <?php if ($quote['viewed_at']): ?><li>First viewed <?= h(fmt_datetime($quote['viewed_at'])) ?></li><?php endif; ?>
        <?php if ($quote['responded_at']): ?><li><?= $status === 'declined' ? 'Declined' : 'Accepted' ?><?= $quote['response_name'] ? ' by ' . h($quote['response_name']) : '' ?><?= !empty($quote['response_email']) ? ' &lt;' . h($quote['response_email']) . '&gt;' : '' ?> · <?= h(fmt_datetime($quote['responded_at'])) ?>
          <?php if ($quote['response_ip']): ?><div class="feed-body"><?= h($quote['response_ip']) ?></div><?php endif; ?>
          <?php if ($quote['decline_reason']): ?><div class="feed-body">“<?= h($quote['decline_reason']) ?>”</div><?php endif; ?></li><?php endif; ?>
      </ul>
    </section>
  </aside>
</div>
