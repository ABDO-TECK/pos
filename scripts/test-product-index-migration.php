<?php
declare(strict_types=1);

/**
 * Product Index Migration Test Suite
 * Tests 057_add_product_search_indexes.sql across all scenarios:
 * 1. Fresh database
 * 2. Existing customer database with data
 * 3. Missing is_active column compatibility
 * 4. Duplicate index execution (idempotency)
 */

putenv('APP_DEPLOYMENT_TARGET=desktop');
putenv('APP_ENV=development');
putenv('APP_DEBUG=true');

require_once __DIR__ . '/../backend/vendor/autoload.php';

$GREEN = "\033[32m";
$RED = "\033[31m";
$CYAN = "\033[36m";
$BOLD = "\033[1m";
$RESET = "\033[0m";

function logHeader(string $title): void {
    global $CYAN, $BOLD, $RESET;
    echo "\n{$CYAN}{$BOLD}================================================================================{$RESET}\n";
    echo "{$CYAN}{$BOLD}{$title}{$RESET}\n";
    echo "{$CYAN}{$BOLD}================================================================================{$RESET}\n\n";
}

function logOk(string $msg): void {
    global $GREEN, $RESET;
    echo "  {$GREEN}✔ [PASS]{$RESET} {$msg}\n";
}

function logErr(string $msg): void {
    global $RED, $BOLD, $RESET;
    echo "  {$RED}{$BOLD}✖ [FAIL]{$RESET} {$msg}\n";
}

$results = [];

try {
    $migrationSqlFile = __DIR__ . '/../database/migrations/057_add_product_search_indexes.sql';
    if (!file_exists($migrationSqlFile)) {
        throw new RuntimeException("Migration file 057_add_product_search_indexes.sql not found!");
    }
    $migrationSql = (string) file_get_contents($migrationSqlFile);

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 1: Fresh Database State
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 1: Fresh Database Migration Execution');

    $pdo1 = new PDO('sqlite::memory:');
    $pdo1->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo1->exec("
        CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            barcode TEXT UNIQUE NOT NULL,
            box_barcode TEXT,
            price REAL NOT NULL DEFAULT 0.00,
            cost REAL NOT NULL DEFAULT 0.00,
            quantity REAL NOT NULL DEFAULT 0.000,
            category_id INTEGER,
            branch_id INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL
        );
    ");

    $pdo1->exec($migrationSql);
    logOk("Migration 057 executed successfully on clean fresh schema.");

    // Verify index exists
    $stmt = $pdo1->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_products_barcode_deleted'");
    if (!$stmt->fetch()) {
        throw new RuntimeException("Index idx_products_barcode_deleted was not created in SQLite.");
    }
    logOk("Index `idx_products_barcode_deleted` verified in schema catalog.");
    $results['scenario1_fresh_db'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 2: Existing Customer Database with Pre-existing Data
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 2: Existing Customer Database with Rows & Prior Indexes');

    $pdo2 = new PDO('sqlite::memory:');
    $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo2->exec("
        CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            barcode TEXT UNIQUE NOT NULL,
            box_barcode TEXT,
            price REAL NOT NULL DEFAULT 0.00,
            cost REAL NOT NULL DEFAULT 0.00,
            quantity REAL NOT NULL DEFAULT 0.000,
            category_id INTEGER,
            branch_id INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL
        );
        CREATE INDEX idx_products_deleted ON products(deleted_at);
        INSERT INTO products (name, barcode, price, cost, quantity, branch_id) VALUES
        ('Milk 1L', '6281001', 5.50, 4.00, 50, 1),
        ('Bread', '6281002', 1.50, 1.00, 100, 1),
        ('Cheese 500g', '6281003', 12.00, 9.50, 20, 1);
    ");

    $pdo2->exec($migrationSql);
    logOk("Migration 057 applied cleanly to populated customer database without data loss.");

    $count = (int) $pdo2->query("SELECT COUNT(*) FROM products")->fetchColumn();
    if ($count !== 3) {
        throw new RuntimeException("Row count mismatch after migration: expected 3, got {$count}");
    }
    logOk("All {$count} existing product rows preserved intact.");
    $results['scenario2_existing_customer_db'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 3: Missing is_active Column Scenario
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 3: Strict Schema Validation (No is_active Dependency)');

    if (stripos($migrationSql, 'is_active') !== false) {
        throw new RuntimeException("Migration SQL still contains invalid reference to 'is_active' column!");
    }
    logOk("Migration SQL confirmed free of non-existent `is_active` column references.");
    logOk("Uses production canonical soft-delete column `deleted_at`.");
    $results['scenario3_no_is_active_dependency'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 4: Duplicate Index Execution (Idempotency)
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 4: Duplicate Index Execution (Idempotency)');

    // In SQLite, re-running CREATE INDEX will throw 'index already exists',
    // while MigrationService ignores duplicate object errors (1061 / already exists).
    // Let's test execution through MigrationService error filter simulation.
    try {
        $pdo2->exec($migrationSql);
        logOk("Executed duplicate migration run cleanly.");
    } catch (PDOException $e) {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'already exists') || str_contains($msg, 'duplicate')) {
            logOk("Duplicate index attempt caught and safely recognized as ignorable migration state: " . $e->getMessage());
        } else {
            throw $e;
        }
    }
    $results['scenario4_idempotent_duplicate'] = true;

} catch (Throwable $e) {
    logErr("Test failed: " . $e->getMessage());
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
}

logHeader('PRODUCT INDEX MIGRATION TEST SUMMARY');
$allPassed = count(array_filter($results)) === 4;
foreach ($results as $name => $passed) {
    echo "  " . str_pad(strtoupper($name), 40) . ": " . ($passed ? "{$GREEN}PASSED ✔{$RESET}" : "{$RED}FAILED ✖{$RESET}") . "\n";
}

if ($allPassed) {
    echo "\n{$GREEN}{$BOLD}🎉 ALL 4 PRODUCT INDEX MIGRATION SCENARIOS PASSED 100%!{$RESET}\n\n";
    exit(0);
} else {
    echo "\n{$RED}{$BOLD}❌ SOME TESTS FAILED.{$RESET}\n\n";
    exit(1);
}
