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
];
