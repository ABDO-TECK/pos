<?php

return new class {
    public function up(PDO $db): void {
        try {
                $db->exec(
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
    }
};