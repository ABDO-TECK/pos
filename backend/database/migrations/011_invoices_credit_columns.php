<?php

return new class {
    public function up(PDO $db): void {
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
    }
};