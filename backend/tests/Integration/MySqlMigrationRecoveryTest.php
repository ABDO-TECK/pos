<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\BackupService;
use App\Services\MigrationSafetyBackupService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('mysql')]
final class MySqlMigrationRecoveryTest extends TestCase
{
    private string $storage;
    private PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('POS_MIGRATION_RECOVERY_TEST') !== '1') {
            self::markTestSkipped('Set POS_MIGRATION_RECOVERY_TEST=1 to run against an isolated MariaDB instance.');
        }

        $this->storage = sys_get_temp_dir() . '/pos_mysql_recovery_' . bin2hex(random_bytes(5));
        mkdir($this->storage, 0750, true);
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', \DB_HOST, \DB_PORT, \DB_NAME),
            \DB_USER,
            \DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        $this->pdo->exec('DROP TABLE IF EXISTS schema_versions');
        $this->pdo->exec('DROP TABLE IF EXISTS recovery_fixture');
        $this->pdo->exec('CREATE TABLE recovery_fixture (id INT PRIMARY KEY, label VARCHAR(64) NOT NULL) ENGINE=InnoDB');
        $this->pdo->exec("INSERT INTO recovery_fixture (id, label) VALUES (1, 'pre-update customer row')");
        $this->pdo->exec('CREATE TABLE schema_versions (id INT AUTO_INCREMENT PRIMARY KEY, version VARCHAR(255) NOT NULL UNIQUE) ENGINE=InnoDB');
        $this->pdo->exec("INSERT INTO schema_versions (version) VALUES ('000_fixture_baseline.sql')");
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS schema_versions');
            $this->pdo->exec('DROP TABLE IF EXISTS recovery_fixture');
        }
        $this->deleteDirectory($this->storage ?? '');
    }

    public function testPartialDdlAndDataMutationRestoreToTheVerifiedPreUpdateState(): void
    {
        $backup = new BackupService();
        $backup->setDb($this->pdo);
        $safety = new MigrationSafetyBackupService($backup, $this->storage);
        $created = $safety->createMigrationSafetyBackup('1.1.47', '1.1.48', 'mysql-partial-ddl');

        self::assertTrue($created['ok']);
        self::assertTrue($safety->verifyBackup($created['backup_path'], 'mysql-partial-ddl')['ok']);

        // ALTER TABLE implicitly commits on MariaDB. The following deliberate
        // failure proves why a SQL transaction alone could not undo this path.
        $this->pdo->exec('ALTER TABLE recovery_fixture ADD COLUMN failed_update_marker VARCHAR(32) NULL');
        $this->pdo->exec("INSERT INTO recovery_fixture (id, label, failed_update_marker) VALUES (2, 'must disappear', 'partial')");
        $this->pdo->exec("INSERT INTO schema_versions (version) VALUES ('999_partial_failure.sql')");
        try {
            $this->pdo->exec('INSERT INTO table_that_does_not_exist VALUES (1)');
            self::fail('The controlled migration failure did not occur.');
        } catch (\PDOException) {
            // Recovery path begins only after the irreversible DDL is visible.
        }

        $restored = $safety->restoreMigrationSafetyBackup($created['backup_path'], 'mysql-partial-ddl');
        self::assertTrue($restored['ok']);

        $columns = $this->pdo->query('SHOW COLUMNS FROM recovery_fixture')->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotContains('failed_update_marker', $columns);
        self::assertSame([[1, 'pre-update customer row']], $this->pdo->query('SELECT id, label FROM recovery_fixture ORDER BY id')->fetchAll(PDO::FETCH_NUM));
        self::assertSame(['000_fixture_baseline.sql'], $this->pdo->query('SELECT version FROM schema_versions ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM recovery_fixture')->fetchColumn());
    }

    private function deleteDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) return;
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $directory . '/' . $entry;
            if (is_dir($path)) $this->deleteDirectory($path);
            else @unlink($path);
        }
        @rmdir($directory);
    }
}
