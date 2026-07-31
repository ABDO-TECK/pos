<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlTestEnvironment;

require_once __DIR__ . '/../Integration/Support/MySqlTestEnvironment.php';

final class MySqlMigrationParserTest extends TestCase
{
    public function testCommentSemicolonsDoNotHideTheIdempotencyTableDefinition(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 3) . '/database/migrations/042_add_sale_idempotency.sql');
        self::assertNotFalse($migration);

        $statements = MySqlTestEnvironment::splitMigrationStatements($migration);

        self::assertCount(1, $statements);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS sale_idempotency_keys', $statements[0]);
        self::assertStringContainsString('idempotency_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin', $statements[0]);
        self::assertStringContainsString('request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin', $statements[0]);
    }

    public function testCatalogMigrationKeepsEachTriggerAsItsOwnStatement(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 3) . '/database/migrations/043_add_product_catalog_changes.sql');
        self::assertNotFalse($migration);

        $statements = MySqlTestEnvironment::splitMigrationStatements($migration);

        self::assertCount(7, $statements);
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS product_catalog_changes', $statements[0]);
        self::assertStringStartsWith('CREATE TRIGGER trg_products_catalog_insert', $statements[2]);
        self::assertStringStartsWith('CREATE TRIGGER trg_products_catalog_update', $statements[4]);
        self::assertStringStartsWith('CREATE TRIGGER trg_products_catalog_delete', $statements[6]);
    }
}
