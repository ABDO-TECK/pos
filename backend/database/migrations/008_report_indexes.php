<?php

return new class {
    public function up(PDO $db): void {
        $indexes = [
                'ALTER TABLE invoices ADD INDEX idx_status_created (status, created_at)',
                'ALTER TABLE invoice_items ADD INDEX idx_invoice_product (invoice_id, product_id)',
                'ALTER TABLE purchases ADD INDEX idx_supplier_created (supplier_id, created_at)',
                'ALTER TABLE customer_ledger ADD INDEX idx_customer_type (customer_id, type)',
                'ALTER TABLE supplier_ledger ADD INDEX idx_supplier_type (supplier_id, type)',
            ];
            foreach ($indexes as $sql) {
                try {
                    $this->db->exec($sql);
                } catch (Throwable $e) {
                    // الفهرس قد يكون موجوداً — تجاهل
                    if (!str_contains($e->getMessage(), 'Duplicate key name')) {
                        Logger::warning('Migration 008 index', ['sql' => $sql, 'error' => $e->getMessage()]);
                    }
                }
            }
    }
};