<?php

return new class {
    public function up(PDO $db): void {
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
    }
};