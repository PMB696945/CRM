<div class="page-head"><div><h1><?= h($title) ?></h1></div></div>
<div class="card stack" style="max-width:40rem">
  <p><?= h($message) ?></p>
  <div class="actions">
    <?php if (!empty($sign)): ?><a class="btn btn-primary" href="<?= h(esign_url($sign)) ?>" target="_blank" rel="noopener">Review and sign</a><?php endif; ?>
    <a class="btn" href="<?= h(portal_url('orders')) ?>">Back to your orders</a>
  </div>
</div>
