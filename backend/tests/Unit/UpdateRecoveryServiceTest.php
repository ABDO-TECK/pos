<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UpdateRecoveryService;
use App\Services\UpdateOperationLock;
use App\Services\UpdateService;
use App\Services\UpdateTelemetryService;
use PHPUnit\Framework\TestCase;

final class UpdateRecoveryServiceTest extends TestCase
{
    private string $storage;
    private UpdateRecoveryService $service;
    private UpdateTelemetryService $telemetryMock;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/pos-recovery-' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0755, true);
        $this->telemetryMock = $this->createMock(UpdateTelemetryService::class);
        $this->telemetryMock->method('recordEvent')->willReturn(true);
        $this->service = new UpdateRecoveryService($this->storage, $this->storage, null, $this->telemetryMock);
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

        $freshProcess = new UpdateRecoveryService($this->storage, $this->storage, $updateService, $this->telemetryMock);
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

    public function testCompletedRollbackStateIsAHealthyTerminalState(): void
    {
        $this->service->writeStateFile([
            'state' => 'rolled_back',
            'status' => 'rolled_back',
            'from_version' => '0.0.1',
            'to_version' => '0.0.3',
            'backup_snapshot' => $this->storage . '/snapshot',
        ]);

        $diagnosis = $this->service->diagnoseState();

        self::assertSame('rolled_back', $diagnosis['status']);
        self::assertFalse($diagnosis['problem_detected']);
        self::assertSame('none', $diagnosis['recommended_action']);
    }

    public function testFailedRollbackStateRequiresEscalation(): void
    {
        $this->service->writeStateFile([
            'state' => 'rollback_failed',
            'status' => 'rollback_failed',
            'error' => 'A required snapshot file could not be restored.',
            'backup_snapshot' => $this->storage . '/snapshot',
        ]);

        $diagnosis = $this->service->diagnoseState();

        self::assertSame('rollback_failed', $diagnosis['status']);
        self::assertTrue($diagnosis['problem_detected']);
        self::assertSame('escalate', $diagnosis['recommended_action']);
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

    public function testRecoveryActionRefusesToRaceAnActiveUpdateOwner(): void
    {
        $this->service->writeStateFile([
            'state' => 'applying',
            'to_version' => '0.0.2',
            'backup_snapshot' => $this->storage . '/snapshot',
        ]);

        $updateLock = new UpdateOperationLock($this->storage);
        $lease = $updateLock->acquire('backend_delta_apply', ['target_version' => '0.0.2']);
        self::assertTrue($lease['acquired']);

        try {
            $result = $this->service->executeAction('clear');
        } finally {
            $updateLock->release();
        }

        self::assertFalse($result['ok']);
        self::assertSame('update_in_progress', $result['reason_code']);
        self::assertFileExists($this->storage . '/update-state.json');
    }

    public function testPendingFullInstallHasAnExplicitRecoveryDiagnosis(): void
    {
        $this->service->writeStateFile([
            'state' => 'full_ready_to_install',
            'target_version' => '0.0.2',
            'updated_at' => date('c'),
        ]);

        $diagnosis = $this->service->diagnoseState();

        self::assertSame('pending_install', $diagnosis['status']);
        self::assertSame('none', $diagnosis['recommended_action']);
        self::assertTrue($diagnosis['problem_detected']);
    }

    public function testInterruptedFullInstallRequiresManualEscalation(): void
    {
        $this->service->writeStateFile([
            'state' => 'installing',
            'target_version' => '0.0.2',
            'updated_at' => date('c'),
        ]);

        $diagnosis = $this->service->diagnoseState();

        self::assertSame('interrupted_installation', $diagnosis['status']);
        self::assertSame('escalate', $diagnosis['recommended_action']);
        self::assertTrue($diagnosis['problem_detected']);
    }
}
