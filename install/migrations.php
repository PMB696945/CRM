<?php
declare(strict_types=1);

/*
 * Database upgrades applied on top of install/schema.sql (which is version 1).
 * Each migration must be safe to re-run if it was interrupted part-way.
 */

return [
    2 => function (): void {
        db()->exec("CREATE TABLE IF NOT EXISTS settings (
            name       VARCHAR(100) NOT NULL PRIMARY KEY,
            value      TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Customer contacts and balances synced from Xero.
        db()->exec("CREATE TABLE IF NOT EXISTS xero_contacts (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            contact_id      CHAR(36) NOT NULL UNIQUE,
            name            VARCHAR(255) NOT NULL,
            account_number  VARCHAR(50) NULL,
            email           VARCHAR(255) NULL,
            status          VARCHAR(20) NULL,
            outstanding     DECIMAL(12,2) NOT NULL DEFAULT 0,
            overdue         DECIMAL(12,2) NOT NULL DEFAULT 0,
            open_invoices   INT UNSIGNED NOT NULL DEFAULT 0,
            oldest_due_date DATE NULL,
            synced_at       DATETIME NULL,
            KEY idx_xero_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!column_exists('accounts', 'xero_contact_id')) {
            db()->exec('ALTER TABLE accounts ADD COLUMN xero_contact_id INT UNSIGNED NULL AFTER credit_limit');
        }
        if (!constraint_exists('accounts', 'fk_accounts_xero')) {
            db()->exec('ALTER TABLE accounts ADD CONSTRAINT fk_accounts_xero FOREIGN KEY (xero_contact_id) REFERENCES xero_contacts(id) ON DELETE SET NULL');
        }
    },

    3 => function (): void {
        // GoCardless customers and their best current Direct Debit mandate.
        db()->exec("CREATE TABLE IF NOT EXISTS gocardless_customers (
            id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id       VARCHAR(40) NOT NULL UNIQUE,
            name              VARCHAR(255) NOT NULL,
            email             VARCHAR(255) NULL,
            crm_reference     VARCHAR(50) NULL,
            mandate_id        VARCHAR(40) NULL,
            mandate_status    VARCHAR(40) NULL,
            mandate_reference VARCHAR(100) NULL,
            mandate_scheme    VARCHAR(30) NULL,
            next_charge_date  DATE NULL,
            synced_at         DATETIME NULL,
            KEY idx_gc_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Mandate setup links generated for customers (GoCardless billing request flows).
        db()->exec("CREATE TABLE IF NOT EXISTS gocardless_setup_links (
            id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id         INT UNSIGNED NOT NULL,
            billing_request_id VARCHAR(40) NOT NULL,
            flow_id            VARCHAR(40) NULL,
            url                VARCHAR(500) NOT NULL,
            expires_at         DATETIME NULL,
            status             VARCHAR(20) NOT NULL DEFAULT 'open',
            created_by         INT UNSIGNED NULL,
            created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_gcl_account (account_id, status),
            CONSTRAINT fk_gcl_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
            CONSTRAINT fk_gcl_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!column_exists('accounts', 'gocardless_customer_id')) {
            db()->exec('ALTER TABLE accounts ADD COLUMN gocardless_customer_id INT UNSIGNED NULL AFTER xero_contact_id');
        }
        if (!constraint_exists('accounts', 'fk_accounts_gocardless')) {
            db()->exec('ALTER TABLE accounts ADD CONSTRAINT fk_accounts_gocardless FOREIGN KEY (gocardless_customer_id) REFERENCES gocardless_customers(id) ON DELETE SET NULL');
        }
    },

    4 => function (): void {
        // Dealers: any customer can be a dealer, and customers can sit under one.
        $columns = [
            'is_dealer'             => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
            'parent_id'             => 'INT UNSIGNED NULL AFTER is_dealer',
            'parent_relationship'   => "ENUM('referral','billed_via_dealer') NULL AFTER parent_id",
            'msa_covered'           => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER parent_relationship',
            'dealer_commission_pct' => 'DECIMAL(5,2) NULL AFTER msa_covered',
        ];
        foreach ($columns as $column => $definition) {
            if (!column_exists('accounts', $column)) {
                db()->exec("ALTER TABLE accounts ADD COLUMN $column $definition");
            }
        }
        if (!constraint_exists('accounts', 'fk_accounts_parent')) {
            db()->exec('ALTER TABLE accounts ADD CONSTRAINT fk_accounts_parent FOREIGN KEY (parent_id) REFERENCES accounts(id) ON DELETE SET NULL');
        }

        // Uploaded contract templates (Word documents with merge fields + Signable tags).
        db()->exec("CREATE TABLE IF NOT EXISTS contract_templates (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name          VARCHAR(150) NOT NULL,
            service_type  VARCHAR(30) NOT NULL,
            file_name     VARCHAR(255) NOT NULL,
            stored_name   VARCHAR(100) NOT NULL,
            active        TINYINT(1) NOT NULL DEFAULT 1,
            notes         TEXT NULL,
            uploaded_by   INT UNSIGNED NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_tpl_type (service_type, active),
            CONSTRAINT fk_tpl_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db()->exec("CREATE TABLE IF NOT EXISTS quotes (
            id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference        VARCHAR(20) NULL UNIQUE,
            account_id       INT UNSIGNED NOT NULL,
            opportunity_id   INT UNSIGNED NULL,
            title            VARCHAR(200) NOT NULL,
            status           ENUM('draft','sent','accepted','declined','expired','cancelled') NOT NULL DEFAULT 'draft',
            valid_until      DATE NULL,
            intro            TEXT NULL,
            recipient_name   VARCHAR(150) NULL,
            recipient_email  VARCHAR(190) NULL,
            token_hash       CHAR(64) NULL UNIQUE,
            sent_at          DATETIME NULL,
            viewed_at        DATETIME NULL,
            responded_at     DATETIME NULL,
            response_name    VARCHAR(150) NULL,
            response_ip      VARCHAR(45) NULL,
            decline_reason   TEXT NULL,
            created_by       INT UNSIGNED NULL,
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_quotes_status (status),
            CONSTRAINT fk_quotes_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
            CONSTRAINT fk_quotes_opp FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE SET NULL,
            CONSTRAINT fk_quotes_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db()->exec("CREATE TABLE IF NOT EXISTS quote_lines (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            quote_id       INT UNSIGNED NOT NULL,
            product_id     INT UNSIGNED NULL,
            service_type   VARCHAR(30) NOT NULL,
            description    VARCHAR(255) NOT NULL,
            quantity       INT UNSIGNED NOT NULL DEFAULT 1,
            monthly_price  DECIMAL(10,2) NOT NULL DEFAULT 0,
            setup_fee      DECIMAL(10,2) NOT NULL DEFAULT 0,
            term_months    SMALLINT UNSIGNED NOT NULL DEFAULT 24,
            sort           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            CONSTRAINT fk_ql_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
            CONSTRAINT fk_ql_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db()->exec("CREATE TABLE IF NOT EXISTS contracts (
            id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference            VARCHAR(20) NULL UNIQUE,
            account_id           INT UNSIGNED NOT NULL,
            quote_id             INT UNSIGNED NULL,
            kind                 ENUM('services','msa') NOT NULL DEFAULT 'services',
            title                VARCHAR(200) NOT NULL,
            status               ENUM('draft','sent','signed','rejected','cancelled','expired','failed') NOT NULL DEFAULT 'draft',
            signer_name          VARCHAR(150) NULL,
            signer_email         VARCHAR(190) NULL,
            documents            TEXT NULL COMMENT 'JSON list of generated files',
            signed_file          VARCHAR(100) NULL,
            signable_fingerprint VARCHAR(64) NULL,
            sent_at              DATETIME NULL,
            signed_at            DATETIME NULL,
            last_error           TEXT NULL,
            created_by           INT UNSIGNED NULL,
            created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_contracts_status (status),
            KEY idx_contracts_fp (signable_fingerprint),
            CONSTRAINT fk_contracts_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
            CONSTRAINT fk_contracts_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
            CONSTRAINT fk_contracts_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },

    5 => function (): void {
        // The person who accepts a quote may not be the person it was sent to.
        if (!column_exists('quotes', 'response_email')) {
            db()->exec('ALTER TABLE quotes ADD COLUMN response_email VARCHAR(190) NULL AFTER response_name');
        }
    },

    6 => function (): void {
        // Security: sign-in throttling, audit log, two-factor sign-in.
        db()->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email        VARCHAR(190) NOT NULL,
            ip           VARCHAR(45) NOT NULL,
            success      TINYINT(1) NOT NULL DEFAULT 0,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_la_email (email, attempted_at),
            KEY idx_la_ip (ip, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NULL,
            action     VARCHAR(50) NOT NULL,
            entity     VARCHAR(50) NULL,
            entity_id  INT UNSIGNED NULL,
            summary    VARCHAR(500) NULL,
            ip         VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_audit_created (created_at),
            KEY idx_audit_entity (entity, entity_id),
            CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $columns = [
            'totp_secret'    => 'TEXT NULL',
            'totp_enabled'   => 'TINYINT(1) NOT NULL DEFAULT 0',
            'totp_last_step' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'recovery_codes' => 'TEXT NULL',
            'last_login_at'  => 'DATETIME NULL',
        ];
        foreach ($columns as $column => $definition) {
            if (!column_exists('users', $column)) {
                db()->exec("ALTER TABLE users ADD COLUMN $column $definition");
            }
        }
        // Encrypt credentials that were stored in plain text.
        foreach (SECRET_SETTINGS as $name) {
            $value = db_value('SELECT value FROM settings WHERE name = ?', [$name]);
            if ($value !== null && !str_starts_with((string)$value, 'enc:v1:')) {
                db_exec('UPDATE settings SET value = ? WHERE name = ?', [encrypt_secret((string)$value), $name]);
            }
        }
        settings_cache(true);
    },

    7 => function (): void {
        // Roles: super admin / admin / manager / staff / sales / support / finance / read only.
        db()->exec("ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL DEFAULT 'staff'");
        db()->exec("UPDATE users SET role = 'super_admin' WHERE role = 'admin'");
        db()->exec("UPDATE users SET role = 'staff' WHERE role = 'agent'");

        // Audit trail: before/after values and the customer each entry relates to.
        if (!column_exists('audit_log', 'changes')) {
            db()->exec('ALTER TABLE audit_log ADD COLUMN changes MEDIUMTEXT NULL AFTER summary');
        }
        if (!column_exists('audit_log', 'account_id')) {
            db()->exec('ALTER TABLE audit_log ADD COLUMN account_id INT UNSIGNED NULL AFTER entity_id, ADD KEY idx_audit_account (account_id, created_at)');
        }

        // Customers: fuller head office address, main + accounts contacts, closure.
        $columns = [
            'address2'           => 'VARCHAR(255) NULL AFTER address',
            'county'             => 'VARCHAR(100) NULL AFTER city',
            'main_contact_id'    => 'INT UNSIGNED NULL',
            'billing_contact_id' => 'INT UNSIGNED NULL',
            'closed_at'          => 'DATETIME NULL',
            'closed_reason'      => 'VARCHAR(500) NULL',
        ];
        foreach ($columns as $column => $definition) {
            if (!column_exists('accounts', $column)) {
                db()->exec("ALTER TABLE accounts ADD COLUMN $column $definition");
            }
        }
        if (!constraint_exists('accounts', 'fk_accounts_main_contact')) {
            db()->exec('ALTER TABLE accounts ADD CONSTRAINT fk_accounts_main_contact FOREIGN KEY (main_contact_id) REFERENCES contacts(id) ON DELETE SET NULL');
        }
        if (!constraint_exists('accounts', 'fk_accounts_billing_contact')) {
            db()->exec('ALTER TABLE accounts ADD CONSTRAINT fk_accounts_billing_contact FOREIGN KEY (billing_contact_id) REFERENCES contacts(id) ON DELETE SET NULL');
        }
        db()->exec('UPDATE accounts a SET a.main_contact_id = (SELECT MIN(c.id) FROM contacts c WHERE c.account_id = a.id AND c.is_primary = 1) WHERE a.main_contact_id IS NULL');
        db()->exec('UPDATE accounts a SET a.billing_contact_id = (SELECT MIN(c.id) FROM contacts c WHERE c.account_id = a.id AND c.is_billing = 1) WHERE a.billing_contact_id IS NULL');

        // Address book: other sites for a customer (installation addresses etc.).
        db()->exec("CREATE TABLE IF NOT EXISTS sites (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id  INT UNSIGNED NOT NULL,
            name        VARCHAR(150) NOT NULL,
            address     VARCHAR(255) NULL,
            address2    VARCHAR(255) NULL,
            city        VARCHAR(100) NULL,
            county      VARCHAR(100) NULL,
            postcode    VARCHAR(12) NULL,
            contact_id  INT UNSIGNED NULL,
            phone       VARCHAR(40) NULL,
            notes       TEXT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sites_postcode (postcode),
            CONSTRAINT fk_sites_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
            CONSTRAINT fk_sites_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!column_exists('services', 'site_id')) {
            db()->exec('ALTER TABLE services ADD COLUMN site_id INT UNSIGNED NULL AFTER account_id');
        }
        if (!constraint_exists('services', 'fk_services_site')) {
            db()->exec('ALTER TABLE services ADD CONSTRAINT fk_services_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL');
        }

        // Marketing preferences and service alert opt-out, per contact.
        $columns = [
            'marketing_email'      => 'TINYINT(1) NOT NULL DEFAULT 0',
            'marketing_phone'      => 'TINYINT(1) NOT NULL DEFAULT 0',
            'marketing_sms'        => 'TINYINT(1) NOT NULL DEFAULT 0',
            'marketing_post'       => 'TINYINT(1) NOT NULL DEFAULT 0',
            'marketing_topics'     => 'VARCHAR(500) NULL',
            'marketing_source'     => 'VARCHAR(30) NULL',
            'marketing_updated_at' => 'DATETIME NULL',
            'service_alerts'       => 'TINYINT(1) NOT NULL DEFAULT 1',
            'unsubscribed_at'      => 'DATETIME NULL',
        ];
        foreach ($columns as $column => $definition) {
            if (!column_exists('contacts', $column)) {
                db()->exec("ALTER TABLE contacts ADD COLUMN $column $definition");
            }
        }

        // Requests from staff that an admin must approve (close / delete a customer).
        db()->exec("CREATE TABLE IF NOT EXISTS approval_requests (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type          VARCHAR(30) NOT NULL,
            account_id    INT UNSIGNED NULL,
            account_label VARCHAR(255) NOT NULL,
            reason        TEXT NOT NULL,
            options       TEXT NULL,
            status        ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            requested_by  INT UNSIGNED NULL,
            decided_by    INT UNSIGNED NULL,
            decided_at    DATETIME NULL,
            decision_note VARCHAR(500) NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_approvals_status (status, created_at),
            CONSTRAINT fk_approvals_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
            CONSTRAINT fk_approvals_requester FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_approvals_decider FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Service alerts and marketing emails.
        db()->exec("CREATE TABLE IF NOT EXISTS campaigns (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference       VARCHAR(20) NULL UNIQUE,
            kind            ENUM('service_alert','marketing') NOT NULL,
            channel         ENUM('email','mailchimp') NOT NULL DEFAULT 'email',
            subject         VARCHAR(200) NOT NULL,
            body            TEXT NOT NULL,
            filters         TEXT NULL,
            status          ENUM('draft','sending','sent','failed','cancelled') NOT NULL DEFAULT 'draft',
            recipients      INT UNSIGNED NOT NULL DEFAULT 0,
            sent_count      INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count    INT UNSIGNED NOT NULL DEFAULT 0,
            mailchimp_id    VARCHAR(50) NULL,
            last_error      VARCHAR(500) NULL,
            created_by      INT UNSIGNED NULL,
            sent_by         INT UNSIGNED NULL,
            sent_at         DATETIME NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_campaigns_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_campaigns_sender FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS campaign_recipients (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT UNSIGNED NOT NULL,
            account_id  INT UNSIGNED NULL,
            contact_id  INT UNSIGNED NULL,
            email       VARCHAR(190) NOT NULL,
            name        VARCHAR(150) NULL,
            status      ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
            error       VARCHAR(500) NULL,
            sent_at     DATETIME NULL,
            UNIQUE KEY uq_campaign_email (campaign_id, email),
            KEY idx_cr_status (campaign_id, status),
            CONSTRAINT fk_cr_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
            CONSTRAINT fk_cr_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
            CONSTRAINT fk_cr_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },

    8 => function (): void {
        // Products: cost price, billing frequency, and the matching item in Xero.
        $columns = [
            'cost_price'        => 'DECIMAL(10,2) NULL AFTER monthly_price',
            'billing_frequency' => "ENUM('weekly','monthly','quarterly','biannually','yearly') NOT NULL DEFAULT 'monthly' AFTER cost_price",
            'xero_item_id'      => 'CHAR(36) NULL',
            'xero_synced_at'    => 'DATETIME NULL',
            'xero_sync_error'   => 'VARCHAR(500) NULL',
            'updated_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ];
        foreach ($columns as $column => $definition) {
            if (!column_exists('products', $column)) {
                db()->exec("ALTER TABLE products ADD COLUMN $column $definition");
            }
        }
    },

    9 => function (): void {
        // Nominal (account) codes per product, for sales and for purchases.
        foreach (['sales_account_code' => 'VARCHAR(20) NULL', 'purchase_account_code' => 'VARCHAR(20) NULL'] as $column => $definition) {
            if (!column_exists('products', $column)) {
                db()->exec("ALTER TABLE products ADD COLUMN $column $definition AFTER description");
            }
        }
        // Start from the defaults saved on the Xero page, if any.
        foreach (['sales_account_code' => 'xero_item_sales_account', 'purchase_account_code' => 'xero_item_purchase_account'] as $column => $setting) {
            if ($code = db_value('SELECT value FROM settings WHERE name = ?', [$setting])) {
                db_exec("UPDATE products SET $column = ? WHERE $column IS NULL", [$code]);
            }
        }
    },

    10 => function (): void {
        // Giacom: availability checks and broadband orders placed from the CRM.
        db()->exec("CREATE TABLE IF NOT EXISTS giacom_checks (
            id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id        INT UNSIGNED NULL,
            site_id           INT UNSIGNED NULL,
            postcode          VARCHAR(12) NULL,
            cli               VARCHAR(20) NULL,
            address_label     VARCHAR(255) NULL,
            address_reference VARCHAR(40) NULL,
            css_database_code VARCHAR(10) NULL,
            uprn              VARCHAR(20) NULL,
            address           TEXT NULL,
            result            MEDIUMTEXT NULL,
            created_by        INT UNSIGNED NULL,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_gc_account (account_id, created_at),
            CONSTRAINT fk_gchk_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
            CONSTRAINT fk_gchk_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL,
            CONSTRAINT fk_gchk_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS giacom_orders (
            id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            account_id         INT UNSIGNED NULL,
            site_id            INT UNSIGNED NULL,
            service_id         INT UNSIGNED NULL,
            check_id           INT UNSIGNED NULL,
            order_type         VARCHAR(20) NOT NULL,
            giacom_order_id    VARCHAR(20) NULL,
            giacom_service_id  VARCHAR(20) NULL,
            cli                VARCHAR(20) NULL,
            product_id         VARCHAR(20) NOT NULL,
            product_name       VARCHAR(190) NULL,
            technology_type    VARCHAR(40) NULL,
            broadband_username VARCHAR(190) NULL,
            address_label      VARCHAR(255) NULL,
            crd                DATE NULL,
            client_ref         VARCHAR(60) NULL,
            status             VARCHAR(100) NULL,
            status_updated_at  DATETIME NULL,
            completed_at       DATETIME NULL,
            details            TEXT NULL,
            last_error         VARCHAR(500) NULL,
            created_by         INT UNSIGNED NULL,
            created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_giacom_order (giacom_order_id),
            KEY idx_go_account (account_id),
            CONSTRAINT fk_gord_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
            CONSTRAINT fk_gord_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL,
            CONSTRAINT fk_gord_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
            CONSTRAINT fk_gord_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS giacom_order_events (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id   INT UNSIGNED NOT NULL,
            event_date DATETIME NULL,
            name       VARCHAR(60) NULL,
            value      VARCHAR(500) NULL,
            UNIQUE KEY uq_goe (order_id, event_date, name, value(150)),
            CONSTRAINT fk_goe_order FOREIGN KEY (order_id) REFERENCES giacom_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },

    11 => function (): void {
        // Ticket groups (queues): staff can be in several; tickets route to a group by category.
        db()->exec("CREATE TABLE IF NOT EXISTS ticket_groups (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(80) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            categories  VARCHAR(255) NULL,
            email       VARCHAR(190) NULL,
            active      TINYINT(1) NOT NULL DEFAULT 1,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS ticket_group_members (
            group_id INT UNSIGNED NOT NULL,
            user_id  INT UNSIGNED NOT NULL,
            PRIMARY KEY (group_id, user_id),
            CONSTRAINT fk_tgm_group FOREIGN KEY (group_id) REFERENCES ticket_groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_tgm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!column_exists('tickets', 'group_id')) {
            db()->exec('ALTER TABLE tickets ADD COLUMN group_id INT UNSIGNED NULL AFTER assigned_to, ADD KEY idx_tickets_queue (group_id, assigned_to, created_at)');
        }
        if (!constraint_exists('tickets', 'fk_tickets_group')) {
            db()->exec('ALTER TABLE tickets ADD CONSTRAINT fk_tickets_group FOREIGN KEY (group_id) REFERENCES ticket_groups(id) ON DELETE SET NULL');
        }
        if (!db_value('SELECT COUNT(*) FROM ticket_groups')) {
            foreach ([['Sales', 'New orders and upgrades', 'order'], ['Faults', 'Service faults and porting', 'fault,porting'],
                      ['Billing', 'Invoices, payments and cancellations', 'billing,cancellation'], ['General', 'Everything else', 'general']] as [$name, $desc, $cats]) {
                db_exec('INSERT INTO ticket_groups (name, description, categories) VALUES (?, ?, ?)', [$name, $desc, $cats]);
            }
        }
    },

    12 => function (): void {
        // Alert when a ticket waits too long in a queue without being picked up.
        if (!column_exists('ticket_groups', 'pickup_minutes')) {
            db()->exec('ALTER TABLE ticket_groups ADD COLUMN pickup_minutes INT UNSIGNED NULL AFTER email');
        }
        if (!column_exists('ticket_groups', 'alert_email')) {
            db()->exec('ALTER TABLE ticket_groups ADD COLUMN alert_email VARCHAR(190) NULL AFTER pickup_minutes');
        }
        if (!column_exists('tickets', 'pickup_alerted_at')) {
            db()->exec('ALTER TABLE tickets ADD COLUMN pickup_alerted_at DATETIME NULL AFTER group_id');
        }
    },
    13 => function (): void {
        // Document library (folders), files on customers and suppliers, and documents sent with quotes.
        db()->exec("CREATE TABLE IF NOT EXISTS doc_folders (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(80) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            sort        INT NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Suppliers, what they sell us and at what cost, and purchase orders.
        db()->exec("CREATE TABLE IF NOT EXISTS suppliers (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name           VARCHAR(150) NOT NULL,
            account_number VARCHAR(60) NULL COMMENT 'Our account number with them',
            category       VARCHAR(40) NULL,
            active         TINYINT(1) NOT NULL DEFAULT 1,
            contact_name   VARCHAR(120) NULL,
            email          VARCHAR(190) NULL COMMENT 'Where purchase orders go',
            accounts_email VARCHAR(190) NULL,
            support_email  VARCHAR(190) NULL,
            phone          VARCHAR(40) NULL,
            support_phone  VARCHAR(40) NULL,
            website        VARCHAR(255) NULL,
            portal_url     VARCHAR(255) NULL,
            address        VARCHAR(150) NULL,
            address2       VARCHAR(150) NULL,
            city           VARCHAR(80) NULL,
            county         VARCHAR(80) NULL,
            postcode       VARCHAR(12) NULL,
            payment_terms  VARCHAR(80) NULL,
            notes          TEXT NULL,
            price_file_mapping TEXT NULL,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_suppliers_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS documents (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            folder_id   INT UNSIGNED NULL,
            account_id  INT UNSIGNED NULL,
            supplier_id INT UNSIGNED NULL,
            title       VARCHAR(190) NOT NULL,
            description VARCHAR(500) NULL,
            file_name   VARCHAR(255) NOT NULL,
            stored_name VARCHAR(64) NOT NULL,
            mime        VARCHAR(100) NULL,
            size        INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by INT UNSIGNED NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_documents_folder (folder_id, title),
            CONSTRAINT fk_doc_folder FOREIGN KEY (folder_id) REFERENCES doc_folders(id),
            CONSTRAINT fk_doc_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
            CONSTRAINT fk_doc_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
            CONSTRAINT fk_doc_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS quote_documents (
            quote_id    INT UNSIGNED NOT NULL,
            document_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (quote_id, document_id),
            CONSTRAINT fk_qd_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
            CONSTRAINT fk_qd_doc FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS supplier_products (
            id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier_id       INT UNSIGNED NOT NULL,
            product_id        INT UNSIGNED NULL,
            supplier_sku      VARCHAR(80) NULL,
            description       VARCHAR(255) NOT NULL,
            cost_price        DECIMAL(10,2) NOT NULL DEFAULT 0,
            billing_frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
            setup_cost        DECIMAL(10,2) NULL,
            term_months       SMALLINT UNSIGNED NULL,
            lead_time_days    SMALLINT UNSIGNED NULL,
            preferred         TINYINT(1) NOT NULL DEFAULT 0,
            active            TINYINT(1) NOT NULL DEFAULT 1,
            notes             TEXT NULL,
            price_updated_at  DATETIME NULL,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_supplier_sku (supplier_id, supplier_sku),
            KEY idx_sp_product (product_id),
            CONSTRAINT fk_sp_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
            CONSTRAINT fk_sp_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference     VARCHAR(20) NULL UNIQUE,
            supplier_id   INT UNSIGNED NOT NULL,
            account_id    INT UNSIGNED NULL COMMENT 'Customer it is for, if any',
            status        VARCHAR(20) NOT NULL DEFAULT 'draft',
            order_date    DATE NULL,
            expected_date DATE NULL,
            supplier_ref  VARCHAR(80) NULL COMMENT 'Their order number',
            deliver_to    TEXT NULL,
            notes         TEXT NULL,
            total         DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_by    INT UNSIGNED NULL,
            sent_at       DATETIME NULL,
            received_at   DATETIME NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_po_supplier (supplier_id, created_at),
            CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
            CONSTRAINT fk_po_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
            CONSTRAINT fk_po_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS purchase_order_lines (
            id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            po_id               INT UNSIGNED NOT NULL,
            supplier_product_id INT UNSIGNED NULL,
            sku                 VARCHAR(80) NULL,
            description         VARCHAR(255) NOT NULL,
            quantity            INT UNSIGNED NOT NULL DEFAULT 1,
            unit_cost           DECIMAL(10,2) NOT NULL DEFAULT 0,
            sort                INT NOT NULL DEFAULT 0,
            CONSTRAINT fk_pol_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_pol_sp FOREIGN KEY (supplier_product_id) REFERENCES supplier_products(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Suppliers can come from (and link to) Xero contacts marked as suppliers.
        if (!column_exists('xero_contacts', 'is_supplier')) {
            db()->exec('ALTER TABLE xero_contacts ADD COLUMN is_supplier TINYINT(1) NOT NULL DEFAULT 0 AFTER status, ADD COLUMN is_customer TINYINT(1) NOT NULL DEFAULT 0 AFTER is_supplier, ADD COLUMN details TEXT NULL AFTER is_customer');
        }
        if (!column_exists('suppliers', 'xero_contact_id')) {
            db()->exec('ALTER TABLE suppliers ADD COLUMN xero_contact_id INT UNSIGNED NULL AFTER notes');
        }
        if (!constraint_exists('suppliers', 'fk_suppliers_xero')) {
            db()->exec('ALTER TABLE suppliers ADD CONSTRAINT fk_suppliers_xero FOREIGN KEY (xero_contact_id) REFERENCES xero_contacts(id) ON DELETE SET NULL');
        }
        if (!db_value('SELECT COUNT(*) FROM doc_folders')) {
            foreach ([['Sales & Marketing', 'Brochures, flyers and presentations'], ['Spec sheets', 'Product and service specifications'],
                      ['Terms & policies', 'Terms and conditions, SLAs and policies'], ['Price lists', 'Tariffs and price guides']] as $i => [$name, $desc]) {
                db_exec('INSERT INTO doc_folders (name, description, sort) VALUES (?, ?, ?)', [$name, $desc, $i]);
            }
        }
        // Giacom becomes the first supplier.
        if (!db_value('SELECT COUNT(*) FROM suppliers')) {
            db_exec("INSERT INTO suppliers (name, category, website, portal_url, notes) VALUES ('Giacom', 'Network', 'https://www.giacom.com', 'https://cloud.market',
                'Broadband and connectivity. Availability checks and orders are under Broadband orders.')");
        }
    },
    14 => function (): void {
        // A fuller record of how a quote was accepted, for the acceptance PDF.
        foreach ([
            'response_email'       => 'VARCHAR(190) NULL AFTER response_name',
            'response_user_agent'  => 'VARCHAR(255) NULL AFTER response_ip',
            'response_method'      => "VARCHAR(10) NULL AFTER response_user_agent",
            'response_recorded_by' => 'INT UNSIGNED NULL AFTER response_method',
            'response_statement'   => 'VARCHAR(500) NULL AFTER response_recorded_by',
            'response_fingerprint' => 'CHAR(64) NULL AFTER response_statement',
            'confirmation_sent_at' => 'DATETIME NULL AFTER response_fingerprint',
        ] as $col => $def) {
            if (!column_exists('quotes', $col)) {
                db()->exec("ALTER TABLE quotes ADD COLUMN $col $def");
            }
        }
    },
    15 => function (): void {
        // Customer orders: created when a quote is accepted, worked through by the onboarding team.
        db()->exec("CREATE TABLE IF NOT EXISTS customer_orders (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reference     VARCHAR(20) NULL UNIQUE,
            quote_id      INT UNSIGNED NULL UNIQUE,
            account_id    INT UNSIGNED NOT NULL,
            title         VARCHAR(200) NOT NULL,
            status        VARCHAR(20) NOT NULL DEFAULT 'accepted',
            assigned_to   INT UNSIGNED NULL,
            contact_name  VARCHAR(150) NULL,
            contact_email VARCHAR(190) NULL,
            token         CHAR(48) NULL UNIQUE,
            monthly_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            setup_total   DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes         TEXT NULL,
            picked_up_at  DATETIME NULL,
            confirmed_at  DATETIME NULL,
            completed_at  DATETIME NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_co_status (status, assigned_to),
            CONSTRAINT fk_co_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE SET NULL,
            CONSTRAINT fk_co_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
            CONSTRAINT fk_co_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS customer_order_events (
            id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id          INT UNSIGNED NOT NULL,
            status            VARCHAR(20) NULL COMMENT 'The step reached, or NULL for a note',
            message           TEXT NULL COMMENT 'What the customer was told',
            note              TEXT NULL COMMENT 'Internal only',
            emailed_to        VARCHAR(190) NULL,
            user_id           INT UNSIGNED NULL,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_coe_order (order_id, id),
            CONSTRAINT fk_coe_order FOREIGN KEY (order_id) REFERENCES customer_orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_coe_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // The onboarding team is a group like Sales or Faults: add people to it under Admin -> Ticket groups.
        $group = db_value("SELECT id FROM ticket_groups WHERE name = 'Onboarding'");
        if (!$group) {
            db_exec("INSERT INTO ticket_groups (name, description) VALUES ('Onboarding', 'Works through new orders from accepted quotes')");
            $group = db()->lastInsertId();
        }
        if (!db_value("SELECT 1 FROM settings WHERE name = 'order_group_id'")) {
            db_exec("INSERT INTO settings (name, value) VALUES ('order_group_id', ?)", [(string)$group]);
        }
    },
    16 => function (): void {
        // Purchase orders raised for a customer order, how each supplier is ordered from, and supplier invoices.
        if (!column_exists('purchase_orders', 'customer_order_id')) {
            db()->exec('ALTER TABLE purchase_orders ADD COLUMN customer_order_id INT UNSIGNED NULL AFTER account_id, ADD KEY idx_po_order (customer_order_id)');
        }
        if (!constraint_exists('purchase_orders', 'fk_po_customer_order')) {
            db()->exec('ALTER TABLE purchase_orders ADD CONSTRAINT fk_po_customer_order FOREIGN KEY (customer_order_id) REFERENCES customer_orders(id) ON DELETE SET NULL');
        }
        if (!column_exists('suppliers', 'ordering')) {
            db()->exec("ALTER TABLE suppliers ADD COLUMN ordering VARCHAR(10) NOT NULL DEFAULT 'email' AFTER category");
            db_exec("UPDATE suppliers SET ordering = 'api' WHERE name = 'Giacom'");
        }
        if (!column_exists('suppliers', 'vat_number')) {
            db()->exec('ALTER TABLE suppliers ADD COLUMN vat_number VARCHAR(30) NULL AFTER account_number');
        }
        db()->exec("CREATE TABLE IF NOT EXISTS supplier_invoices (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            supplier_id    INT UNSIGNED NULL,
            po_id          INT UNSIGNED NULL,
            status         VARCHAR(20) NOT NULL DEFAULT 'needs_review',
            invoice_number VARCHAR(80) NULL,
            invoice_date   DATE NULL,
            due_date       DATE NULL,
            po_reference   VARCHAR(255) NULL COMMENT 'As printed on the invoice',
            supplier_name  VARCHAR(190) NULL COMMENT 'As printed on the invoice',
            net            DECIMAL(12,2) NULL,
            vat            DECIMAL(12,2) NULL,
            total          DECIMAL(12,2) NULL,
            currency       CHAR(3) NULL,
            line_items     MEDIUMTEXT NULL,
            problems       TEXT NULL,
            reader         VARCHAR(10) NULL,
            read_error     VARCHAR(500) NULL,
            raw_text       MEDIUMTEXT NULL,
            file_name      VARCHAR(255) NOT NULL,
            stored_name    VARCHAR(64) NOT NULL,
            mime           VARCHAR(100) NULL,
            size           INT UNSIGNED NOT NULL DEFAULT 0,
            file_hash      CHAR(64) NULL,
            notes          TEXT NULL,
            approved_by    INT UNSIGNED NULL,
            approved_at    DATETIME NULL,
            created_by     INT UNSIGNED NULL,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_si_status (status),
            KEY idx_si_supplier (supplier_id, invoice_number),
            KEY idx_si_po (po_id),
            CONSTRAINT fk_si_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
            CONSTRAINT fk_si_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE SET NULL,
            CONSTRAINT fk_si_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_si_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
    17 => function (): void {
        // Supplier invoices sent to Xero as bills.
        foreach ([
            'xero_invoice_id' => 'CHAR(36) NULL AFTER notes',
            'xero_posted_at'  => 'DATETIME NULL AFTER xero_invoice_id',
            'xero_error'      => 'VARCHAR(500) NULL AFTER xero_posted_at',
            'xero_attached'   => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER xero_error',
        ] as $col => $def) {
            if (!column_exists('supplier_invoices', $col)) {
                db()->exec("ALTER TABLE supplier_invoices ADD COLUMN $col $def");
            }
        }
    },
    18 => function (): void {
        // One company record: a customer or dealer can also be a supplier (the supplier record is linked to it).
        if (!column_exists('suppliers', 'account_id')) {
            db()->exec('ALTER TABLE suppliers ADD COLUMN account_id INT UNSIGNED NULL AFTER id, ADD UNIQUE KEY uq_suppliers_account (account_id)');
        }
        if (!constraint_exists('suppliers', 'fk_suppliers_account')) {
            db()->exec('ALTER TABLE suppliers ADD CONSTRAINT fk_suppliers_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL');
        }
        // Link suppliers that already exist as a customer, where the name or Xero contact matches just one.
        $accounts = [];
        foreach (db_all('SELECT id, name, xero_contact_id FROM accounts') as $a) {
            $accounts['n:' . company_match_key($a['name'])][] = (int)$a['id'];
            if ($a['xero_contact_id']) {
                $accounts['x:' . $a['xero_contact_id']][] = (int)$a['id'];
            }
        }
        foreach (db_all('SELECT id, name, xero_contact_id FROM suppliers WHERE account_id IS NULL') as $s) {
            $match = ($s['xero_contact_id'] ? $accounts['x:' . $s['xero_contact_id']] ?? [] : []) ?: ($accounts['n:' . company_match_key($s['name'])] ?? []);
            if (count($match) === 1 && !db_value('SELECT 1 FROM suppliers WHERE account_id = ?', [$match[0]])) {
                db_exec('UPDATE suppliers SET account_id = ? WHERE id = ?', [$match[0], $s['id']]);
            }
        }
    },
    19 => function (): void {
        // A supplier's default nominal code for their bills in Xero.
        if (!column_exists('suppliers', 'purchase_account_code')) {
            db()->exec('ALTER TABLE suppliers ADD COLUMN purchase_account_code VARCHAR(20) NULL AFTER payment_terms');
        }
    },
    20 => function (): void {
        // VAT per product and supplier (including reverse charge), and reverse-charge invoices.
        foreach ([
            ['products', 'sales_tax_type', 'VARCHAR(50) NULL AFTER sales_account_code'],
            ['products', 'purchase_tax_type', 'VARCHAR(50) NULL AFTER purchase_account_code'],
            ['suppliers', 'purchase_tax_type', 'VARCHAR(50) NULL AFTER purchase_account_code'],
            ['supplier_invoices', 'reverse_charge', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER currency'],
        ] as [$table, $col, $def]) {
            if (!column_exists($table, $col)) {
                db()->exec("ALTER TABLE $table ADD COLUMN $col $def");
            }
        }
    },
    21 => function (): void {
        // Xero contacts merged into another: the archived one keeps details such as the account number.
        if (!column_exists('xero_contacts', 'merged_to')) {
            db()->exec('ALTER TABLE xero_contacts ADD COLUMN merged_to CHAR(36) NULL AFTER status, ADD KEY idx_xero_merged (merged_to)');
        }
    },
    22 => function (): void {
        // Products can be one-off (installation, hardware bought outright) as well as recurring.
        db()->exec("ALTER TABLE products MODIFY billing_frequency VARCHAR(20) NOT NULL DEFAULT 'monthly'");
    },
    23 => function (): void {
        // Temporary passwords for new users (changed at first sign-in), and who may sign in from any IP address.
        foreach ([
            'must_change_password'    => 'TINYINT(1) NOT NULL DEFAULT 0',
            'temp_password_expires_at' => 'DATETIME NULL',
            'ip_anywhere'             => 'TINYINT(1) NOT NULL DEFAULT 0',
        ] as $col => $def) {
            if (!column_exists('users', $col)) {
                db()->exec("ALTER TABLE users ADD COLUMN $col $def");
            }
        }
    },
    24 => function (): void {
        // Built-in e-signature (replacing Signable): the signing link, email code, and the evidence recorded.
        foreach ([
            'sign_token'        => 'CHAR(48) NULL',
            'viewed_at'         => 'DATETIME NULL',
            'viewed_ip'         => 'VARCHAR(45) NULL',
            'code_hash'         => 'VARCHAR(255) NULL',
            'code_expires_at'   => 'DATETIME NULL',
            'code_attempts'     => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'code_sent_at'      => 'DATETIME NULL',
            'code_sends'        => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'verified_at'       => 'DATETIME NULL',
            'signed_name'       => 'VARCHAR(150) NULL',
            'signed_position'   => 'VARCHAR(150) NULL',
            'signed_ip'         => 'VARCHAR(45) NULL',
            'signed_user_agent' => 'VARCHAR(255) NULL',
            'signed_statement'  => 'TEXT NULL',
            'document_hashes'   => 'TEXT NULL',
            'declined_reason'   => 'TEXT NULL',
            'reminders_sent'    => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'last_reminded_at'  => 'DATETIME NULL',
        ] as $col => $def) {
            if (!column_exists('contracts', $col)) {
                db()->exec("ALTER TABLE contracts ADD COLUMN $col $def");
            }
        }
        $indexes = db_all("SHOW INDEX FROM contracts WHERE Key_name = 'uq_contracts_sign_token'");
        if (!$indexes) {
            db()->exec('ALTER TABLE contracts ADD UNIQUE KEY uq_contracts_sign_token (sign_token)');
        }
        // Signable's settings aren't used any more. Its contract history (envelope references) is kept.
        db_exec("DELETE FROM settings WHERE name LIKE 'signable\\_%'");
    },
    25 => function (): void {
        // aBILLity (Giacom's billing platform): customers, products and services sent from the CRM.
        $add = [
            'accounts' => ['abillity_company_id' => 'INT UNSIGNED NULL', 'abillity_site_id' => 'INT UNSIGNED NULL', 'abillity_pending' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'abillity_synced_at' => 'DATETIME NULL', 'abillity_error' => 'VARCHAR(500) NULL'],
            'contacts' => ['abillity_contact_id' => 'INT UNSIGNED NULL'],
            'products' => ['abillity_charge_type_id' => 'INT UNSIGNED NULL', 'abillity_synced_at' => 'DATETIME NULL', 'abillity_error' => 'VARCHAR(500) NULL'],
            'services' => ['abillity_charge_id' => 'INT UNSIGNED NULL', 'abillity_setup_charge_id' => 'INT UNSIGNED NULL', 'abillity_first_payment' => 'DATE NULL',
                'abillity_provisional' => 'TINYINT(1) NOT NULL DEFAULT 0', 'abillity_last_payment' => 'DATE NULL', 'abillity_pending' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'abillity_synced_at' => 'DATETIME NULL', 'abillity_error' => 'VARCHAR(500) NULL'],
        ];
        foreach ($add as $table => $cols) {
            foreach ($cols as $col => $def) {
                if (!column_exists($table, $col)) {
                    db()->exec("ALTER TABLE $table ADD COLUMN $col $def");
                }
            }
        }
    },
    26 => function (): void {
        // Billing diary: every change to a service, to tick off when checking the month's billing.
        db()->exec("CREATE TABLE IF NOT EXISTS service_changes (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_id     INT UNSIGNED NULL,
            account_id     INT UNSIGNED NOT NULL,
            change_type    VARCHAR(20) NOT NULL,
            summary        VARCHAR(255) NOT NULL,
            from_value     VARCHAR(255) NULL,
            to_value       VARCHAR(255) NULL,
            monthly_change DECIMAL(10,2) NULL,
            one_off        DECIMAL(10,2) NULL,
            effective_date DATE NULL,
            identifier     VARCHAR(190) NULL,
            user_id        INT UNSIGNED NULL,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            checked_at     DATETIME NULL,
            checked_by     INT UNSIGNED NULL,
            check_note     VARCHAR(500) NULL,
            KEY idx_sc_created (created_at),
            KEY idx_sc_service (service_id),
            KEY idx_sc_account (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
    27 => function (): void {
        // Contract Summary before signing, the customer's size (Ofcom's rules protect all but larger businesses),
        // and a timestamped log of every step of signing.
        if (!column_exists('accounts', 'customer_size')) {
            db()->exec('ALTER TABLE accounts ADD COLUMN customer_size VARCHAR(20) NULL AFTER type');
        }
        foreach (['summary_ack_at' => 'DATETIME NULL', 'summary_ack_ip' => 'VARCHAR(45) NULL'] as $col => $def) {
            if (!column_exists('contracts', $col)) {
                db()->exec("ALTER TABLE contracts ADD COLUMN $col $def");
            }
        }
        db()->exec("CREATE TABLE IF NOT EXISTS contract_events (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            contract_id INT UNSIGNED NOT NULL,
            event       VARCHAR(40) NOT NULL,
            detail      TEXT NULL,
            ip          VARCHAR(45) NULL,
            user_agent  VARCHAR(255) NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ce_contract (contract_id, id),
            CONSTRAINT fk_ce_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
    28 => function (): void {
        // Giacom orders the CRM wrongly treated as cancelled when Giacom had refused the cancellation (before its
        // Sept 2026 reply format was handled): put the order back to be refreshed, and the service back to pending.
        $orders = db_all("SELECT o.* FROM giacom_orders o WHERE o.status = 'Cancellation requested'
            AND EXISTS (SELECT 1 FROM giacom_order_events e WHERE e.order_id = o.id AND e.name = 'cancel' AND e.value LIKE 'error:%')");
        foreach ($orders as $o) {
            db_exec("UPDATE giacom_orders SET status = 'Placed', last_error = 'Giacom refused the cancellation, so the order is still open. Refresh it to see its status.' WHERE id = ?", [$o['id']]);
            db_exec('INSERT IGNORE INTO giacom_order_events (order_id, event_date, name, value) VALUES (?, NOW(), ?, ?)',
                [$o['id'], 'cancel', 'Correction: Giacom refused the cancellation, so the order is still open']);
            $service = $o['service_id'] ? db_one('SELECT * FROM services WHERE id = ?', [$o['service_id']]) : null;
            if ($service && $service['status'] === 'ceased') {
                db_exec("UPDATE services SET status = 'pending' WHERE id = ?", [$service['id']]);
                if (function_exists('service_changed')) {
                    service_changed((int)$service['id'], $service);
                }
            }
        }
    },
];
