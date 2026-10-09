<?php
declare(strict_types=1);

/*
 * Entity definitions. Each entry drives the generic list, form, validation,
 * CSV export and persistence code in repository.php, so adding a field is
 * usually a one-line change here plus a schema column.
 *
 * Field types: text, textarea, email, tel, select, ref, money, int, term, date, bool.
 * "ref" fields point to another entity and are rendered via a JOIN.
 */

const SERVICE_TYPES = [
    'broadband' => 'Broadband', 'leased_line' => 'Leased line', 'mobile' => 'Mobile', 'sip_trunk' => 'SIP trunk',
    'hosted_pbx' => 'Hosted PBX', 'hardware' => 'Hardware', 'other' => 'Other',
];

/**
 * What a product is, within its category, so the order form can price an order from the price list:
 * e.g. the Hosted PBX licence for the chosen tier, SIP channels, DDIs, or the handsets to choose from.
 */
const PRODUCT_ITEM_TYPES = [
    'tariff'             => 'Mobile tariff',
    'sip_channel'        => 'SIP channel',
    'ddi'                => 'DDI (phone number)',
    'number_port'        => 'Number port (per number)',
    'licence_basic'      => 'Hosted PBX licence: Basic',
    'licence_enterprise' => 'Hosted PBX licence: Enterprise',
    'licence_ultimate'   => 'Hosted PBX licence: Ultimate',
    'handset'            => 'Handset / phone',
    'headset'            => 'Headset',
    'router'             => 'Router',
    'accessory'          => 'Accessory',
];

const CARRIERS = [
    'EE', 'Vodafone', 'O2', 'Three', 'BT Wholesale', 'Openreach', 'TalkTalk Wholesale',
    'CityFibre', 'Virgin Media Business', 'Gamma', 'Colt', 'Giacom', 'Other',
];

/** Contract terms offered, in months (1 = 30 days, rolling). */
const TERM_OPTIONS = [0 => 'One-off (no term)', 1 => '30 days', 12 => '12 months', 24 => '24 months', 36 => '36 months', 60 => '60 months'];

function term_label(mixed $months): string
{
    if ($months === null || $months === '') {
        return '';
    }
    $m = (int)$months;
    return TERM_OPTIONS[$m] ?? ($m === 0 ? 'No minimum term' : $m . ' month' . ($m === 1 ? '' : 's'));
}

/** Term choices for a dropdown, keeping an older value that isn't one of the set terms. */
function term_options(mixed $current = null): array
{
    $opts = TERM_OPTIONS;
    if ($current !== null && $current !== '' && !isset($opts[(int)$current])) {
        $opts[(int)$current] = term_label($current);
        ksort($opts);
    }
    return $opts;
}

const OPP_STAGES = ['lead', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];
const STAGE_PROBABILITY = ['lead' => 10, 'qualified' => 25, 'proposal' => 50, 'negotiation' => 75, 'won' => 100, 'lost' => 0];

/** Billing cycles, and how many of each make a month (for MRR). */
const BILLING_FREQUENCIES = [
    'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'biannually' => 'Bi-annually', 'yearly' => 'Yearly',
    'one_off' => 'One-off',
];
// A one-off charge (e.g. installation, hardware bought outright) adds nothing per month.
const BILLING_PER_MONTH = ['weekly' => 52 / 12, 'monthly' => 1, 'quarterly' => 1 / 3, 'biannually' => 1 / 6, 'yearly' => 1 / 12, 'one_off' => 0];

/** A price per billing cycle as a monthly amount (services and quotes work in monthly amounts). */
function monthly_equivalent(mixed $price, ?string $frequency): float
{
    return round((float)$price * (BILLING_PER_MONTH[$frequency ?? 'monthly'] ?? 1), 2);
}

function billing_monthly_sql(string $price, string $frequency): string
{
    return "ROUND($price * CASE $frequency WHEN 'weekly' THEN 52/12 WHEN 'quarterly' THEN 1/3 WHEN 'biannually' THEN 1/6 WHEN 'yearly' THEN 1/12 WHEN 'one_off' THEN 0 ELSE 1 END, 2)";
}

const MARKETING_SOURCES = [
    'existing_customer' => 'Existing customer (soft opt-in)',
    'verbal'            => 'Verbally, on a call or in person',
    'email'             => 'By email',
    'web_form'          => 'Website form',
    'contract'          => 'Order form or contract',
    'unsubscribed'      => 'Unsubscribed themselves',
];

/** Marketing topics customers can choose from (Settings → Marketing), as [key => label]. */
function marketing_topics(): array
{
    $raw = (string)(setting('marketing_topics') ?: "Newsletter\nProduct news & offers\nEvents & webinars");
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[substr(preg_replace('/[^a-z0-9]+/', '_', strtolower($line)), 0, 40)] = $line;
        }
    }
    return $out;
}

/** Reference targets: which column is shown for a foreign key. */
const REF_LABELS = [
    'accounts' => 'name',
    'products' => 'name',
    'services' => 'identifier',
    'contacts' => 'name',
    'sites'    => 'name',
    'ticket_groups' => 'name',
    'users'    => 'name',
    'xero_contacts' => 'name',
    'gocardless_customers' => 'name',
    'quotes'        => 'reference',
    'opportunities' => 'title',
    'suppliers'     => 'name',
    'supplier_products' => 'description',
    'purchase_orders'   => 'reference',
    'customer_orders'   => 'reference',
];

const SUPPLIER_CATEGORIES = ['network' => 'Network / connectivity', 'hardware' => 'Hardware', 'software' => 'Software & licences',
    'services' => 'Services', 'distributor' => 'Distributor', 'other' => 'Other'];
const PO_STATUSES = ['draft' => 'Draft', 'sent' => 'Sent', 'received' => 'Received', 'cancelled' => 'Cancelled'];

/** Refs to external systems: [table => [id column, function building a URL to it]]. */
const EXTERNAL_REFS = [
    'xero_contacts'        => ['contact_id', 'xero_contact_url'],
    'gocardless_customers' => ['customer_id', 'gc_customer_url'],
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
    $key = (xero_connected() ? 'xero' : '') . '|' . (gc_configured() ? 'gc' : '') . '|' . (can('finance.view') ? 'fin' : '')
        . '|' . (can('costs.view') ? 'cv' : '') . (can('revenue.view') ? 'rv' : '') . (can('costs.edit') ? 'ce' : '') . (can('products.edit') ? 'pe' : '') . (can('suppliers.edit') ? 'se' : '');
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $entities = [
        'accounts' => [
            'label' => 'Customer', 'plural' => 'Customers', 'icon' => '🏢', 'perm' => 'customers.edit',
            'fields' => [
                'account_number' => ['label' => 'Account no.', 'type' => 'text', 'help' => 'Leave blank to auto-generate'],
                'name'           => ['label' => 'Name', 'type' => 'text', 'required' => true],
                'type'           => ['label' => 'Type', 'type' => 'select', 'options' => opts(['business', 'residential']), 'required' => true],
                'customer_size'  => ['label' => 'Size', 'type' => 'select', 'options' => CUSTOMER_SIZES,
                    'help' => 'Ofcom\'s contract rules (Contract Summary before signing) protect all but larger businesses. Leave blank if unsure: they\'re treated as protected'],
                'status'         => ['label' => 'Status', 'type' => 'select', 'options' => ['prospect' => 'Prospect', 'active' => 'Active', 'suspended' => 'Suspended', 'churned' => 'Closed / churned'], 'required' => true],
                'is_dealer'      => ['label' => 'This customer is a dealer', 'type' => 'bool', 'help' => 'Dealers can have other customers under them'],
                'is_supplier'    => ['label' => 'This company is also a supplier', 'type' => 'bool', 'virtual' => true, 'if' => fn() => can('suppliers.edit'),
                    'help' => 'Adds a Supplier tab for their products, purchase orders and invoices. The name, address and phone are shared.'],
                'parent_id'      => ['label' => 'Dealer', 'type' => 'ref', 'ref' => 'accounts', 'ref_where' => 'is_dealer = 1', 'help' => 'The dealer this customer came through or is billed via'],
                'parent_relationship' => ['label' => 'Relationship to dealer', 'type' => 'select', 'options' => ['referral' => 'Referred by the dealer', 'billed_via_dealer' => 'Billed via the dealer']],
                'msa_covered'    => ['label' => "Covered by the dealer's MSA", 'type' => 'bool', 'help' => "Contracts become service schedules under the dealer's master services agreement"],
                'dealer_commission_pct' => ['label' => 'Dealer commission %', 'type' => 'int', 'min' => 0, 'max' => 100, 'help' => 'For dealers: % of their customers\' MRR'],
                'industry'       => ['label' => 'Industry', 'type' => 'text'],
                'company_number' => ['label' => 'Company no.', 'type' => 'text'],
                'owner_id'       => ['label' => 'Account manager', 'type' => 'ref', 'ref' => 'users'],
                'credit_limit'   => ['label' => 'Credit limit', 'type' => 'money', 'if' => fn() => can('finance.view')],
                'email'          => ['label' => 'Company email', 'type' => 'email', 'section' => 'Head office', 'help' => 'General inbox, e.g. info@'],
                'phone'          => ['label' => 'Main phone', 'type' => 'tel'],
                'address'        => ['label' => 'Address line 1', 'type' => 'text'],
                'address2'       => ['label' => 'Address line 2', 'type' => 'text'],
                'city'           => ['label' => 'Town / city', 'type' => 'text'],
                'county'         => ['label' => 'County', 'type' => 'text'],
                'postcode'       => ['label' => 'Postcode', 'type' => 'text'],
                'main_name'      => ['label' => 'Name', 'type' => 'text', 'virtual' => true, 'section' => 'Main contact',
                    'section_help' => 'The day-to-day contact. Saved as one of the customer\'s contacts.'],
                'main_job_title' => ['label' => 'Job title', 'type' => 'text', 'virtual' => true],
                'main_phone'     => ['label' => 'Phone', 'type' => 'tel', 'virtual' => true],
                'main_email'     => ['label' => 'Email', 'type' => 'email', 'virtual' => true],
                'billing_same'   => ['label' => 'Send invoices and statements to the main contact', 'type' => 'bool', 'virtual' => true, 'default' => 1, 'section' => 'Accounts contact',
                    'section_help' => 'Who receives invoices and statements.'],
                'billing_name'   => ['label' => 'Name', 'type' => 'text', 'virtual' => true, 'show_if' => 'billing_same=0'],
                'billing_phone'  => ['label' => 'Phone', 'type' => 'tel', 'virtual' => true, 'show_if' => 'billing_same=0'],
                'billing_email'  => ['label' => 'Email for invoices', 'type' => 'email', 'virtual' => true, 'show_if' => 'billing_same=0'],
                'xero_contact_id' => ['label' => 'Xero contact', 'type' => 'ref', 'ref' => 'xero_contacts', 'if' => 'xero_connected', 'section' => 'Links & notes',
                    'help' => 'Linked automatically on sync when the account number, email or name matches'],
                'gocardless_customer_id' => ['label' => 'GoCardless customer', 'type' => 'ref', 'ref' => 'gocardless_customers', 'if' => 'gc_configured',
                    'help' => 'Linked automatically when they complete a setup link, or on sync when the email or name matches'],
                'abillity_company_id' => ['label' => 'aBILLity company ID', 'type' => 'int', 'if' => 'abillity_configured',
                    'help' => 'Filled in when the customer is sent to aBILLity. Enter it (and the site ID) to link a customer already there'],
                'abillity_site_id' => ['label' => 'aBILLity site ID', 'type' => 'int', 'if' => 'abillity_configured'],
                'abillity_error'  => ['label' => 'aBILLity problem', 'type' => 'text', 'readonly' => true, 'if' => 'abillity_configured'],
                'notes'          => ['label' => 'Notes', 'type' => 'textarea', 'section' => 'Links & notes'],
                'main_contact_id'    => ['label' => 'Main contact', 'type' => 'ref', 'ref' => 'contacts', 'readonly' => true],
                'billing_contact_id' => ['label' => 'Accounts contact', 'type' => 'ref', 'ref' => 'contacts', 'readonly' => true],
                'closed_at'      => ['label' => 'Closed', 'type' => 'datetime', 'readonly' => true],
                'closed_reason'  => ['label' => 'Reason for closing', 'type' => 'text', 'readonly' => true],
            ],
            'list'    => ['account_number', 'name', 'type', 'status', 'parent_id', 'main_contact_id', 'owner_id', '_mrr'],
            'search'  => ['name', 'account_number', 'email', 'phone', 'postcode', 'company_number'],
            'filters' => ['status', 'type', 'owner_id', 'parent_id'],
            'presets' => [
                'customers' => ['label' => 'Customers', 'sql' => 't.is_customer = 1'],
                'dealers' => ['label' => 'Dealers', 'sql' => 't.is_dealer = 1'],
                'suppliers' => ['label' => 'Suppliers', 'sql' => 'EXISTS (SELECT 1 FROM suppliers su WHERE su.account_id = t.id)'],
            ],
            'default_sort' => ['name', 'asc'],
            'computed' => [
                '_mrr' => ['label' => 'MRR', 'type' => 'money',
                    'sql' => "(SELECT COALESCE(SUM(s.monthly_price),0) FROM services s WHERE s.account_id = t.id AND s.status = 'active')"],
            ],
        ],

        'contacts' => [
            'label' => 'Contact', 'plural' => 'Contacts', 'icon' => '👤', 'perm' => 'customers.edit',
            'fields' => [
                'account_id' => ['label' => 'Customer', 'type' => 'ref', 'ref' => 'accounts', 'required' => true],
                'name'       => ['label' => 'Name', 'type' => 'text', 'required' => true],
                'job_title'  => ['label' => 'Job title', 'type' => 'text'],
                'email'      => ['label' => 'Email', 'type' => 'email'],
                'phone'      => ['label' => 'Phone', 'type' => 'tel'],
                'mobile'     => ['label' => 'Mobile', 'type' => 'tel'],
                'is_primary' => ['label' => 'Main contact', 'type' => 'bool'],
                'is_billing' => ['label' => 'Accounts contact (invoices & statements)', 'type' => 'bool'],
                'notes'      => ['label' => 'Notes', 'type' => 'textarea'],
                'service_alerts'   => ['label' => 'Service alerts (faults, maintenance, outages)', 'type' => 'bool', 'default' => 1, 'section' => 'Communication preferences',
                    'section_help' => 'Service alerts are about services they have with you. Marketing needs their permission.'],
                'marketing_email'  => ['label' => 'Marketing by email', 'type' => 'bool'],
                'marketing_phone'  => ['label' => 'Marketing by phone', 'type' => 'bool'],
                'marketing_sms'    => ['label' => 'Marketing by text message', 'type' => 'bool'],
                'marketing_post'   => ['label' => 'Marketing by post', 'type' => 'bool'],
                'marketing_topics' => ['label' => 'Interested in', 'type' => 'checkboxes', 'options' => marketing_topics(), 'help' => 'Leave all unticked to receive every topic'],
                'marketing_source' => ['label' => 'How permission was given', 'type' => 'select', 'options' => MARKETING_SOURCES],
                'marketing_updated_at' => ['label' => 'Preferences updated', 'type' => 'datetime', 'readonly' => true],
                'unsubscribed_at'  => ['label' => 'Unsubscribed', 'type' => 'datetime', 'readonly' => true],
            ],
            'list'    => ['name', 'account_id', 'job_title', 'email', 'phone', 'mobile', 'is_primary', 'is_billing', 'marketing_email'],
            'search'  => ['name', 'email', 'phone', 'mobile'],
            'filters' => ['account_id', 'marketing_email', 'service_alerts'],
            'presets' => [
                'marketing' => ['label' => 'Opted in to email marketing', 'sql' => 't.marketing_email = 1'],
                'no_alerts' => ['label' => 'Opted out of service alerts', 'sql' => 't.service_alerts = 0'],
            ],
            'default_sort' => ['name', 'asc'],
        ],

        'sites' => [
            'label' => 'Site', 'plural' => 'Sites & addresses', 'icon' => '📍', 'perm' => 'customers.edit',
            'fields' => [
                'account_id' => ['label' => 'Customer', 'type' => 'ref', 'ref' => 'accounts', 'required' => true],
                'name'       => ['label' => 'Site name', 'type' => 'text', 'required' => true, 'help' => 'e.g. "Manchester office" or "Warehouse"'],
                'address'    => ['label' => 'Address line 1', 'type' => 'text'],
                'address2'   => ['label' => 'Address line 2', 'type' => 'text'],
                'city'       => ['label' => 'Town / city', 'type' => 'text'],
                'county'     => ['label' => 'County', 'type' => 'text'],
                'postcode'   => ['label' => 'Postcode', 'type' => 'text'],
                'phone'      => ['label' => 'Site phone', 'type' => 'tel'],
                'contact_id' => ['label' => 'Site contact', 'type' => 'ref', 'ref' => 'contacts', 'scoped' => true, 'help' => 'Leave blank to use the head office main contact'],
                'notes'      => ['label' => 'Access notes', 'type' => 'textarea', 'help' => 'Parking, opening hours, comms room location…'],
            ],
            'list'    => ['name', 'account_id', 'address', 'city', 'postcode', 'contact_id', '_services'],
            'search'  => ['name', 'address', 'city', 'postcode'],
            'filters' => ['account_id'],
            'computed' => [
                '_services' => ['label' => 'Live services', 'type' => 'int', 'sql' => "(SELECT COUNT(*) FROM services s WHERE s.site_id = t.id AND s.status = 'active')"],
            ],
            'default_sort' => ['name', 'asc'],
        ],

        'products' => [
            'label' => 'Product', 'plural' => 'Products & tariffs', 'icon' => '📦', 'perm' => ['products.edit', 'costs.edit'],
            'fields' => [
                'sku'               => ['label' => 'SKU', 'type' => 'text', 'required' => true, 'help' => 'Also the item code in Xero (up to 30 characters)'],
                'name'              => ['label' => 'Name', 'type' => 'text', 'required' => true],
                'category'          => ['label' => 'Category', 'type' => 'select', 'options' => SERVICE_TYPES, 'required' => true],
                'item_type'         => ['label' => 'Item type', 'type' => 'select', 'options' => PRODUCT_ITEM_TYPES,
                    'help' => 'What it is, so orders can be priced from it: e.g. the licence for each Hosted PBX tier, SIP channels, DDIs, or handsets offered with a phone system'],
                'carrier'           => ['label' => 'Carrier / network', 'type' => 'select', 'options' => opts(CARRIERS)],
                'billing_frequency' => ['label' => 'Billing cycle', 'type' => 'select', 'options' => BILLING_FREQUENCIES, 'default' => 'monthly', 'help' => 'How often the customer is billed for it'],
                'monthly_price'     => ['label' => 'Sale price', 'type' => 'money', 'required' => true, 'help' => 'Per billing cycle, excluding VAT'],
                'cost_price'        => ['label' => 'Cost price', 'type' => 'money', 'help' => 'What it costs you per billing cycle',
                    'if' => fn() => can('costs.view'), 'readonly' => !can('costs.edit')],
                'setup_fee'         => ['label' => 'Setup fee', 'type' => 'money', 'help' => 'One-off'],
                'term_months'       => ['label' => 'Term', 'type' => 'term', 'required' => true, 'default' => 24],
                'active'            => ['label' => 'Available to sell', 'type' => 'bool', 'default' => 1],
                'description'       => ['label' => 'Description', 'type' => 'textarea'],
                'dealer_price'      => ['label' => 'Dealer price', 'type' => 'money', 'section' => 'Dealer portal',
                    'section_help' => 'Products with a dealer price and supplier product IDs are offered to dealers on the partner portal, wherever one of those supplier products is available.',
                    'help' => 'Per month, excluding VAT. Blank: not on the portal'],
                'dealer_setup_fee'  => ['label' => 'Dealer setup fee', 'type' => 'money', 'help' => 'One-off'],
                'supplier_product_ids' => ['label' => 'Supplier product IDs', 'type' => 'text',
                    'help' => 'The broadband supplier\'s product IDs this product covers (shown on availability checks), separated by commas, e.g. 34350, 34380'],
                'sales_account_code'    => ['label' => 'Sales nominal code', 'type' => 'text', 'section' => 'Accounts', 'datalist' => 'sales',
                    'section_help' => 'Where sales and costs of this product are posted in your accounts (and in Xero).',
                    'default' => setting('xero_item_sales_account'), 'help' => 'e.g. 200 Sales'],
                'sales_tax_type'    => ['label' => 'VAT on sales', 'type' => 'text', 'datalist' => 'tax',
                    'help' => 'Xero tax type when you sell it, e.g. OUTPUT2 (20%), ZERORATEDOUTPUT, or a reverse charge rate. Blank: the default under Admin → Xero'],
                'purchase_account_code' => ['label' => 'Purchases nominal code', 'type' => 'text', 'datalist' => 'purchases',
                    'default' => setting('xero_item_purchase_account'), 'help' => 'e.g. 310 Cost of goods sold'],
                'purchase_tax_type' => ['label' => 'VAT on purchases', 'type' => 'text', 'datalist' => 'tax',
                    'help' => 'Xero tax type when you buy it in, e.g. INPUT2 (20%), ZERORATEDINPUT, or a reverse charge rate. Blank: whatever rate the supplier\'s invoice shows'],
                'xero_synced_at'    => ['label' => 'Sent to Xero', 'type' => 'datetime', 'readonly' => true, 'if' => 'xero_connected'],
                'xero_sync_error'   => ['label' => 'Xero problem', 'type' => 'text', 'readonly' => true, 'if' => 'xero_connected'],
                'abillity_charge_type_id' => ['label' => 'aBILLity charge type ID', 'type' => 'int', 'if' => 'abillity_configured',
                    'help' => 'Filled in when the product is sent to aBILLity. Enter it to link one already there'],
                'abillity_synced_at' => ['label' => 'Sent to aBILLity', 'type' => 'datetime', 'readonly' => true, 'if' => 'abillity_configured'],
                'abillity_error'    => ['label' => 'aBILLity problem', 'type' => 'text', 'readonly' => true, 'if' => 'abillity_configured'],
            ],
            'list'    => ['sku', 'name', 'category', 'billing_frequency', 'monthly_price', 'cost_price', '_margin', '_monthly', 'setup_fee', '_suppliers', 'active'],
            'search'  => ['sku', 'name', 'description'],
            'filters' => ['category', 'item_type', 'carrier', 'billing_frequency', 'active'],
            'computed' => [
                '_monthly' => ['label' => 'Per month', 'type' => 'money', 'sql' => billing_monthly_sql('t.monthly_price', 't.billing_frequency')],
                '_suppliers' => ['label' => 'Suppliers', 'type' => 'int', 'sql' => '(SELECT COUNT(*) FROM supplier_products sp WHERE sp.product_id = t.id AND sp.active = 1)'],
            ],
            'presets' => [
                'no_supplier' => ['label' => 'No supplier', 'sql' => 't.active = 1 AND NOT EXISTS (SELECT 1 FROM supplier_products sp WHERE sp.product_id = t.id AND sp.active = 1)'],
            ],
            'default_sort' => ['name', 'asc'],
        ],

        'services' => [
            'label' => 'Service', 'plural' => 'Services & lines', 'icon' => '📶', 'perm' => 'services.edit',
            'fields' => [
                'account_id'        => ['label' => 'Customer', 'type' => 'ref', 'ref' => 'accounts', 'required' => true],
                'site_id'           => ['label' => 'Installation site', 'type' => 'ref', 'ref' => 'sites', 'scoped' => true, 'help' => 'From the customer\'s address book. Leave blank for head office.'],
                'product_id'        => ['label' => 'Product / tariff', 'type' => 'ref', 'ref' => 'products', 'help' => 'Prices, term and type are copied from the product when left blank. Prices on services are per month, so a yearly product is divided by 12'],
                'service_type'      => ['label' => 'Service type', 'type' => 'select', 'options' => SERVICE_TYPES],
                'identifier'        => ['label' => 'Number / circuit ID', 'type' => 'text', 'required' => true, 'help' => 'MSISDN, CLI, circuit reference or serial number'],
                'carrier'           => ['label' => 'Carrier / network', 'type' => 'select', 'options' => opts(CARRIERS)],
                'status'            => ['label' => 'Status', 'type' => 'select', 'options' => opts(['pending', 'active', 'suspended', 'ceased']), 'required' => true],
                'monthly_price'     => ['label' => 'Monthly price', 'type' => 'money'],
                'cost_price'        => ['label' => 'Monthly cost', 'type' => 'money', 'help' => 'What it costs you per month, recorded when it was ordered (from the product). Change it only when the supplier\'s price for this line changes',
                    'if' => fn() => can('costs.view'), 'readonly' => !can('costs.edit')],
                'setup_fee'         => ['label' => 'Setup fee', 'type' => 'money'],
                'start_date'        => ['label' => 'Contract start', 'type' => 'date'],
                'term_months'       => ['label' => 'Term', 'type' => 'term'],
                'contract_end_date' => ['label' => 'Contract end', 'type' => 'date', 'help' => 'Calculated from start + term when left blank'],
                'install_address'   => ['label' => 'Installation address notes', 'type' => 'text', 'help' => 'Only if the site isn\'t in the address book'],
                'notes'             => ['label' => 'Notes', 'type' => 'textarea'],
                'login_username'    => ['label' => 'Login username', 'type' => 'text', 'section' => 'Login & IP',
                    'help' => 'e.g. the broadband (PPP) username. The password is set on the service\'s page'],
                'ip_details'        => ['label' => 'IP address(es)', 'type' => 'text', 'help' => 'e.g. Dynamic, 81.2.69.160, or 81.2.69.160/29. Filled in for broadband orders when the service goes live'],
                'abillity_charge_id' => ['label' => 'aBILLity charge ID', 'type' => 'int', 'readonly' => true, 'if' => 'abillity_configured'],
                'abillity_first_payment' => ['label' => 'Billing starts (aBILLity)', 'type' => 'date', 'readonly' => true, 'if' => 'abillity_configured'],
                'abillity_synced_at' => ['label' => 'Sent to aBILLity', 'type' => 'datetime', 'readonly' => true, 'if' => 'abillity_configured'],
                'abillity_error'    => ['label' => 'aBILLity problem', 'type' => 'text', 'readonly' => true, 'if' => 'abillity_configured'],
            ],
            'list'    => ['identifier', 'account_id', 'site_id', 'service_type', 'carrier', 'status', 'monthly_price', 'contract_end_date'],
            'search'  => ['identifier', 'install_address', 'notes', 'login_username'],
            'filters' => ['status', 'service_type', 'carrier', 'account_id', 'site_id'],
            'presets' => [
                'expiring' => ['label' => 'Up for renewal', 'sql' => "t.status = 'active' AND t.contract_end_date IS NOT NULL AND t.contract_end_date <= DATE_ADD(CURDATE(), INTERVAL :window DAY)", 'params' => fn() => ['window' => (int)config('renewal_window_days')]],
                'out_of_contract' => ['label' => 'Out of contract', 'sql' => "t.status = 'active' AND t.contract_end_date < CURDATE()"],
            ],
            'default_sort' => ['contract_end_date', 'asc'],
        ],

        'tickets' => [
            'label' => 'Ticket', 'plural' => 'Support tickets', 'icon' => '🎫', 'perm' => 'tickets.edit',
            'fields' => [
                'reference'   => ['label' => 'Ref', 'type' => 'text', 'readonly' => true],
                'account_id'  => ['label' => 'Customer', 'type' => 'ref', 'ref' => 'accounts', 'required' => true],
                'service_id'  => ['label' => 'Affected service', 'type' => 'ref', 'ref' => 'services', 'scoped' => true],
                'contact_id'  => ['label' => 'Reported by', 'type' => 'ref', 'ref' => 'contacts', 'scoped' => true],
                'subject'     => ['label' => 'Subject', 'type' => 'text', 'required' => true],
                'category'    => ['label' => 'Category', 'type' => 'select', 'options' => opts(['fault', 'billing', 'order', 'porting', 'cancellation', 'general']), 'required' => true],
                'priority'    => ['label' => 'Priority', 'type' => 'select', 'options' => ['P1' => 'P1 – Critical', 'P2' => 'P2 – High', 'P3' => 'P3 – Normal', 'P4' => 'P4 – Low'], 'required' => true, 'default' => 'P3'],
                'status'      => ['label' => 'Status', 'type' => 'select', 'options' => opts(['open', 'in_progress', 'awaiting_customer', 'awaiting_carrier', 'resolved', 'closed']), 'required' => true],
                'group_id'    => ['label' => 'Group', 'type' => 'ref', 'ref' => 'ticket_groups', 'help' => 'Leave blank to route by category'],
                'assigned_to' => ['label' => 'Assigned to', 'type' => 'ref', 'ref' => 'users', 'help' => 'Leave blank to put it in the group\'s queue'],
                'carrier_ref' => ['label' => 'Carrier fault ref', 'type' => 'text'],
                'description' => ['label' => 'Description', 'type' => 'textarea'],
                'sla_due_at'  => ['label' => 'SLA due', 'type' => 'datetime', 'readonly' => true],
                'created_at'  => ['label' => 'Opened', 'type' => 'datetime', 'readonly' => true],
            ],
            'list'    => ['reference', 'subject', 'account_id', 'category', 'priority', 'status', 'group_id', 'assigned_to', 'sla_due_at'],
            'search'  => ['reference', 'subject', 'description', 'carrier_ref'],
            'filters' => ['status', 'priority', 'category', 'group_id', 'assigned_to', 'account_id'],
            'scope'   => 'ticket_scope',
            'presets' => [
                'queue'    => ['label' => 'Waiting in my groups', 'sql' => "t.status NOT IN ('resolved','closed') AND t.assigned_to IS NULL AND t.group_id IN (SELECT m.group_id FROM ticket_group_members m WHERE m.user_id = :qme)",
                    'params' => fn() => ['qme' => current_user()['id'] ?? 0]],
                'open'     => ['label' => 'Open', 'sql' => "t.status NOT IN ('resolved','closed')"],
                'breached' => ['label' => 'SLA breached', 'sql' => "t.status NOT IN ('resolved','closed') AND t.sla_due_at < NOW()"],
                'mine'     => ['label' => 'Assigned to me', 'sql' => "t.status NOT IN ('resolved','closed') AND t.assigned_to = :me", 'params' => fn() => ['me' => current_user()['id'] ?? 0]],
            ],
            'default_sort' => ['sla_due_at', 'asc'],
        ],

        'opportunities' => [
            'label' => 'Opportunity', 'plural' => 'Opportunities', 'icon' => '💷', 'perm' => 'sales.edit',
            'fields' => [
                'account_id'     => ['label' => 'Customer', 'type' => 'ref', 'ref' => 'accounts', 'required' => true],
                'title'          => ['label' => 'Title', 'type' => 'text', 'required' => true],
                'opp_type'       => ['label' => 'Type', 'type' => 'select', 'options' => opts(['new_business', 'upsell', 'renewal']), 'required' => true],
                'stage'          => ['label' => 'Stage', 'type' => 'select', 'options' => opts(OPP_STAGES), 'required' => true],
                'monthly_value'  => ['label' => 'Monthly value', 'type' => 'money'],
                'one_off_value'  => ['label' => 'One-off value', 'type' => 'money'],
                'term_months'    => ['label' => 'Term', 'type' => 'term', 'default' => 24],
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

        'quotes' => [
            'label' => 'Quote', 'plural' => 'Quotes', 'icon' => '📝', 'perm' => 'sales.edit',
            'fields' => [
                'reference'       => ['label' => 'Ref', 'type' => 'text', 'readonly' => true],
                'account_id'      => ['label' => 'Customer', 'type' => 'ref', 'ref' => 'accounts', 'required' => true],
                'title'           => ['label' => 'Title', 'type' => 'text', 'required' => true],
                'status'          => ['label' => 'Status', 'type' => 'select', 'options' => opts(['draft', 'sent', 'accepted', 'declined', 'expired', 'cancelled']), 'readonly' => true],
                'valid_until'     => ['label' => 'Valid until', 'type' => 'date'],
                'opportunity_id'  => ['label' => 'Opportunity', 'type' => 'ref', 'ref' => 'opportunities', 'scoped' => true],
                'intro'           => ['label' => 'Message to the customer', 'type' => 'textarea', 'help' => 'Shown at the top of the quote page'],
                'recipient_name'  => ['label' => 'Sent to', 'type' => 'text', 'readonly' => true],
                'sent_at'         => ['label' => 'Sent', 'type' => 'datetime', 'readonly' => true],
                'created_at'      => ['label' => 'Created', 'type' => 'datetime', 'readonly' => true],
            ],
            'list'    => ['reference', 'title', 'account_id', 'status', '_monthly', '_setup', 'valid_until', 'sent_at'],
            'search'  => ['reference', 'title', 'recipient_name', 'recipient_email'],
            'filters' => ['status', 'account_id'],
            'presets' => [
                'awaiting' => ['label' => 'Awaiting response', 'sql' => "t.status = 'sent'"],
                'accepted' => ['label' => 'Accepted', 'sql' => "t.status = 'accepted'"],
                'drafts'   => ['label' => 'Drafts', 'sql' => "t.status = 'draft'"],
            ],
            'computed' => [
                '_monthly' => ['label' => 'Monthly', 'type' => 'money', 'sql' => '(SELECT COALESCE(SUM(l.quantity * l.monthly_price),0) FROM quote_lines l WHERE l.quote_id = t.id)'],
                '_setup'   => ['label' => 'One-off', 'type' => 'money', 'sql' => '(SELECT COALESCE(SUM(l.quantity * l.setup_fee),0) FROM quote_lines l WHERE l.quote_id = t.id)'],
            ],
            'default_sort' => ['created_at', 'desc'],
        ],

        'contracts' => [
            'label' => 'Contract', 'plural' => 'Contracts', 'icon' => '✍️', 'perm' => 'sales.edit',
            'fields' => [
                'reference'    => ['label' => 'Ref', 'type' => 'text', 'readonly' => true],
                'account_id'   => ['label' => 'Customer', 'type' => 'ref', 'ref' => 'accounts', 'required' => true],
                'title'        => ['label' => 'Title', 'type' => 'text', 'required' => true],
                'kind'         => ['label' => 'Kind', 'type' => 'select', 'options' => ['services' => 'Services', 'msa' => 'Master services agreement']],
                'status'       => ['label' => 'Status', 'type' => 'select', 'options' => opts(['draft', 'sent', 'signed', 'rejected', 'cancelled', 'expired', 'failed']), 'readonly' => true],
                'quote_id'     => ['label' => 'Quote', 'type' => 'ref', 'ref' => 'quotes', 'readonly' => true],
                'signer_name'  => ['label' => 'Signer', 'type' => 'text'],
                'signer_email' => ['label' => 'Signer email', 'type' => 'email'],
                'sent_at'      => ['label' => 'Sent', 'type' => 'datetime', 'readonly' => true],
                'signed_at'    => ['label' => 'Signed', 'type' => 'datetime', 'readonly' => true],
            ],
            'list'    => ['reference', 'title', 'account_id', 'kind', 'status', 'signer_name', 'sent_at', 'signed_at'],
            'search'  => ['reference', 'title', 'signer_name', 'signer_email'],
            'filters' => ['status', 'kind', 'account_id'],
            'presets' => [
                'awaiting' => ['label' => 'Awaiting signature', 'sql' => "t.status = 'sent'"],
                'signed'   => ['label' => 'Signed', 'sql' => "t.status = 'signed'"],
                'problems' => ['label' => 'Needs attention', 'sql' => "t.status IN ('failed','rejected','expired') OR (t.status = 'draft' AND t.last_error IS NOT NULL)"],
            ],
            'default_sort' => ['created_at', 'desc'],
        ],

        'activities' => [
            'label' => 'Activity', 'plural' => 'Activities', 'icon' => '🗒️', 'perm' => 'customers.edit',
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

    $entities += [
        'suppliers' => [
            'label' => 'Supplier', 'plural' => 'Suppliers', 'icon' => '🚚', 'perm' => 'suppliers.edit', 'view_perm' => 'suppliers.view',
            'fields' => [
                'name'           => ['label' => 'Name', 'type' => 'text', 'required' => true],
                'account_number' => ['label' => 'Our account no.', 'type' => 'text', 'help' => 'Your account number with them'],
                'category'       => ['label' => 'Type', 'type' => 'select', 'options' => SUPPLIER_CATEGORIES],
                'ordering'       => ['label' => 'How we order', 'type' => 'select', 'options' => SUPPLIER_ORDERING, 'default' => 'email',
                    'help' => 'Orders only raise purchase orders for suppliers ordered by email'],
                'vat_number'     => ['label' => 'VAT number', 'type' => 'text', 'help' => 'Helps match their invoices'],
                'active'         => ['label' => 'Active', 'type' => 'bool', 'default' => 1],
                'contact_name'   => ['label' => 'Account manager', 'type' => 'text', 'section' => 'Contact'],
                'email'          => ['label' => 'Orders email', 'type' => 'email', 'help' => 'Purchase orders are emailed here'],
                'phone'          => ['label' => 'Phone', 'type' => 'tel'],
                'accounts_email' => ['label' => 'Accounts email', 'type' => 'email'],
                'support_email'  => ['label' => 'Support email', 'type' => 'email'],
                'support_phone'  => ['label' => 'Support phone', 'type' => 'tel'],
                'website'        => ['label' => 'Website', 'type' => 'text'],
                'portal_url'     => ['label' => 'Partner portal', 'type' => 'text', 'help' => 'Where you log in to order or raise faults'],
                'address'        => ['label' => 'Address line 1', 'type' => 'text', 'section' => 'Address'],
                'address2'       => ['label' => 'Address line 2', 'type' => 'text'],
                'city'           => ['label' => 'Town / city', 'type' => 'text'],
                'county'         => ['label' => 'County', 'type' => 'text'],
                'postcode'       => ['label' => 'Postcode', 'type' => 'text'],
                'payment_terms'  => ['label' => 'Payment terms', 'type' => 'text', 'section' => 'Terms & notes', 'help' => 'e.g. 30 days from invoice, Direct Debit'],
                'purchase_account_code' => ['label' => 'Default nominal code', 'type' => 'text', 'datalist' => 'purchases',
                    'help' => 'For their bills in Xero, on lines that aren\'t one of your products (e.g. 320 for connectivity, 429 for general expenses)'],
                'purchase_tax_type' => ['label' => 'Default VAT', 'type' => 'text', 'datalist' => 'tax',
                    'help' => 'Xero tax type for their bills when a line has no rate of its own, e.g. a reverse charge rate for an overseas or wholesale telecoms supplier'],
                'notes'          => ['label' => 'Notes', 'type' => 'textarea'],
                'account_id'     => ['label' => 'Customer / dealer record', 'type' => 'ref', 'ref' => 'accounts', 'section' => 'Links',
                    'help' => 'When they are also a customer or dealer: one company record, with this as its Supplier tab. The name, address and phone are shared.'],
                'xero_contact_id' => ['label' => 'Xero contact', 'type' => 'ref', 'ref' => 'xero_contacts', 'if' => 'xero_connected',
                    'ref_where' => 'is_supplier = 1', 'help' => 'Linked automatically when brought in from Xero'],
            ],
            'list'    => ['name', 'category', 'account_number', 'contact_name', 'email', 'phone', '_products', 'active'],
            'search'  => ['name', 'account_number', 'contact_name', 'email'],
            'filters' => ['category', 'active'],
            'default_sort' => ['name', 'asc'],
            'computed' => [
                '_products' => ['label' => 'Products', 'type' => 'int', 'sql' => '(SELECT COUNT(*) FROM supplier_products sp WHERE sp.supplier_id = t.id AND sp.active = 1)'],
            ],
        ],

        'supplier_products' => [
            'label' => 'Supplier product', 'plural' => 'Supplier prices', 'icon' => '🏷️', 'perm' => 'suppliers.edit', 'view_perm' => 'suppliers.view',
            'fields' => [
                'supplier_id'       => ['label' => 'Supplier', 'type' => 'ref', 'ref' => 'suppliers', 'required' => true],
                'supplier_sku'      => ['label' => 'Supplier code', 'type' => 'text', 'help' => 'Their product code or SKU; price files are matched on this'],
                'description'       => ['label' => 'Description', 'type' => 'text', 'required' => true],
                'product_id'        => ['label' => 'Our product', 'type' => 'ref', 'ref' => 'products', 'help' => 'The product or tariff this is the cost of'],
                'cost_price'        => ['label' => 'Cost price', 'type' => 'money', 'required' => true, 'help' => 'Per billing cycle, excluding VAT', 'if' => fn() => can('costs.view')],
                'billing_frequency' => ['label' => 'Billed', 'type' => 'select', 'options' => BILLING_FREQUENCIES, 'default' => 'monthly', 'required' => true],
                'setup_cost'        => ['label' => 'Setup / one-off cost', 'type' => 'money', 'if' => fn() => can('costs.view')],
                'term_months'       => ['label' => 'Minimum term', 'type' => 'term'],
                'lead_time_days'    => ['label' => 'Lead time (days)', 'type' => 'int', 'min' => 0, 'max' => 365],
                'preferred'         => ['label' => 'Preferred supplier for our product', 'type' => 'bool',
                    'help' => 'Its cost becomes the product\'s cost price, and keeps it up to date when prices change'],
                'active'            => ['label' => 'Available', 'type' => 'bool', 'default' => 1],
                'notes'             => ['label' => 'Notes', 'type' => 'textarea'],
                'price_updated_at'  => ['label' => 'Price last changed', 'type' => 'datetime', 'readonly' => true],
            ],
            'list'    => ['supplier_id', 'supplier_sku', 'description', 'product_id', 'cost_price', 'billing_frequency', 'setup_cost', 'preferred', 'price_updated_at'],
            'search'  => ['supplier_sku', 'description'],
            'filters' => ['supplier_id', 'product_id', 'preferred', 'active'],
            'default_sort' => ['description', 'asc'],
        ],

        'customer_orders' => [
            'label' => 'Order', 'plural' => 'Orders', 'icon' => '📦', 'perm' => 'onboarding.edit', 'no_new' => true,
            'fields' => [
                'reference'     => ['label' => 'Order', 'type' => 'text', 'readonly' => true],
                'account_id'    => ['label' => 'Customer', 'type' => 'ref', 'ref' => 'accounts', 'readonly' => true],
                'title'         => ['label' => 'Title', 'type' => 'text', 'readonly' => true],
                'status'        => ['label' => 'Step', 'type' => 'select', 'options' => ORDER_STATUSES, 'readonly' => true],
                'assigned_to'   => ['label' => 'Being handled by', 'type' => 'ref', 'ref' => 'users', 'readonly' => true],
                'quote_id'      => ['label' => 'Quote', 'type' => 'ref', 'ref' => 'quotes', 'readonly' => true],
                'contact_name'  => ['label' => 'Customer contact', 'type' => 'text', 'readonly' => true],
                'contact_email' => ['label' => 'Contact email', 'type' => 'email', 'readonly' => true],
                'monthly_total' => ['label' => 'Monthly', 'type' => 'money', 'readonly' => true],
                'setup_total'   => ['label' => 'One-off', 'type' => 'money', 'readonly' => true],
                'created_at'    => ['label' => 'Accepted', 'type' => 'datetime', 'readonly' => true],
                'completed_at'  => ['label' => 'Completed', 'type' => 'datetime', 'readonly' => true],
            ],
            'list'    => ['reference', 'account_id', 'title', 'status', 'assigned_to', 'monthly_total', 'created_at'],
            'search'  => ['reference', 'title', 'contact_name', 'contact_email'],
            'filters' => ['status', 'assigned_to', 'account_id'],
            'presets' => [
                'waiting' => ['label' => 'Waiting to be picked up', 'sql' => "t.assigned_to IS NULL AND t.status NOT IN ('completed','cancelled')"],
                'mine'    => ['label' => 'Mine', 'sql' => 't.assigned_to = ' . (int)(current_user()['id'] ?? 0) . " AND t.status NOT IN ('completed','cancelled')"],
                'open'    => ['label' => 'In progress', 'sql' => "t.status NOT IN ('completed','cancelled')"],
            ],
            'default_sort' => ['created_at', 'desc'],
        ],

        'purchase_orders' => [
            'label' => 'Purchase order', 'plural' => 'Purchase orders', 'icon' => '🛒', 'perm' => 'purchasing.edit', 'view_perm' => 'suppliers.view',
            'fields' => [
                'reference'     => ['label' => 'PO number', 'type' => 'text', 'readonly' => true],
                'supplier_id'   => ['label' => 'Supplier', 'type' => 'ref', 'ref' => 'suppliers', 'required' => true],
                'account_id'    => ['label' => 'For customer', 'type' => 'ref', 'ref' => 'accounts', 'help' => 'If you are ordering for a particular customer'],
                'status'        => ['label' => 'Status', 'type' => 'select', 'options' => PO_STATUSES, 'readonly' => true],
                'order_date'    => ['label' => 'Order date', 'type' => 'date'],
                'expected_date' => ['label' => 'Expected delivery', 'type' => 'date'],
                'supplier_ref'  => ['label' => 'Supplier\'s order no.', 'type' => 'text'],
                'total'         => ['label' => 'Total (ex VAT)', 'type' => 'money', 'readonly' => true, 'if' => fn() => can('costs.view')],
                'deliver_to'    => ['label' => 'Deliver to', 'type' => 'textarea'],
                'notes'         => ['label' => 'Notes for the supplier', 'type' => 'textarea'],
                'created_by'    => ['label' => 'Raised by', 'type' => 'ref', 'ref' => 'users', 'readonly' => true],
                'sent_at'       => ['label' => 'Sent', 'type' => 'datetime', 'readonly' => true],
                'received_at'   => ['label' => 'Received', 'type' => 'datetime', 'readonly' => true],
                'created_at'    => ['label' => 'Created', 'type' => 'datetime', 'readonly' => true],
            ],
            'list'    => ['reference', 'supplier_id', 'account_id', 'status', 'order_date', 'expected_date', 'total', 'created_by'],
            'search'  => ['reference', 'supplier_ref', 'notes'],
            'filters' => ['status', 'supplier_id', 'account_id'],
            'default_sort' => ['created_at', 'desc'],
        ],
    ];

    // Supplier prices and purchase order amounts are costs.
    if (!can('costs.view')) {
        $entities['supplier_products']['list'] = array_values(array_diff($entities['supplier_products']['list'], ['cost_price', 'setup_cost']));
        $entities['purchase_orders']['list'] = array_values(array_diff($entities['purchase_orders']['list'], ['total']));
    }
    // Revenue totals only for people allowed to see them.
    if (!can('revenue.view')) {
        unset($entities['accounts']['computed']['_mrr']);
        $entities['accounts']['list'] = array_values(array_diff($entities['accounts']['list'], ['_mrr']));
    }
    // Cost prices and margins only for people allowed to see them.
    if (can('costs.view')) {
        $entities['services']['computed']['_margin'] = ['label' => 'Margin', 'type' => 'percent',
            'sql' => '(CASE WHEN t.cost_price IS NULL OR t.monthly_price = 0 THEN NULL ELSE ROUND((t.monthly_price - t.cost_price) / t.monthly_price * 100, 1) END)'];
        array_splice($entities['services']['list'], array_search('monthly_price', $entities['services']['list'], true) + 1, 0, ['cost_price', '_margin']);
        $entities['products']['computed']['_margin'] = ['label' => 'Margin', 'type' => 'percent',
            'sql' => '(CASE WHEN t.cost_price IS NULL OR t.monthly_price = 0 THEN NULL ELSE ROUND((t.monthly_price - t.cost_price) / t.monthly_price * 100, 1) END)'];
    } else {
        $entities['products']['list'] = array_values(array_diff($entities['products']['list'], ['cost_price', '_margin']));
    }
    // People who may change cost prices but not manage products can only change the cost.
    if (!can('products.edit') && can('costs.edit')) {
        foreach ($entities['products']['fields'] as $f => &$def) {
            if ($f !== 'cost_price') {
                $def['readonly'] = true;
            }
        }
        unset($def);
    }
    if (xero_connected()) {
        $entities['products']['list'][] = '_xero';
        $entities['products']['computed']['_xero'] = ['label' => 'Xero', 'type' => 'xero_item',
            'sql' => "(CASE WHEN t.xero_sync_error IS NOT NULL THEN 'error' WHEN t.xero_synced_at IS NULL THEN 'not_sent' WHEN t.xero_synced_at < t.updated_at THEN 'changed' ELSE 'sent' END)"];
        $entities['products']['presets'] = [
            'xero_not_sent' => ['label' => 'Not in Xero', 'sql' => 't.xero_synced_at IS NULL'],
            'xero_changed'  => ['label' => 'Changed since sent to Xero', 'sql' => 't.xero_synced_at < t.updated_at'],
            'xero_error'    => ['label' => 'Xero problems', 'sql' => 't.xero_sync_error IS NOT NULL'],
        ];
    }

    // Xero balances, once connected (and only for people allowed to see money).
    if (xero_connected() && can('finance.view')) {
        $balance = '(SELECT %s FROM xero_contacts x WHERE x.id = t.xero_contact_id)';
        $entities['accounts']['computed'] += [
            '_balance' => ['label' => 'Balance', 'type' => 'money', 'sql' => sprintf($balance, 'x.outstanding')],
            '_overdue' => ['label' => 'Overdue', 'type' => 'money', 'sql' => sprintf($balance, 'x.overdue')],
        ];
        $entities['accounts']['list'][] = '_balance';
        $entities['accounts']['list'][] = '_overdue';
        $entities['accounts']['presets'] = ($entities['accounts']['presets'] ?? []) + [
            'arrears'   => ['label' => 'In arrears', 'sql' => 'EXISTS (SELECT 1 FROM xero_contacts x WHERE x.id = t.xero_contact_id AND x.overdue > 0)'],
            'over_limit' => ['label' => 'Over credit limit', 'sql' => 't.credit_limit IS NOT NULL AND EXISTS (SELECT 1 FROM xero_contacts x WHERE x.id = t.xero_contact_id AND x.outstanding > t.credit_limit)'],
            'no_xero'   => ['label' => 'Not linked to Xero', 'sql' => "t.xero_contact_id IS NULL AND t.status IN ('active','suspended')"],
        ];
    }

    // GoCardless Direct Debit status, once set up.
    if (gc_configured() && can('finance.view')) {
        $entities['accounts']['computed']['_dd'] = ['label' => 'Direct Debit', 'type' => 'mandate',
            'sql' => '(SELECT g.mandate_status FROM gocardless_customers g WHERE g.id = t.gocardless_customer_id)'];
        $entities['accounts']['list'][] = '_dd';
        $entities['accounts']['presets'] = ($entities['accounts']['presets'] ?? []) + [
            'no_dd' => ['label' => 'No Direct Debit', 'sql' => "t.status = 'active' AND NOT (t.parent_relationship <=> 'billed_via_dealer') AND NOT EXISTS (SELECT 1 FROM gocardless_customers g WHERE g.id = t.gocardless_customer_id
                AND g.mandate_status IN ('active','submitted','pending_submission','pending_customer_approval'))"],
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
            if (array_key_exists('parent_id', $data) && empty($data['parent_id'])) {
                $data['parent_relationship'] = null;
                $data['msa_covered'] = 0;
            }
            if (array_key_exists('is_dealer', $data) && empty($data['is_dealer'])) {
                $data['dealer_commission_pct'] = null;
            }
            if (isset($data['status'])) {
                if ($data['status'] === 'churned' && ($existing['status'] ?? null) !== 'churned') {
                    $data['closed_at'] = $now;
                } elseif ($data['status'] !== 'churned') {
                    $data['closed_at'] = null;
                    $data['closed_reason'] = null;
                }
            }
            break;

        case 'sites':
            if (!empty($data['postcode'])) {
                $data['postcode'] = strtoupper($data['postcode']);
            }
            break;

        case 'contacts':
            $prefs = ['marketing_email', 'marketing_phone', 'marketing_sms', 'marketing_post', 'marketing_topics', 'service_alerts'];
            $changed = false;
            foreach ($prefs as $p) {
                if (array_key_exists($p, $data) && (string)($data[$p] ?? '') !== (string)($existing[$p] ?? ($existing === null ? '' : null))) {
                    $changed = true;
                }
            }
            $anyMarketing = !empty($data['marketing_email']) || !empty($data['marketing_phone']) || !empty($data['marketing_sms']) || !empty($data['marketing_post']);
            if ($changed && ($existing !== null || $anyMarketing)) {
                $data['marketing_updated_at'] = $now;
            }
            if (!empty($data['marketing_email']) && empty($existing['marketing_email'])) {
                $data['unsubscribed_at'] = null;
            }
            break;

        case 'services':
            if (!empty($data['product_id'])) {
                $product = db_one('SELECT * FROM products WHERE id = ?', [$data['product_id']]);
                if ($product) {
                    $data['service_type'] = $data['service_type'] ?: $product['category'];
                    $data['carrier'] = $data['carrier'] ?: $product['carrier'];
                    $data['monthly_price'] ??= monthly_equivalent($product['monthly_price'], $product['billing_frequency'] ?? 'monthly');
                    $data['setup_fee'] ??= $product['setup_fee'];
                    $data['term_months'] ??= $product['term_months'];
                    // The cost when it was ordered: kept, whatever the product's cost does later.
                    if ($existing === null && ($data['cost_price'] ?? null) === null && $product['cost_price'] !== null) {
                        $data['cost_price'] = monthly_equivalent($product['cost_price'], $product['billing_frequency'] ?? 'monthly');
                    }
                }
            }
            // Defaults for a new service, or for fields being saved (a partial update leaves the others alone).
            foreach (['service_type' => 'other', 'monthly_price' => 0, 'setup_fee' => 0] as $col => $default) {
                if ($existing === null || array_key_exists($col, $data)) {
                    $data[$col] = ($data[$col] ?? null) ?: ($col === 'service_type' ? $default : ($data[$col] ?? $default));
                }
            }
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
            // Route to a group by category; grouped tickets wait in the queue for someone to pick up.
            if (array_key_exists('group_id', $data) && empty($data['group_id']) && ($existing === null || $existing['category'] !== $data['category'] || empty($existing['group_id']))) {
                $data['group_id'] = ticket_group_for_category($data['category'] ?? null);
            }
            if ($existing === null && empty($data['assigned_to']) && empty($data['group_id']) && $user) {
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

        case 'suppliers':
            if (array_key_exists('ordering', $data) && empty($data['ordering'])) {
                $data['ordering'] = 'email';
            }
            break;

        case 'supplier_products':
            if ($existing === null || (array_key_exists('cost_price', $data) && (float)$data['cost_price'] !== (float)$existing['cost_price'])
                || (array_key_exists('setup_cost', $data) && (string)$data['setup_cost'] !== (string)($existing['setup_cost'] ?? ''))) {
                $data['price_updated_at'] = $now;
            }
            if (array_key_exists('supplier_sku', $data) && $data['supplier_sku'] === '') {
                $data['supplier_sku'] = null;
            }
            if (array_key_exists('product_id', $data) && empty($data['product_id'])) {
                $data['preferred'] = 0;
            }
            break;

        case 'products':
            if ($existing === null || array_key_exists('setup_fee', $data)) {
                $data['setup_fee'] ??= 0;
            }
            if (array_key_exists('billing_frequency', $data) || $existing === null) {
                $data['billing_frequency'] = $data['billing_frequency'] ?? null ?: 'monthly';
            }
            // Bought once, so there's no minimum term.
            if (($data['billing_frequency'] ?? $existing['billing_frequency'] ?? null) === 'one_off') {
                $data['term_months'] = 0;
            }
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

/** Runs after a row is inserted or updated. $data includes virtual (form-only) fields. */
function after_save(string $name, int $id, array $data, ?array $existing): void
{
    if ($name === 'supplier_products') {
        supplier_product_saved($id);
    }
    if ($name === 'accounts' && array_key_exists('main_name', $data)) {
        save_account_contacts($id, $data);
    }
    if ($name === 'accounts') {
        if (array_key_exists('is_supplier', $data)) {
            account_set_supplier($id, (bool)$data['is_supplier']);
        }
        company_sync('accounts', $id);
    }
    if ($name === 'suppliers' && !empty($data['account_id'])) {
        // Newly linked: fill each side's blanks from the other. Afterwards, changes here carry over to the customer.
        $linked = (int)($existing['account_id'] ?? 0) !== (int)$data['account_id'];
        company_sync('suppliers', $id, $linked);
        if ($linked) {
            company_sync('accounts', (int)$data['account_id'], true);
        }
    }
    if ($name === 'contacts' && isset($data['account_id'])) {
        // Keep the customer's main/accounts contact in step with the ticks on the contact.
        foreach (['is_primary' => 'main_contact_id', 'is_billing' => 'billing_contact_id'] as $flag => $column) {
            if (!array_key_exists($flag, $data)) {
                continue;
            }
            if ($data[$flag]) {
                db_exec("UPDATE accounts SET $column = ? WHERE id = ?", [$id, $data['account_id']]);
                db_exec("UPDATE contacts SET $flag = 0 WHERE account_id = ? AND id <> ?", [$data['account_id'], $id]);
            } else {
                db_exec("UPDATE accounts SET $column = NULL WHERE id = ? AND $column = ?", [$data['account_id'], $id]);
            }
        }
        if ($existing && (int)$existing['account_id'] !== (int)$data['account_id']) {
            db_exec('UPDATE accounts SET main_contact_id = IF(main_contact_id = ?, NULL, main_contact_id), billing_contact_id = IF(billing_contact_id = ?, NULL, billing_contact_id) WHERE id = ?',
                [$id, $id, $existing['account_id']]);
        }
    }
}

/** Main and accounts contact as edited on the customer form (virtual fields). */
function account_contact_values(?array $account): array
{
    $main = !empty($account['main_contact_id']) ? db_one('SELECT * FROM contacts WHERE id = ?', [$account['main_contact_id']]) : null;
    $billing = !empty($account['billing_contact_id']) ? db_one('SELECT * FROM contacts WHERE id = ?', [$account['billing_contact_id']]) : null;
    $same = !$billing || ($main && (int)$main['id'] === (int)$billing['id']);
    return [
        'main_name' => $main['name'] ?? null, 'main_job_title' => $main['job_title'] ?? null,
        'main_phone' => $main['phone'] ?? ($main['mobile'] ?? null), 'main_email' => $main['email'] ?? null,
        'billing_same' => $same ? 1 : 0,
        'billing_name' => $same ? null : $billing['name'], 'billing_phone' => $same ? null : ($billing['phone'] ?: $billing['mobile']),
        'billing_email' => $same ? null : $billing['email'],
        'is_supplier' => !empty($account['id']) && db_value('SELECT 1 FROM suppliers WHERE account_id = ?', [$account['id']]) ? 1 : 0,
    ];
}

/** Create/update the main and accounts contacts from the customer form. */
function save_account_contacts(int $accountId, array $v): void
{
    $account = db_one('SELECT main_contact_id, billing_contact_id FROM accounts WHERE id = ?', [$accountId]);
    $upsert = function (?int $contactId, string $name, ?string $jobTitle, ?string $phone, ?string $email, bool $keepJob) use ($accountId): int {
        $exists = $contactId ? db_value('SELECT id FROM contacts WHERE id = ? AND account_id = ?', [$contactId, $accountId]) : null;
        if ($exists) {
            db_exec('UPDATE contacts SET name = ?, phone = ?, email = ?' . ($keepJob ? '' : ', job_title = ?') . ' WHERE id = ?',
                array_merge([$name, $phone, $email], $keepJob ? [] : [$jobTitle], [$contactId]));
            return $contactId;
        }
        db_exec('INSERT INTO contacts (account_id, name, job_title, phone, email) VALUES (?, ?, ?, ?, ?)', [$accountId, $name, $jobTitle, $phone, $email]);
        return (int)db()->lastInsertId();
    };

    $main = null;
    if (trim((string)($v['main_name'] ?? '')) !== '') {
        $main = $upsert($account['main_contact_id'] ? (int)$account['main_contact_id'] : null, trim($v['main_name']), $v['main_job_title'] ?? null, $v['main_phone'] ?? null, $v['main_email'] ?? null, false);
    }

    $billing = null;
    if (!empty($v['billing_same'])) {
        $billing = $main;
    } elseif (trim((string)($v['billing_name'] ?? '')) !== '' || !empty($v['billing_email'])) {
        $current = $account['billing_contact_id'] && (int)$account['billing_contact_id'] !== $main ? (int)$account['billing_contact_id'] : null;
        $billing = $upsert($current, trim((string)($v['billing_name'] ?? '')) ?: 'Accounts', null, $v['billing_phone'] ?? null, $v['billing_email'] ?? null, true);
    }

    db_exec('UPDATE accounts SET main_contact_id = ?, billing_contact_id = ? WHERE id = ?', [$main, $billing, $accountId]);
    db_exec('UPDATE contacts SET is_primary = (id <=> ?), is_billing = (id <=> ?) WHERE account_id = ?', [$main, $billing, $accountId]);
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


/** Business-rule validation that needs the database. Returns [field => message]. */
function validate_rules(string $name, array $data, ?int $id): array
{
    $errors = [];
    if ($name === 'suppliers' && !empty($data['account_id'])) {
        $other = db_one('SELECT id, name FROM suppliers WHERE account_id = ? AND id <> ?', [$data['account_id'], (int)$id]);
        if ($other) {
            $errors['account_id'] = 'That customer is already linked to the supplier ' . $other['name'] . '.';
        }
    }
    if ($name === 'accounts') {
        $parent = $data['parent_id'] ?? null;
        if ($parent) {
            if ($id && (int)$parent === $id) {
                $errors['parent_id'] = 'A customer can\'t be its own dealer.';
            } elseif (!db_value('SELECT is_dealer FROM accounts WHERE id = ?', [$parent])) {
                $errors['parent_id'] = 'The chosen customer isn\'t marked as a dealer.';
            } elseif ($id) {
                // Walk up the chain to stop loops (A under B under A).
                $seen = [];
                for ($p = (int)$parent; $p && !isset($seen[$p]); $p = (int)db_value('SELECT parent_id FROM accounts WHERE id = ?', [$p])) {
                    if ($p === $id) {
                        $errors['parent_id'] = 'That would create a loop: the dealer is already under this customer.';
                        break;
                    }
                    $seen[$p] = true;
                }
            }
            if (empty($data['parent_relationship'])) {
                $errors['parent_relationship'] = 'Choose how this customer relates to the dealer.';
            }
        }
        if (($data['status'] ?? null) === 'churned' && !can('customers.close')
            && (!$id || db_value('SELECT status FROM accounts WHERE id = ?', [$id]) !== 'churned')) {
            $errors['status'] = 'Closing a customer needs approval. Save without closing, then use "Request closure" on the customer\'s page.';
        }
        if (array_key_exists('main_name', $data)) {
            if (trim((string)$data['main_name']) === '' && (!empty($data['main_email']) || !empty($data['main_phone']))) {
                $errors['main_name'] = 'Enter the main contact\'s name.';
            }
            if (empty($data['billing_same']) && trim((string)($data['billing_name'] ?? '')) !== '' && empty($data['billing_email'])) {
                $errors['billing_email'] = 'Enter the email invoices should go to, or tick "Send invoices and statements to the main contact".';
            }
        }
        if ($id && array_key_exists('is_dealer', $data) && empty($data['is_dealer'])) {
            $children = (int)db_value('SELECT COUNT(*) FROM accounts WHERE parent_id = ?', [$id]);
            if ($children) {
                $errors['is_dealer'] = "This dealer has $children customer" . ($children === 1 ? '' : 's') . ' under it. Move them first.';
            }
        }
    }
    if (in_array($name, ['products', 'suppliers'], true) && ($codes = nominal_codes())) {
        foreach (['sales_account_code', 'purchase_account_code'] as $f) {
            if (!empty($data[$f]) && !isset($codes[$data[$f]])) {
                $errors[$f] = 'There\'s no active account with code "' . $data[$f] . '" in your Xero chart of accounts.';
            }
        }
    }
    if (in_array($name, ['products', 'suppliers'], true)) {
        foreach (['sales_tax_type', 'purchase_tax_type'] as $f) {
            if (!empty($data[$f]) && ($problem = xero_code_problem((string)$data[$f], 'tax'))) {
                $errors[$f] = $problem;
            }
        }
    }
    if ($name === 'contacts') {
        $marketing = !empty($data['marketing_email']) || !empty($data['marketing_phone']) || !empty($data['marketing_sms']) || !empty($data['marketing_post']);
        if ($marketing && empty($data['marketing_source'])) {
            $errors['marketing_source'] = 'Record how they gave permission for marketing.';
        }
        if (!empty($data['marketing_email']) && empty($data['email'])) {
            $errors['marketing_email'] = 'Add an email address to send them marketing emails.';
        }
    }
    return $errors;
}
