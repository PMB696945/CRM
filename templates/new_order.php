<?php
$v = fn(string $k, $default = '') => !array_key_exists($k, $values) ? $default : (is_array($values[$k]) ? $values[$k] : (string)$values[$k]);
$err = fn(string $k) => isset($errors[$k]) ? '<div class="error">' . h($errors[$k]) . '</div>' : '';
$sel = fn(array $options, string $current, string $placeholder = '') => ($placeholder !== '' ? '<option value="">' . h($placeholder) . '</option>' : '')
    . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $current ? ' selected' : '') . '>' . h($l) . '</option>', array_keys($options), $options));
$productOpts = fn(array $products) => array_column(array_map(fn($p) => ['id' => $p['id'], 'label' => $p['name'] . ' – ' . money($p['monthly_price'])
    . ($p['billing_frequency'] === 'one_off' ? '' : '/' . (BILLING_FREQUENCIES[$p['billing_frequency']] ?? 'month'))], $products), 'label', 'id');
?>
<div class="page-head"><div>
  <?php if ($account): ?><div class="crumbs"><a href="<?= h(url('accounts', ['action' => 'view', 'id' => $account['id']])) ?>"><?= h($account['name']) ?></a></div><?php endif; ?>
  <h1>New order</h1>
  <p class="muted">Choose the service, then fill in what it needs. Broadband is checked and ordered straight away; everything else becomes a quote,
    priced from the price list, to send to the customer. Once they accept and sign, the order goes to the onboarding team with all these details.</p></div></div>

<?php if (!$account): ?>
  <form method="get" class="card form-grid">
    <input type="hidden" name="page" value="new_order">
    <div class="field"><label for="o_customer">Customer</label>
      <select id="o_customer" name="account_id" required><option value="">Choose…</option>
        <?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?><?= $c['account_number'] ? ' (' . h($c['account_number']) . ')' : '' ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>&nbsp;</label><button class="btn btn-primary">Continue</button></div>
  </form>
<?php return; endif; ?>

<?php
$id = (int)$account['id'];
$choices = order_address_choices($account);
// An address from the address book, or typed in.
$address = function (string $prefix, string $label) use ($v, $err, $choices): string {
    $current = $v($prefix . '_choice', 'head');
    $html = '<div class="field wide"><label for="' . $prefix . '_choice">' . h($label) . '</label><select id="' . $prefix . '_choice" name="' . $prefix . '_choice">';
    foreach ($choices as $k => $c) {
        $html .= '<option value="' . h((string)$k) . '"' . ((string)$k === $current ? ' selected' : '') . '>' . h($c['label']) . '</option>';
    }
    $html .= '<option value="other"' . ($current === 'other' ? ' selected' : '') . '>Another address…</option></select>' . $err($prefix) . '</div>';
    $html .= '<fieldset class="order-part" data-if="' . $prefix . '_choice=other">';
    foreach (['address' => 'First line', 'city' => 'Town', 'postcode' => 'Postcode'] as $k => $l) {
        $html .= '<div class="field"><label>' . $l . '</label><input name="' . $prefix . '_' . $k . '" value="' . h($v($prefix . '_' . $k)) . '" required></div>';
    }
    return $html . '</fieldset>';
};
// Rows of a product and a quantity (handsets, hardware).
$itemRow = function (string $prefix, array $options, string $what, $pid = '', $qty = '1') use ($sel): string {
    return '<div class="order-row order-row-items" data-row>'
        . '<div class="field"><label>' . h($what) . '</label><select name="' . $prefix . '_product[]">' . $sel($options, (string)$pid, 'Choose…') . '</select></div>'
        . '<div class="field"><label>Quantity</label><input type="number" name="' . $prefix . '_qty[]" min="1" max="999" value="' . h((string)$qty) . '"></div>'
        . '<button type="button" class="btn btn-sm btn-ghost btn-danger order-row-remove" data-remove-row title="Remove">✕</button></div>';
};
$itemRows = function (string $prefix, array $options, string $what) use ($v, $itemRow, $err): string {
    $pids = (array)$v($prefix . '_product', []);
    $html = '<div class="field wide"><div class="order-rows" data-rows="' . $prefix . '">';
    foreach ($pids ?: [''] as $i => $pid) {
        $html .= $itemRow($prefix, $options, $what, $pid, $v($prefix . '_qty', [])[$i] ?? '1');
    }
    $html .= '</div><template data-row-template="' . $prefix . '">' . $itemRow($prefix, $options, $what) . '</template>'
        . '<div><button type="button" class="btn btn-sm btn-add" data-add-row="' . $prefix . '">+ Add another</button></div>' . $err($prefix) . '</div>';
    return $html;
};
$tariffs = $productOpts(order_products('mobile'));
$mobileRow = function (array $r = []) use ($sel, $tariffs): string {
    $g = fn($k) => (string)($r[$k] ?? '');
    $nets = array_combine(MOBILE_NETWORKS, MOBILE_NETWORKS);
    return '<div class="order-row" data-row><div class="order-row-num" data-row-num></div>'
        . '<div class="field"><label>First name</label><input name="m_first[]" value="' . h($g('first')) . '" required></div>'
        . '<div class="field"><label>Last name</label><input name="m_last[]" value="' . h($g('last')) . '" required></div>'
        . '<div class="field"><label>Connection</label><select name="m_connection[]" data-row-control>' . $sel(MOBILE_CONNECTIONS, $g('connection') ?: 'new') . '</select></div>'
        . '<div class="field"><label>Network</label><select name="m_network[]" data-row-control>' . $sel($nets, $g('network') ?: 'O2') . '</select></div>'
        . '<div class="field" style="grid-column:span 2"><label>Tariff</label><select name="m_product_id[]" required>' . $sel($tariffs, $g('product_id'), $tariffs ? 'Choose…' : 'No mobile tariffs in the price list') . '</select></div>'
        . '<div class="field" data-row-if="m_connection[]=migration,port"><label>Mobile number</label><input name="m_number[]" value="' . h($g('number')) . '" inputmode="tel" placeholder="07…"></div>'
        . '<div class="field" data-row-if="m_connection[]=migration,port"><label>Network they\'re on now</label><input name="m_current_network[]" value="' . h($g('current_network')) . '" list="mobile-networks" data-row-control></div>'
        . '<div class="field" data-row-if="m_connection[]=migration,port"><label>PAC code</label><input name="m_pac[]" value="' . h($g('pac')) . '" placeholder="ABC123456" maxlength="12" style="text-transform:uppercase"></div>'
        . '<div class="field" data-row-if="m_connection[]=migration,port" data-sim-field><label>SIM card number <span class="muted small" data-sim-note></span></label><input name="m_sim[]" value="' . h($g('sim')) . '" inputmode="numeric" placeholder="8944…"></div>'
        . '<button type="button" class="btn btn-sm btn-ghost btn-danger order-row-remove" data-remove-row title="Remove">✕</button></div>';
};
?>

<section class="card">
  <div class="form-grid">
    <div class="field"><label>Customer</label>
      <div><b><?= h($account['name']) ?></b> <span class="muted"><?= h($account['account_number'] ?? '') ?></span> · <a class="small" href="<?= h(url('new_order')) ?>">change</a></div></div>
    <div class="field"><label for="service_type">Service type</label>
      <select id="service_type" name="service_type" form="order-form" data-order-type required>
        <option value="">Choose…</option>
        <?php foreach (ORDER_FORM_TYPES as $t): ?><option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= h(SERVICE_TYPES[$t]) ?></option><?php endforeach; ?>
      </select><?= $err('service_type') ?></div>
  </div>
</section>

<?php if ($errors): ?><div class="flash flash-error"><b>Please check:</b><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<section class="card" data-order-for="broadband" hidden>
  <div class="card-head"><h2>Broadband: find the address</h2></div>
  <?php if (!can('orders.check') || !giacom_configured()): ?>
    <p class="muted"><?= giacom_configured() ? 'You don\'t have permission to check availability. Ask an admin to give your role "Check availability".' : 'The broadband supplier isn\'t connected yet. An admin can set it up under Admin.' ?></p>
  <?php else: ?>
    <p class="muted small">Find the address, check what's available there, then choose a product to order it: install date, engineer visit, IP addresses and login.</p>
    <form method="post" action="<?= h(url('giacom', ['action' => 'check'])) ?>" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="search">
      <input type="hidden" name="account_id" value="<?= $id ?>">
      <div class="field wide"><label for="bb_site">Installation site</label>
        <select id="bb_site" name="site_id" data-fill-postcode>
          <?php foreach ($choices as $k => $c): ?><option value="<?= $k === 'head' ? '' : h((string)$k) ?>" data-postcode="<?= h((string)$c['postcode']) ?>"><?= h($c['label']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label for="bb_postcode">Postcode</label><input id="bb_postcode" name="postcode" value="<?= h((string)$account['postcode']) ?>" required autocomplete="off"></div>
      <div class="field"><label for="bb_building">Building name or number (optional)</label><input id="bb_building" name="building"></div>
      <div class="field"><label for="bb_cli">Existing phone number (optional)</label><input id="bb_cli" name="cli" inputmode="tel">
        <div class="help">Include it if there's already a line or broadband there.</div></div>
      <div class="field wide"><div><button class="btn btn-primary">Find addresses</button></div></div>
    </form>
  <?php endif; ?>
</section>

<form method="post" id="order-form" enctype="multipart/form-data" action="<?= h(url('new_order', ['account_id' => $id])) ?>">
  <?= csrf_field() ?>
  <datalist id="mobile-networks"><?php foreach (['O2', 'EE', 'Vodafone', 'Three', 'Virgin Mobile', 'Sky Mobile', 'Tesco Mobile', 'giffgaff', 'iD Mobile', 'BT Mobile'] as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist>

  <fieldset class="card form-grid" data-order-for="leased_line" hidden>
    <div class="form-section wide"><h2>Leased line</h2></div>
    <?= $address('ll_addr', 'Installation address') ?>
    <div class="field wide"><label for="ll_product">Leased line</label>
      <select id="ll_product" name="ll_product"><?= $sel($productOpts(order_products('leased_line')), $v('ll_product'), 'Choose…') ?></select><?= $err('ll_product') ?>
      <div class="help">Bandwidth, term and price come from the price list.</div></div>
    <div class="field"><label for="ll_crd">Required by (optional)</label><input id="ll_crd" type="date" name="ll_crd" min="<?= date('Y-m-d') ?>" value="<?= h($v('ll_crd')) ?>"><?= $err('ll_crd') ?></div>
    <div class="field"><label for="ll_ip">IP addresses</label><select id="ll_ip" name="ll_ip"><?= $sel(array_map(fn($o) => $o[0], GIACOM_IP_OPTIONS), $v('ll_ip', 'static')) ?></select></div>
    <div class="field"><label for="ll_cn">Site contact</label><input id="ll_cn" name="ll_contact_name" value="<?= h($v('ll_contact_name')) ?>" placeholder="Name"></div>
    <div class="field"><label for="ll_cp">Site contact phone</label><input id="ll_cp" name="ll_contact_phone" value="<?= h($v('ll_contact_phone')) ?>" inputmode="tel"><?= $err('ll_contact') ?></div>
  </fieldset>

  <fieldset class="card" data-order-for="mobile" hidden>
    <div class="card-head"><h2>Mobile connections</h2></div>
    <p class="muted small">One row per user. A migration or port needs their number, PAC code and the network they're on now; a migration, or a port staying on the same network, also needs the SIM card number.</p>
    <div class="order-rows" data-rows="mobile">
      <?php $rows = []; foreach ((array)$v('m_first', []) as $i => $_) { $r = []; foreach (['first', 'last', 'connection', 'network', 'current_network', 'number', 'pac', 'sim', 'product_id'] as $k) { $r[$k] = $values['m_' . $k][$i] ?? ''; } $rows[] = $r; }
      foreach ($rows ?: [[]] as $r) { echo $mobileRow($r); } ?>
    </div>
    <template data-row-template="mobile"><?= $mobileRow() ?></template>
    <div style="margin-top:.75rem"><button type="button" class="btn btn-sm btn-add" data-add-row="mobile">+ Add another connection</button></div>
    <?= $err('mobile') ?>
  </fieldset>

  <fieldset class="card form-grid" data-order-for="sip_trunk" hidden>
    <div class="form-section wide"><h2>SIP trunk</h2></div>
    <?= $address('sip_addr', 'Installation address') ?>
    <div class="field wide"><div class="choice-cards">
      <label><input type="radio" name="sip_mode" value="new" <?= $v('sip_mode') === 'new' ? 'checked' : '' ?>><span><b>New numbers</b><span class="help">We provide new numbers.</span></span></label>
      <label><input type="radio" name="sip_mode" value="port" <?= $v('sip_mode') === 'port' ? 'checked' : '' ?>><span><b>Port existing numbers</b><span class="help">Bring their numbers over from their current provider.</span></span></label>
    </div><?= $err('sip_mode') ?></div>
    <div class="field"><label for="sip_channels">Channels</label><input id="sip_channels" type="number" min="1" name="sip_channels" value="<?= h($v('sip_channels')) ?>"><?= $err('sip_channels') ?>
      <div class="help">How many calls at once.</div></div>
    <fieldset class="order-part" data-if="sip_mode=new">
      <div class="field"><label for="sip_std">STD code</label><input id="sip_std" name="sip_std" value="<?= h($v('sip_std')) ?>" placeholder="e.g. 0161" inputmode="numeric"><?= $err('sip_std') ?></div>
      <div class="field"><label for="sip_ddis">DDIs required</label><input id="sip_ddis" type="number" min="0" name="sip_ddis" value="<?= h($v('sip_ddis')) ?>"><?= $err('sip_ddis') ?></div>
    </fieldset>
    <fieldset class="order-part" data-if="sip_mode=port">
      <div class="field"><label for="sip_numbers">Numbers to port</label><textarea id="sip_numbers" name="sip_numbers" rows="4" placeholder="One per line"><?= h($v('sip_numbers')) ?></textarea><?= $err('sip_numbers') ?></div>
      <div class="field"><label for="sip_provider">Current provider</label><input id="sip_provider" name="sip_provider" value="<?= h($v('sip_provider')) ?>"><?= $err('sip_provider') ?>
        <label for="sip_bill" style="margin-top:1rem">Copy bill</label><input id="sip_bill" type="file" name="sip_bill" accept=".pdf,.jpg,.jpeg,.png,.heic,.doc,.docx">
        <div class="help">A recent bill showing the numbers. Can be added to the customer's files later. A letter of authority is created from these details and signed with the contract.</div></div>
    </fieldset>
  </fieldset>

  <fieldset class="card form-grid" data-order-for="hosted_pbx" hidden>
    <div class="form-section wide"><h2>Hosted PBX</h2></div>
    <div class="field"><label for="pbx_users">Users / extensions</label><input id="pbx_users" type="number" min="1" name="pbx_users" value="<?= h($v('pbx_users')) ?>"><?= $err('pbx_users') ?></div>
    <div class="field"><label for="pbx_licence">Licence</label><select id="pbx_licence" name="pbx_licence"><?= $sel(PBX_LICENCES, $v('pbx_licence'), 'Choose…') ?></select><?= $err('pbx_licence') ?></div>
    <div class="field wide"><div class="choice-cards">
      <label><input type="radio" name="pbx_mode" value="new" <?= $v('pbx_mode') === 'new' ? 'checked' : '' ?>><span><b>New system</b><span class="help">New numbers on a new system.</span></span></label>
      <label><input type="radio" name="pbx_mode" value="migrate" <?= $v('pbx_mode') === 'migrate' ? 'checked' : '' ?>><span><b>Migrating their system to us</b><span class="help">Their existing numbers move over from their current provider.</span></span></label>
    </div><?= $err('pbx_mode') ?></div>
    <fieldset class="order-part" data-if="pbx_mode=new">
      <div class="field"><label for="pbx_ddis">DDIs required</label><input id="pbx_ddis" type="number" min="0" name="pbx_ddis" value="<?= h($v('pbx_ddis')) ?>"><?= $err('pbx_ddis') ?></div>
    </fieldset>
    <fieldset class="order-part" data-if="pbx_mode=migrate">
      <div class="field"><label for="pbx_numbers">Existing numbers</label><textarea id="pbx_numbers" name="pbx_numbers" rows="4" placeholder="One per line"><?= h($v('pbx_numbers')) ?></textarea><?= $err('pbx_numbers') ?></div>
      <div class="field"><label for="pbx_provider">Current provider</label><input id="pbx_provider" name="pbx_provider" value="<?= h($v('pbx_provider')) ?>"><?= $err('pbx_provider') ?>
        <label for="pbx_bill" style="margin-top:1rem">Current bill</label><input id="pbx_bill" type="file" name="pbx_bill" accept=".pdf,.jpg,.jpeg,.png,.heic,.doc,.docx">
        <div class="help">A letter of authority is created from these details and signed with the contract.</div></div>
      <?= $address('pbx_addr', 'Current installation address') ?>
    </fieldset>
    <div class="form-section wide"><h2>Handsets</h2><p class="help">From the price list. Leave the row empty if they don't need any.</p></div>
    <?php $handsets = order_products('hardware', ['handset', 'headset', 'accessory']) ?: order_products('hardware'); ?>
    <?= $itemRows('pbx_handset', $productOpts($handsets), 'Handset') ?>
  </fieldset>

  <fieldset class="card form-grid" data-order-for="hardware" hidden>
    <div class="form-section wide"><h2>Hardware</h2></div>
    <?= $address('hw_addr', 'Delivery address') ?>
    <?= $itemRows('hw_item', $productOpts(order_products('hardware')), 'Item') ?>
  </fieldset>

  <fieldset class="card form-grid" data-order-for="leased_line mobile sip_trunk hosted_pbx hardware" hidden>
    <div class="field wide"><label for="o_notes">Notes for the onboarding team (optional)</label><textarea id="o_notes" name="notes" rows="3"><?= h($v('notes')) ?></textarea></div>
    <div class="field wide"><div><button class="btn btn-primary">Create quote</button></div>
      <div class="help">Creates a draft quote priced from the price list, with these details attached. Check it, then send it to the customer.</div></div>
  </fieldset>
</form>
