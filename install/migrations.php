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
];
