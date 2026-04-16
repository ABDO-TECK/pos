<?php

return new class {
    public function up(PDO $db): void {
        try {
                $db->exec(
                    'ALTER TABLE purchases
                     ADD COLUMN purchase_invoice_id INT UNSIGNED NULL AFTER id'
                );
            } catch (Throwable $e) {}
    }
};