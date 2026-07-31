<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductCatalogMigrationTest extends TestCase
{
    public function testCatalogMigrationProvidesBranchSequenceTriggersAndRollback(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 3) . '/database/migrations/043_add_product_catalog_changes.sql'
        );
        $rollback = file_get_contents(
            dirname(__DIR__, 3) . '/database/migrations/rollback/043_add_product_catalog_changes.sql'
        );

        $this->assertIsString($migration);
        $this->assertStringContainsString(
            'idx_catalog_changes_branch_sequence (branch_id, id)',
            $migration
        );
        $this->assertStringContainsString('AFTER INSERT ON products', $migration);
        $this->assertStringContainsString('AFTER UPDATE ON products', $migration);
        $this->assertStringContainsString('AFTER DELETE ON products', $migration);
        $this->assertIsString($rollback);
        $this->assertStringContainsString('DROP TABLE IF EXISTS product_catalog_changes', $rollback);
    }
}
