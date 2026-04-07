-- ============================================================
-- Migration: Settings table + Payment method ENUM update
-- ============================================================

USE pos_db;

-- ── Settings table ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
    `key`   VARCHAR(100) NOT NULL PRIMARY KEY,
    `value` TEXT         NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (`key`, `value`) VALUES
    ('store_name',   'سوبر ماركت'),
    ('tax_enabled',  '1'),
    ('tax_rate',     '15')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- ── Update payment_method ENUM ─────────────────────────────
ALTER TABLE invoices
    MODIFY COLUMN payment_method
    ENUM('cash','card','vodafone_cash','instapay','other_wallet')
    NOT NULL DEFAULT 'cash';
