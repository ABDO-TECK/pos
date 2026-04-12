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
                Logger::warning('Migration 001 notice', ['error' => $e->getMessage()]);
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
                Logger::warning('Migration 003 notice', ['error' => $e->getMessage()]);
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
                    Logger::warning('Migration 004 add column', ['error' => $e->getMessage()]);
                }
            }
            try {
                $this->db->exec(
                    'UPDATE invoice_items ii
                     INNER JOIN products p ON p.id = ii.product_id
                     SET ii.unit_cost = p.cost'
                );
            } catch (Throwable $e) {
                Logger::warning('Migration 004 backfill', ['error' => $e->getMessage()]);
            }
            $this->mark('004_invoice_items_unit_cost');
        }

        // ── 005: suppliers.initial_balance — رصيد مبدئي للمورد ──────────
        if (!$this->applied('005_suppliers_initial_balance')) {
            try {
                $this->db->exec(
                    'ALTER TABLE suppliers
                     ADD COLUMN initial_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00
                     COMMENT "رصيد مبدئي — لمورد قديم له دين مسبق"
                     AFTER address'
                );
            } catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'Duplicate column')) {
                    Logger::warning('Migration 005', ['error' => $e->getMessage()]);
                }
            }
            $this->mark('005_suppliers_initial_balance');
        }

        // ── 006: supplier_ledger — كشف حساب المورد (مثل customer_ledger) ─
        if (!$this->applied('006_supplier_ledger')) {
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS supplier_ledger (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    supplier_id INT UNSIGNED NOT NULL,
                    type ENUM("debit","credit") NOT NULL COMMENT "debit=مدين (مشتريات آجلة), credit=دائن (دفعات للمورد)",
                    amount DECIMAL(10,2) NOT NULL,
                    description VARCHAR(500) NULL COMMENT "البيان: فاتورة شراء / دفعة نقدية / رصيد مبدئي...",
                    purchase_invoice_id INT UNSIGNED NULL COMMENT "رابط لفاتورة المشتريات إن وجدت",
                    created_by INT UNSIGNED NULL COMMENT "معرف المستخدم الذي سجّل القيد",
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_sledger_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
                    CONSTRAINT fk_sledger_pinvoice FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL,
                    INDEX idx_supplier_ledger (supplier_id),
                    INDEX idx_sledger_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $this->mark('006_supplier_ledger');
        }

        // ── 010: products.box_barcode & units_per_box (Fix for old backups) ──
        if (!$this->applied('010_products_box_barcode')) {
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
            $this->mark('010_products_box_barcode');
        }

        // ── 011: invoices.customer_id & amount_due (Fix for old backups) ──
        if (!$this->applied('011_invoices_credit_columns')) {
            try {
                $this->db->exec(
                    'ALTER TABLE invoices
                     ADD COLUMN customer_id INT UNSIGNED NULL COMMENT "رابط العميل — فارغ للمبيعات النقدية" AFTER user_id'
                );
            } catch (Throwable $e) {}
            try {
                $this->db->exec(
                    'ALTER TABLE invoices
                     ADD COLUMN amount_due DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT "المتبقي على ذمة العميل بعد خصم العربون" AFTER change_due'
                );
            } catch (Throwable $e) {}
            try {
                $this->db->exec(
                    'ALTER TABLE invoices
                     ADD CONSTRAINT fk_invoice_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL'
                );
            } catch (Throwable $e) {}
            $this->mark('011_invoices_credit_columns');
        }

        // ── 012: purchases.purchase_invoice_id (Fix for old backups) ──────
        if (!$this->applied('012_purchases_invoice_link')) {
            try {
                $this->db->exec(
                    'ALTER TABLE purchases
                     ADD COLUMN purchase_invoice_id INT UNSIGNED NULL AFTER id'
                );
            } catch (Throwable $e) {}
            $this->mark('012_purchases_invoice_link');
        }

        // ── 007: Auto-cleanup expired tokens ──────────────────────────
        // يُنفَّذ في كل طلب لكن خفيف الحمل — يحذف Tokens المنتهية فقط
        if (!$this->applied('007_token_cleanup_event')) {
            try {
                // إنشاء event لتنظيف الـ tokens (يومياً)
                // إذا كان scheduler غير مفعّل — نحذف يدوياً
                $this->db->exec('DELETE FROM tokens WHERE expires_at IS NOT NULL AND expires_at < NOW()');
            } catch (Throwable $e) {
                Logger::warning('Migration 007', ['error' => $e->getMessage()]);
            }
            $this->mark('007_token_cleanup_event');
        }

        // تنظيف Tokens المنتهية دورياً (بدون migration — يُنفَّذ كل 100 طلب تقريباً)
        try {
            if (mt_rand(1, 100) === 1) {
                $this->db->exec('DELETE FROM tokens WHERE expires_at IS NOT NULL AND expires_at < NOW()');
            }
        } catch (Throwable) {}

        // ── 008: Composite indexes for reports performance ───────────
        if (!$this->applied('008_report_indexes')) {
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
            $this->mark('008_report_indexes');
        }

        // ── 009: Log cleanup + Rate Limit cleanup ────────────────────
        if (!$this->applied('009_cleanup_setup')) {
            // تنظيف ملفات اللوج القديمة
            try {
                Logger::cleanup();
                (new RateLimiter())->cleanup();
            } catch (Throwable) {}
            $this->mark('009_cleanup_setup');
        }

        // تنظيف دوري (مرة كل ~200 طلب)
        try {
            if (mt_rand(1, 200) === 1) {
                Logger::cleanup();
                (new RateLimiter())->cleanup();
            }
        } catch (Throwable) {}
    }
}
