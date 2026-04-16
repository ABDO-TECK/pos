<?php

return new class {
    public function up(PDO $db): void {
        $db->exec(
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
    }
};