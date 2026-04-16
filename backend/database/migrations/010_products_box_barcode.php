<?php

return new class {
    public function up(PDO $db): void {
        try {
                $this->db->exec(
                    'ALTER TABLE products
                     ADD COLUMN box_barcode VARCHAR(100) NULL DEFAULT NULL UNIQUE AFTER barcode'
                );
            } catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'Duplicate column')) {
                    Logger::warning('Migration 010 box_barcode', ['error' => $e->getMessage()]);
                }
            }
            try {
                $this->db->exec(
                    'ALTER TABLE products
                     ADD COLUMN units_per_box INT NOT NULL DEFAULT 1 COMMENT "عدد القطع في الصندوق الواحد" AFTER low_stock_threshold'
                );
            } catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'Duplicate column')) {
                    Logger::warning('Migration 010 units_per_box', ['error' => $e->getMessage()]);
                }
            }
    }
};