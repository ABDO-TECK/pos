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
                     ENUM('cash','card','vodafone_cash','instapay','other_wallet')
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
    }
}
