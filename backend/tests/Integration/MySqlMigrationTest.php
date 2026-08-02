<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
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

    public function testMigration044BackfillsPartnersAndAddsCompositeLedgerOwnership(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_partner_migration_test');
        try {
            $pdo = MySqlTestEnvironment::connect($database);
            $this->createLegacyPartnerSchema($pdo);
            $pdo->exec("INSERT INTO branches (id, name) VALUES (1, 'Main'), (2, 'Second')");
            $pdo->exec("INSERT INTO customers (id, name) VALUES (10, 'Customer')");
            $pdo->exec("INSERT INTO suppliers (id, name) VALUES (20, 'Supplier')");
            $pdo->exec('INSERT INTO invoices (id, branch_id, customer_id) VALUES (30, 2, 10)');
            $pdo->exec('INSERT INTO purchase_invoices (id, branch_id, supplier_id) VALUES (40, 2, 20)');
            $pdo->exec("INSERT INTO customer_ledger (id, customer_id, type, amount) VALUES (50, 10, 'debit', 5)");
            $pdo->exec("INSERT INTO supplier_ledger (id, supplier_id, type, amount) VALUES (60, 20, 'debit', 7)");

            MySqlTestEnvironment::applyMigration(
                $pdo,
                'database/migrations/044_scope_business_partners_by_branch.sql'
            );

            self::assertSame('2', (string) $pdo->query('SELECT branch_id FROM customers WHERE id = 10')->fetchColumn());
            self::assertSame('2', (string) $pdo->query('SELECT branch_id FROM suppliers WHERE id = 20')->fetchColumn());
            self::assertSame('2', (string) $pdo->query('SELECT branch_id FROM customer_ledger WHERE id = 50')->fetchColumn());
            self::assertSame('2', (string) $pdo->query('SELECT branch_id FROM supplier_ledger WHERE id = 60')->fetchColumn());

            $foreignKeyColumns = $pdo->prepare(
                'SELECT constraint_name, GROUP_CONCAT(column_name ORDER BY ordinal_position)
                 FROM information_schema.key_column_usage
                 WHERE table_schema = ?
                   AND constraint_name IN (\'fk_ledger_customer\', \'fk_sledger_supplier\')
                 GROUP BY constraint_name
                 ORDER BY constraint_name'
            );
            $foreignKeyColumns->execute([$database]);
            self::assertSame([
                'fk_ledger_customer' => 'branch_id,customer_id',
                'fk_sledger_supplier' => 'branch_id,supplier_id',
            ], $foreignKeyColumns->fetchAll(PDO::FETCH_KEY_PAIR));
        } finally {
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    public function testMigration044RejectsHistoricallySharedCustomer(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_partner_conflict_test');
        try {
            $pdo = MySqlTestEnvironment::connect($database);
            $this->createLegacyPartnerSchema($pdo);
            $pdo->exec("INSERT INTO branches (id, name) VALUES (1, 'Main'), (2, 'Second')");
            $pdo->exec("INSERT INTO customers (id, name) VALUES (10, 'Shared customer')");
            $pdo->exec('INSERT INTO invoices (id, branch_id, customer_id) VALUES (30, 1, 10), (31, 2, 10)');

            $this->expectException(PDOException::class);
            MySqlTestEnvironment::applyMigration(
                $pdo,
                'database/migrations/044_scope_business_partners_by_branch.sql'
            );
        } finally {
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    public function testMigration044HandlesLegacyLedgerForeignKeyNames(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_partner_legacy_fk_test');
        try {
            $pdo = MySqlTestEnvironment::connect($database);
            $this->createLegacyPartnerSchema($pdo, 'fk_cl_customer', 'fk_sl_supplier');
            $pdo->exec("INSERT INTO branches (id, name) VALUES (1, 'Main')");
            $pdo->exec("INSERT INTO customers (id, name) VALUES (10, 'Customer')");
            $pdo->exec("INSERT INTO suppliers (id, name) VALUES (20, 'Supplier')");

            MySqlTestEnvironment::applyMigration(
                $pdo,
                'database/migrations/044_scope_business_partners_by_branch.sql'
            );

            $foreignKeys = $pdo->prepare(
                'SELECT constraint_name
                 FROM information_schema.referential_constraints
                 WHERE constraint_schema = ?
                   AND table_name IN (\'customer_ledger\', \'supplier_ledger\')
                 ORDER BY constraint_name'
            );
            $foreignKeys->execute([$database]);
            $names = array_column($foreignKeys->fetchAll(PDO::FETCH_ASSOC), 'constraint_name');

            self::assertContains('fk_ledger_customer', $names);
            self::assertContains('fk_sledger_supplier', $names);
            self::assertNotContains('fk_cl_customer', $names);
            self::assertNotContains('fk_sl_supplier', $names);
        } finally {
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    private function createLegacyPartnerSchema(
        PDO $pdo,
        string $customerForeignKey = 'fk_ledger_customer',
        string $supplierForeignKey = 'fk_sledger_supplier'
    ): void
    {
        $statements = [
            'CREATE TABLE branches (id INT PRIMARY KEY, name VARCHAR(100) NOT NULL) ENGINE=InnoDB',
            'CREATE TABLE customers (
                id INT PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                deleted_at TIMESTAMP NULL
            ) ENGINE=InnoDB',
            'CREATE TABLE suppliers (
                id INT PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                deleted_at TIMESTAMP NULL
            ) ENGINE=InnoDB',
            'CREATE TABLE invoices (
                id INT PRIMARY KEY,
                branch_id INT NOT NULL,
                customer_id INT NULL
            ) ENGINE=InnoDB',
            'CREATE TABLE purchase_invoices (
                id INT PRIMARY KEY,
                branch_id INT NOT NULL,
                supplier_id INT NOT NULL
            ) ENGINE=InnoDB',
            "CREATE TABLE customer_ledger (
                id INT PRIMARY KEY,
                customer_id INT NOT NULL,
                type ENUM('debit', 'credit') NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT {$customerForeignKey} FOREIGN KEY (customer_id)
                    REFERENCES customers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB",
            "CREATE TABLE supplier_ledger (
                id INT PRIMARY KEY,
                supplier_id INT NOT NULL,
                type ENUM('debit', 'credit') NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT {$supplierForeignKey} FOREIGN KEY (supplier_id)
                    REFERENCES suppliers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB",
            'CREATE TABLE inventory_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB',
        ];

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }
}
