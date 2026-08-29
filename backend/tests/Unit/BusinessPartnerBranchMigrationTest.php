<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BusinessPartnerBranchMigrationTest extends TestCase
{
    public function testMigrationScopesBusinessPartnersAndHasManualRollback(): void
    {
        $root = dirname(__DIR__, 3);
        $migration = file_get_contents(
            $root . '/database/migrations/044_scope_business_partners_by_branch.sql'
        );
        $rollback = file_get_contents(
            $root . '/database/migrations/rollback/044_scope_business_partners_by_branch.sql'
        );

        self::assertIsString($migration);
        self::assertStringContainsString('ALTER TABLE customers ADD COLUMN branch_id', $migration);
        self::assertStringContainsString('ALTER TABLE suppliers ADD COLUMN branch_id', $migration);
        self::assertStringContainsString('fk_ledger_customer FOREIGN KEY (branch_id, customer_id)', $migration);
        self::assertStringContainsString('fk_sledger_supplier FOREIGN KEY (branch_id, supplier_id)', $migration);
        self::assertStringContainsString('HAVING COUNT(DISTINCT branch_id) > 1', $migration);
        self::assertStringContainsString('CREATE EVENT IF NOT EXISTS cleanup_inventory_events', $migration);

        self::assertIsString($rollback);
        self::assertStringContainsString('DROP EVENT IF EXISTS cleanup_inventory_events', $rollback);
        self::assertStringContainsString('ALTER TABLE customers DROP COLUMN branch_id', $rollback);
        self::assertStringContainsString('ALTER TABLE suppliers DROP COLUMN branch_id', $rollback);
    }
}
