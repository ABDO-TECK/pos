<?php

return new class {
    public function up(PDO $db): void {
        try {
                $db->exec(
                    'ALTER TABLE invoice_items
                     ADD COLUMN unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00
                     COMMENT "تكلفة الوحدة لحظة البيع"
                     AFTER price'
                );
            } catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'Duplicate column')) {
                    Logger::warning('Migration 004 add column', ['error' => $e->getMessage()]);
                }
            }
            try {
                $db->exec(
                    'UPDATE invoice_items ii
                     INNER JOIN products p ON p.id = ii.product_id
                     SET ii.unit_cost = p.cost'
                );
            } catch (Throwable $e) {
                Logger::warning('Migration 004 backfill', ['error' => $e->getMessage()]);
            }
    }
};