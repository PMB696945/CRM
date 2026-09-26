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
];
