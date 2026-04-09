<?php

/**
 * Lightweight migration runner.
 * Each migration is keyed by a unique name; once applied it is recorded
 * in the `migrations` table and never runs again.
 */
class Migrations {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->bootstrap();
    }

    private function bootstrap(): void {
        // Migrations tracking table
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name       VARCHAR(200) NOT NULL UNIQUE,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function applied(string $name): bool {
        $stmt = $this->db->prepare('SELECT 1 FROM migrations WHERE name = ?');
        $stmt->execute([$name]);
        return (bool)$stmt->fetchColumn();
    }

    private function mark(string $name): void {
        $this->db->prepare('INSERT IGNORE INTO migrations (name) VALUES (?)')->execute([$name]);
    }

    public function run(): void {
        // ── 001: payment_method ENUM update ──────────────────────
        if (!$this->applied('001_payment_method_enum')) {
            try {
                $this->db->exec(
                    "ALTER TABLE invoices
                     MODIFY COLUMN payment_method
                     ENUM('cash','card','vodafone_cash','instapay','other_wallet','credit')
                     NOT NULL DEFAULT 'cash'"
                );
            } catch (Throwable $e) {
                // Column may already have correct ENUM — ignore
                error_log('Migration 001 notice: ' . $e->getMessage());
            }
            $this->mark('001_payment_method_enum');
        }

        // ── 002: product_barcodes (multiple barcodes per product) ─
        if (!$this->applied('002_product_barcodes')) {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS product_barcodes (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    product_id INT UNSIGNED NOT NULL,
                    barcode VARCHAR(100) NOT NULL,
                    UNIQUE KEY uq_product_barcodes_barcode (barcode),
                    KEY idx_product_barcodes_product (product_id),
                    CONSTRAINT fk_pb_product FOREIGN KEY (product_id)
                        REFERENCES products(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $this->mark('002_product_barcodes');
        }

        // ── 003: add 'credit' to payment_method ENUM (fix for DBs that ran 001 without it) ─
        if (!$this->applied('003_payment_method_credit')) {
            try {
                $this->db->exec(
                    "ALTER TABLE invoices
                     MODIFY COLUMN payment_method
                     ENUM('cash','card','vodafone_cash','instapay','other_wallet','credit')
                     NOT NULL DEFAULT 'cash'"
                );
            } catch (Throwable $e) {
                error_log('Migration 003 notice: ' . $e->getMessage());
            }
            $this->mark('003_payment_method_credit');
        }

        // ── 004: invoice_items.unit_cost — تكلفة لحظة البيع (تقارير أرباح صحيحة) ─
        if (!$this->applied('004_invoice_items_unit_cost')) {
            try {
                $this->db->exec(
                    'ALTER TABLE invoice_items
                     ADD COLUMN unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00
                     COMMENT "تكلفة الوحدة لحظة البيع"
                     AFTER price'
                );
            } catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'Duplicate column')) {
                    error_log('Migration 004 add column: ' . $e->getMessage());
                }
            }
            try {
                $this->db->exec(
                    'UPDATE invoice_items ii
                     INNER JOIN products p ON p.id = ii.product_id
                     SET ii.unit_cost = p.cost'
                );
            } catch (Throwable $e) {
                error_log('Migration 004 backfill: ' . $e->getMessage());
            }
            $this->mark('004_invoice_items_unit_cost');
        }
    }
}
