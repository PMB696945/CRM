<?php
// Copy this file to config.php and fill in your database details.
// Any value can also be supplied by environment variable (CRM_DB_HOST, etc.).
return [
    'app_name'  => 'Telecom CRM',
    'db_host'   => getenv('CRM_DB_HOST') ?: '127.0.0.1',
    'db_port'   => getenv('CRM_DB_PORT') ?: '3306',
    'db_name'   => getenv('CRM_DB_NAME') ?: 'telecom_crm',
    'db_user'   => getenv('CRM_DB_USER') ?: 'crm',
    'db_pass'   => getenv('CRM_DB_PASS') ?: '',
    'timezone'  => 'Europe/London',
    'currency'  => '£',
    // Days ahead to flag contracts as "up for renewal".
    'renewal_window_days' => 90,
    // Ticket SLA targets in hours, by priority.
    'sla_hours' => ['P1' => 4, 'P2' => 8, 'P3' => 24, 'P4' => 72],
];
