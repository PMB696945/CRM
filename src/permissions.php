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
                    'records.delete', 'export', 'finance.view', 'products.edit', 'marketing.send', 'settings.manage', 'users.manage'],
    'manager'   => ['customers.edit', 'customers.close', 'approvals.decide', 'services.edit', 'tickets.edit', 'sales.edit',
                    'records.delete', 'export', 'finance.view', 'marketing.send'],
    'staff'     => ['customers.edit', 'services.edit', 'tickets.edit', 'sales.edit', 'finance.view'],
    'sales'     => ['customers.edit', 'sales.edit', 'tickets.edit'],
    'support'   => ['customers.edit', 'services.edit', 'tickets.edit'],
    'finance'   => ['customers.edit', 'finance.view', 'export'],
    'read_only' => [],
];

function all_permissions(): array
{
    return array_merge(...array_values(array_map('array_keys', PERMISSIONS)));
}

/** Permission lists per role, including any changes made on the Roles page. */
function role_permissions(): array
{
    $saved = json_decode((string)setting('role_permissions', ''), true);
    $out = [];
    foreach (ROLES as $role => $label) {
        if ($role === 'super_admin') {
            $out[$role] = all_permissions();
            continue;
        }
        $list = is_array($saved[$role] ?? null) ? $saved[$role] : (DEFAULT_ROLE_PERMISSIONS[$role] ?? []);
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
    return ROLES[$role] ?? humanize($role);
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
        if (($_POST['reset'] ?? '') === '1') {
            set_setting('role_permissions', null);
            audit('roles', 'Role permissions reset to the defaults');
            flash('Role permissions reset to the defaults.');
            redirect(url('roles'));
        }
        $before = role_permissions();
        $posted = is_array($_POST['perm'] ?? null) ? $_POST['perm'] : [];
        $save = [];
        $changes = [];
        foreach (ROLES as $role => $label) {
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
        set_setting('role_permissions', json_encode($save));
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
