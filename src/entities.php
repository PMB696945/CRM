<?php
declare(strict_types=1);

/*
 * Entity definitions. Each entry drives the generic list, form, validation,
 * CSV export and persistence code in repository.php, so adding a field is
 * usually a one-line change here plus a schema column.
 *
 * Field types: text, textarea, email, tel, select, ref, money, int, date, bool.
 * "ref" fields point to another entity and are rendered via a JOIN.
 */

const SERVICE_TYPES = [
    'mobile' => 'Mobile', 'broadband' => 'Broadband', 'voip' => 'VoIP line', 'sip_trunk' => 'SIP trunk',
    'hosted_pbx' => 'Hosted PBX', 'leased_line' => 'Leased line', 'ethernet' => 'Ethernet',
    'hardware' => 'Hardware', 'other' => 'Other',
];

const CARRIERS = [
    'EE', 'Vodafone', 'O2', 'Three', 'BT Wholesale', 'Openreach', 'TalkTalk Wholesale',
    'CityFibre', 'Virgin Media Business', 'Gamma', 'Colt', 'Other',
];

const OPP_STAGES = ['lead', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];
const STAGE_PROBABILITY = ['lead' => 10, 'qualified' => 25, 'proposal' => 50, 'negotiation' => 75, 'won' => 100, 'lost' => 0];

/** Reference targets: which column is shown for a foreign key. */
const REF_LABELS = [
    'accounts' => 'name',
    'products' => 'name',
    'services' => 'identifier',
    'contacts' => 'name',
    'users'    => 'name',
    'xero_contacts' => 'name',
];

function opts(array $values): array
{
    $out = [];
    foreach ($values as $key => $label) {
        if (is_int($key)) {
            $out[$label] = humanize($label);
        } else {
            $out[$key] = $label;
        }
    }
    return $out;
}

function entities(): array
{
    // Cached per request, keyed on the features that change the definitions.
    static $cache = [];
    $key = xero_connected() ? 'xero' : 'base';
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $entities = [
        'accounts' => [
            'label' => 'Customer', 'plural' => 'Customers', 'icon' => '🏢',
            'fields' => [
                'account_number' => ['label' => 'Account no.', 'type' => 'text', 'help' => 'Leave blank to auto-generate'],
                'name'           => ['label' => 'Name', 'type' => 'text', 'required' => true],
                'type'           => ['label' => 'Type', 'type' => 'select', 'options' => opts(['business', 'residential']), 'required' => true],
                'status'         => ['label' => 'Status', 'type' => 'select', 'options' => opts(['prospect', 'active', 'suspended', 'churned']), 'required' => true],
                'industry'       => ['label' => 'Industry', 'type' => 'text'],
                'company_number' => ['label' => 'Company no.', 'type' => 'text'],
                'email'          => ['label' => 'Email', 'type' => 'email'],
                'phone'          => ['label' => 'Phone', 'type' => 'tel'],
                'address'        => ['label' => 'Address', 'type' => 'text'],
                'city'           => ['label' => 'City', 'type' => 'text'],
                'postcode'       => ['label' => 'Postcode', 'type' => 'text'],
                'owner_id'       => ['label' => 'Account manager', 'type' => 'ref', 'ref' => 'users'],
                'credit_limit'   => ['label' => 'Credit limit', 'type' => 'money'],
                'xero_contact_id' => ['label' => 'Xero contact', 'type' => 'ref', 'ref' => 'xero_contacts', 'if' => 'xero_connected',
                    'help' => 'Linked automatically on sync when the account number, email or name matches'],
                'notes'          => ['label' => 'Notes', 'type' => 'textarea'],
            ],
            'list'    => ['account_number', 'name', 'type', 'status', 'city', 'owner_id', '_mrr'],
            'search'  => ['name', 'account_number', 'email', 'phone', 'postcode', 'company_number'],
            'filters' => ['status', 'type', 'owner_id'],
            'default_sort' => ['name', 'asc'],
            'computed' => [
                '_mrr' => ['label' => 'MRR', 'type' => 'money',
                    'sql' => "(SELECT COALESCE(SUM(s.monthly_price),0) FROM services s WHERE s.account_id = t.id AND s.status = 'active')"],
            ],
        ],

        'contacts' => [
            'label' => 'Contact', 'plural' => 'Contacts', 'icon' => '👤',
            'fields' => [
                'account_id' => ['label' => 'Customer', 'type' => 'ref', 'ref' => 'accounts', 'required' => true],
                'name'       => ['label' => 'Name', 'type' => 'text', 'required' => true],
                'job_title'  => ['label' => 'Job title', 'type' => 'text'],
                'email'      => ['label' => 'Email', 'type' => 'email'],
                'phone'      => ['label' => 'Phone', 'type' => 'tel'],
                'mobile'     => ['label' => 'Mobile', 'type' => 'tel'],
                'is_primary' => ['label' => 'Primary contact', 'type' => 'bool'],
                'is_billing' => ['label' => 'Billing contact', 'type' => 'bool'],
                'notes'      => ['label' => 'Notes', 'type' => 'textarea'],
            ],
            'list'    => ['name', 'account_id', 'job_title', 'email', 'phone', 'mobile', 'is_primary'],
            'search'  => ['name', 'email', 'phone', 'mobile'],
            'filters' => ['account_id'],
            'default_sort' => ['name', 'asc'],
        ],

        'products' => [
            'label' => 'Product', 'plural' => 'Products & tariffs', 'icon' => '📦', 'admin_write' => true,
            'fields' => [
                'sku'           => ['label' => 'SKU', 'type' => 'text', 'required' => true],
                'name'          => ['label' => 'Name', 'type' => 'text', 'required' => true],
                'category'      => ['label' => 'Category', 'type' => 'select', 'options' => SERVICE_TYPES, 'required' => true],
                'carrier'       => ['label' => 'Carrier / network', 'type' => 'select', 'options' => opts(CARRIERS)],
                'monthly_price' => ['label' => 'Monthly price', 'type' => 'money', 'required' => true],
                'setup_fee'     => ['label' => 'Setup fee', 'type' => 'money'],
                'term_months'   => ['label' => 'Term (months)', 'type' => 'int', 'required' => true, 'min' => 0],
                'active'        => ['label' => 'Available to sell', 'type' => 'bool', 'default' => 1],
                'description'   => ['label' => 'Description', 'type' => 'textarea'],
            ],
            'list'    => ['sku', 'name', 'category', 'carrier', 'monthly_price', 'setup_fee', 'term_months', 'active'],
            'search'  => ['sku', 'name', 'description'],
            'filters' => ['category', 'carrier', 'active'],
            'default_sort' => ['name', 'asc'],
        ],

        'services' => [
            'label' => 'Service', 'plural' => 'Services & lines', 'icon' => '📶',
            'fields' => [
                'account_id'        => ['label' => 'Customer', 'type' => 'ref', 'ref' => 'accounts', 'required' => true],
                'product_id'        => ['label' => 'Product / tariff', 'type' => 'ref', 'ref' => 'products', 'help' => 'Prices, term and type are copied from the product when left blank'],
                'service_type'      => ['label' => 'Service type', 'type' => 'select', 'options' => SERVICE_TYPES],
                'identifier'        => ['label' => 'Number / circuit ID', 'type' => 'text', 'required' => true, 'help' => 'MSISDN, CLI, circuit reference or serial number'],
                'carrier'           => ['label' => 'Carrier / network', 'type' => 'select', 'options' => opts(CARRIERS)],
                'status'            => ['label' => 'Status', 'type' => 'select', 'options' => opts(['pending', 'active', 'suspended', 'ceased']), 'required' => true],
                'monthly_price'     => ['label' => 'Monthly price', 'type' => 'money'],
                'setup_fee'         => ['label' => 'Setup fee', 'type' => 'money'],
                'start_date'        => ['label' => 'Contract start', 'type' => 'date'],
                'term_months'       => ['label' => 'Term (months)', 'type' => 'int', 'min' => 0],
                'contract_end_date' => ['label' => 'Contract end', 'type' => 'date', 'help' => 'Calculated from start + term when left blank'],
                'install_address'   => ['label' => 'Installation address', 'type' => 'text'],
                'notes'             => ['label' => 'Notes', 'type' => 'textarea'],
            ],
            'list'    => ['identifier', 'account_id', 'service_type', 'carrier', 'status', 'monthly_price', 'contract_end_date'],
            'search'  => ['identifier', 'install_address', 'notes'],
            'filters' => ['status', 'service_type', 'carrier', 'account_id'],
            'presets' => [
                'expiring' => ['label' => 'Up for renewal', 'sql' => "t.status = 'active' AND t.contract_end_date IS NOT NULL AND t.contract_end_date <= DATE_ADD(CURDATE(), INTERVAL :window DAY)", 'params' => fn() => ['window' => (int)config('renewal_window_days')]],
                'out_of_contract' => ['label' => 'Out of contract', 'sql' => "t.status = 'active' AND t.contract_end_date < CURDATE()"],
            ],
            'default_sort' => ['contract_end_date', 'asc'],
        ],

        'tickets' => [
            'label' => 'Ticket', 'plural' => 'Support tickets', 'icon' => '🎫',
            'fields' => [
                'reference'   => ['label' => 'Ref', 'type' => 'text', 'readonly' => true],
                'account_id'  => ['label' => 'Customer', 'type' => 'ref', 'ref' => 'accounts', 'required' => true],
                'service_id'  => ['label' => 'Affected service', 'type' => 'ref', 'ref' => 'services', 'scoped' => true],
                'contact_id'  => ['label' => 'Reported by', 'type' => 'ref', 'ref' => 'contacts', 'scoped' => true],
                'subject'     => ['label' => 'Subject', 'type' => 'text', 'required' => true],
                'category'    => ['label' => 'Category', 'type' => 'select', 'options' => opts(['fault', 'billing', 'order', 'porting', 'cancellation', 'general']), 'required' => true],
                'priority'    => ['label' => 'Priority', 'type' => 'select', 'options' => ['P1' => 'P1 – Critical', 'P2' => 'P2 – High', 'P3' => 'P3 – Normal', 'P4' => 'P4 – Low'], 'required' => true, 'default' => 'P3'],
                'status'      => ['label' => 'Status', 'type' => 'select', 'options' => opts(['open', 'in_progress', 'awaiting_customer', 'awaiting_carrier', 'resolved', 'closed']), 'required' => true],
                'assigned_to' => ['label' => 'Assigned to', 'type' => 'ref', 'ref' => 'users'],
                'carrier_ref' => ['label' => 'Carrier fault ref', 'type' => 'text'],
                'description' => ['label' => 'Description', 'type' => 'textarea'],
                'sla_due_at'  => ['label' => 'SLA due', 'type' => 'datetime', 'readonly' => true],
                'created_at'  => ['label' => 'Opened', 'type' => 'datetime', 'readonly' => true],
            ],
            'list'    => ['reference', 'subject', 'account_id', 'category', 'priority', 'status', 'assigned_to', 'sla_due_at'],
            'search'  => ['reference', 'subject', 'description', 'carrier_ref'],
            'filters' => ['status', 'priority', 'category', 'assigned_to', 'account_id'],
            'presets' => [
                'open'     => ['label' => 'Open', 'sql' => "t.status NOT IN ('resolved','closed')"],
                'breached' => ['label' => 'SLA breached', 'sql' => "t.status NOT IN ('resolved','closed') AND t.sla_due_at < NOW()"],
                'mine'     => ['label' => 'Assigned to me', 'sql' => "t.status NOT IN ('resolved','closed') AND t.assigned_to = :me", 'params' => fn() => ['me' => current_user()['id'] ?? 0]],
            ],
            'default_sort' => ['sla_due_at', 'asc'],
        ],

        'opportunities' => [
            'label' => 'Opportunity', 'plural' => 'Opportunities', 'icon' => '💷',
            'fields' => [
                'account_id'     => ['label' => 'Customer', 'type' => 'ref', 'ref' => 'accounts', 'required' => true],
                'title'          => ['label' => 'Title', 'type' => 'text', 'required' => true],
                'opp_type'       => ['label' => 'Type', 'type' => 'select', 'options' => opts(['new_business', 'upsell', 'renewal']), 'required' => true],
                'stage'          => ['label' => 'Stage', 'type' => 'select', 'options' => opts(OPP_STAGES), 'required' => true],
                'monthly_value'  => ['label' => 'Monthly value', 'type' => 'money'],
                'one_off_value'  => ['label' => 'One-off value', 'type' => 'money'],
                'term_months'    => ['label' => 'Term (months)', 'type' => 'int', 'default' => 24, 'min' => 0],
                'probability'    => ['label' => 'Probability %', 'type' => 'int', 'min' => 0, 'max' => 100, 'help' => 'Defaults from the stage when left blank'],
                'expected_close' => ['label' => 'Expected close', 'type' => 'date'],
                'owner_id'       => ['label' => 'Owner', 'type' => 'ref', 'ref' => 'users'],
                'notes'          => ['label' => 'Notes', 'type' => 'textarea'],
            ],
            'list'    => ['title', 'account_id', 'opp_type', 'stage', 'monthly_value', '_tcv', 'probability', 'expected_close', 'owner_id'],
            'search'  => ['title', 'notes'],
            'filters' => ['stage', 'opp_type', 'owner_id', 'account_id'],
            'presets' => [
                'open' => ['label' => 'Open', 'sql' => "t.stage NOT IN ('won','lost')"],
            ],
            'computed' => [
                '_tcv' => ['label' => 'Contract value', 'type' => 'money', 'sql' => '(t.monthly_value * t.term_months + t.one_off_value)'],
            ],
            'default_sort' => ['expected_close', 'asc'],
        ],

        'activities' => [
            'label' => 'Activity', 'plural' => 'Activities', 'icon' => '🗒️',
            'fields' => [
                'account_id' => ['label' => 'Customer', 'type' => 'ref', 'ref' => 'accounts', 'required' => true],
                'type'       => ['label' => 'Type', 'type' => 'select', 'options' => opts(['call', 'email', 'meeting', 'note', 'task']), 'required' => true],
                'subject'    => ['label' => 'Subject', 'type' => 'text', 'required' => true],
                'body'       => ['label' => 'Details', 'type' => 'textarea'],
                'due_date'   => ['label' => 'Due date', 'type' => 'date', 'help' => 'For tasks'],
                'done'       => ['label' => 'Done', 'type' => 'bool', 'default' => 1],
                'user_id'    => ['label' => 'By', 'type' => 'ref', 'ref' => 'users', 'readonly' => true],
                'created_at' => ['label' => 'Logged', 'type' => 'datetime', 'readonly' => true],
            ],
            'list'    => ['created_at', 'type', 'subject', 'account_id', 'user_id', 'due_date', 'done'],
            'search'  => ['subject', 'body'],
            'filters' => ['type', 'done', 'account_id', 'user_id'],
            'presets' => [
                'tasks' => ['label' => 'Open tasks', 'sql' => "t.type = 'task' AND t.done = 0"],
            ],
            'default_sort' => ['created_at', 'desc'],
        ],
    ];

    // Xero balances, once connected.
    if (xero_connected()) {
        $balance = '(SELECT %s FROM xero_contacts x WHERE x.id = t.xero_contact_id)';
        $entities['accounts']['computed'] += [
            '_balance' => ['label' => 'Balance', 'type' => 'money', 'sql' => sprintf($balance, 'x.outstanding')],
            '_overdue' => ['label' => 'Overdue', 'type' => 'money', 'sql' => sprintf($balance, 'x.overdue')],
        ];
        $entities['accounts']['list'][] = '_balance';
        $entities['accounts']['list'][] = '_overdue';
        $entities['accounts']['presets'] = [
            'arrears'   => ['label' => 'In arrears', 'sql' => 'EXISTS (SELECT 1 FROM xero_contacts x WHERE x.id = t.xero_contact_id AND x.overdue > 0)'],
            'over_limit' => ['label' => 'Over credit limit', 'sql' => 't.credit_limit IS NOT NULL AND EXISTS (SELECT 1 FROM xero_contacts x WHERE x.id = t.xero_contact_id AND x.outstanding > t.credit_limit)'],
            'no_xero'   => ['label' => 'Not linked to Xero', 'sql' => "t.xero_contact_id IS NULL AND t.status IN ('active','suspended')"],
        ];
    }

    return $cache[$key] = $entities;
}

/** Whether a field is available (fields can depend on a feature, e.g. Xero). */
function field_enabled(array $def): bool
{
    return !isset($def['if']) || ($def['if'])();
}

function entity(string $name): ?array
{
    return entities()[$name] ?? null;
}

/** Look up a field or computed column definition. */
function column_def(array $entity, string $column): ?array
{
    return $entity['fields'][$column] ?? $entity['computed'][$column] ?? null;
}

/* ---------------------------------------------------------------------------
 * Business rules applied before/after saving. $data holds validated values,
 * $existing the current row on update (null on insert).
 * ------------------------------------------------------------------------- */

function before_save(string $name, array $data, ?array $existing): array
{
    $user = current_user();
    $now = date('Y-m-d H:i:s');

    switch ($name) {
        case 'accounts':
            if ($existing === null && empty($data['owner_id']) && $user) {
                $data['owner_id'] = $user['id'];
            }
            if (($data['account_number'] ?? '') === '') {
                // Temporary unique value, replaced with ACC-<id> in after_insert().
                $data['account_number'] = $existing['account_number'] ?? 'TMP-' . bin2hex(random_bytes(6));
            }
            if (!empty($data['postcode'])) {
                $data['postcode'] = strtoupper($data['postcode']);
            }
            break;

        case 'services':
            if (!empty($data['product_id'])) {
                $product = db_one('SELECT * FROM products WHERE id = ?', [$data['product_id']]);
                if ($product) {
                    $data['service_type'] = $data['service_type'] ?: $product['category'];
                    $data['carrier'] = $data['carrier'] ?: $product['carrier'];
                    $data['monthly_price'] ??= $product['monthly_price'];
                    $data['setup_fee'] ??= $product['setup_fee'];
                    $data['term_months'] ??= $product['term_months'];
                }
            }
            $data['service_type'] = $data['service_type'] ?: 'other';
            $data['monthly_price'] ??= 0;
            $data['setup_fee'] ??= 0;
            if (empty($data['contract_end_date']) && !empty($data['start_date']) && !empty($data['term_months'])) {
                $data['contract_end_date'] = contract_end_date($data['start_date'], (int)$data['term_months']);
            }
            if ($existing === null && ($data['status'] ?? '') === 'active' && empty($data['start_date'])) {
                $data['start_date'] = date('Y-m-d');
            }
            break;

        case 'tickets':
            $sla = config('sla_hours');
            $opened = $existing['created_at'] ?? $now;
            if ($existing === null || $existing['priority'] !== $data['priority']) {
                $hours = (int)($sla[$data['priority']] ?? 24);
                $data['sla_due_at'] = date('Y-m-d H:i:s', strtotime($opened) + $hours * 3600);
            }
            $closed = in_array($data['status'], ['resolved', 'closed'], true);
            if ($closed && empty($existing['resolved_at'])) {
                $data['resolved_at'] = $now;
            } elseif (!$closed) {
                $data['resolved_at'] = null;
            }
            if ($existing === null && empty($data['assigned_to']) && $user) {
                $data['assigned_to'] = $user['id'];
            }
            break;

        case 'opportunities':
            $stageChanged = $existing === null || $existing['stage'] !== $data['stage'];
            $untouched = $existing !== null && (int)$data['probability'] === (int)$existing['probability'];
            if ($stageChanged && (in_array($data['stage'], ['won', 'lost'], true) || $untouched)) {
                // Follow the stage unless the user typed their own probability.
                $data['probability'] = STAGE_PROBABILITY[$data['stage']];
            } elseif ($data['probability'] === null) {
                $data['probability'] = STAGE_PROBABILITY[$data['stage']] ?? 10;
            }
            $data['monthly_value'] ??= 0;
            $data['one_off_value'] ??= 0;
            $data['term_months'] ??= 24;
            if (empty($data['owner_id']) && $user) {
                $data['owner_id'] = $user['id'];
            }
            break;

        case 'activities':
            if ($existing === null && $user) {
                $data['user_id'] = $user['id'];
            }
            if (($data['type'] ?? '') !== 'task') {
                $data['done'] = 1;
            }
            break;

        case 'products':
            $data['setup_fee'] ??= 0;
            break;
    }
    return $data;
}

function after_insert(string $name, int $id, array $data): void
{
    if ($name === 'accounts' && str_starts_with($data['account_number'], 'TMP-')) {
        db_exec('UPDATE accounts SET account_number = ? WHERE id = ?', [sprintf('ACC-%05d', 10000 + $id), $id]);
    }
    if ($name === 'tickets') {
        db_exec('UPDATE tickets SET reference = ? WHERE id = ?', [sprintf('TCK-%06d', $id), $id]);
    }
}

function contract_end_date(string $start, int $months): string
{
    // End date is the day before the anniversary, e.g. 2025-01-15 + 24m = 2027-01-14.
    // Clamp to month end so 31 Jan + 1m doesn't roll into March.
    $d = new DateTimeImmutable($start);
    $target = $d->modify('first day of this month')->modify("+{$months} months");
    $day = min((int)$d->format('j'), (int)$target->format('t'));
    return $target->setDate((int)$target->format('Y'), (int)$target->format('n'), $day)
        ->modify('-1 day')->format('Y-m-d');
}
