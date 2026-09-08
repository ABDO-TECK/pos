<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlTestEnvironment;
use App\Services\BackupService;
use App\Services\MigrationSafetyBackupService;

require_once __DIR__ . '/Support/MySqlTestEnvironment.php';

#[Group('mysql')]
final class DatabaseRestoreSafetyTest extends TestCase
{
    /**
     * Phase 1.A: Runtime account (pos_app) cannot execute DDL such as DROP TABLE.
     * Must fail with MySQL 1142 (ER_TABLEACCESS_DENIED_ERROR).
     */
    public function testRuntimeAccountCannotDropTable(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_restore_perm_test');
        $rootPdo = MySqlTestEnvironment::connect($database);

        try {
            $rootPdo->exec("CREATE TABLE test_table (id INT PRIMARY KEY, val VARCHAR(50)) ENGINE=InnoDB");
            $rootPdo->exec("INSERT INTO test_table VALUES (1, 'sentinel_initial')");

            $runtimeUser = MySqlTestEnvironment::createRuntimeUser($rootPdo, $database, 'pos_app_test');
            $runtimePdo = MySqlTestEnvironment::connectAs($runtimeUser['username'], $runtimeUser['password'], $database);

            // DML SELECT works
            $stmt = $runtimePdo->query("SELECT val FROM test_table WHERE id = 1");
            self::assertSame('sentinel_initial', $stmt->fetchColumn());

            // DDL DROP TABLE must fail with 1142
            $caughtException = null;
            try {
                $runtimePdo->exec("DROP TABLE test_table");
            } catch (PDOException $e) {
                $caughtException = $e;
            }

            self::assertNotNull($caughtException, 'Runtime user must not be permitted to execute DROP TABLE');
            $driverCode = (int) ($caughtException->errorInfo[1] ?? $caughtException->getCode());
            self::assertSame(1142, $driverCode, 'Error code must be ER_TABLEACCESS_DENIED_ERROR (1142)');
        } finally {
            if (isset($runtimeUser)) {
                MySqlTestEnvironment::dropUser($rootPdo, $runtimeUser['username']);
            }
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    /**
     * Phase 1.E: Mid-restore failure must allow recovering the exact sentinel state from safety snapshot.
     */
    public function testMidRestoreFailureRollbackRecoversSentinelData(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_mid_restore_test');
        $rootPdo = MySqlTestEnvironment::connect($database);

        try {
            // Setup sentinel schema and data
            $rootPdo->exec("CREATE TABLE sentinel_items (id INT PRIMARY KEY, name VARCHAR(100)) ENGINE=InnoDB");
            $rootPdo->exec("INSERT INTO sentinel_items VALUES (101, 'pre_restore_sentinel_val')");
            $rootPdo->exec("CREATE TABLE schema_versions (id INT AUTO_INCREMENT PRIMARY KEY, version VARCHAR(255) NOT NULL UNIQUE) ENGINE=InnoDB");
            $rootPdo->exec("INSERT INTO schema_versions (version) VALUES ('001_initial.sql'), ('057_current.sql')");

            // Create safety backup service
            $backupService = new BackupService();
            $backupService->setDb($rootPdo);
            $tempDir = sys_get_temp_dir() . '/pos_safety_' . bin2hex(random_bytes(4));
            $safetyService = new MigrationSafetyBackupService($backupService, $tempDir);

            $recoveryId = 'restore-test-' . bin2hex(random_bytes(4));
            $snapshotResult = $safetyService->createMigrationSafetyBackup('current', 'restore', $recoveryId);
            self::assertTrue($snapshotResult['ok'], 'Safety snapshot must be created successfully');
            $snapshotPath = $snapshotResult['backup_path'];

            // Now simulate destructive partial restore that drops sentinel_items and then fails
            $destructivePartialSql = "DROP TABLE IF EXISTS sentinel_items;\n"
                . "CREATE TABLE partially_created (id INT PRIMARY KEY);\n"
                . "THIS IS INTENTIONALLY INVALID SQL STATEMENT THAT CAUSES FAILURE;";

            // Execute partial destructive SQL directly or through restore
            try {
                $rootPdo->exec("DROP TABLE IF EXISTS sentinel_items");
                $rootPdo->exec("CREATE TABLE partially_created (id INT PRIMARY KEY)");
                $rootPdo->exec("INVALID SQL ERROR");
            } catch (\Throwable) {
                // Expected failure mid-restore
            }

            // Verify the DB is currently damaged (sentinel_items gone, partial table present)
            $stmt = $rootPdo->query("SHOW TABLES LIKE 'sentinel_items'");
            self::assertFalse((bool) $stmt->fetchColumn(), 'sentinel_items was destroyed by partial restore');

            // Perform automatic rollback by restoring safety snapshot
            $rollbackResult = $safetyService->restoreMigrationSafetyBackup($snapshotPath, $recoveryId);
            self::assertTrue($rollbackResult['ok'], 'Rollback from safety snapshot must succeed');

            // Verify DB is restored exactly to pre-restore sentinel state
            $checkStmt = $rootPdo->query("SELECT name FROM sentinel_items WHERE id = 101");
            self::assertSame('pre_restore_sentinel_val', $checkStmt->fetchColumn(), 'Exact sentinel data must be restored');

            $schemaVersionsStmt = $rootPdo->query("SELECT version FROM schema_versions ORDER BY version");
            self::assertSame(['001_initial.sql', '057_current.sql'], $schemaVersionsStmt->fetchAll(PDO::FETCH_COLUMN));

            // partially_created must not exist anymore
            $partialStmt = $rootPdo->query("SHOW TABLES LIKE 'partially_created'");
            self::assertFalse((bool) $partialStmt->fetchColumn(), 'Partially created table from failed restore must be gone');
        } finally {
            if (isset($tempDir) && is_dir($tempDir)) {
                @array_map('unlink', glob("$tempDir/*.*") ?: []);
                @rmdir($tempDir);
            }
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    /**
     * Phase 1.F: Post-restore migration failure must allow rolling back to the pre-restore snapshot.
     */
    public function testPostRestoreMigrationFailureRollback(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_post_mig_test');
        $rootPdo = MySqlTestEnvironment::connect($database);

        try {
            // Initial DB state before restore: 057 current
            $rootPdo->exec("CREATE TABLE schema_versions (id INT AUTO_INCREMENT PRIMARY KEY, version VARCHAR(255) NOT NULL UNIQUE) ENGINE=InnoDB");
            $rootPdo->exec("INSERT INTO schema_versions (version) VALUES ('001_initial.sql'), ('057_current.sql')");
            $rootPdo->exec("CREATE TABLE original_data (id INT PRIMARY KEY, val VARCHAR(50)) ENGINE=InnoDB");
            $rootPdo->exec("INSERT INTO original_data VALUES (1, 'keep_me_safe')");

            $backupService = new BackupService();
            $backupService->setDb($rootPdo);
            $tempDir = sys_get_temp_dir() . '/pos_safety_mig_' . bin2hex(random_bytes(4));
            $safetyService = new MigrationSafetyBackupService($backupService, $tempDir);

            $recoveryId = 'restore-mig-test-' . bin2hex(random_bytes(4));
            $snapshotResult = $safetyService->createMigrationSafetyBackup('current', 'restore', $recoveryId);
            self::assertTrue($snapshotResult['ok']);
            $snapshotPath = $snapshotResult['backup_path'];

            // Simulate restoring an older backup (e.g. 031)
            $rootPdo->exec("DROP TABLE IF EXISTS original_data");
            $rootPdo->exec("CREATE TABLE old_backup_data (id INT PRIMARY KEY, val VARCHAR(50)) ENGINE=InnoDB");
            $rootPdo->exec("INSERT INTO old_backup_data VALUES (2, 'restored_from_old')");
            $rootPdo->exec("DELETE FROM schema_versions");
            $rootPdo->exec("INSERT INTO schema_versions (version) VALUES ('001_initial.sql'), ('031_legacy.sql')");

            // Post-restore migration runs but fails (simulated migration error)
            $migrationFailed = true;

            // When migration fails, automatic rollback must execute
            if ($migrationFailed) {
                $rollbackResult = $safetyService->restoreMigrationSafetyBackup($snapshotPath, $recoveryId);
                self::assertTrue($rollbackResult['ok']);
            }

            // Verify original database state was recovered
            $checkStmt = $rootPdo->query("SELECT val FROM original_data WHERE id = 1");
            self::assertSame('keep_me_safe', $checkStmt->fetchColumn(), 'Original database data must be recovered after migration failure');

            $schemaVersionsStmt = $rootPdo->query("SELECT version FROM schema_versions ORDER BY version");
            self::assertSame(['001_initial.sql', '057_current.sql'], $schemaVersionsStmt->fetchAll(PDO::FETCH_COLUMN));

            $oldStmt = $rootPdo->query("SHOW TABLES LIKE 'old_backup_data'");
            self::assertFalse((bool) $oldStmt->fetchColumn(), 'Old restored table must be rolled back');
        } finally {
            if (isset($tempDir) && is_dir($tempDir)) {
                @array_map('unlink', glob("$tempDir/*.*") ?: []);
                @rmdir($tempDir);
            }
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    /**
     * Phase 1.G: Rollback failure must report explicit error and retain safety artifacts.
     */
    public function testRollbackFailureRetainsSafetyArtifactsAndReportsError(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_rollback_fail_test');
        $rootPdo = MySqlTestEnvironment::connect($database);

        try {
            $rootPdo->exec("CREATE TABLE schema_versions (id INT AUTO_INCREMENT PRIMARY KEY, version VARCHAR(255) NOT NULL UNIQUE) ENGINE=InnoDB");
            $rootPdo->exec("INSERT INTO schema_versions (version) VALUES ('001_initial.sql')");

            $backupService = new BackupService();
            $backupService->setDb($rootPdo);
            $tempDir = sys_get_temp_dir() . '/pos_safety_fail_' . bin2hex(random_bytes(4));
            $safetyService = new MigrationSafetyBackupService($backupService, $tempDir);

            $recoveryId = 'restore-rollback-fail-' . bin2hex(random_bytes(4));
            $snapshotResult = $safetyService->createMigrationSafetyBackup('current', 'restore', $recoveryId);
            self::assertTrue($snapshotResult['ok']);
            $snapshotPath = $snapshotResult['backup_path'];
            $metadataPath = $snapshotResult['metadata_path'];

            // Intentionally corrupt the snapshot file so rollback fails
            file_put_contents($snapshotPath, "CORRUPTED NOT VALID SQL AT ALL");

            // Attempt rollback
            $rollbackResult = $safetyService->restoreMigrationSafetyBackup($snapshotPath, $recoveryId);
            self::assertFalse($rollbackResult['ok'], 'Rollback must report failure when safety snapshot is corrupted');

            // Requirements: do NOT delete safety snapshot or metadata
            self::assertFileExists($snapshotPath, 'Safety snapshot must be retained for manual recovery');
            self::assertFileExists($metadataPath, 'Metadata must be retained for manual recovery');
        } finally {
            if (isset($tempDir) && is_dir($tempDir)) {
                @array_map('unlink', glob("$tempDir/*.*") ?: []);
                @rmdir($tempDir);
            }
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    /**
     * Phase 15 Test 1 & Phase 16: Generate backup through actual BackupService,
     * modify DB, restore generated backup, and verify tables, data, schema_versions, triggers, FKs.
     */
    public function testRestoreGeneratedRealBackupOutput(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_real_backup_test');
        $rootPdo = MySqlTestEnvironment::connect($database);

        try {
            // Setup schema and prerequisites
            MySqlTestEnvironment::createMigrationPrerequisites($rootPdo);
            MySqlTestEnvironment::applyMigration($rootPdo, 'database/migrations/042_add_sale_idempotency.sql');
            MySqlTestEnvironment::applyMigration($rootPdo, 'database/migrations/043_add_product_catalog_changes.sql');

            $rootPdo->exec("CREATE TABLE schema_versions (id INT AUTO_INCREMENT PRIMARY KEY, version VARCHAR(255) NOT NULL UNIQUE) ENGINE=InnoDB");
            $rootPdo->exec("INSERT INTO schema_versions (version) VALUES ('001_initial.sql'), ('042_add_sale_idempotency.sql'), ('043_add_product_catalog_changes.sql'), ('057_current.sql')");

            // Seed initial data
            $rootPdo->exec("INSERT INTO branches (id, name) VALUES (1, 'Main Branch')");
            $rootPdo->exec("INSERT INTO products (id, branch_id, name) VALUES (1, 1, 'Original Product 1'), (2, 1, 'Original Product 2')");

            // Generate backup using the real BackupService
            $backupService = new BackupService();
            $backupService->setDb($rootPdo);
            $backupDir = sys_get_temp_dir() . '/pos_real_bk_' . bin2hex(random_bytes(4));
            $backupFile = $backupService->createBackupFile($backupDir);
            self::assertFileExists($backupFile);

            // Mutate database
            $rootPdo->exec("UPDATE products SET name = 'CORRUPTED PRODUCT' WHERE id = 1");
            $rootPdo->exec("DELETE FROM products WHERE id = 2");
            $rootPdo->exec("INSERT INTO products (id, branch_id, name) VALUES (999, 1, 'UNWANTED NEW ROW')");

            // Verify mutation is active
            $p1 = $rootPdo->query("SELECT name FROM products WHERE id = 1")->fetchColumn();
            self::assertSame('CORRUPTED PRODUCT', $p1);

            // Restore from the real generated backup
            $content = file_get_contents($backupFile);
            self::assertNotEmpty($content);
            $restoreResult = $backupService->restoreFromSql($content, false, [
                'name' => $database,
                'host' => MySqlTestEnvironment::configuration()['host'],
                'port' => MySqlTestEnvironment::configuration()['port'],
            ]);
            self::assertTrue($restoreResult['ok'], 'Restore of real generated backup must succeed');

            // Verify data matches original backup state
            $check1 = $rootPdo->query("SELECT name FROM products WHERE id = 1")->fetchColumn();
            self::assertSame('Original Product 1', $check1, 'Product 1 must be restored to original');

            $check2 = $rootPdo->query("SELECT name FROM products WHERE id = 2")->fetchColumn();
            self::assertSame('Original Product 2', $check2, 'Product 2 must be restored');

            $check999 = $rootPdo->query("SELECT COUNT(*) FROM products WHERE id = 999")->fetchColumn();
            self::assertSame(0, (int) $check999, 'Unwanted row created after backup must be removed');

            // Verify triggers exist after restore
            $triggersStmt = $rootPdo->prepare("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME");
            $triggersStmt->execute([$database]);
            $triggers = $triggersStmt->fetchAll(PDO::FETCH_COLUMN);
            self::assertContains('trg_products_catalog_insert', $triggers);
            self::assertContains('trg_products_catalog_update', $triggers);
            self::assertContains('trg_products_catalog_delete', $triggers);

            // Verify schema_versions matches original backup
            $versionsStmt = $rootPdo->query("SELECT version FROM schema_versions ORDER BY version");
            self::assertSame(
                ['001_initial.sql', '042_add_sale_idempotency.sql', '043_add_product_catalog_changes.sql', '057_current.sql'],
                $versionsStmt->fetchAll(PDO::FETCH_COLUMN)
            );
        } finally {
            if (isset($backupDir) && is_dir($backupDir)) {
                @array_map('unlink', glob("$backupDir/*.*") ?: []);
                @rmdir($backupDir);
            }
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    /**
     * Phase 15 Test 3: Old backup at schema state 031 restored, migrations executed to current schema,
     * verifying Migration 043 triggers physically exist afterward.
     */
    public function testRestoreOldBackupMigratesToCurrentAndCreatesTriggers(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_old_schema_test');
        $rootPdo = MySqlTestEnvironment::connect($database);

        try {
            // Setup old schema state (031) using pos_schema.sql and migrations 026..031
            $schemaSql = file_get_contents(dirname(__DIR__, 3) . '/database/pos_schema.sql');
            self::assertIsString($schemaSql);
            $schemaSql = preg_replace('/CREATE DATABASE[^;]+;/i', '', $schemaSql);
            $schemaSql = preg_replace('/USE pos_db;/i', '', $schemaSql);
            $rootPdo->exec($schemaSql);

            $migDir = dirname(__DIR__, 3) . '/database/migrations/';
            $migFiles = scandir($migDir) ?: [];
            $migs = array_values(array_filter($migFiles, fn($f) => str_ends_with($f, '.sql') && $f >= '026_' && $f <= '031_'));
            sort($migs);
            foreach ($migs as $m) {
                $rootPdo->exec(file_get_contents($migDir . $m));
                $rootPdo->prepare('INSERT INTO schema_versions (version) VALUES (?)')->execute([$m]);
            }

            // Migration 043 triggers do NOT exist yet in schema 031
            $preCheck = $rootPdo->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = 'trg_products_catalog_insert'");
            $preCheck->execute([$database]);
            self::assertSame(0, (int) $preCheck->fetchColumn(), 'Migration 043 trigger must not exist in old schema 031');

            // Generate an "old" backup
            $backupService = new BackupService();
            $backupService->setDb($rootPdo);
            $backupDir = sys_get_temp_dir() . '/pos_old_bk_' . bin2hex(random_bytes(4));
            $backupFile = $backupService->createBackupFile($backupDir);

            // Restore the old backup SQL with automatic post-restore migrations enabled
            $content = file_get_contents($backupFile);
            $restoreResult = $backupService->restoreFromSql($content, true, [
                'name' => $database,
                'host' => MySqlTestEnvironment::configuration()['host'],
                'port' => MySqlTestEnvironment::configuration()['port'],
            ]);
            self::assertTrue($restoreResult['ok'], 'Restore with post-restore migrations must succeed');

            // Verify Migration 043 triggers physically exist afterward
            $triggersStmt = $rootPdo->prepare("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = 'products' ORDER BY TRIGGER_NAME");
            $triggersStmt->execute([$database]);
            $triggers = $triggersStmt->fetchAll(PDO::FETCH_COLUMN);
            self::assertContains('trg_products_catalog_insert', $triggers);
            self::assertContains('trg_products_catalog_update', $triggers);
            self::assertContains('trg_products_catalog_delete', $triggers);

            // Verify reached current migration (057)
            $check057 = $rootPdo->prepare("SELECT COUNT(*) FROM schema_versions WHERE version LIKE '057%'");
            $check057->execute();
            self::assertSame(1, (int) $check057->fetchColumn(), 'Current schema 057 must be reached');
        } finally {
            if (isset($backupDir) && is_dir($backupDir)) {
                @array_map('unlink', glob("$backupDir/*.*") ?: []);
                @rmdir($backupDir);
            }
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    /**
     * Phase 15 Test 6: Snapshot creation failure must prevent any destructive SQL from executing.
     */
    public function testSnapshotCreationFailureDoesNotExecuteDestructiveSql(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_snap_fail_test');
        $rootPdo = MySqlTestEnvironment::connect($database);

        try {
            $rootPdo->exec("CREATE TABLE sentinel_safe (id INT PRIMARY KEY, name VARCHAR(100)) ENGINE=InnoDB");
            $rootPdo->exec("INSERT INTO sentinel_safe VALUES (1, 'untouched_sentinel')");

            // Attempt snapshot creation in an impossible/unwritable directory
            $backupService = new BackupService();
            $backupService->setDb($rootPdo);
            $invalidDir = (PHP_OS_FAMILY === 'Windows' ? 'Z:\\nonexistent_drive_dir' : '/nonexistent/directory');
            $safetyService = new MigrationSafetyBackupService($backupService, $invalidDir);

            $snapshotResult = $safetyService->createMigrationSafetyBackup('current', 'restore', 'test-snap-fail');
            self::assertFalse($snapshotResult['ok'], 'Snapshot creation must fail when directory is invalid');

            // Sentinel data must be completely untouched
            $val = $rootPdo->query("SELECT name FROM sentinel_safe WHERE id = 1")->fetchColumn();
            self::assertSame('untouched_sentinel', $val, 'Database must remain completely untouched after snapshot failure');
        } finally {
            MySqlTestEnvironment::dropDatabase($database);
        }
    }

    /**
     * Regression test: Real production backup (with table columns like `source` and
     * descriptions like `View system updates`) must pass validateUploadedSqlFile,
     * and verify-database CLI must successfully verify the schema.
     */
    public function testValidateUploadedRealProductionBackupAndVerifyDatabaseCli(): void
    {
        $database = MySqlTestEnvironment::createDatabase('pos_real_val_test');
        $rootPdo = MySqlTestEnvironment::connect($database);

        try {
            // Load base schema
            $schemaSql = file_get_contents(dirname(__DIR__, 3) . '/database/pos_schema.sql');
            $schemaSql = preg_replace('/CREATE DATABASE[^;]+;/i', '', $schemaSql);
            $schemaSql = preg_replace('/USE pos_db;/i', '', $schemaSql);
            $rootPdo->exec($schemaSql);

            // Apply all migrations to bring DB to current schema 057
            $migrationService = new \App\Services\MigrationService($rootPdo);
            $migResult = $migrationService->runAllMigrations(true);
            self::assertEmpty($migResult['errors'], 'All migrations must succeed');

            // Generate real backup using BackupService
            $backupService = new BackupService();
            $backupService->setDb($rootPdo);
            $backupDir = sys_get_temp_dir() . '/pos_val_bk_' . bin2hex(random_bytes(4));
            $backupFile = $backupService->createBackupFile($backupDir);
            self::assertFileExists($backupFile);

            // 1. Validate the generated production backup file
            $validation = $backupService->validateUploadedSqlFile([
                'name' => basename($backupFile),
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($backupFile),
                'tmp_name' => $backupFile,
            ]);

            self::assertTrue(
                $validation['ok'],
                'Real production backup must pass validateUploadedSqlFile: ' . ($validation['error'] ?? '')
            );

            // 2. Test verify-database CLI script against this database
            $phpBin = PHP_BINARY;
            $verifyScript = dirname(__DIR__, 2) . '/cli/verify-database.php';
            $port = MySqlTestEnvironment::configuration()['port'];
            $cmd = sprintf(
                '"%s" "%s"',
                $phpBin,
                $verifyScript
            );

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $env = array_merge(getenv(), [
                'DB_HOST' => MySqlTestEnvironment::configuration()['host'],
                'DB_PORT' => (string) $port,
                'DB_NAME' => $database,
                'DB_USER' => MySqlTestEnvironment::configuration()['user'],
                'DB_PASS' => MySqlTestEnvironment::configuration()['password'],
            ]);

            $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__, 2), $env);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            self::assertSame(0, $exitCode, "verify-database.php must exit with 0. stderr: {$stderr}");
            $parsed = json_decode((string) $stdout, true);
            self::assertTrue($parsed['ok'] ?? false, 'verify-database must return ok=true');
        } finally {
            if (isset($backupDir) && is_dir($backupDir)) {
                @array_map('unlink', glob("$backupDir/*.*") ?: []);
                @rmdir($backupDir);
            }
            MySqlTestEnvironment::dropDatabase($database);
        }
    }
}
