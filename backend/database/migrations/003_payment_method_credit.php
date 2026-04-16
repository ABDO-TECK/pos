<?php

return new class {
    public function up(PDO $db): void {
        try {
                $db->exec(
                    "ALTER TABLE invoices
                     MODIFY COLUMN payment_method
                     ENUM('cash','card','vodafone_cash','instapay','other_wallet','credit')
                     NOT NULL DEFAULT 'cash'"
                );
            } catch (Throwable $e) {
                Logger::warning('Migration 003 notice', ['error' => $e->getMessage()]);
            }
    }
};