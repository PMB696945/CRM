<?php
declare(strict_types=1);

/*
 * Ticket groups (queues) such as Sales, Faults and Billing. Staff can belong
 * to several groups. New tickets go to a group (chosen, or by category) and
 * wait unassigned in that group's queue until someone picks them up, oldest
 * first. People only see tickets in their groups, ones assigned to them and
 * ungrouped ones, unless they have "See tickets in every group".
 */

function ticket_groups(bool $activeOnly = false): array
{
    return db_all('SELECT g.*, (SELECT COUNT(*) FROM ticket_group_members m WHERE m.group_id = g.id) AS members
        FROM ticket_groups g' . ($activeOnly ? ' WHERE g.active = 1' : '') . ' ORDER BY g.name');
}

/** Group ids a user belongs to. */
function user_group_ids(?int $userId = null, bool $refresh = false): array
{
    static $cache = [];
    $userId ??= (int)(current_user()['id'] ?? 0);
    if ($refresh || !isset($cache[$userId])) {
        try {
            $cache[$userId] = array_map('intval', array_column(db_all('SELECT group_id FROM ticket_group_members WHERE user_id = ?', [$userId]), 'group_id'));
        } catch (PDOException) {
            $cache[$userId] = []; // before the upgrade has run
        }
    }
    return $cache[$userId];
}

/** The active group that handles a ticket category, if any. */
function ticket_group_for_category(?string $category): ?int
{
    if (!$category) {
        return null;
    }
    foreach (db_all('SELECT id, categories FROM ticket_groups WHERE active = 1 ORDER BY id') as $g) {
        if (in_array($category, array_map('trim', explode(',', (string)$g['categories'])), true)) {
            return (int)$g['id'];
        }
    }
    return null;
}

/** SQL limiting tickets to those this user may see, or null for everything. */
function ticket_scope(): ?array
{
    if (can('tickets.all')) {
        return null;
    }
    $ids = user_group_ids();
    $in = $ids ? 't.group_id IN (' . implode(',', $ids) . ') OR ' : '';
    return ["(t.group_id IS NULL OR {$in}t.assigned_to = :scope_me)", ['scope_me' => (int)(current_user()['id'] ?? 0)]];
}

function can_see_ticket(array $ticket): bool
{
    return can('tickets.all') || !$ticket['group_id'] || in_array((int)$ticket['group_id'], user_group_ids(), true)
        || (int)$ticket['assigned_to'] === (int)(current_user()['id'] ?? 0);
}

/** Take an unassigned ticket. Returns false if someone else got there first. */
function ticket_pick_up(int $id): bool
{
    $user = current_user();
    $ticket = db_one('SELECT * FROM tickets WHERE id = ?', [$id]);
    if (!$ticket || !can_see_ticket($ticket)) {
        return false;
    }
    $taken = db_exec("UPDATE tickets SET assigned_to = ?, status = IF(status = 'open', 'in_progress', status) WHERE id = ? AND assigned_to IS NULL", [$user['id'], $id]);
    if (!$taken) {
        return false;
    }
    db_exec('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal) VALUES (?, ?, ?, 1)', [$id, $user['id'], $user['name'] . ' picked up this ticket.']);
    audit('ticket_pickup', "Ticket {$ticket['reference']} picked up from the queue", 'tickets', $id);
    return true;
}

/** Waiting tickets in the user's groups (oldest first), or in one group. */
function ticket_queue(?int $groupId = null, int $limit = 200): array
{
    $ids = $groupId ? [$groupId] : user_group_ids();
    if (!$ids) {
        return [];
    }
    return db_all("SELECT t.*, a.name AS account_name, g.name AS group_name FROM tickets t
        JOIN accounts a ON a.id = t.account_id LEFT JOIN ticket_groups g ON g.id = t.group_id
        WHERE t.assigned_to IS NULL AND t.status NOT IN ('resolved','closed') AND t.group_id IN (" . implode(',', array_map('intval', $ids)) . ")
        ORDER BY t.created_at, t.id LIMIT " . (int)$limit);
}

function ticket_queue_count(): int
{
    try {
        $ids = user_group_ids();
        return $ids ? (int)db_value("SELECT COUNT(*) FROM tickets WHERE assigned_to IS NULL AND status NOT IN ('resolved','closed') AND group_id IN (" . implode(',', $ids) . ')') : 0;
    } catch (PDOException) {
        return 0;
    }
}

/** Tell a group's members (or its shared inbox) about a new ticket waiting for them. */
function ticket_notify_group(int $ticketId): void
{
    $t = db_one('SELECT t.*, a.name AS account_name, g.name AS group_name, g.email AS group_email FROM tickets t
        JOIN accounts a ON a.id = t.account_id JOIN ticket_groups g ON g.id = t.group_id WHERE t.id = ?', [$ticketId]);
    if (!$t || !mail_configured() || $t['assigned_to']) {
        return;
    }
    $to = $t['group_email']
        ? [['email' => $t['group_email'], 'name' => $t['group_name']]]
        : db_all('SELECT u.name, u.email FROM ticket_group_members m JOIN users u ON u.id = m.user_id WHERE m.group_id = ? AND u.active = 1 AND u.id <> ?',
            [$t['group_id'], (int)(current_user()['id'] ?? 0)]);
    $subject = "New {$t['priority']} ticket in {$t['group_name']}: {$t['subject']}";
    $body = '<p><b>' . h($t['reference']) . '</b> for ' . h($t['account_name']) . ' is waiting in the ' . h($t['group_name']) . ' queue.</p>'
        . ($t['description'] ? '<p>' . nl2br(h(mb_strimwidth($t['description'], 0, 600, '…'))) . '</p>' : '')
        . email_button(app_url() . '/' . url('tickets', ['action' => 'view', 'id' => $t['id']]), 'Open the ticket');
    foreach ($to as $u) {
        try {
            send_mail($u['email'], $u['name'], $subject, email_layout('New ticket waiting', $body));
        } catch (IntegrationException $e) {
            error_log('Ticket notification failed: ' . $e->getMessage());
        }
    }
}

/* --------------------------------------------------------------- Pages --- */

/** The queue: waiting tickets in my groups, oldest first, with "Take next". */
function queue_controller(): void
{
    require_permission('tickets.edit');
    $groupId = query_int('group');
    $mine = user_group_ids();
    if ($groupId && !in_array($groupId, $mine, true) && !can('tickets.all')) {
        forbidden();
    }
    if (is_post()) {
        verify_csrf();
        $id = (int)($_POST['id'] ?? 0);
        if (($_POST['next'] ?? '') === '1') {
            // Take the oldest waiting ticket; retry if someone else takes it at the same moment.
            foreach (ticket_queue($groupId, 5) as $t) {
                if (ticket_pick_up((int)$t['id'])) {
                    flash("You've picked up {$t['reference']}.");
                    redirect(url('tickets', ['action' => 'view', 'id' => $t['id']]));
                }
            }
            flash('Nothing is waiting in your queue.');
            redirect(url('queue', ['group' => $groupId]));
        }
        if ($id && ticket_pick_up($id)) {
            flash('Ticket picked up.');
            redirect(url('tickets', ['action' => 'view', 'id' => $id]));
        }
        flash('Someone else has already picked that ticket up.', 'error');
        redirect(url('queue', ['group' => $groupId]));
    }
    $groups = array_values(array_filter(ticket_groups(true), fn($g) => in_array((int)$g['id'], $mine, true) || can('tickets.all')));
    $counts = [];
    foreach (db_all("SELECT group_id, COUNT(*) AS n FROM tickets WHERE assigned_to IS NULL AND status NOT IN ('resolved','closed') AND group_id IS NOT NULL GROUP BY group_id") as $r) {
        $counts[(int)$r['group_id']] = (int)$r['n'];
    }
    $queue = ($groupId || $mine) ? ticket_queue($groupId) : [];
    $myOpen = db_all("SELECT t.*, a.name AS account_name, g.name AS group_name FROM tickets t JOIN accounts a ON a.id = t.account_id
        LEFT JOIN ticket_groups g ON g.id = t.group_id WHERE t.assigned_to = ? AND t.status NOT IN ('resolved','closed') ORDER BY t.sla_due_at LIMIT 50",
        [current_user()['id']]);
    page('queue', compact('groups', 'counts', 'queue', 'groupId', 'mine', 'myOpen'), 'Ticket queue');
}

/** Admin → Ticket groups: create groups, route categories, choose members. */
function ticket_groups_controller(): void
{
    require_permission('users.manage');
    $id = query_int('id');
    $group = $id ? (db_one('SELECT * FROM ticket_groups WHERE id = ?', [$id]) ?? not_found()) : null;
    $categories = entity('tickets')['fields']['category']['options'];

    if (is_post()) {
        verify_csrf();
        if (query('action') === 'delete' && $group) {
            $n = (int)db_value('SELECT COUNT(*) FROM tickets WHERE group_id = ?', [$group['id']]);
            db_exec('DELETE FROM ticket_groups WHERE id = ?', [$group['id']]);
            audit('ticket_group', "Ticket group \"{$group['name']}\" deleted" . ($n ? " ($n tickets now ungrouped)" : ''));
            flash('Group deleted.' . ($n ? " Its $n ticket" . ($n === 1 ? ' is' : 's are') . ' now ungrouped.' : ''));
            redirect(url('ticket_groups'));
        }
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $cats = array_values(array_intersect(array_keys($categories), is_array($_POST['categories'] ?? null) ? $_POST['categories'] : []));
        $members = array_map('intval', is_array($_POST['members'] ?? null) ? $_POST['members'] : []);
        if ($name === '' || mb_strlen($name) > 80) {
            flash('Give the group a name.', 'error');
            redirect(url('ticket_groups', ['id' => $id]));
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('That shared email address isn\'t valid.', 'error');
            redirect(url('ticket_groups', ['id' => $id]));
        }
        if (db_value('SELECT id FROM ticket_groups WHERE name = ? AND id <> ?', [$name, $id ?? 0])) {
            flash('There is already a group with that name.', 'error');
            redirect(url('ticket_groups', ['id' => $id]));
        }
        // A category is routed to one group only.
        if ($cats) {
            foreach (db_all('SELECT id, categories FROM ticket_groups WHERE id <> ?', [$id ?? 0]) as $g) {
                $left = array_diff(array_filter(explode(',', (string)$g['categories'])), $cats);
                db_exec('UPDATE ticket_groups SET categories = ? WHERE id = ?', [$left ? implode(',', $left) : null, $g['id']]);
            }
        }
        $params = [$name, mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255) ?: null, $cats ? implode(',', $cats) : null, $email ?: null, empty($_POST['active']) ? 0 : 1];
        $before = $group ? array_map('intval', array_column(db_all('SELECT user_id FROM ticket_group_members WHERE group_id = ?', [$id]), 'user_id')) : [];
        if ($group) {
            db_exec('UPDATE ticket_groups SET name = ?, description = ?, categories = ?, email = ?, active = ? WHERE id = ?', array_merge($params, [$id]));
        } else {
            db_exec('INSERT INTO ticket_groups (name, description, categories, email, active) VALUES (?, ?, ?, ?, ?)', $params);
            $id = (int)db()->lastInsertId();
        }
        db_exec('DELETE FROM ticket_group_members WHERE group_id = ?', [$id]);
        foreach (array_unique($members) as $u) {
            if (db_value('SELECT id FROM users WHERE id = ?', [$u])) {
                db_exec('INSERT INTO ticket_group_members (group_id, user_id) VALUES (?, ?)', [$id, $u]);
            }
        }
        $names = fn(array $ids) => implode(', ', array_column($ids ? db_all('SELECT name FROM users WHERE id IN (' . implode(',', $ids) . ') ORDER BY name') : [], 'name'));
        $added = array_diff($members, $before);
        $removed = array_diff($before, $members);
        audit('ticket_group', "Ticket group \"$name\" " . ($group ? 'updated' : 'created'), null, null, null, array_filter([
            'Categories' => ['from' => (string)($group['categories'] ?? ''), 'to' => implode(',', $cats)],
            'Members added' => $added ? ['from' => '', 'to' => $names($added)] : null,
            'Members removed' => $removed ? ['from' => $names($removed), 'to' => ''] : null,
        ]));
        flash('Group saved.');
        redirect(url('ticket_groups'));
    }

    $users = db_all('SELECT id, name, email, role, active FROM users ORDER BY active DESC, name');
    $memberIds = $group ? array_map('intval', array_column(db_all('SELECT user_id FROM ticket_group_members WHERE group_id = ?', [$group['id']]), 'user_id')) : [];
    $groups = ticket_groups();
    $memberNames = [];
    foreach (db_all('SELECT m.group_id, u.name FROM ticket_group_members m JOIN users u ON u.id = m.user_id ORDER BY u.name') as $r) {
        $memberNames[(int)$r['group_id']][] = $r['name'];
    }
    $waiting = [];
    foreach (db_all("SELECT group_id, COUNT(*) AS n FROM tickets WHERE assigned_to IS NULL AND status NOT IN ('resolved','closed') GROUP BY group_id") as $r) {
        $waiting[(int)$r['group_id']] = (int)$r['n'];
    }
    page('ticket_groups', compact('group', 'groups', 'users', 'memberIds', 'categories', 'memberNames', 'waiting') + ['editing' => $group !== null || query('action') === 'new'],
        'Ticket groups');
}
