<div class="page-head"><h1>Ticket groups</h1>
  <?php if (!$editing): ?><a class="btn btn-primary" href="<?= h(url('ticket_groups', ['action' => 'new'])) ?>">+ New group</a><?php endif; ?></div>
<p class="lead">Groups such as Sales, Faults and Billing. New tickets go to a group, chosen on the ticket or by its category, and wait in that group's queue until someone in the group picks them up. People can be in several groups. Staff only see tickets in their own groups (and ungrouped ones) unless their role can "See tickets in every group".</p>

<?php if ($editing): ?>
<form method="post" class="card form-grid">
  <?= csrf_field() ?>
  <div class="field"><label for="tg_name">Name <span class="req">*</span></label><input id="tg_name" name="name" value="<?= h($group['name'] ?? '') ?>" required maxlength="80"></div>
  <div class="field"><label for="tg_desc">Description</label><input id="tg_desc" name="description" value="<?= h($group['description'] ?? '') ?>" maxlength="255"></div>
  <div class="field"><label for="tg_email">Shared email (optional)</label><input id="tg_email" type="email" name="email" value="<?= h($group['email'] ?? '') ?>" placeholder="e.g. faults@yourcompany.co.uk">
    <div class="help">New tickets are emailed here. Leave blank to email each member instead.</div></div>
  <div class="field"><label for="tg_pick">Alert if not picked up within (minutes)</label><input id="tg_pick" type="number" min="0" max="10080" name="pickup_minutes" value="<?= h($group['pickup_minutes'] ?? '') ?>" placeholder="<?= (int)ticket_pickup_minutes(null) ?> (the default)">
    <div class="help">Leave blank for the default below; 0 turns alerts off for this group.</div></div>
  <div class="field"><label for="tg_alert">Also alert (optional)</label><input id="tg_alert" type="email" name="alert_email" value="<?= h($group['alert_email'] ?? '') ?>" placeholder="e.g. team-leader@yourcompany.co.uk">
    <div class="help">As well as the people whose role gets ticket alerts.</div></div>
  <div class="field field-check"><label><input type="checkbox" name="active" value="1" <?= ($group['active'] ?? 1) ? 'checked' : '' ?>> Active</label></div>
  <div class="field wide">
    <fieldset class="checkbox-group"><legend>Tickets in these categories go to this group</legend>
      <?php $cats = array_filter(explode(',', (string)($group['categories'] ?? ''))); foreach ($categories as $k => $l): ?>
        <label class="check"><input type="checkbox" name="categories[]" value="<?= h($k) ?>" <?= in_array($k, $cats, true) ? 'checked' : '' ?>> <?= h($l) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <div class="help">A category can only route to one group; ticking it here moves it from any other group.</div>
  </div>
  <div class="field wide">
    <fieldset class="checkbox-group"><legend>Members</legend>
      <?php foreach ($users as $u): ?>
        <label class="check"><input type="checkbox" name="members[]" value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $memberIds, true) ? 'checked' : '' ?>> <?= h($u['name']) ?><?= $u['active'] ? '' : ' <span class="muted">(disabled)</span>' ?></label>
      <?php endforeach; ?>
    </fieldset>
  </div>
  <div class="form-actions wide"><button class="btn btn-primary">Save group</button><a class="btn btn-ghost" href="<?= h(url('ticket_groups')) ?>">Cancel</a></div>
</form>
<?php if ($group): ?>
  <form method="post" action="<?= h(url('ticket_groups', ['action' => 'delete', 'id' => $group['id']])) ?>" data-confirm="Delete the <?= h($group['name']) ?> group? Its tickets become ungrouped."><?= csrf_field() ?><button class="btn btn-danger btn-sm">Delete group</button></form>
<?php endif; ?>
<?php endif; ?>

<div class="table-wrap" style="margin-top:1.5rem"><table class="table">
  <thead><tr><th>Group</th><th>Categories</th><th>Members</th><th>Alert after</th><th class="num">Waiting</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($groups as $g): ?>
    <tr>
      <td><a class="row-link" href="<?= h(url('ticket_groups', ['id' => $g['id']])) ?>"><?= h($g['name']) ?></a><div class="muted small"><?= h($g['description']) ?></div></td>
      <td class="small"><?= h(implode(', ', array_map(fn($c) => $categories[$c] ?? $c, array_filter(explode(',', (string)$g['categories']))))) ?: '<span class="muted">—</span>' ?></td>
      <td class="small"><?= h(implode(', ', $memberNames[(int)$g['id']] ?? [])) ?: '<span class="text-warning">No members</span>' ?></td>
      <td class="small"><?= ($m = ticket_pickup_minutes($g)) ? h(duration_label($m)) . ($g['pickup_minutes'] === null ? ' <span class="muted">(default)</span>' : '') : '<span class="muted">Off</span>' ?></td>
      <td class="num"><a href="<?= h(url('queue', ['group' => $g['id']])) ?>"><?= (int)($waiting[(int)$g['id']] ?? 0) ?></a></td>
      <td><?= $g['active'] ? badge('active') : badge('disabled') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<section class="card" style="margin-top:1.5rem">
  <div class="card-head"><h2>Tickets nobody picks up</h2></div>
  <p>If a ticket waits in a group's queue longer than the group's limit, an alert is emailed and shown at the top of the CRM to people whose role can "Get alerts about tickets nobody has picked up"<?= $alertees ? ' (currently ' . h(implode(', ', array_column($alertees, 'name'))) . ')' : '' ?>. Each ticket is alerted once, and a note is added to it.</p>
  <form method="post" action="<?= h(url('ticket_groups', ['action' => 'default_pickup'])) ?>" class="inline-form">
    <?= csrf_field() ?>
    <label class="check" for="tg_default">Default limit</label>
    <input id="tg_default" type="number" min="0" max="10080" name="default_minutes" value="<?= (int)ticket_pickup_minutes(null) ?>" style="max-width:7rem"> <span class="muted">minutes (0 = off)</span>
    <button class="btn btn-sm">Save</button>
  </form>
  <p class="help" style="margin-top:.75rem">The check runs every minute while anyone is using the CRM, and on every run of the cron job.</p>
</section>
