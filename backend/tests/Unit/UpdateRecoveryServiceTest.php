<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UpdateRecoveryService;
use App\Services\UpdateService;
use PHPUnit\Framework\TestCase;

final class UpdateRecoveryServiceTest extends TestCase
{
    private string $storage;
    private UpdateRecoveryService $service;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/pos-recovery-' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0755, true);
        $this->service = new UpdateRecoveryService($this->storage, $this->storage);
    }

    protected function tearDown(): void
    {
        @unlink($this->storage . '/update-state.json');
        @rmdir($this->storage);
    }

    public function testDeltaJournalSchemaTriggersMigrationRollbackAfterRestart(): void
    {
        $this->service->writeStateFile([
            'state' => 'migrating',
            'to_version' => '1.1.49',
            'backup_snapshot' => '/trusted/snapshot',
            'db_recovery' => ['backup_path' => '/trusted/backup.sql', 'recovery_id' => 'fixture'],
        ]);

        $diagnosis = $this->service->diagnoseState();

        self::assertSame('failed_migration', $diagnosis['status']);
        self::assertSame('rollback', $diagnosis['recommended_action']);
        self::assertSame('1.1.49', $diagnosis['details']['target_version']);
    }

    public function testFreshStartupRecoversPersistedDesktopDeltaJournal(): void
    {
        $snapshot = $this->storage . '/patch_1.1.48_to_1.1.49';
        mkdir($snapshot, 0755, true);
        $updateService = $this->createMock(UpdateService::class);
        $updateService->expects(self::once())
            ->method('rollbackUpdate')
            ->with($snapshot)
            ->willReturn(['ok' => true, 'snapshot' => $snapshot, 'logs' => ['restored']]);

        $freshProcess = new UpdateRecoveryService($this->storage, $this->storage, $updateService);
        $freshProcess->writeStateFile([
            'state' => 'migrating',
            'to_version' => '1.1.49',
            'backup_snapshot' => $snapshot,
            'db_recovery' => ['backup_path' => $this->storage . '/recovery.sql', 'recovery_id' => 'fixture'],
        ]);

        $result = $freshProcess->autoRecoverOnStartup();

        self::assertTrue($result['ok']);
        self::assertSame('rollback', $result['action']);
        self::assertSame('rolled_back', $freshProcess->readStateFile()['status']);
        @rmdir($snapshot);
    }

    public function testHealthCheckUsesSourceEntrypointForSourceLayout(): void
    {
        $root = $this->storage . '/source';
        mkdir($root . '/backend', 0755, true);
        file_put_contents($root . '/version.json', '{"version":"1.1.48"}');
        file_put_contents($root . '/backend/index.php', str_repeat('x', 51));

        $health = (new UpdateRecoveryService($this->storage, $root))->validatePostUpdateHealth();

        self::assertTrue($health['checks']['version_file']);
        self::assertTrue($health['checks']['backend_entry']);
    }

    public function testHealthCheckUsesPharEntrypointForPackagedLayout(): void
    {
        $root = $this->storage . '/app.asar.unpacked';
        mkdir($root . '/backend', 0755, true);
        file_put_contents($root . '/version.json', '{"version":"1.1.48"}');
        file_put_contents($root . '/backend/backend.phar', str_repeat('x', 51));

        $health = (new UpdateRecoveryService($this->storage, $root))->validatePostUpdateHealth();

        self::assertTrue($health['checks']['version_file']);
        self::assertTrue($health['checks']['backend_entry']);
        self::assertNotContains('backend/index.php is missing or empty', $health['errors']);
    }
}
