<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlTestEnvironment;

require_once __DIR__ . '/Support/MySqlTestEnvironment.php';

#[Group('mysql')]
final class MySqlMigrationTest extends TestCase
{
    public function testMigrations042And043ApplyToDisposableMySql(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_migration_test');
        try {
            $pdo = MySqlTestEnvironment::connect($database);
            MySqlTestEnvironment::createMigrationPrerequisites($pdo);
            MySqlTestEnvironment::applyMigration($pdo, 'database/migrations/042_add_sale_idempotency.sql');
            MySqlTestEnvironment::applyMigration($pdo, 'database/migrations/043_add_product_catalog_changes.sql');

            $uniqueIndex = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.statistics
                 WHERE table_schema = ? AND table_name = \'sale_idempotency_keys\'
                   AND index_name = \'uq_sale_idempotency_branch_key\' AND non_unique = 0'
            );
            $uniqueIndex->execute([$database]);
            self::assertSame(2, (int) $uniqueIndex->fetchColumn(), 'The unique key must cover both indexed columns.');

            $collations = $pdo->prepare(
                'SELECT column_name, collation_name
                 FROM information_schema.columns
                 WHERE table_schema = ? AND table_name = \'sale_idempotency_keys\'
                   AND column_name IN (\'idempotency_key\', \'request_hash\')'
            );
            $collations->execute([$database]);
            self::assertSame([
                'idempotency_key' => 'ascii_bin',
                'request_hash' => 'ascii_bin',
            ], $collations->fetchAll(PDO::FETCH_KEY_PAIR));

            $triggerCount = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.triggers
                 WHERE trigger_schema = ? AND event_object_table = \'products\''
            );
            $triggerCount->execute([$database]);
            self::assertSame(3, (int) $triggerCount->fetchColumn());

            $pdo->exec("INSERT INTO branches (id, name) VALUES (1, 'Migration branch')");
            $pdo->exec("INSERT INTO products (id, branch_id, name) VALUES (7, 1, 'Original')");
            $pdo->exec("UPDATE products SET name = 'Updated' WHERE id = 7");
            $pdo->exec('DELETE FROM products WHERE id = 7');

            self::assertSame(
                [
                    ['branch_id' => 1, 'product_id' => 7],
                    ['branch_id' => 1, 'product_id' => 7],
                    ['branch_id' => 1, 'product_id' => 7],
                ],
                array_map(
                    static fn (array $row): array => [
                        'branch_id' => (int) $row['branch_id'],
                        'product_id' => (int) $row['product_id'],
                    ],
                    $pdo->query(
                        'SELECT branch_id, product_id FROM product_catalog_changes ORDER BY id'
                    )->fetchAll(PDO::FETCH_ASSOC)
                )
            );
        } finally {
            MySqlTestEnvironment::dropDatabase($database);
        }
    }
}
