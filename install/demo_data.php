<?php
declare(strict_types=1);

/** Load a realistic sample data set. Dates are relative to today. */
function seed_demo_data(): void
{
    if (db_value('SELECT COUNT(*) FROM accounts') > 0) {
        echo "  (accounts already exist — skipping demo data)\n";
        return;
    }

    $d = fn(string $modify) => date('Y-m-d', strtotime($modify));
    $dt = fn(string $modify) => date('Y-m-d H:i:s', strtotime($modify));

    // Staff
    $staff = [];
    foreach ([['Sarah Mitchell', 'sarah@example.com'], ['James Patel', 'james@example.com'], ['Emma Clarke', 'emma@example.com']] as [$name, $email]) {
        $id = db_value('SELECT id FROM users WHERE email = ?', [$email]);
        if (!$id) {
            db_exec("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'agent')",
                [$name, $email, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT)]);
            $id = db()->lastInsertId();
        }
        $staff[] = (int)$id;
    }

    // Product catalogue
    $products = [
        ['MOB-UNL-5G', 'Business Unlimited 5G SIM', 'mobile', 'EE', 22.00, 0, 24],
        ['MOB-30GB', 'Business 30GB SIM', 'mobile', 'Vodafone', 14.00, 0, 24],
        ['MOB-DATA-100', 'Data-only 100GB SIM', 'mobile', 'O2', 18.50, 0, 12],
        ['BB-FTTP-900', 'Full Fibre 900', 'broadband', 'CityFibre', 55.00, 99, 24],
        ['BB-FTTP-500', 'Full Fibre 500', 'broadband', 'Openreach', 42.00, 99, 24],
        ['BB-SOGEA-80', 'SOGEA 80/20', 'broadband', 'Openreach', 32.00, 49, 24],
        ['VOIP-SEAT', 'Hosted VoIP seat', 'hosted_pbx', 'Gamma', 9.50, 0, 36],
        ['SIP-CH', 'SIP trunk channel', 'sip_trunk', 'Gamma', 6.00, 25, 36],
        ['LL-100', 'Leased line 100Mb/1Gb bearer', 'leased_line', 'BT Wholesale', 295.00, 0, 36],
        ['LL-1000', 'Leased line 1Gb/1Gb', 'leased_line', 'Colt', 495.00, 0, 36],
        ['HW-YEALINK-T54', 'Yealink T54W handset (rental)', 'hardware', null, 4.50, 0, 36],
        ['HW-ROUTER', 'Managed router', 'hardware', null, 12.00, 150, 36],
    ];
    $productIds = [];
    foreach ($products as [$sku, $name, $cat, $carrier, $price, $setup, $term]) {
        $productIds[$sku] = insert_row('products', [
            'sku' => $sku, 'name' => $name, 'category' => $cat, 'carrier' => $carrier,
            'monthly_price' => $price, 'setup_fee' => $setup, 'term_months' => $term, 'active' => 1,
        ]);
    }

    $customers = [
        ['Harbour View Dental Ltd', 'business', 'active', 'Healthcare', 'Bristol', 'BS1 4QA', 'Dr. Priya Shah', 'Practice Owner'],
        ['Kestrel Logistics', 'business', 'active', 'Logistics', 'Birmingham', 'B6 7EU', 'Mark Owens', 'IT Manager'],
        ['Oakridge Accountants LLP', 'business', 'active', 'Professional services', 'Leeds', 'LS1 5AB', 'Helen Barker', 'Office Manager'],
        ['The Copper Kettle Café', 'business', 'active', 'Hospitality', 'York', 'YO1 8RS', 'Tom Hughes', 'Owner'],
        ['Brightside Primary Academy', 'business', 'active', 'Education', 'Manchester', 'M14 5PL', 'Angela Reid', 'Business Manager'],
        ['Northgate Motors', 'business', 'suspended', 'Automotive', 'Newcastle', 'NE1 4XF', 'Gary Lister', 'Director'],
        ['Mr David Wilson', 'residential', 'active', null, 'Reading', 'RG1 3JH', 'David Wilson', null],
        ['Fenwick & Rowe Solicitors', 'business', 'prospect', 'Legal', 'London', 'EC2A 4NE', 'Charlotte Rowe', 'Partner'],
        ['Summit Fitness Group', 'business', 'prospect', 'Leisure', 'Nottingham', 'NG1 6FB', 'Ryan Cole', 'Operations Director'],
        ['Greenfield Farm Supplies', 'business', 'churned', 'Agriculture', 'Shrewsbury', 'SY1 2DY', 'Ian Morris', 'Owner'],
    ];

    $accountIds = [];
    foreach ($customers as $i => [$name, $type, $status, $industry, $city, $postcode, $contact, $title]) {
        $slug = preg_replace('/[^a-z]+/', '', strtolower(explode(' ', $name)[0] . ($type === 'residential' ? 'wilson' : '')));
        $id = insert_row('accounts', [
            'account_number' => null, 'name' => $name, 'type' => $type, 'status' => $status, 'industry' => $industry,
            'company_number' => $type === 'business' ? sprintf('%08d', 10234567 + $i * 7919) : null,
            'email' => "accounts@$slug.example.co.uk", 'phone' => sprintf('0%d %03d %04d', 113 + $i * 10, 400 + $i * 31, 1000 + $i * 777),
            'address' => (10 + $i * 7) . ' ' . ['High Street', 'Market Place', 'Station Road', 'Church Lane', 'Mill Road'][$i % 5],
            'city' => $city, 'postcode' => $postcode, 'owner_id' => $staff[$i % 3], 'credit_limit' => $type === 'business' ? 5000 : null,
            'notes' => null,
        ]);
        $accountIds[$name] = $id;
        insert_row('contacts', [
            'account_id' => $id, 'name' => $contact, 'job_title' => $title, 'email' => strtolower(explode(' ', str_replace(['Dr. ', 'Mr '], '', $contact))[0]) . "@$slug.example.co.uk",
            'phone' => null, 'mobile' => sprintf('07%03d %06d', 700 + $i * 11, 100000 + $i * 12345), 'is_primary' => 1, 'is_billing' => $type === 'residential' ? 1 : 0, 'notes' => null,
        ]);
    }
    insert_row('contacts', ['account_id' => $accountIds['Kestrel Logistics'], 'name' => 'Joanne Price', 'job_title' => 'Finance Controller', 'email' => 'joanne@kestrel.example.co.uk', 'phone' => '0121 555 0199', 'mobile' => null, 'is_primary' => 0, 'is_billing' => 1, 'notes' => null]);
    insert_row('contacts', ['account_id' => $accountIds['Harbour View Dental Ltd'], 'name' => 'Lucy Grant', 'job_title' => 'Practice Manager', 'email' => 'lucy@harbour.example.co.uk', 'phone' => null, 'mobile' => '07700 900123', 'is_primary' => 0, 'is_billing' => 1, 'notes' => null]);

    // Services: [account, sku, identifier, status, start (relative)]
    $msisdn = fn(int $n) => sprintf('07700 9%05d', $n);
    $services = [
        ['Harbour View Dental Ltd', 'BB-FTTP-500', 'OR-FTTP-44810273', 'active', '-23 months'],
        ['Harbour View Dental Ltd', 'VOIP-SEAT', '0117 496 0001', 'active', '-34 months'],
        ['Harbour View Dental Ltd', 'VOIP-SEAT', '0117 496 0002', 'active', '-34 months'],
        ['Harbour View Dental Ltd', 'VOIP-SEAT', '0117 496 0003', 'active', '-34 months'],
        ['Harbour View Dental Ltd', 'MOB-30GB', $msisdn(10001), 'active', '-22 months'],
        ['Kestrel Logistics', 'LL-100', 'BTW-EAD-90012384', 'active', '-30 months'],
        ['Kestrel Logistics', 'SIP-CH', '0121 496 0500 (10ch)', 'active', '-30 months'],
        ['Kestrel Logistics', 'MOB-UNL-5G', $msisdn(20001), 'active', '-8 months'],
        ['Kestrel Logistics', 'MOB-UNL-5G', $msisdn(20002), 'active', '-8 months'],
        ['Kestrel Logistics', 'MOB-UNL-5G', $msisdn(20003), 'active', '-8 months'],
        ['Kestrel Logistics', 'MOB-UNL-5G', $msisdn(20004), 'active', '-8 months'],
        ['Kestrel Logistics', 'MOB-DATA-100', $msisdn(20005), 'active', '-11 months'],
        ['Kestrel Logistics', 'HW-ROUTER', 'SN-MR-7781203', 'active', '-30 months'],
        ['Oakridge Accountants LLP', 'BB-FTTP-900', 'CF-FTTP-10028841', 'active', '-5 months'],
        ['Oakridge Accountants LLP', 'VOIP-SEAT', '0113 496 0100', 'active', '-5 months'],
        ['Oakridge Accountants LLP', 'VOIP-SEAT', '0113 496 0101', 'active', '-5 months'],
        ['Oakridge Accountants LLP', 'HW-YEALINK-T54', 'SN-YL-54-00931', 'active', '-5 months'],
        ['The Copper Kettle Café', 'BB-SOGEA-80', 'OR-SOGEA-55190442', 'active', '-25 months'],
        ['The Copper Kettle Café', 'MOB-30GB', $msisdn(30001), 'active', '-14 months'],
        ['Brightside Primary Academy', 'LL-1000', 'COLT-ETH-338120', 'active', '-12 months'],
        ['Brightside Primary Academy', 'VOIP-SEAT', '0161 496 0700', 'active', '-12 months'],
        ['Brightside Primary Academy', 'VOIP-SEAT', '0161 496 0701', 'active', '-12 months'],
        ['Northgate Motors', 'BB-FTTP-500', 'OR-FTTP-77120984', 'suspended', '-18 months'],
        ['Northgate Motors', 'MOB-UNL-5G', $msisdn(40001), 'suspended', '-18 months'],
        ['Mr David Wilson', 'BB-FTTP-900', 'CF-FTTP-20019934', 'active', '-23 months'],
        ['Mr David Wilson', 'MOB-UNL-5G', $msisdn(50001), 'pending', null],
        ['Greenfield Farm Supplies', 'BB-SOGEA-80', 'OR-SOGEA-11873321', 'ceased', '-40 months'],
    ];
    $serviceIds = [];
    foreach ($services as [$acct, $sku, $ident, $status, $start]) {
        $serviceIds[$ident] = insert_row('services', [
            'account_id' => $accountIds[$acct], 'product_id' => $productIds[$sku], 'service_type' => null, 'identifier' => $ident,
            'carrier' => null, 'status' => $status, 'monthly_price' => null, 'setup_fee' => null,
            'start_date' => $start ? $d($start) : null, 'term_months' => null, 'contract_end_date' => null,
            'install_address' => null, 'notes' => null,
        ]);
    }

    // Tickets: [account, service identifier, subject, category, priority, status, opened]
    $tickets = [
        ['Kestrel Logistics', 'BTW-EAD-90012384', 'Leased line down – no sync on NTE', 'fault', 'P1', 'awaiting_carrier', '-3 hours', 'BTW-FLT-0048812'],
        ['Harbour View Dental Ltd', 'OR-FTTP-44810273', 'Intermittent broadband drops in afternoons', 'fault', 'P3', 'in_progress', '-20 hours', null],
        ['The Copper Kettle Café', null, 'Query on last invoice – setup fee charged twice', 'billing', 'P4', 'open', '-2 days', null],
        ['Mr David Wilson', $msisdn(50001), 'Port in number from Three (PAC supplied)', 'porting', 'P3', 'awaiting_customer', '-1 day', null],
        ['Oakridge Accountants LLP', '0113 496 0101', 'Handset not registering after move', 'fault', 'P2', 'resolved', '-6 days', null],
        ['Brightside Primary Academy', null, 'Add 4 extra VoIP seats for new staff', 'order', 'P3', 'open', '-4 hours', null],
        ['Northgate Motors', null, 'Account suspended – arrears', 'billing', 'P2', 'open', '-12 hours', null],
    ];
    $ticketIds = [];
    foreach ($tickets as [$acct, $ident, $subject, $cat, $prio, $status, $opened, $carrierRef]) {
        $id = insert_row('tickets', [
            'account_id' => $accountIds[$acct], 'service_id' => $ident ? $serviceIds[$ident] : null, 'contact_id' => null,
            'subject' => $subject, 'category' => $cat, 'priority' => $prio, 'status' => $status,
            'assigned_to' => $staff[array_rand($staff)], 'carrier_ref' => $carrierRef, 'description' => $subject . '.',
        ]);
        // Back-date so SLA clocks look realistic.
        $sla = (int)(config('sla_hours')[$prio] ?? 24);
        $created = strtotime($opened);
        db_exec('UPDATE tickets SET created_at = ?, sla_due_at = ?, resolved_at = IF(resolved_at IS NULL, NULL, ?) WHERE id = ?',
            [date('Y-m-d H:i:s', $created), date('Y-m-d H:i:s', $created + $sla * 3600), date('Y-m-d H:i:s', $created + 3 * 3600), $id]);
        $ticketIds[] = $id;
    }
    db_exec('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal, created_at) VALUES (?, ?, ?, 1, ?)',
        [$ticketIds[0], $staff[0], 'Remote tests show loss of light on the NTE. Fault raised with BT Wholesale, engineer appointment requested.', $dt('-2 hours')]);
    db_exec('INSERT INTO ticket_comments (ticket_id, user_id, body, is_internal, created_at) VALUES (?, ?, ?, 0, ?)',
        [$ticketIds[0], $staff[0], 'Hi Mark, we have raised this with the carrier as a priority fault and will update you within the hour.', $dt('-110 minutes')]);

    // Opportunities
    $opps = [
        ['Fenwick & Rowe Solicitors', 'Office move – 1Gb leased line + 25 VoIP seats', 'new_business', 'proposal', 732.50, 1500, 36, '+3 weeks'],
        ['Summit Fitness Group', 'Full fibre for 4 gym sites', 'new_business', 'qualified', 220.00, 396, 24, '+6 weeks'],
        ['Kestrel Logistics', 'Fleet mobile refresh – 20 x 5G SIMs', 'upsell', 'negotiation', 440.00, 0, 24, '+10 days'],
        ['Harbour View Dental Ltd', 'Contract renewal – broadband & mobiles', 'renewal', 'qualified', 83.50, 0, 24, '+5 weeks'],
        ['Brightside Primary Academy', 'Additional VoIP seats', 'upsell', 'won', 38.00, 0, 36, '-5 days'],
        ['Greenfield Farm Supplies', 'Win-back: SOGEA + mobiles', 'new_business', 'lost', 60.00, 0, 24, '-20 days'],
        ['The Copper Kettle Café', 'Card terminal SIM + backup 4G', 'upsell', 'lead', 18.50, 0, 12, '+2 months'],
    ];
    foreach ($opps as $i => [$acct, $title, $type, $stage, $mrr, $oneOff, $term, $close]) {
        insert_row('opportunities', [
            'account_id' => $accountIds[$acct], 'title' => $title, 'opp_type' => $type, 'stage' => $stage,
            'monthly_value' => $mrr, 'one_off_value' => $oneOff, 'term_months' => $term, 'probability' => null,
            'expected_close' => $d($close), 'owner_id' => $staff[$i % 3], 'notes' => null,
        ]);
    }

    // Activities
    $acts = [
        ['Kestrel Logistics', 'call', 'Discussed fleet SIM refresh, wants pricing on 20 lines', null, 1, '-3 days'],
        ['Harbour View Dental Ltd', 'meeting', 'Renewal review meeting at practice', null, 1, '-1 week'],
        ['Harbour View Dental Ltd', 'task', 'Send renewal proposal', '+4 days', 0, '-1 week'],
        ['Fenwick & Rowe Solicitors', 'email', 'Sent leased line survey booking confirmation', null, 1, '-2 days'],
        ['Summit Fitness Group', 'task', 'Check FTTP availability at all 4 sites', '-1 day', 0, '-5 days'],
        ['Northgate Motors', 'call', 'Chased overdue balance of £412.60 – promised payment Friday', null, 1, '-12 hours'],
        ['Oakridge Accountants LLP', 'note', 'Very happy with install, potential referral to sister firm', null, 1, '-4 months'],
    ];
    foreach ($acts as $i => [$acct, $type, $subject, $due, $done, $when]) {
        $id = insert_row('activities', [
            'account_id' => $accountIds[$acct], 'type' => $type, 'subject' => $subject, 'body' => null,
            'due_date' => $due ? $d($due) : null, 'done' => $done,
        ]);
        db_exec('UPDATE activities SET created_at = ?, user_id = ? WHERE id = ?', [$dt($when), $staff[$i % 3], $id]);
    }
}
