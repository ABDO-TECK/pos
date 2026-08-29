<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\BackupService;
use App\Services\DeltaUpdateService;
use App\Services\FrontendBuildService;
use App\Services\GitService;
use App\Services\MigrationSafetyBackupService;
use App\Services\UpdateManifestService;
use App\Services\UpdateRecoveryService;
use App\Services\UpdateService;
use App\Services\UpdateTelemetryService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the destructive Delta recovery boundary with a disposable MariaDB
 * database and the real snapshot, safety-backup and startup-recovery services.
 */
#[Group('mysql')]
final class CombinedDeltaRecoveryTest extends TestCase
{
    private string $fixtureRoot;
    private string $storage;
    private string $releaseRoot;
    private PDO $pdo;

    /** @var array<string, string> */
    private array $beforeFiles;

    protected function setUp(): void
    {
        if (getenv('POS_COMBINED_DELTA_RECOVERY_TEST') !== '1') {
            self::markTestSkipped('Set POS_COMBINED_DELTA_RECOVERY_TEST=1 to run against the isolated MariaDB fixture.');
        }

        $base = sys_get_temp_dir() . '/pos_combined_delta_recovery_' . bin2hex(random_bytes(6));
        $this->fixtureRoot = $base . '/deployment';
        $this->storage = $base . '/customer-data';
        $this->releaseRoot = $base . '/release';
        foreach ([
            $this->fixtureRoot . '/backend',
            $this->fixtureRoot . '/frontend/dist/assets',
            $this->storage . '/uploads',
            $this->releaseRoot . '/frontend/dist/assets',
        ] as $directory) {
            mkdir($directory, 0750, true);
        }

        $assets = glob(dirname(__DIR__, 3) . '/frontend/dist/assets/*.js') ?: [];
        if (count($assets) < 2) {
            self::markTestSkipped('The production frontend build artifacts required for this rehearsal are unavailable.');
        }
        $beforeAsset = (string) file_get_contents($assets[0]);
        $targetAsset = (string) file_get_contents($assets[1]);
        self::assertNotSame(hash('sha256', $beforeAsset), hash('sha256', $targetAsset));

        $projectPhar = dirname(__DIR__, 3) . '/backend/backend.phar';
        self::assertFileExists($projectPhar);
        self::assertTrue(copy($projectPhar, $this->fixtureRoot . '/backend/backend.phar'));
        file_put_contents($this->fixtureRoot . '/frontend/dist/assets/replaced.js', $beforeAsset);
        file_put_contents($this->fixtureRoot . '/frontend/dist/assets/deleted.js', 'fixture artifact that VERSION B deletes');
        file_put_contents($this->fixtureRoot . '/version.json', "{\"version\":\"1.1.48\"}\n");
        file_put_contents($this->storage . '/settings.json', '{"store":"recovery-fixture","currency":"EGP"}');
        file_put_contents($this->storage . '/uploads/customer-receipt.txt', 'customer-owned upload marker');

        file_put_contents($this->releaseRoot . '/frontend/dist/assets/replaced.js', $targetAsset);
        file_put_contents($this->releaseRoot . '/frontend/dist/assets/new.js', $targetAsset);

        $this->beforeFiles = [
            'backend/backend.phar' => hash_file('sha256', $this->fixtureRoot . '/backend/backend.phar'),
            'frontend/dist/assets/replaced.js' => hash_file('sha256', $this->fixtureRoot . '/frontend/dist/assets/replaced.js'),
            'frontend/dist/assets/deleted.js' => hash_file('sha256', $this->fixtureRoot . '/frontend/dist/assets/deleted.js'),
            'version.json' => hash_file('sha256', $this->fixtureRoot . '/version.json'),
            'settings.json' => hash_file('sha256', $this->storage . '/settings.json'),
            'uploads/customer-receipt.txt' => hash_file('sha256', $this->storage . '/uploads/customer-receipt.txt'),
        ];

        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', \DB_HOST, \DB_PORT, \DB_NAME),
            \DB_USER,
            \DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        $this->pdo->exec('DROP TABLE IF EXISTS schema_versions');
        $this->pdo->exec('DROP TABLE IF EXISTS recovery_customer');
        $this->pdo->exec('CREATE TABLE recovery_customer (id INT PRIMARY KEY, label VARCHAR(64) NOT NULL) ENGINE=InnoDB');
        $this->pdo->exec("INSERT INTO recovery_customer (id, label) VALUES (1, 'before customer row')");
        $this->pdo->exec('CREATE TABLE schema_versions (id INT AUTO_INCREMENT PRIMARY KEY, version VARCHAR(255) NOT NULL UNIQUE) ENGINE=InnoDB');
        $this->pdo->exec("INSERT INTO schema_versions (version) VALUES ('000_before_rehearsal.sql')");
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS schema_versions');
            $this->pdo->exec('DROP TABLE IF EXISTS recovery_customer');
        }
        $this->deleteDirectory(dirname($this->fixtureRoot ?? sys_get_temp_dir()));
    }

    public function testPartialMigrationFailureRestoresVerifiedDatabaseAndManagedFiles(): void
    {
        [$delta, $safety, $snapshot] = $this->beginDestructiveDelta();

        // This DDL commits in MariaDB. The second statement deliberately fails
        // afterwards, proving that rollback requires the verified SQL snapshot.
        $this->applyDatabaseChangeThenFail();

        $updateService = $this->makeUpdateService($delta, $safety);
        $rollback = $updateService->rollbackUpdate($snapshot['snapshot_path']);

        self::assertTrue($rollback['ok']);
        $this->assertKnownGoodState();
    }

    public function testFreshStartupUsesPersistedDeltaJournalToRecoverInterruptedDestructiveUpdate(): void
    {
        [$delta, $safety, $snapshot] = $this->beginDestructiveDelta();
        $this->applyDatabaseChangeThenFail();

        // This is the durable state left by an abrupt process termination after
        // files and the first migration have changed state. A new service object
        // models a fresh backend startup with no updater process memory.
        $telemetry = $this->createMock(UpdateTelemetryService::class);
        $telemetry->method('recordEvent')->willReturn(true);
        $freshStartup = new UpdateRecoveryService(
            $this->storage,
            $this->fixtureRoot,
            $this->makeUpdateService($this->freshDeltaService(), $this->freshSafetyService(), $telemetry),
            $telemetry,
            $this->pdo,
        );
        $freshStartup->writeStateFile([
            'state' => 'migrating',
            'from_version' => '1.1.48',
            'to_version' => '1.1.49',
            'backup_snapshot' => $snapshot['snapshot_path'],
            'db_recovery' => $snapshot['db_recovery'],
            'migration_started' => true,
        ]);

        $result = $freshStartup->autoRecoverOnStartup();

        self::assertTrue($result['ok']);
        self::assertSame('rollback', $result['action']);
        self::assertSame('rolled_back', $freshStartup->readStateFile()['status']);
        $this->assertKnownGoodState();
    }

    public function testInvalidDatabaseSafetyBackupDoesNotClaimSuccessfulRollbackOrRollbackFiles(): void
    {
        [$delta, $safety, $snapshot] = $this->beginDestructiveDelta();
        $this->applyDatabaseChangeThenFail();
        file_put_contents($snapshot['db_recovery']['backup_path'], 'intentionally invalid fixture backup');

        $telemetry = $this->createMock(UpdateTelemetryService::class);
        $telemetry->method('recordEvent')->willReturn(true);
        $freshStartup = new UpdateRecoveryService(
            $this->storage,
            $this->fixtureRoot,
            $this->makeUpdateService($this->freshDeltaService(), $this->freshSafetyService(), $telemetry),
            $telemetry,
            $this->pdo,
        );
        $freshStartup->writeStateFile([
            'state' => 'migrating',
            'from_version' => '1.1.48',
            'to_version' => '1.1.49',
            'backup_snapshot' => $snapshot['snapshot_path'],
            'db_recovery' => $snapshot['db_recovery'],
            'migration_started' => true,
        ]);

        $rollback = $freshStartup->autoRecoverOnStartup();

        self::assertFalse($rollback['ok']);
        self::assertStringContainsString('Database recovery failed', $rollback['error']);
        self::assertSame('1.1.49', json_decode((string) file_get_contents($this->fixtureRoot . '/version.json'), true)['version']);
        self::assertSame('database_recovery_failed', $freshStartup->readStateFile()['status']);
        self::assertNotSame($this->beforeFiles['frontend/dist/assets/replaced.js'], hash_file('sha256', $this->fixtureRoot . '/frontend/dist/assets/replaced.js'));
    }

    /** @return array{0:DeltaUpdateService,1:MigrationSafetyBackupService,2:array{snapshot_path:string,db_recovery:array{backup_path:string,recovery_id:string}}} */
    private function beginDestructiveDelta(): array
    {
        $manifest = $this->manifest();
        $safety = $this->freshSafetyService();
        $recovery = $safety->createMigrationSafetyBackup('1.1.48', '1.1.49', 'combined-fixture');
        self::assertTrue($recovery['ok']);
        self::assertTrue($safety->verifyBackup($recovery['backup_path'], 'combined-fixture')['ok']);

        $delta = new DeltaUpdateService(new UpdateManifestService(), $this->fixtureRoot, $this->storage);
        $snapshot = $delta->createBackupSnapshot('1.1.48', '1.1.49', $manifest, [
            'backup_path' => $recovery['backup_path'],
            'recovery_id' => $recovery['recovery_id'],
        ]);
        self::assertTrue($snapshot['ok']);
        self::assertTrue($delta->stageFromLocalFiles($manifest, $this->releaseRoot)['ok']);
        $applied = $delta->applyStagedFiles($manifest, $snapshot['snapshot_path']);
        self::assertTrue($applied['ok']);
        self::assertSame('1.1.49', json_decode((string) file_get_contents($this->fixtureRoot . '/version.json'), true)['version']);
        self::assertFileDoesNotExist($this->fixtureRoot . '/frontend/dist/assets/deleted.js');
        self::assertFileExists($this->fixtureRoot . '/frontend/dist/assets/new.js');

        $snapshot['db_recovery'] = [
            'backup_path' => $recovery['backup_path'],
            'recovery_id' => $recovery['recovery_id'],
        ];

        return [$delta, $safety, $snapshot];
    }

    private function applyDatabaseChangeThenFail(): void
    {
        $this->pdo->exec('ALTER TABLE recovery_customer ADD COLUMN failed_update_marker VARCHAR(32) NULL');
        $this->pdo->exec("INSERT INTO recovery_customer (id, label, failed_update_marker) VALUES (2, 'must disappear', 'partial')");
        $this->pdo->exec("INSERT INTO schema_versions (version) VALUES ('999_failed_after_ddl.sql')");
        try {
            $this->pdo->exec('INSERT INTO table_that_does_not_exist VALUES (1)');
            self::fail('The deterministic post-DDL migration failure did not occur.');
        } catch (\PDOException) {
            // The first destructive migration has completed; recovery can begin.
        }
    }

    private function makeUpdateService(
        DeltaUpdateService $delta,
        MigrationSafetyBackupService $safety,
        ?UpdateTelemetryService $telemetry = null,
    ): UpdateService {
        return new UpdateService(
            new GitService(),
            new FrontendBuildService(),
            new BackupService(),
            $delta,
            new UpdateManifestService(),
            null,
            null,
            null,
            $telemetry,
            $safety,
        );
    }

    private function freshDeltaService(): DeltaUpdateService
    {
        return new DeltaUpdateService(new UpdateManifestService(), $this->fixtureRoot, $this->storage);
    }

    private function freshSafetyService(): MigrationSafetyBackupService
    {
        $backup = new BackupService();
        $backup->setDb($this->pdo);

        return new MigrationSafetyBackupService($backup, $this->storage);
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $replaced = $this->releaseRoot . '/frontend/dist/assets/replaced.js';
        $new = $this->releaseRoot . '/frontend/dist/assets/new.js';

        return [
            'version' => '1.1.49',
            'files' => [
                ['path' => 'frontend/dist/assets/replaced.js', 'action' => 'replace', 'sha256' => hash_file('sha256', $replaced), 'size' => filesize($replaced)],
                ['path' => 'frontend/dist/assets/new.js', 'action' => 'add', 'sha256' => hash_file('sha256', $new), 'size' => filesize($new)],
            ],
            'deleted_files' => ['frontend/dist/assets/deleted.js'],
        ];
    }

    private function assertKnownGoodState(): void
    {
        self::assertSame(['id', 'label'], $this->pdo->query('SHOW COLUMNS FROM recovery_customer')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame([[1, 'before customer row']], $this->pdo->query('SELECT id, label FROM recovery_customer ORDER BY id')->fetchAll(PDO::FETCH_NUM));
        self::assertSame(['000_before_rehearsal.sql'], $this->pdo->query('SELECT version FROM schema_versions ORDER BY version')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM recovery_customer')->fetchColumn());
        self::assertSame('1.1.48', json_decode((string) file_get_contents($this->fixtureRoot . '/version.json'), true)['version']);
        self::assertSame($this->beforeFiles['backend/backend.phar'], hash_file('sha256', $this->fixtureRoot . '/backend/backend.phar'));
        self::assertSame($this->beforeFiles['frontend/dist/assets/replaced.js'], hash_file('sha256', $this->fixtureRoot . '/frontend/dist/assets/replaced.js'));
        self::assertSame($this->beforeFiles['frontend/dist/assets/deleted.js'], hash_file('sha256', $this->fixtureRoot . '/frontend/dist/assets/deleted.js'));
        self::assertFileDoesNotExist($this->fixtureRoot . '/frontend/dist/assets/new.js');
        self::assertSame($this->beforeFiles['version.json'], hash_file('sha256', $this->fixtureRoot . '/version.json'));
        self::assertSame($this->beforeFiles['settings.json'], hash_file('sha256', $this->storage . '/settings.json'));
        self::assertSame($this->beforeFiles['uploads/customer-receipt.txt'], hash_file('sha256', $this->storage . '/uploads/customer-receipt.txt'));
        self::assertSame(1, (int) $this->pdo->query('SELECT 1')->fetchColumn());
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
