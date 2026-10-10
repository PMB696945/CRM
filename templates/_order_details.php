<?php
/** The details taken on the order form, for the quote and order pages. Expects $details. */
$d = $details;
$row = fn(string $label, ?string $value) => $value !== null && $value !== '' ? '<dt>' . h($label) . '</dt><dd>' . h($value) . '</dd>' : '';
?>
<section class="card">
  <div class="card-head"><h2>Order details <span class="muted small"><?= h(SERVICE_TYPES[$d['type']] ?? $d['type']) ?></span></h2></div>
  <dl class="details">
    <?= $row(['hardware' => 'Delivery address'][$d['type']] ?? 'Installation address', order_address_text($d['address'] ?? null)) ?>
    <?php if ($d['type'] === 'leased_line'): ?>
      <?= $row('Leased line', $d['product'] ?? '') ?>
      <?= $row('Required by', !empty($d['crd']) ? fmt_date($d['crd']) : 'As soon as possible') ?>
      <?= $row('IP addresses', GIACOM_IP_OPTIONS[$d['ip_option'] ?? 'dynamic'][0] ?? '') ?>
      <?= $row('Site contact', trim(($d['contact_name'] ?? '') . ', ' . ($d['contact_phone'] ?? ''), ', ')) ?>
    <?php elseif ($d['type'] === 'sip_trunk'): ?>
      <?= $row('Numbers', ($d['mode'] ?? '') === 'port' ? 'Porting existing numbers' : 'New numbers') ?>
      <?= $row('Channels', (string)($d['channels'] ?? '')) ?>
      <?php if (($d['mode'] ?? '') === 'new'): ?><?= $row('STD code', $d['std_code'] ?? '') ?><?= $row('DDIs', (string)($d['ddis'] ?? 0)) ?><?php endif; ?>
    <?php elseif ($d['type'] === 'hosted_pbx'): ?>
      <?= $row('Users / extensions', (string)($d['users'] ?? '')) ?>
      <?= $row('Licence', PBX_LICENCES[$d['licence'] ?? ''] ?? '') ?>
      <?= $row('System', ($d['mode'] ?? '') === 'migrate' ? 'Migrating their system to us' : 'New system') ?>
      <?php if (($d['mode'] ?? '') === 'new'): ?><?= $row('DDIs', (string)($d['ddis'] ?? 0)) ?><?php else: ?><?= $row('Current installation address', order_address_text($d['current_address'] ?? null)) ?><?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($d['numbers'])): ?>
      <dt><?= $d['type'] === 'sip_trunk' ? 'Numbers to port' : 'Existing numbers' ?></dt><dd><?= h(implode(', ', $d['numbers'])) ?></dd>
      <?= $row('Current provider', $d['provider'] ?? '') ?>
      <dt>Copy bill</dt><dd><?php if (!empty($d['bill_document_id']) && ($doc = db_one('SELECT id, title FROM documents WHERE id = ?', [$d['bill_document_id']]))): ?>
        <a href="<?= h(url('documents', ['action' => 'download', 'id' => $doc['id']])) ?>"><?= h($doc['title']) ?></a>
        <?php else: ?><span class="text-warning">Still needed</span> <span class="muted small">— add it to the customer's files</span><?php endif; ?></dd>
      <dt>Letter of authority</dt><dd><?php if (!empty($d['loa_document_id']) && ($loa = db_one('SELECT id, title FROM documents WHERE id = ?', [$d['loa_document_id']]))): ?>
        <a href="<?= h(url('documents', ['action' => 'download', 'id' => $loa['id']])) ?>"><?= h($loa['title']) ?></a> <span class="muted small">— in the customer's files, and signed with the contract</span>
        <?php else: ?>Created from these details and signed with the contract<?php endif; ?></dd>
    <?php endif; ?>
  </dl>

  <?php if (!empty($d['connections'])): ?>
    <div class="table-wrap"><table class="table table-compact">
      <thead><tr><th>User</th><th>Connection</th><th>Network</th><th>Tariff</th><th>Number</th><th>From</th><th>PAC</th><th>SIM</th></tr></thead>
      <tbody><?php foreach ($d['connections'] as $c): ?><tr>
        <td><?= h($c['first'] . ' ' . $c['last']) ?></td><td><?= h(MOBILE_CONNECTIONS[$c['connection']] ?? $c['connection']) ?></td><td><?= h($c['network']) ?></td>
        <td><?= h($c['product'] ?? '') ?></td><td><?= h($c['number']) ?></td><td><?= h($c['current_network']) ?></td><td><?= h($c['pac']) ?></td><td><?= h($c['sim']) ?></td>
      </tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
  <?php foreach (['handsets' => 'Handsets', 'items' => 'Items'] as $k => $label): if (!empty($d[$k])): ?>
    <h3 class="small muted" style="margin-top:1rem"><?= $label ?></h3>
    <ul><?php foreach ($d[$k] as $i): ?><li><?= (int)$i['qty'] ?> × <?= h($i['product']) ?></li><?php endforeach; ?></ul>
  <?php endif; endforeach; ?>
  <?php if (!empty($d['notes'])): ?><p class="small"><b>Notes:</b> <?= nl2br(h($d['notes'])) ?></p><?php endif; ?>
</section>
