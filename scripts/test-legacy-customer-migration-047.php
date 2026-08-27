<?php
declare(strict_types=1);

/**
 * Migration 047 & Legacy Customer Compatibility Test Suite
 * 
 * Verifies that update permissions seeding (047 / 053-056) runs cleanly across:
 * - Scenario A: Fresh database installation
 * - Scenario B: Real legacy v1.1.46 customer database (WITHOUT 'module' column)
 * - Scenario C: Partially migrated database with pre-existing update records
 * - Scenario D: Double execution / Idempotency test
 */

$GREEN = "\033[32m";
$RED = "\033[31m";
$CYAN = "\033[36m";
$YELLOW = "\033[33m";
$BOLD = "\033[1m";
$RESET = "\033[0m";

function logHeader(string $title): void {
    global $CYAN, $BOLD, $RESET;
    echo "\n{$CYAN}{$BOLD}================================================================================{$RESET}\n";
    echo "{$CYAN}{$BOLD}{$title}{$RESET}\n";
    echo "{$CYAN}{$BOLD}================================================================================{$RESET}\n";
}

function logOk(string $msg): void {
    global $GREEN, $RESET;
    echo "  {$GREEN}✔ [PASS]{$RESET} {$msg}\n";
}

function logFail(string $msg): void {
    global $RED, $BOLD, $RESET;
    echo "  {$RED}{$BOLD}✖ [FAIL]{$RESET} {$msg}\n";
}

$allTestsPassed = true;

function runSqlStatements(PDO $pdo, string $sql): void {
    // Clean SQL comments
    $lines = explode("\n", $sql);
    $cleanLines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $cleanLines[] = $line;
    }
    $cleanSql = implode("\n", $cleanLines);

    // Split by semicolon
    $statements = array_filter(
        array_map('trim', explode(';', $cleanSql)),
        fn($stmt) => !empty($stmt)
    );

    foreach ($statements as $statement) {
        // SQLite compatibility adaptations if running on SQLite
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            // Replace `role_permissions` condition subquery if needed
            $statement = str_replace("table_schema = DATABASE() AND table_name = 'role_permissions'", "1=1", $statement);
            $statement = str_replace("INSERT IGNORE", "INSERT OR IGNORE", $statement);
        }
        $pdo->exec($statement);
    }
}

// Load migration 047 (backend/database/migrations/047_seed_update_permissions.sql)
$mig047Path = __DIR__ . '/../backend/database/migrations/047_seed_update_permissions.sql';
$mig053Path = __DIR__ . '/../database/migrations/053_seed_update_permissions.sql';
$mig055Path = __DIR__ . '/../database/migrations/055_create_update_telemetry_table.sql';
$mig056Path = __DIR__ . '/../database/migrations/056_add_update_recovery_permissions.sql';

if (!file_exists($mig047Path)) {
    die("Migration 047 file not found: {$mig047Path}\n");
}
$mig047Sql = file_get_contents($mig047Path);
$mig053Sql = file_get_contents($mig053Path);
$mig056Sql = file_get_contents($mig056Path);

$expectedUpdatePerms = [
    'updates.view',
    'updates.check',
    'updates.apply',
    'updates.rollback',
];

logHeader("1. Verify Migration 047 SQL Content & Safety");
if (stripos($mig047Sql, 'module') !== false) {
    logFail("047_seed_update_permissions.sql still references 'module' column!");
    $allTestsPassed = false;
} else {
    logOk("047_seed_update_permissions.sql does NOT contain unsupported 'module' column references.");
}

// ══════════════════════════════════════════════════════════════
// SCENARIO A: Fresh Database Installation
// ══════════════════════════════════════════════════════════════
logHeader("Scenario A: Fresh Database Installation");
try {
    $pdoA = new PDO('sqlite::memory:');
    $pdoA->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdoA->exec("
        CREATE TABLE permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100) UNIQUE NOT NULL,
            description TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE role_permissions (
            role VARCHAR(20) NOT NULL,
            permission_id INTEGER NOT NULL,
            PRIMARY KEY (role, permission_id)
        );
    ");

    runSqlStatements($pdoA, $mig047Sql);

    $stmt = $pdoA->query("SELECT name FROM permissions WHERE name LIKE 'updates.%'");
    $permsA = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $missing = array_diff($expectedUpdatePerms, $permsA);
    if (!empty($missing)) {
        throw new Exception("Missing permissions in Scenario A: " . implode(', ', $missing));
    }

    $stmtRole = $pdoA->query("SELECT COUNT(*) FROM role_permissions WHERE role = 'admin'");
    $adminPermCount = (int)$stmtRole->fetchColumn();
    if ($adminPermCount !== count($expectedUpdatePerms)) {
        throw new Exception("Admin role expected " . count($expectedUpdatePerms) . " permissions, found {$adminPermCount}");
    }

    logOk("Fresh installation seeded all " . count($expectedUpdatePerms) . " update permissions and mapped to admin role.");
} catch (Exception $e) {
    logFail("Scenario A failed: " . $e->getMessage());
    $allTestsPassed = false;
}

// ══════════════════════════════════════════════════════════════
// SCENARIO B: Real Legacy v1.1.46 Customer Database (WITHOUT module column)
// ══════════════════════════════════════════════════════════════
logHeader("Scenario B: Real Legacy v1.1.46 Customer Database (NO module column)");
try {
    $pdoB = new PDO('sqlite::memory:');
    $pdoB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Schema matching pos_schema.sql / 024_create_rbac_tables.sql exactly
    $pdoB->exec("
        CREATE TABLE permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100) UNIQUE NOT NULL,
            description VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE role_permissions (
            role VARCHAR(20) NOT NULL,
            permission_id INTEGER NOT NULL,
            PRIMARY KEY (role, permission_id)
        );

        -- Legacy seed data
        INSERT INTO permissions (name, description) VALUES
        ('products.view', 'عرض المنتجات'),
        ('products.create', 'إضافة منتجات'),
        ('products.update', 'تعديل المنتجات'),
        ('products.delete', 'حذف المنتجات'),
        ('invoices.view', 'عرض الفواتير'),
        ('invoices.create', 'إنشاء فواتير'),
        ('invoices.delete', 'حذف فواتير'),
        ('reports.view', 'عرض التقارير'),
        ('settings.view', 'عرض الإعدادات'),
        ('users.manage', 'إدارة المستخدمين');

        INSERT INTO role_permissions (role, permission_id)
        SELECT 'admin', id FROM permissions;

        INSERT INTO role_permissions (role, permission_id)
        SELECT 'cashier', id FROM permissions WHERE name IN ('products.view', 'invoices.view', 'invoices.create');
    ");

    $stmtInitialCount = $pdoB->query("SELECT COUNT(*) FROM permissions");
    $initialCount = (int)$stmtInitialCount->fetchColumn();

    // Run migration 047 on legacy DB
    runSqlStatements($pdoB, $mig047Sql);

    // Verify legacy permissions still intact
    $stmtCheckLegacy = $pdoB->query("SELECT COUNT(*) FROM permissions WHERE name = 'products.view'");
    if ((int)$stmtCheckLegacy->fetchColumn() !== 1) {
        throw new Exception("Legacy permission 'products.view' was lost or corrupted!");
    }

    $stmtTotal = $pdoB->query("SELECT COUNT(*) FROM permissions");
    $totalAfter = (int)$stmtTotal->fetchColumn();
    if ($totalAfter !== $initialCount + count($expectedUpdatePerms)) {
        throw new Exception("Expected " . ($initialCount + count($expectedUpdatePerms)) . " permissions, got {$totalAfter}");
    }

    $stmtAdminTotal = $pdoB->query("SELECT COUNT(*) FROM role_permissions WHERE role = 'admin'");
    $adminTotalAfter = (int)$stmtAdminTotal->fetchColumn();
    if ($adminTotalAfter !== $initialCount + count($expectedUpdatePerms)) {
        throw new Exception("Admin role permissions expected " . ($initialCount + count($expectedUpdatePerms)) . ", got {$adminTotalAfter}");
    }

    // Cashier must remain unaffected
    $stmtCashier = $pdoB->query("SELECT COUNT(*) FROM role_permissions WHERE role = 'cashier'");
    if ((int)$stmtCashier->fetchColumn() !== 3) {
        throw new Exception("Cashier role permissions were unexpectedly modified!");
    }

    logOk("Legacy v1.1.46 customer database migrated seamlessly without 'module' column error.");
    logOk("All 10 legacy permissions preserved, cashier role unmodified, admin granted update permissions.");
} catch (Exception $e) {
    logFail("Scenario B failed: " . $e->getMessage());
    $allTestsPassed = false;
}

// ══════════════════════════════════════════════════════════════
// SCENARIO C: Partially Migrated Database (Pre-existing update records)
// ══════════════════════════════════════════════════════════════
logHeader("Scenario C: Partially Migrated Database");
try {
    $pdoC = new PDO('sqlite::memory:');
    $pdoC->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdoC->exec("
        CREATE TABLE permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100) UNIQUE NOT NULL,
            description VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE role_permissions (
            role VARCHAR(20) NOT NULL,
            permission_id INTEGER NOT NULL,
            PRIMARY KEY (role, permission_id)
        );

        -- Pre-seed 2 of the 4 update permissions
        INSERT INTO permissions (name, description) VALUES
        ('products.view', 'View Products'),
        ('updates.view', 'View system update status'),
        ('updates.check', 'Check for new system updates');

        INSERT INTO role_permissions (role, permission_id)
        SELECT 'admin', id FROM permissions;
    ");

    // Run migration 047
    runSqlStatements($pdoC, $mig047Sql);

    $stmtTotalC = $pdoC->query("SELECT COUNT(*) FROM permissions");
    $totalC = (int)$stmtTotalC->fetchColumn();
    // 1 product perm + 4 update perms = 5
    if ($totalC !== 5) {
        throw new Exception("Expected 5 permissions total, got {$totalC}");
    }

    $stmtUpdatePerms = $pdoC->query("SELECT name FROM permissions WHERE name LIKE 'updates.%'");
    $foundPerms = $stmtUpdatePerms->fetchAll(PDO::FETCH_COLUMN);
    $missingC = array_diff($expectedUpdatePerms, $foundPerms);
    if (!empty($missingC)) {
        throw new Exception("Missing permissions after partial migration: " . implode(', ', $missingC));
    }

    $stmtAdminC = $pdoC->query("SELECT COUNT(*) FROM role_permissions WHERE role = 'admin'");
    if ((int)$stmtAdminC->fetchColumn() !== 5) {
        throw new Exception("Admin role expected 5 permissions, got " . $stmtAdminC->fetchColumn());
    }

    logOk("Partially migrated database handled gracefully: missing permissions added, no duplicate keys.");
} catch (Exception $e) {
    logFail("Scenario C failed: " . $e->getMessage());
    $allTestsPassed = false;
}

// ══════════════════════════════════════════════════════════════
// SCENARIO D: Idempotency (Double Migration Execution)
// ══════════════════════════════════════════════════════════════
logHeader("Scenario D: Re-running Migration Twice (Idempotency)");
try {
    $pdoD = new PDO('sqlite::memory:');
    $pdoD->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdoD->exec("
        CREATE TABLE permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(100) UNIQUE NOT NULL,
            description VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE role_permissions (
            role VARCHAR(20) NOT NULL,
            permission_id INTEGER NOT NULL,
            PRIMARY KEY (role, permission_id)
        );
        INSERT INTO permissions (name, description) VALUES ('products.view', 'View Products');
        INSERT INTO role_permissions (role, permission_id) SELECT 'admin', id FROM permissions;
    ");

    // First execution
    runSqlStatements($pdoD, $mig047Sql);
    $countAfterFirst = (int)$pdoD->query("SELECT COUNT(*) FROM permissions")->fetchColumn();
    $roleCountAfterFirst = (int)$pdoD->query("SELECT COUNT(*) FROM role_permissions")->fetchColumn();

    // Second execution
    runSqlStatements($pdoD, $mig047Sql);
    $countAfterSecond = (int)$pdoD->query("SELECT COUNT(*) FROM permissions")->fetchColumn();
    $roleCountAfterSecond = (int)$pdoD->query("SELECT COUNT(*) FROM role_permissions")->fetchColumn();

    // Third execution of canonical 053/056
    runSqlStatements($pdoD, $mig053Sql);
    runSqlStatements($pdoD, $mig056Sql);
    $countAfterThird = (int)$pdoD->query("SELECT COUNT(*) FROM permissions")->fetchColumn();
    $roleCountAfterThird = (int)$pdoD->query("SELECT COUNT(*) FROM role_permissions")->fetchColumn();

    if ($countAfterFirst !== $countAfterSecond || $roleCountAfterFirst !== $roleCountAfterSecond) {
        throw new Exception("Idempotency violation: count changed between identical runs!");
    }

    if ($countAfterThird !== $countAfterFirst + 2) { // 056 adds 2 recovery permissions
        throw new Exception("Expected 056 to add 2 recovery perms, count is {$countAfterThird}");
    }

    logOk("Migration 047 / 053 / 056 re-runs produce identical results with 0 duplicate rows or SQL errors.");
} catch (Exception $e) {
    logFail("Scenario D failed: " . $e->getMessage());
    $allTestsPassed = false;
}

logHeader("Test Results Summary");
if ($allTestsPassed) {
    echo "  {$GREEN}{$BOLD}ALL 4 MIGRATION COMPATIBILITY SCENARIOS PASSED (100% SUCCESS){$RESET}\n\n";
    exit(0);
} else {
    echo "  {$RED}{$BOLD}SOME MIGRATION COMPATIBILITY TESTS FAILED!{$RESET}\n\n";
    exit(1);
}
