<?php
declare(strict_types=1);

/**
 * Health Core Tables Verification Test Suite
 *
 * Tests HealthService and UpdateRecoveryService core table validation:
 * 1. Valid existing POS database (PASS)
 * 2. Missing products table (FAIL on products)
 * 3. Missing actual sales table / invoices (FAIL on invoices)
 * 4. Legacy customer database compatibility (PASS without false positive 'sales' failure)
 */

putenv('APP_DEPLOYMENT_TARGET=desktop');
putenv('APP_ENV=development');
putenv('APP_DEBUG=true');

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\HealthService;
use App\Services\UpdateRecoveryService;

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
$testStorage = __DIR__ . '/../backend/storage/test_health_' . time();
@mkdir($testStorage, 0755, true);

try {
    $healthService = new HealthService();

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 1: Valid Existing POS Database
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 1: Valid Existing POS Database');

    $pdo1 = new PDO('sqlite::memory:');
    $pdo1->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo1->exec("
        CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, role TEXT);
        CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, barcode TEXT, price REAL, deleted_at TEXT);
        CREATE TABLE settings (id INTEGER PRIMARY KEY, `key` TEXT UNIQUE, `value` TEXT);
        CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, total REAL, status TEXT, deleted_at TEXT);
        CREATE TABLE invoice_items (id INTEGER PRIMARY KEY, invoice_id INTEGER, product_id INTEGER, quantity REAL, price REAL);
    ");

    $recoveryService1 = new UpdateRecoveryService($testStorage, __DIR__ . '/..', null, null, $pdo1);
    $check1 = $recoveryService1->validatePostUpdateHealth();

    if (empty($check1['checks']['core_tables'])) {
        throw new RuntimeException("Core tables check failed on valid database: " . json_encode($check1['errors']));
    }
    logOk("Core tables validated: users, products, settings, invoices.");
    logOk("No false positive for 'sales' table.");
    $results['scenario1_valid_db'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 2: Missing Products Table
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 2: Missing Products Table Detection');

    $pdo2 = new PDO('sqlite::memory:');
    $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo2->exec("
        CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);
        CREATE TABLE settings (id INTEGER PRIMARY KEY, `key` TEXT, `value` TEXT);
        CREATE TABLE invoices (id INTEGER PRIMARY KEY, total REAL);
    ");

    $recoveryService2 = new UpdateRecoveryService($testStorage, __DIR__ . '/..', null, null, $pdo2);
    $check2 = $recoveryService2->validatePostUpdateHealth();

    if (!empty($check2['checks']['core_tables'])) {
        throw new RuntimeException("Should have failed core tables check when 'products' is missing.");
    }
    $hasProductsError = false;
    foreach ($check2['errors'] as $err) {
        if (str_contains($err, 'products')) {
            $hasProductsError = true;
            break;
        }
    }
    if (!$hasProductsError) {
        throw new RuntimeException("Error message should explicitly mention missing 'products' table.");
    }
    logOk("Missing 'products' table correctly detected and flagged.");
    $results['scenario2_missing_products'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 3: Missing Actual Sales Table (invoices)
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 3: Missing Actual Sales Table (invoices)');

    $pdo3 = new PDO('sqlite::memory:');
    $pdo3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo3->exec("
        CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);
        CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT);
        CREATE TABLE settings (id INTEGER PRIMARY KEY, `key` TEXT, `value` TEXT);
    ");

    $recoveryService3 = new UpdateRecoveryService($testStorage, __DIR__ . '/..', null, null, $pdo3);
    $check3 = $recoveryService3->validatePostUpdateHealth();

    if (!empty($check3['checks']['core_tables'])) {
        throw new RuntimeException("Should have failed core tables check when 'invoices' is missing.");
    }
    $hasInvoicesError = false;
    foreach ($check3['errors'] as $err) {
        if (str_contains($err, 'invoices')) {
            $hasInvoicesError = true;
            break;
        }
    }
    if (!$hasInvoicesError) {
        throw new RuntimeException("Error message should explicitly mention missing 'invoices' table.");
    }
    logOk("Missing sales table 'invoices' correctly detected and flagged.");
    $results['scenario3_missing_invoices'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 4: Legacy Customer Database Compatibility
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 4: Legacy Customer Database Compatibility');

    $pdo4 = new PDO('sqlite::memory:');
    $pdo4->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo4->exec("
        CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT);
        CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, barcode TEXT);
        CREATE TABLE settings (id INTEGER PRIMARY KEY, `key` TEXT, `value` TEXT);
        CREATE TABLE invoices (id INTEGER PRIMARY KEY, total REAL);
        CREATE TABLE customers (id INTEGER PRIMARY KEY, name TEXT);
        CREATE TABLE suppliers (id INTEGER PRIMARY KEY, name TEXT);
        CREATE TABLE expenses (id INTEGER PRIMARY KEY, amount REAL);
    ");

    $recoveryService4 = new UpdateRecoveryService($testStorage, __DIR__ . '/..', null, null, $pdo4);
    $check4 = $recoveryService4->validatePostUpdateHealth();

    if (empty($check4['checks']['core_tables'])) {
        throw new RuntimeException("Legacy customer database failed core tables check: " . json_encode($check4['errors']));
    }
    logOk("Legacy customer database without 'sales' table passes health check cleanly.");
    $results['scenario4_legacy_compatibility'] = true;

} catch (Throwable $e) {
    logErr("Health core tables test failed: " . $e->getMessage());
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
} finally {
    if (is_dir($testStorage)) {
        @array_map('unlink', glob("{$testStorage}/*") ?: []);
        @rmdir($testStorage);
    }
}

logHeader('HEALTH CORE TABLES TEST SUMMARY');
$allPassed = count(array_filter($results)) === 4;
foreach ($results as $name => $passed) {
    echo "  " . str_pad(strtoupper($name), 40) . ": " . ($passed ? "{$GREEN}PASSED ✔{$RESET}" : "{$RED}FAILED ✖{$RESET}") . "\n";
}

if ($allPassed) {
    echo "\n{$GREEN}{$BOLD}🎉 ALL 4 HEALTH CORE TABLES TESTS PASSED 100%!{$RESET}\n\n";
    exit(0);
} else {
    echo "\n{$RED}{$BOLD}❌ SOME HEALTH TESTS FAILED.{$RESET}\n\n";
    exit(1);
}
