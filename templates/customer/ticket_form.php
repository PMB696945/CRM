<?php $f = fn(string $name, string $label, string $type = 'text', string $help = '', bool $req = false) => portal_field($v, $errors, $name, $label, $type, $help, $req); ?>
<div class="page-head"><div><div class="crumbs"><a href="<?= h(portal_url('tickets')) ?>">Support tickets</a></div><h1>Raise a support ticket</h1></div></div>
<?php if ($errors): ?><div class="flash flash-error">Please fix the highlighted fields.</div><?php endif; ?>
<form method="post" action="<?= h(portal_url('ticket_new')) ?>" class="card form-grid">
  <?= csrf_field() ?>
  <div class="field <?= isset($errors['category']) ? 'has-error' : '' ?>"><label for="p_category">What's it about?</label>
    <select id="p_category" name="category"><?php foreach (CUSTOMER_TICKET_CATEGORIES as $k => $l): ?><option value="<?= $k ?>" <?= $v['category'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="p_service">Which service? (optional)</label>
    <select id="p_service" name="service_id"><option value="">Not about a particular service</option>
      <?php foreach ($services as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $v['service_id'] === (string)$s['id'] ? 'selected' : '' ?>><?= h($s['identifier'] . ($s['product_name'] ? ' – ' . $s['product_name'] : '')) ?></option><?php endforeach; ?></select></div>
  <div class="field wide" data-show-if="category=fault"><label>How bad is it?</label>
    <div class="choice-cards">
      <label><input type="radio" name="urgency" value="down" <?= $v['urgency'] === 'down' ? 'checked' : '' ?>><span><b>Not working at all</b><span class="help">The service is completely down.</span></span></label>
      <label><input type="radio" name="urgency" value="normal" <?= $v['urgency'] !== 'down' ? 'checked' : '' ?>><span><b>Working, but with problems</b><span class="help">Slow, dropping out, or something isn't right.</span></span></label>
    </div></div>
  <div class="wide"><?= $f('subject', 'Summary', 'text', 'e.g. "Broadband dropping out every evening"', true) ?></div>
  <div class="field wide <?= isset($errors['description']) ? 'has-error' : '' ?>"><label for="p_description">Details <span class="req">*</span></label>
    <textarea id="p_description" name="description" rows="6" required placeholder="What's happening, since when, and anything you've already tried"><?= h($v['description']) ?></textarea>
    <?php if (isset($errors['description'])): ?><div class="error"><?= h($errors['description']) ?></div><?php endif; ?></div>
  <div class="form-actions wide"><button class="btn btn-primary">Send</button> <a class="btn btn-ghost" href="<?= h(portal_url('tickets')) ?>">Cancel</a>
    <?php if (company('phone')): ?><p class="help">For an urgent fault, you can also call us on <?= h(company('phone')) ?>.</p><?php endif; ?></div>
</form>
