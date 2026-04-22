<?php

return new class {
    public function up(PDO $db): void
    {
        // 1) Add sell_by_weight column to products
        $cols = array_column(
            $db->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );

        if (!in_array('sell_by_weight', $cols, true)) {
            $db->exec('ALTER TABLE products ADD COLUMN sell_by_weight TINYINT(1) NOT NULL DEFAULT 0 AFTER units_per_box');
        }

        // 2) Change invoice_items.quantity to DECIMAL(10,3) to support fractional weights
        $iiCol = $db->query("SHOW COLUMNS FROM invoice_items LIKE 'quantity'")->fetch(PDO::FETCH_ASSOC);
        if ($iiCol && stripos($iiCol['Type'], 'decimal') === false) {
            $db->exec('ALTER TABLE invoice_items MODIFY COLUMN quantity DECIMAL(10,3) NOT NULL');
        }

        // 3) Change purchases.quantity to DECIMAL(10,3) if the table exists
        try {
            $pCol = $db->query("SHOW COLUMNS FROM purchases LIKE 'quantity'")->fetch(PDO::FETCH_ASSOC);
            if ($pCol && stripos($pCol['Type'], 'decimal') === false) {
                $db->exec('ALTER TABLE purchases MODIFY COLUMN quantity DECIMAL(10,3) NOT NULL');
            }
        } catch (Throwable $e) {
            // purchases table may not exist
        }
    }
};
