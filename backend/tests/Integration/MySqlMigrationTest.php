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
            $names = array_map('strval', $foreignKeys->fetchAll(PDO::FETCH_COLUMN));

            self::assertContains('fk_ledger_customer', $names);
            self::assertContains('fk_sledger_supplier', $names);
            self::assertNotContains('fk_cl_customer', $names);
            self::assertNotContains('fk_sl_supplier', $names);
        } finally {
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    public function testMigration044ExecutesSafelyWhenNoLegacyForeignKeysExist(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_partner_no_fk_test');
        try {
            $pdo = MySqlTestEnvironment::connect($database);
            $this->createLegacyPartnerSchemaWithoutForeignKeys($pdo);
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
            $names = array_map('strval', $foreignKeys->fetchAll(PDO::FETCH_COLUMN));

            self::assertContains('fk_ledger_customer', $names);
            self::assertContains('fk_sledger_supplier', $names);
        } finally {
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    private function createLegacyPartnerSchemaWithoutForeignKeys(PDO $pdo): void
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
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB",
            "CREATE TABLE supplier_ledger (
                id INT PRIMARY KEY,
                supplier_id INT NOT NULL,
                type ENUM('debit', 'credit') NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
    public function testMigration032LeastPrivilegeAndIdempotency(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_mig032_test');
        $rootPdo = MySqlTestEnvironment::connect($database);
        $restrictedAccount = null;

        try {
            // Create baseline tables required by Migration 032
            $rootPdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    email VARCHAR(100) NOT NULL UNIQUE
                ) ENGINE=InnoDB;
                CREATE TABLE IF NOT EXISTS tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token VARCHAR(64) NOT NULL,
                    expires_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;
                CREATE TABLE IF NOT EXISTS refresh_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    token VARCHAR(64) NOT NULL,
                    family_id VARCHAR(64) NOT NULL,
                    expires_at TIMESTAMP NOT NULL,
                    used_at TIMESTAMP NULL,
                    revoked_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;
            ");

            // Create a restricted user with ONLY db-level privileges (NO SUPER)
            $restrictedAccount = MySqlTestEnvironment::createRestrictedUser($rootPdo, $database, 'pos_test');
            $restrictedPdo = MySqlTestEnvironment::connectAs(
                $restrictedAccount['username'],
                $restrictedAccount['password'],
                $database
            );

            // Explicitly assert least-privilege grants
            MySqlTestEnvironment::assertDatabaseScopedPrivilegesOnly($restrictedPdo, $database);

            // Scenario A: Fresh execution with expired & active tokens
            $restrictedPdo->exec("
                INSERT INTO tokens (user_id, token, expires_at) VALUES
                (1, 'expired_access', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR)),
                (1, 'active_access', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 HOUR));
                INSERT INTO refresh_tokens (user_id, token, family_id, expires_at) VALUES
                (1, 'expired_refresh', 'fam1', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR)),
                (1, 'active_refresh', 'fam1', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 HOUR));
            ");

            MySqlTestEnvironment::applyMigration($restrictedPdo, 'database/migrations/032_cleanup_expired_tokens.sql');

            // Verify expired tokens were purged and active tokens remain
            $activeTokens = $restrictedPdo->query("SELECT token FROM tokens ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
            self::assertSame(['active_access'], $activeTokens);

            $activeRefresh = $restrictedPdo->query("SELECT token FROM refresh_tokens ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
            self::assertSame(['active_refresh'], $activeRefresh);

            // Scenario B: Idempotent rerun after partial application
            MySqlTestEnvironment::applyMigration($restrictedPdo, 'database/migrations/032_cleanup_expired_tokens.sql');
            self::assertCount(1, $restrictedPdo->query("SELECT token FROM tokens")->fetchAll());

            // Scenario C: Legacy event already exists (dropped cleanly by restricted user)
            // Temporarily create event defined by restricted user to simulate legacy event state
            $rootPdo->exec(sprintf(
                "CREATE DEFINER = `%s`@`%s` EVENT IF NOT EXISTS cleanup_expired_tokens
                ON SCHEDULE EVERY 1 DAY
                DO DELETE FROM tokens WHERE expires_at < UTC_TIMESTAMP();",
                $restrictedAccount['username'],
                $restrictedAccount['host']
            ));
            MySqlTestEnvironment::applyMigration($restrictedPdo, 'database/migrations/032_cleanup_expired_tokens.sql');

            $events = $restrictedPdo->query("SHOW EVENTS FROM `{$database}` LIKE 'cleanup_expired_tokens'")->fetchAll();
            self::assertEmpty($events, 'The cleanup event must be dropped.');
        } finally {
            if ($restrictedAccount !== null) {
                MySqlTestEnvironment::dropUser($rootPdo, $restrictedAccount['username'], $restrictedAccount['host']);
            }
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    public function testFullMigrationServiceWithRestrictedUser(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_full_mig_test');
        $rootPdo = MySqlTestEnvironment::connect($database);
        $migrationAccount = null;
        $runtimeAccount = null;

        try {
            // Load baseline schema into the disposable database
            $schemaPath = dirname(__DIR__, 3) . '/database/pos_schema.sql';
            $schemaSql = file_get_contents($schemaPath);
            self::assertNotFalse($schemaSql, "pos_schema.sql must exist at {$schemaPath}");
            foreach (MySqlTestEnvironment::splitMigrationStatements($schemaSql) as $statement) {
                if (preg_match('/^\s*(CREATE DATABASE|USE)\b/i', $statement)) {
                    continue;
                }
                $rootPdo->exec($statement);
            }

            // 1. Create dedicated migration user (pos_migration) with database-scoped schema administration
            $migrationAccount = MySqlTestEnvironment::createMigrationUser($rootPdo, $database, 'pos_migration');
            $migrationPdo = MySqlTestEnvironment::connectAs(
                $migrationAccount['username'],
                $migrationAccount['password'],
                $database
            );

            // 2. Create runtime user (pos_app) with strictly least-privilege DML grants
            $runtimeAccount = MySqlTestEnvironment::createRuntimeUser($rootPdo, $database, 'pos_app');
            $runtimePdo = MySqlTestEnvironment::connectAs(
                $runtimeAccount['username'],
                $runtimeAccount['password'],
                $database
            );

            // Assert both identities are strictly database-scoped without global SUPER/SYSTEM_USER
            MySqlTestEnvironment::assertDatabaseScopedPrivilegesOnly($migrationPdo, $database);
            MySqlTestEnvironment::assertDatabaseScopedPrivilegesOnly($runtimePdo, $database);

            // Assert runtime user cannot create triggers (least privilege)
            try {
                $runtimePdo->exec("CREATE TRIGGER trg_forbidden AFTER INSERT ON products FOR EACH ROW SET @x = 1");
                self::fail('Runtime user (pos_app) must NOT have permission to create triggers');
            } catch (PDOException $e) {
                self::assertContains((int) ($e->errorInfo[1] ?? $e->getCode()), [1142, 1419], 'Runtime trigger attempt must fail with permission denied');
            }

            // Run MigrationService using dedicated migration connection
            $migrationService = new \App\Services\MigrationService($migrationPdo);
            $result = $migrationService->runAllMigrations(true);

            self::assertSame([], $result['errors'], 'All migrations must pass without errors under migration account');
            self::assertGreaterThan(0, $result['executed']);

            // Verify Migration 043's three catalog triggers physically exist in information_schema.TRIGGERS
            $stmt = $migrationPdo->prepare(
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME'
            );
            $stmt->execute([$database]);
            $triggers = $stmt->fetchAll(PDO::FETCH_COLUMN);
            self::assertContains('trg_products_catalog_insert', $triggers, 'trg_products_catalog_insert must physically exist');
            self::assertContains('trg_products_catalog_update', $triggers, 'trg_products_catalog_update must physically exist');
            self::assertContains('trg_products_catalog_delete', $triggers, 'trg_products_catalog_delete must physically exist');

            // Verify normal runtime operations pass under pos_app (runtime account)
            $runtimePdo->exec("INSERT INTO categories (id, name) VALUES (100, 'General')");
            $runtimePdo->exec("INSERT INTO products (id, branch_id, name, barcode, price, cost, quantity) VALUES (100, 1, 'Product A', 'BAR100', 50.00, 30.00, 100)");
            $runtimePdo->exec("UPDATE products SET price = 55.00 WHERE id = 100");
            $runtimePdo->exec("DELETE FROM products WHERE id = 100");

            // Verify triggers fired and inserted audit records into product_catalog_changes
            $catalogChanges = $runtimePdo->query("SELECT product_id, branch_id FROM product_catalog_changes WHERE product_id = 100 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(3, $catalogChanges, 'Catalog triggers must have recorded insert, update, and delete events');
            self::assertSame('100', (string) $catalogChanges[0]['product_id']);

            // Verify 032 and latest 057 are recorded in schema_versions
            $recorded = $runtimePdo->query("SELECT version FROM schema_versions ORDER BY version")->fetchAll(PDO::FETCH_COLUMN);
            self::assertContains('032_cleanup_expired_tokens.sql', $recorded);
            self::assertContains('057_add_product_search_indexes.sql', $recorded);

            // Simulate replay: delete 032+ from schema_versions
            $migrationPdo->exec("DELETE FROM schema_versions WHERE version >= '032_cleanup_expired_tokens.sql'");
            $replayResult = $migrationService->runAllMigrations(true);

            self::assertSame([], $replayResult['errors'], 'Replay of 032+ must be completely idempotent and error-free');
            self::assertGreaterThanOrEqual(25, $replayResult['executed']);

            $replayedVersions = $runtimePdo->query("SELECT version FROM schema_versions ORDER BY version")->fetchAll(PDO::FETCH_COLUMN);
            self::assertContains('032_cleanup_expired_tokens.sql', $replayedVersions);
            self::assertContains('057_add_product_search_indexes.sql', $replayedVersions);
        } finally {
            if ($runtimeAccount !== null) {
                MySqlTestEnvironment::dropUser($rootPdo, $runtimeAccount['username'], $runtimeAccount['host']);
            }
            if ($migrationAccount !== null) {
                MySqlTestEnvironment::dropUser($rootPdo, $migrationAccount['username'], $migrationAccount['host']);
            }
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    public function testRestrictedUserAccountHostIsPortableAndStrictlyDatabaseScoped(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_portability_test');
        $rootPdo = MySqlTestEnvironment::connect($database);
        $restrictedAccount = null;

        try {
            $restrictedAccount = MySqlTestEnvironment::createRestrictedUser($rootPdo, $database, 'pos_test');
            self::assertSame('%', $restrictedAccount['host'], 'Test accounts must use host % for network topology portability');

            $restrictedPdo = MySqlTestEnvironment::connectAs(
                $restrictedAccount['username'],
                $restrictedAccount['password'],
                $database
            );

            MySqlTestEnvironment::assertDatabaseScopedPrivilegesOnly($restrictedPdo, $database);
        } finally {
            if ($restrictedAccount !== null) {
                MySqlTestEnvironment::dropUser($rootPdo, $restrictedAccount['username'], $restrictedAccount['host']);
            }
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    public function testRuntimeAccountIsLeastPrivilegeAndCannotCreateTriggers(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_runtime_priv_test');
        $rootPdo = MySqlTestEnvironment::connect($database);
        $runtimeAccount = null;

        try {
            $rootPdo->exec("CREATE TABLE branches (id INT PRIMARY KEY, name VARCHAR(100)) ENGINE=InnoDB");
            $rootPdo->exec("CREATE TABLE products (id INT PRIMARY KEY, branch_id INT, name VARCHAR(100)) ENGINE=InnoDB");

            $runtimeAccount = MySqlTestEnvironment::createRuntimeUser($rootPdo, $database, 'pos_app');
            $runtimePdo = MySqlTestEnvironment::connectAs($runtimeAccount['username'], $runtimeAccount['password'], $database);

            // Normal DML works
            $runtimePdo->exec("INSERT INTO branches (id, name) VALUES (1, 'B1')");
            self::assertSame(1, (int) $runtimePdo->query("SELECT COUNT(*) FROM branches")->fetchColumn());

            // DDL / Trigger creation is strictly denied
            $denied = false;
            try {
                $runtimePdo->exec("CREATE TRIGGER trg_test AFTER INSERT ON products FOR EACH ROW SET @x = 1");
            } catch (PDOException $e) {
                $denied = true;
                self::assertContains((int) ($e->errorInfo[1] ?? $e->getCode()), [1142, 1419]);
            }
            self::assertTrue($denied, 'Runtime account pos_app must be denied trigger creation');
        } finally {
            if ($runtimeAccount !== null) {
                MySqlTestEnvironment::dropUser($rootPdo, $runtimeAccount['username'], $runtimeAccount['host']);
            }
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    public function testMigration043FailureIsFatalWhenTriggerFails(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_fatal_mig_test');
        $rootPdo = MySqlTestEnvironment::connect($database);
        $migrationAccount = null;

        try {
            $rootPdo->exec("CREATE TABLE branches (id INT PRIMARY KEY, name VARCHAR(100)) ENGINE=InnoDB");
            $rootPdo->exec("CREATE TABLE products (id INT PRIMARY KEY, branch_id INT, name VARCHAR(100)) ENGINE=InnoDB");

            $migrationAccount = MySqlTestEnvironment::createMigrationUser($rootPdo, $database, 'pos_migration');
            $realPdo = MySqlTestEnvironment::connectAs($migrationAccount['username'], $migrationAccount['password'], $database);

            // Wrap PDO to simulate MySQL 8 binary logging error 1419 on CREATE TRIGGER
            $simulatedPdo = new class($realPdo) extends PDO {
                private PDO $inner;
                public function __construct(PDO $inner) {
                    $this->inner = $inner;
                }
                public function exec(string $statement): int|false {
                    if (preg_match('/\bCREATE\s+TRIGGER\b/i', $statement)) {
                        $e = new PDOException("You do not have the SUPER privilege and binary logging is enabled", 1419);
                        $e->errorInfo = ['HY000', 1419, 'You do not have the SUPER privilege and binary logging is enabled'];
                        throw $e;
                    }
                    return $this->inner->exec($statement);
                }
                public function prepare(string $query, array $options = []): \PDOStatement|false {
                    return $this->inner->prepare($query, $options);
                }
                public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false {
                    return $this->inner->query($query, ...$fetchModeArgs);
                }
            };

            $migrationService = new \App\Services\MigrationService($simulatedPdo);
            $result = $migrationService->runMigrations(['043_add_product_catalog_changes.sql'], true);

            self::assertNotEmpty($result['errors'], 'Migration 043 must fail when trigger creation returns 1419');
            self::assertStringContainsString('043_add_product_catalog_changes.sql', implode(' ', $result['errors']));

            // It must NOT be recorded in schema_versions
            $stmt = $rootPdo->query("SELECT COUNT(*) FROM schema_versions WHERE version = '043_add_product_catalog_changes.sql'");
            self::assertSame(0, (int) $stmt->fetchColumn(), 'Failed Migration 043 must NOT be recorded in schema_versions');
        } finally {
            if ($migrationAccount !== null) {
                MySqlTestEnvironment::dropUser($rootPdo, $migrationAccount['username'], $migrationAccount['host']);
            }
            MySqlTestEnvironment::dropDatabase($database);
        }
    }
}
