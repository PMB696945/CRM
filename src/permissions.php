<?php
declare(strict_types=1);

/*
 * Roles and permissions. Each user has one role; each role has a set of
 * permissions. Super admins can change what every other role may do on the
 * Roles page (stored in the "role_permissions" setting); super admins
 * themselves always have every permission.
 */

const ROLES = [
    'super_admin' => 'Super admin',
    'admin'       => 'Admin',
    'manager'     => 'Manager',
    'staff'       => 'Staff',
    'sales'       => 'Sales',
    'support'     => 'Support',
    'finance'     => 'Finance',
    'read_only'   => 'Read only',
];

const ROLE_DESCRIPTIONS = [
    'super_admin' => 'Everything, including the audit trail, roles and other super admins.',
    'admin'       => 'Runs the CRM: users, settings and integrations, and approves requests.',
    'manager'     => 'Day-to-day work plus approving close/delete requests.',
    'staff'       => 'Day-to-day customer, sales and support work. Closing or deleting a customer needs approval.',
    'sales'       => 'Customers, quotes, contracts and the pipeline.',
    'support'     => 'Customers, services and support tickets.',
    'finance'     => 'Customers, balances and Direct Debit.',
    'read_only'   => 'Can look but not change anything.',
];

const PERMISSIONS = [
    'Customers' => [
        'customers.edit'   => 'Add and edit customers, contacts, sites and activity notes',
        'customers.close'  => 'Close a customer without approval',
        'customers.delete' => 'Delete a customer without approval',
        'approvals.decide' => 'Approve or reject close/delete requests',
    ],
    'Work' => [
        'services.edit' => 'Add and edit services & lines',
        'tickets.edit'  => 'Log and update support tickets',
        'sales.edit'    => 'Quotes, contracts and opportunities',
        'records.delete' => 'Delete contacts, services, tickets and other records',
        'export'        => 'Export lists to CSV',
    ],
    'Money' => [
        'finance.view'  => 'See balances, credit and Direct Debit status',
        'products.edit' => 'Manage products & tariffs',
        'costs.view'    => 'See cost prices and margins',
        'costs.edit'    => 'Change cost prices',
    ],
    'Marketing' => [
        'marketing.send' => 'Create and send service alerts and marketing emails',
    ],
    'Administration' => [
        'settings.manage' => 'Settings, integrations and contract templates',
        'users.manage'    => 'Add and edit users',
        'audit.view'      => 'View the audit trail',
    ],
];

const DEFAULT_ROLE_PERMISSIONS = [
    'admin'     => ['customers.edit', 'customers.close', 'customers.delete', 'approvals.decide', 'services.edit', 'tickets.edit', 'sales.edit',
                    'records.delete', 'export', 'finance.view', 'products.edit', 'costs.view', 'costs.edit', 'marketing.send', 'settings.manage', 'users.manage'],
    'manager'   => ['customers.edit', 'customers.close', 'approvals.decide', 'services.edit', 'tickets.edit', 'sales.edit',
                    'records.delete', 'export', 'finance.view', 'costs.view', 'costs.edit', 'marketing.send'],
    'staff'     => ['customers.edit', 'services.edit', 'tickets.edit', 'sales.edit', 'finance.view', 'costs.view'],
    'sales'     => ['customers.edit', 'sales.edit', 'tickets.edit', 'costs.view'],
    'support'   => ['customers.edit', 'services.edit', 'tickets.edit'],
    'finance'   => ['customers.edit', 'finance.view', 'costs.view', 'costs.edit', 'export'],
    'read_only' => [],
];

/** Permissions added after the Roles page existed: roles saved before then get the defaults for these. */
const PERMISSIONS_ADDED_LATER = ['costs.view', 'costs.edit'];

/** Built-in roles plus any custom roles created on the Roles page: [key => label]. */
function roles(): array
{
    $out = ROLES;
    foreach (custom_roles() as $key => $r) {
        $out[$key] = $r['label'];
    }
    return $out;
}

/** Custom roles: [key => ['label' => ..., 'description' => ...]]. */
function custom_roles(): array
{
    $saved = json_decode((string)setting('custom_roles', ''), true);
    return is_array($saved) ? $saved : [];
}

function role_description(string $role): string
{
    return ROLE_DESCRIPTIONS[$role] ?? (custom_roles()[$role]['description'] ?? '') ?: 'Custom role.';
}

function all_permissions(): array
{
    return array_merge(...array_values(array_map('array_keys', PERMISSIONS)));
}

/** Permission lists per role, including any changes made on the Roles page. */
function role_permissions(): array
{
    $saved = json_decode((string)setting('role_permissions', ''), true);
    $out = [];
    foreach (roles() as $role => $label) {
        if ($role === 'super_admin') {
            $out[$role] = all_permissions();
            continue;
        }
        $defaults = DEFAULT_ROLE_PERMISSIONS[$role] ?? [];
        if (is_array($saved[$role] ?? null)) {
            // Permissions that didn't exist when the grid was saved keep their defaults.
            $known = is_array($saved['_known'] ?? null) ? $saved['_known'] : array_diff(all_permissions(), PERMISSIONS_ADDED_LATER);
            $list = array_merge($saved[$role], array_diff(array_intersect($defaults, all_permissions()), $known));
        } else {
            $list = $defaults;
        }
        $out[$role] = array_values(array_intersect(all_permissions(), $list));
    }
    return $out;
}

/** Does the signed-in user have this permission? */
function can(string $permission): bool
{
    $role = current_user()['role'] ?? null;
    return $role !== null && in_array($permission, role_permissions()[$role] ?? [], true);
}

function require_permission(string $permission): void
{
    if (!can($permission)) {
        forbidden();
    }
}

function is_super_admin(): bool
{
    return (current_user()['role'] ?? null) === 'super_admin';
}

function role_label(?string $role): string
{
    return roles()[$role] ?? humanize($role);
}

/** Users with a permission (e.g. who to tell about a new approval request). */
function users_with_permission(string $permission): array
{
    $roles = array_keys(array_filter(role_permissions(), fn($perms) => in_array($permission, $perms, true)));
    if (!$roles) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($roles), '?'));
    return db_all("SELECT id, name, email FROM users WHERE active = 1 AND role IN ($marks) ORDER BY name", $roles);
}

function roles_controller(): void
{
    if (!is_super_admin()) {
        forbidden();
    }
    if (is_post()) {
        verify_csrf();
        $saved = json_decode((string)setting('role_permissions', ''), true) ?: [];
        $action = query('action');

        if ($action === 'add_role') {
            $label = trim(preg_replace('/\s+/', ' ', (string)($_POST['label'] ?? '')));
            $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 200);
            $copy = (string)($_POST['copy_from'] ?? '');
            if ($label === '' || mb_strlen($label) > 40) {
                flash('Give the role a name (up to 40 characters).', 'error');
                redirect(url('roles'));
            }
            if (in_array(mb_strtolower($label), array_map('mb_strtolower', roles()), true)) {
                flash('There is already a role called "' . $label . '".', 'error');
                redirect(url('roles'));
            }
            $base = 'c_' . substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_') ?: 'role', 0, 14);
            $key = $base;
            for ($i = 2; isset(roles()[$key]); $i++) {
                $key = substr($base, 0, 17) . $i;
            }
            $custom = custom_roles();
            $custom[$key] = ['label' => $label, 'description' => $description];
            set_setting('custom_roles', json_encode($custom));
            $perms = $copy !== '' && $copy !== 'super_admin' && isset(roles()[$copy]) ? role_permissions()[$copy] : [];
            set_setting('role_permissions', json_encode([$key => $perms] + $saved + ['_known' => $saved['_known'] ?? array_values(array_diff(all_permissions(), PERMISSIONS_ADDED_LATER))]));
            audit('roles', "Custom role \"$label\" created" . ($perms ? ' with the permissions of ' . role_label($copy) : ''), null, null, null,
                ['Permissions' => ['from' => '', 'to' => implode(', ', $perms)]]);
            flash("Role \"$label\" created. Tick what it can do below, then give it to people on the Users page.");
            redirect(url('roles') . '#role-' . $key);
        }

        if ($action === 'edit_role' || $action === 'delete_role') {
            $key = (string)($_POST['role'] ?? '');
            $custom = custom_roles();
            if (!isset($custom[$key])) {
                flash('Only custom roles can be renamed or deleted.', 'error');
                redirect(url('roles'));
            }
            if ($action === 'delete_role') {
                $users = (int)db_value('SELECT COUNT(*) FROM users WHERE role = ?', [$key]);
                if ($users) {
                    flash("Move the $users user" . ($users === 1 ? '' : 's') . " with this role to another role first.", 'error');
                    redirect(url('roles'));
                }
                $deleted = $custom[$key]['label'];
                unset($custom[$key], $saved[$key]);
                set_setting('custom_roles', json_encode($custom));
                set_setting('role_permissions', json_encode($saved));
                audit('roles', "Custom role \"$deleted\" deleted");
                flash('Role deleted.');
                redirect(url('roles'));
            }
            $label = trim(preg_replace('/\s+/', ' ', (string)($_POST['label'] ?? '')));
            if ($label === '' || mb_strlen($label) > 40) {
                flash('Give the role a name (up to 40 characters).', 'error');
                redirect(url('roles'));
            }
            $old = $custom[$key];
            $custom[$key] = ['label' => $label, 'description' => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 200)];
            set_setting('custom_roles', json_encode($custom));
            audit('roles', "Custom role \"{$old['label']}\" updated", null, null, null,
                ['Name' => ['from' => $old['label'], 'to' => $label], 'Description' => ['from' => $old['description'], 'to' => $custom[$key]['description']]]);
            flash('Role updated.');
            redirect(url('roles'));
        }

        if (($_POST['reset'] ?? '') === '1') {
            // Built-in roles go back to their defaults; custom roles keep their ticks.
            $keep = array_intersect_key($saved, custom_roles());
            set_setting('role_permissions', $keep ? json_encode($keep + ['_known' => all_permissions()]) : null);
            audit('roles', 'Role permissions reset to the defaults');
            flash('Role permissions reset to the defaults.');
            redirect(url('roles'));
        }
        $before = role_permissions();
        $posted = is_array($_POST['perm'] ?? null) ? $_POST['perm'] : [];
        $save = [];
        $changes = [];
        foreach (roles() as $role => $label) {
            if ($role === 'super_admin') {
                continue;
            }
            $list = is_array($posted[$role] ?? null) ? array_keys($posted[$role]) : [];
            $save[$role] = array_values(array_intersect(all_permissions(), $list));
            $added = array_diff($save[$role], $before[$role]);
            $removed = array_diff($before[$role], $save[$role]);
            if ($added || $removed) {
                $changes[$label] = [implode(', ', $removed) ?: '', implode(', ', $added) ?: ''];
            }
        }
        set_setting('role_permissions', json_encode($save + ['_known' => all_permissions()]));
        audit('roles', 'Role permissions changed' . ($changes ? ': ' . implode(', ', array_keys($changes)) : ' (no changes)'), null, null, null,
            array_map(fn($c) => ['removed' => $c[0], 'added' => $c[1]], $changes));
        flash('Role permissions saved.');
        redirect(url('roles'));
    }
    $counts = [];
    foreach (db_all('SELECT role, COUNT(*) AS n FROM users WHERE active = 1 GROUP BY role') as $r) {
        $counts[$r['role']] = (int)$r['n'];
    }
    page('roles', ['matrix' => role_permissions(), 'counts' => $counts], 'Roles & permissions');
}
