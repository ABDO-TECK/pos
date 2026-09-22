<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\UpdateService;
use App\Services\GitService;
use App\Services\FrontendBuildService;
use App\Services\BackupService;
use App\Services\MigrationSafetyBackupService;
use App\Services\UpdateOperationLock;

class UpdateServiceTest extends TestCase
{
    private GitService|\PHPUnit\Framework\MockObject\MockObject $gitMock;
    private FrontendBuildService|\PHPUnit\Framework\MockObject\MockObject $buildMock;
    private BackupService|\PHPUnit\Framework\MockObject\MockObject $backupMock;
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->gitMock = $this->createMock(GitService::class);
        $this->buildMock = $this->createMock(FrontendBuildService::class);
        $this->backupMock = $this->createMock(BackupService::class);

        // Backup existing env vars
        $this->envBackup['ENABLE_UPDATE_CHECKS'] = $_ENV['ENABLE_UPDATE_CHECKS'] ?? null;
        $this->envBackup['UPDATE_SERVER_URL'] = $_ENV['UPDATE_SERVER_URL'] ?? null;
        
        unset($_ENV['ENABLE_UPDATE_CHECKS']);
        unset($_ENV['UPDATE_SERVER_URL']);
        putenv('ENABLE_UPDATE_CHECKS');
        putenv('UPDATE_SERVER_URL');

        $this->cleanCacheFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanCacheFiles();

        // Restore env vars
        foreach ($this->envBackup as $key => $val) {
            if ($val === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $val;
                putenv("{$key}={$val}");
            }
        }
    }

    private function cleanCacheFiles(): void
    {
        $storage = realpath(__DIR__ . '/../../storage');
        if ($storage && is_dir($storage)) {
            $files = glob($storage . '/remote_version_cache_*.json');
            if (is_array($files)) {
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
        }
    }

    public function testCheckForUpdateWhenDisabled(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'false';

        $service = new UpdateService($this->gitMock, $this->buildMock, $this->backupMock);
        $res = $service->checkForUpdate(true);

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['updates_disabled']);
        $this->assertFalse($res['updates_unreachable']);
        $this->assertEquals('خادم التحديثات غير مهيأ.', $res['message']);
        $this->assertFalse($res['has_update']);
    }

    public function testCheckForUpdateWhenUrlEmpty(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $_ENV['UPDATE_SERVER_URL'] = '';

        $service = new UpdateService($this->gitMock, $this->buildMock, $this->backupMock);
        $res = $service->checkForUpdate();

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['updates_disabled']);
        $this->assertFalse($res['updates_unreachable']);
        $this->assertEquals('خادم التحديثات غير مهيأ.', $res['message']);
        $this->assertFalse($res['has_update']);
    }

    public function testCheckForUpdateWhenUnreachable(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $_ENV['UPDATE_SERVER_URL'] = 'https://api.github.com/invalid-url';

        // Partially mock UpdateService to mock fetchRemoteVersion to return null (unreachable)
        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock])
            ->onlyMethods(['fetchRemoteVersion'])
            ->getMock();

        $service->method('fetchRemoteVersion')->willReturn(null);

        $res = $service->checkForUpdate();

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['updates_disabled']);
        $this->assertTrue($res['updates_unreachable']);
        $this->assertEquals('تعذر الاتصال بخادم التحديثات. تحقق من الاتصال أو إعدادات الخادم.', $res['message']);
        $this->assertFalse($res['has_update']);
    }

    public function testManualCheckWithNewerVersionReturnsUpdateAvailableStatus(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock])
            ->onlyMethods(['fetchRemoteVersionDiagnostics', 'getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn([
            'version' => '1.1.32',
            'released_at' => '2026-06-10',
            'changelog' => [],
        ]);

        $service->method('fetchRemoteVersionDiagnostics')->willReturn([
            'ok' => true,
            'data' => [
                'version' => '1.1.33',
                'released_at' => '2026-06-15',
                'changelog' => ['New release'],
            ],
            'checkedUrl' => 'https://raw.githubusercontent.com/ABDO-TECK/pos/main/version.json',
            'errorCode' => null,
            'details' => null,
        ]);

        $res = $service->checkForUpdate();

        $this->assertSame('update_available', $res['status']);
        $this->assertTrue($res['success']);
        $this->assertTrue($res['updateAvailable']);
        $this->assertSame('1.1.32', $res['currentVersion']);
        $this->assertSame('1.1.33', $res['latestVersion']);
        $this->assertSame('https://raw.githubusercontent.com/ABDO-TECK/pos/main/version.json', $res['checkedUrl']);
    }

    public function testManualCheckWithSameVersionReturnsNoUpdateAvailableStatus(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock])
            ->onlyMethods(['fetchRemoteVersionDiagnostics', 'getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn([
            'version' => '1.1.32',
            'released_at' => '2026-06-10',
            'changelog' => [],
        ]);

        $service->method('fetchRemoteVersionDiagnostics')->willReturn([
            'ok' => true,
            'data' => [
                'version' => '1.1.32',
                'released_at' => '2026-06-10',
                'changelog' => [],
            ],
            'checkedUrl' => 'https://raw.githubusercontent.com/ABDO-TECK/pos/main/version.json',
            'errorCode' => null,
            'details' => null,
        ]);

        $res = $service->checkForUpdate();

        $this->assertSame('no_update_available', $res['status']);
        $this->assertTrue($res['success']);
        $this->assertFalse($res['updateAvailable']);
        $this->assertSame('1.1.32', $res['currentVersion']);
        $this->assertSame('1.1.32', $res['latestVersion']);
    }

    public function testManualCheckWithNetworkFailureReturnsClearDiagnostics(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock])
            ->onlyMethods(['fetchRemoteVersionDiagnostics', 'getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn([
            'version' => '1.1.32',
            'released_at' => '2026-06-10',
            'changelog' => [],
        ]);

        $service->method('fetchRemoteVersionDiagnostics')->willReturn([
            'ok' => false,
            'data' => null,
            'checkedUrl' => 'https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main',
            'errorCode' => 'github_network_timeout',
            'details' => 'Operation timed out after 15000 milliseconds',
        ]);

        $res = $service->checkForUpdate();

        $this->assertFalse($res['success']);
        $this->assertSame('github_network_timeout', $res['status']);
        $this->assertSame('github_network_timeout', $res['errorCode']);
        $this->assertStringContainsString('Operation timed out', $res['details']);
        $this->assertSame('https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main', $res['checkedUrl']);
    }

    public function testFetchRemoteVersionDiagnosticsClassifiesInvalidJson(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $_ENV['UPDATE_SERVER_URL'] = 'https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main';

        $service = new class($this->gitMock, $this->buildMock, $this->backupMock) extends UpdateService {
            protected function executeRemoteVersionRequest(array $curlOptions): array
            {
                return ['body' => '{"version":', 'httpCode' => 200, 'curlErr' => '', 'curlErrNo' => 0];
            }

            public function diagnostics(): array
            {
                return $this->fetchRemoteVersionDiagnostics();
            }
        };

        $res = $service->diagnostics();

        $this->assertFalse($res['ok']);
        $this->assertSame('invalid_version_json', $res['errorCode']);
        $this->assertSame('https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main', $res['checkedUrl']);
    }

    public function testFetchRemoteVersionDiagnosticsClassifiesGitHub404(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $_ENV['UPDATE_SERVER_URL'] = 'https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main';

        $service = new class($this->gitMock, $this->buildMock, $this->backupMock) extends UpdateService {
            protected function executeRemoteVersionRequest(array $curlOptions): array
            {
                return ['body' => '{"message":"Not Found"}', 'httpCode' => 404, 'curlErr' => '', 'curlErrNo' => 0];
            }

            public function diagnostics(): array
            {
                return $this->fetchRemoteVersionDiagnostics();
            }
        };

        $res = $service->diagnostics();

        $this->assertFalse($res['ok']);
        $this->assertSame('github_http_404', $res['errorCode']);
        $this->assertStringContainsString('HTTP 404', $res['details']);
    }

    public function testCheckForUpdateWhenHasUpdate(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $_ENV['UPDATE_SERVER_URL'] = 'https://api.github.com/valid-url';

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock])
            ->onlyMethods(['fetchRemoteVersionDiagnostics', 'getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn([
            'version' => '1.0.0',
            'released_at' => '2026-06-01',
            'changelog' => ['Fix bugs']
        ]);

        $service->method('fetchRemoteVersionDiagnostics')->willReturn([
            'ok' => true,
            'data' => [
                'version' => '1.1.0',
                'released_at' => '2026-06-15',
                'changelog' => ['New features'],
                'requires_npm_install' => true,
            ],
            'checkedUrl' => 'https://api.github.com/valid-url',
            'errorCode' => null,
            'details' => null,
        ]);

        $res = $service->checkForUpdate();

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['updates_disabled']);
        $this->assertFalse($res['updates_unreachable']);
        $this->assertTrue($res['has_update']);
        $this->assertEquals('1.0.0', $res['current_version']);
        $this->assertEquals('1.1.0', $res['latest_version']);
        $this->assertTrue($res['requires_npm_install']);
        $this->assertEquals(['New features'], $res['changelog']);
    }

    public function testApplyUpdateWhenDisabled(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'false';

        $service = new UpdateService($this->gitMock, $this->buildMock, $this->backupMock);
        $res = $service->applyUpdate(false);

        $this->assertFalse($res['ok']);
        $this->assertTrue($res['updates_disabled']);
        $this->assertEquals('خادم التحديثات غير مهيأ.', $res['error']);
        $this->assertEquals(403, $res['code']);
    }

    public function testCheckForUpdateDetectsDeltaUpdateWhenManifestPresent(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $_ENV['UPDATE_SERVER_URL'] = 'https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main';

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock])
            ->onlyMethods(['fetchRemoteVersionDiagnostics', 'getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn([
            'version' => '1.1.40',
            'released_at' => '2026-06-01',
            'changelog' => [],
        ]);

        $service->method('fetchRemoteVersionDiagnostics')->willReturn([
            'ok' => true,
            'data' => [
                'version' => '1.1.41',
                'released_at' => '2026-08-26',
                'changelog' => ['Delta update release'],
                'manifest' => [
                    'version' => '1.1.41',
                    'minimum_version' => '1.0.0',
                    'files' => [
                        [
                            'path' => 'backend/Helpers/Logger.php',
                            'action' => 'replace',
                            'sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                        ],
                    ],
                ],
            ],
            'checkedUrl' => 'https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main',
            'errorCode' => null,
            'details' => null,
        ]);

        $res = $service->checkForUpdate(true);

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['has_update']);
        $this->assertSame('delta', $res['update_type']);
        $this->assertTrue($res['is_delta']);
        $this->assertSame(1, $res['files_count']);

        $legacy = $service->checkForUpdate(false);
        $this->assertTrue($legacy['has_update']);
        $this->assertSame('bootstrap', $legacy['update_type']);
        $this->assertTrue($legacy['bootstrap_required']);
        $this->assertFalse($legacy['is_delta']);
    }

    public function testCheckForUpdateFallsBackToFullWhenVersionBelowMinimum(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $_ENV['UPDATE_SERVER_URL'] = 'https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main';

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock])
            ->onlyMethods(['fetchRemoteVersionDiagnostics', 'getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn([
            'version' => '1.0.5',
            'released_at' => '2026-01-01',
            'changelog' => [],
        ]);

        $service->method('fetchRemoteVersionDiagnostics')->willReturn([
            'ok' => true,
            'data' => [
                'version' => '1.1.41',
                'released_at' => '2026-08-26',
                'changelog' => ['Delta update release'],
                'manifest' => [
                    'version' => '1.1.41',
                    'minimum_version' => '1.1.0',
                    'files' => [
                        [
                            'path' => 'backend/Helpers/Logger.php',
                            'action' => 'replace',
                            'sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                        ],
                    ],
                ],
            ],
            'checkedUrl' => 'https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main',
            'errorCode' => null,
            'details' => null,
        ]);

        $res = $service->checkForUpdate();

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['has_update']);
        $this->assertSame('full', $res['update_type']);
        $this->assertFalse($res['is_delta']);
        $this->assertStringContainsString('below the minimum required version', $res['fallback_reason']);
    }

    public function testApplyUpdateAppliesDeltaUpdateSuccessfully(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $_ENV['UPDATE_SERVER_URL'] = 'https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main';

        $tempRoot = sys_get_temp_dir() . '/update_svc_test_' . bin2hex(random_bytes(4));
        @mkdir($tempRoot . '/backend/Helpers', 0755, true);
        @mkdir($tempRoot . '/backend/storage', 0755, true);
        file_put_contents($tempRoot . '/version.json', json_encode(['version' => '1.1.40']));
        file_put_contents($tempRoot . '/backend/Helpers/Logger.php', '<?php echo "Original Logger";');

        $manifestService = new \App\Services\UpdateManifestService();
        $deltaMock = $this->getMockBuilder(\App\Services\DeltaUpdateService::class)
            ->setConstructorArgs([$manifestService, $tempRoot, $tempRoot . '/backend/storage'])
            ->onlyMethods(['downloadFilesToStaging', 'applyStagedFiles', 'createBackupSnapshot'])
            ->getMock();

        $deltaMock->method('downloadFilesToStaging')->willReturn([
            'ok' => true,
            'staging_dir' => $tempRoot . '/backend/storage/staging',
            'downloaded_count' => 1,
            'errors' => [],
            'logs' => ['Downloaded files to staging.'],
        ]);

        $deltaMock->method('createBackupSnapshot')->willReturn([
            'ok' => true,
            'snapshot_path' => $tempRoot . '/backend/storage/snapshot_123',
            'backed_up_files' => ['backend/Helpers/Logger.php'],
            'new_files' => [],
            'error' => null,
        ]);

        $deltaMock->method('applyStagedFiles')->willReturn([
            'ok' => true,
            'applied_files' => ['backend/Helpers/Logger.php'],
            'deleted_files' => [],
            'errors' => [],
            'logs' => ['Applied files to root.'],
            'rolled_back' => false,
        ]);

        $this->backupMock->expects($this->never())->method('createBackupFile');

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock, $deltaMock, $manifestService])
            ->onlyMethods(['fetchRemoteVersion', 'getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn(['version' => '1.1.40']);
        $service->method('fetchRemoteVersion')->willReturn([
            'version' => '1.1.41',
            'manifest' => [
                'version' => '1.1.41',
                'minimum_version' => '1.0.0',
                'files' => [
                    [
                        'path' => 'backend/Helpers/Logger.php',
                        'action' => 'replace',
                        'sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                    ],
                ],
            ],
        ]);

        $res = $service->applyUpdate(false);

        $this->assertTrue($res['ok']);
        $this->assertSame('delta', $res['data']['update_type']);
        $this->assertSame('1.1.41', $res['data']['latest_version']);
        $this->assertSame(['backend/Helpers/Logger.php'], $res['data']['applied_files']);
    }

    public function testApplyUpdateMigrationFailureDoesNotClaimRollbackWhenFileRestoreFails(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $_ENV['UPDATE_SERVER_URL'] = 'https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main';

        $tempRoot = sys_get_temp_dir() . '/update_mig_fail_' . bin2hex(random_bytes(4));
        @mkdir($tempRoot . '/backend/Helpers', 0755, true);
        @mkdir($tempRoot . '/backend/storage', 0755, true);
        file_put_contents($tempRoot . '/version.json', json_encode(['version' => '1.1.40']));
        file_put_contents($tempRoot . '/backend/Helpers/Logger.php', '<?php echo "Original Logger";');

        $manifestService = new \App\Services\UpdateManifestService();
        $deltaMock = $this->getMockBuilder(\App\Services\DeltaUpdateService::class)
            ->setConstructorArgs([$manifestService, $tempRoot, $tempRoot . '/backend/storage'])
            ->onlyMethods(['downloadFilesToStaging', 'applyStagedFiles', 'createBackupSnapshot', 'rollbackFiles'])
            ->getMock();

        $deltaMock->method('downloadFilesToStaging')->willReturn([
            'ok' => true,
            'staging_dir' => $tempRoot . '/backend/storage/staging',
            'downloaded_count' => 1,
            'errors' => [],
            'logs' => ['Downloaded files to staging.'],
        ]);

        $deltaMock->method('createBackupSnapshot')->willReturn([
            'ok' => true,
            'snapshot_path' => $tempRoot . '/backend/storage/snapshot_mig_fail',
            'backed_up_files' => ['backend/Helpers/Logger.php'],
            'new_files' => [],
            'error' => null,
        ]);

        $deltaMock->method('applyStagedFiles')->willReturn([
            'ok' => true,
            'applied_files' => ['backend/Helpers/Logger.php'],
            'deleted_files' => [],
            'errors' => [],
            'logs' => ['Applied files to root.'],
            'rolled_back' => false,
        ]);

        // Expect rollbackFiles to be invoked automatically when migration fails
        $deltaMock->expects($this->once())
            ->method('rollbackFiles')
            ->with($tempRoot . '/backend/storage/snapshot_mig_fail')
            ->willReturn([
                'ok' => false,
                'restored_files' => [],
                'removed_new_files' => [],
                'errors' => ['Failed to restore backend/Helpers/Logger.php from backup.'],
                'logs' => ['Rollback failed.'],
            ]);

        $migrationMock = $this->createMock(\App\Services\MigrationService::class);
        $migrationMock->method('runMigrations')->willReturn([
            'executed' => 0,
            'errors' => ['Syntax error in migration 045_add_column.sql'],
        ]);

        $recoveryMock = $this->createMock(MigrationSafetyBackupService::class);
        $recoveryMock->expects($this->once())
            ->method('createMigrationSafetyBackup')
            ->willReturn([
                'ok' => true,
                'backup_path' => $tempRoot . '/backend/storage/update-backups/migration-safety/fixture.sql',
                'recovery_id' => 'fixture',
            ]);
        $recoveryMock->expects($this->once())
            ->method('restoreMigrationSafetyBackup')
            ->with($tempRoot . '/backend/storage/update-backups/migration-safety/fixture.sql', 'fixture')
            ->willReturn(['ok' => true]);

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock, $deltaMock, $manifestService, $migrationMock, null, null, null, $recoveryMock])
            ->onlyMethods(['fetchRemoteVersion', 'getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn(['version' => '1.1.40']);
        $service->method('fetchRemoteVersion')->willReturn([
            'version' => '1.1.41',
            'manifest' => [
                'version' => '1.1.41',
                'minimum_version' => '1.0.0',
                'migrations' => ['045_add_column.sql'],
                'files' => [
                    [
                        'path' => 'backend/Helpers/Logger.php',
                        'action' => 'replace',
                        'sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                    ],
                ],
            ],
        ]);

        $res = $service->applyUpdate(false);

        $this->assertFalse($res['ok']);
        $this->assertSame(500, $res['code']);
        $this->assertStringContainsString('فشل ترحيل قاعدة البيانات', $res['error']);
        $this->assertStringContainsString('فشل استعادة ملفات التحديث', $res['error']);
        $this->assertStringNotContainsString('تم التراجع التلقائي بنجاح', $res['error']);
    }

    public function testRollbackUpdateDelegatesToDeltaService(): void

    {
        $manifestService = new \App\Services\UpdateManifestService();
        $deltaMock = $this->createMock(\App\Services\DeltaUpdateService::class);
        $deltaMock->expects($this->once())
            ->method('rollbackUpdate')
            ->with('/mock/snapshot/path')
            ->willReturn([
                'ok' => true,
                'snapshot' => '/mock/snapshot/path',
                'logs' => ['Rollback complete'],
                'error' => null,
            ]);

        $service = new UpdateService($this->gitMock, $this->buildMock, $this->backupMock, $deltaMock, $manifestService);
        $res = $service->rollbackUpdate('/mock/snapshot/path');

        $this->assertTrue($res['ok']);
        $this->assertSame('/mock/snapshot/path', $res['snapshot']);
    }

    public function testCheckForUpdateGitHubReleaseWithValidSignature(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $_ENV['UPDATE_SERVER_URL'] = 'https://api.github.com/repos/ABDO-TECK/pos/releases/latest';

        $keys = \App\Services\ManifestSignatureService::generateKeyPair(2048);
        $sigService = new \App\Services\ManifestSignatureService();

        $manifestData = [
            'version' => '1.1.49',
            'minimum_version' => '1.0.0',
            'files' => [
                [
                    'path' => 'backend/Helpers/Logger.php',
                    'action' => 'replace',
                    'sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                ],
            ],
        ];
        $manifestJson = json_encode($manifestData, JSON_PRETTY_PRINT);
        $signature = $sigService->signData($manifestJson, $keys['private_key']);

        $ghMock = $this->createMock(\App\Services\GitHubReleaseProvider::class);
        $ghMock->method('getLatestRelease')->willReturn([
            'ok' => true,
            'latest_version' => '1.1.49',
            'tag_name' => 'v1.1.49',
            'release_url' => 'https://github.com/ABDO-TECK/pos/releases/tag/v1.1.49',
            'published_at' => '2026-08-27T08:00:00Z',
            'changelog' => ['Fix logger'],
            'manifest_url' => 'https://github.com/ABDO-TECK/pos/releases/download/v1.1.49/manifest.json',
            'signature_url' => 'https://github.com/ABDO-TECK/pos/releases/download/v1.1.49/manifest.sig',
            'delta_url' => 'https://github.com/ABDO-TECK/pos/releases/download/v1.1.49/delta-1.1.48-to-1.1.49.zip',
            'full_package_url' => null,
            'assets' => [],
            'error' => null,
            'error_code' => null,
        ]);

        $ghMock->method('fetchAssetContent')->willReturnCallback(function ($url) use ($manifestJson, $signature) {
            if (str_ends_with($url, 'manifest.json')) {
                return ['ok' => true, 'content' => $manifestJson, 'error' => null];
            }
            if (str_ends_with($url, 'manifest.sig')) {
                return ['ok' => true, 'content' => $signature, 'error' => null];
            }
            return ['ok' => false, 'content' => null, 'error' => 'Not found'];
        });

        $sigServiceMock = $this->createMock(\App\Services\ManifestSignatureService::class);
        $sigServiceMock->method('verifySignature')->willReturn(true);

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([
                $this->gitMock,
                $this->buildMock,
                $this->backupMock,
                null,
                null,
                null,
                $ghMock,
                $sigServiceMock,
            ])
            ->onlyMethods(['getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn(['version' => '1.1.48']);

        $res = $service->checkForUpdate(true);

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['has_update']);
        $this->assertSame('1.1.49', $res['latest_version']);
        $this->assertSame('v1.1.49', $res['release_tag']);
        $this->assertSame('github_release', $res['source']);
        $this->assertSame('delta', $res['update_type']);
        $this->assertTrue($res['is_delta']);
        $this->assertSame(1, $res['files_count']);
    }

    public function testCheckForUpdateRejectsInvalidSignature(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $_ENV['UPDATE_SERVER_URL'] = 'https://api.github.com/repos/ABDO-TECK/pos/releases/latest';

        $manifestData = [
            'version' => '1.1.49',
            'minimum_version' => '1.0.0',
            'files' => [
                [
                    'path' => 'backend/Helpers/Logger.php',
                    'action' => 'replace',
                    'sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                ],
            ],
        ];
        $manifestJson = json_encode($manifestData, JSON_PRETTY_PRINT);

        $ghMock = $this->createMock(\App\Services\GitHubReleaseProvider::class);
        $ghMock->method('getLatestRelease')->willReturn([
            'ok' => true,
            'latest_version' => '1.1.49',
            'tag_name' => 'v1.1.49',
            'release_url' => 'https://github.com/ABDO-TECK/pos/releases/tag/v1.1.49',
            'published_at' => '2026-08-27T08:00:00Z',
            'changelog' => ['Tampered release'],
            'manifest_url' => 'https://github.com/ABDO-TECK/pos/releases/download/v1.1.49/manifest.json',
            'signature_url' => 'https://github.com/ABDO-TECK/pos/releases/download/v1.1.49/manifest.sig',
            'delta_url' => 'https://github.com/ABDO-TECK/pos/releases/download/v1.1.49/delta-1.1.48-to-1.1.49.zip',
            'full_package_url' => null,
            'assets' => [],
            'error' => null,
            'error_code' => null,
        ]);

        $ghMock->method('fetchAssetContent')->willReturnCallback(function ($url) use ($manifestJson) {
            if (str_ends_with($url, 'manifest.json')) {
                return ['ok' => true, 'content' => $manifestJson, 'error' => null];
            }
            if (str_ends_with($url, 'manifest.sig')) {
                return ['ok' => true, 'content' => 'corrupt-invalid-signature', 'error' => null];
            }
            return ['ok' => false, 'content' => null, 'error' => 'Not found'];
        });

        $sigServiceMock = $this->createMock(\App\Services\ManifestSignatureService::class);
        // Signature verification returns FALSE (tampered/invalid)
        $sigServiceMock->method('verifySignature')->willReturn(false);

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([
                $this->gitMock,
                $this->buildMock,
                $this->backupMock,
                null,
                null,
                null,
                $ghMock,
                $sigServiceMock,
            ])
            ->onlyMethods(['getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn(['version' => '1.1.48']);

        $res = $service->checkForUpdate();

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['has_update']);
        $this->assertSame('full', $res['update_type']);
        $this->assertFalse($res['is_delta']);
        $this->assertStringContainsString('Manifest digital signature verification failed', $res['fallback_reason']);
    }

    public function testCheckForUpdateNoUpdateWhenVersionMatches(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $_ENV['UPDATE_SERVER_URL'] = 'https://api.github.com/repos/ABDO-TECK/pos/releases/latest';

        $ghMock = $this->createMock(\App\Services\GitHubReleaseProvider::class);
        $ghMock->method('getLatestRelease')->willReturn([
            'ok' => true,
            'latest_version' => '1.1.48',
            'tag_name' => 'v1.1.48',
            'release_url' => 'https://github.com/ABDO-TECK/pos/releases/tag/v1.1.48',
            'published_at' => '2026-08-27T08:00:00Z',
            'changelog' => [],
            'manifest_url' => null,
            'signature_url' => null,
            'delta_url' => null,
            'full_package_url' => null,
            'assets' => [],
            'error' => null,
            'error_code' => null,
        ]);

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([
                $this->gitMock,
                $this->buildMock,
                $this->backupMock,
                null,
                null,
                null,
                $ghMock,
            ])
            ->onlyMethods(['getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn(['version' => '1.1.48']);

        $res = $service->checkForUpdate();

        $this->assertTrue($res['ok']);
        $this->assertFalse($res['has_update']);
        $this->assertSame('no_update_available', $res['status']);
    }

    public function testCheckForUpdateRejectsV1148ForV001AndPreservesReason(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock])
            ->onlyMethods(['fetchRemoteVersionDiagnostics', 'getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn(['version' => '0.0.1']);
        $service->method('fetchRemoteVersionDiagnostics')->willReturn([
            'ok' => true,
            'data' => ['version' => '1.1.48', 'changelog' => ['Legacy release']],
            'checkedUrl' => 'https://raw.githubusercontent.com/ABDO-TECK/pos/main/version.json',
            'errorCode' => null,
            'details' => null,
        ]);

        $result = $service->checkForUpdate(true);

        $this->assertFalse($result['has_update']);
        $this->assertSame('no_update_available', $result['status']);
        $this->assertSame('Release belongs to a legacy series and cannot be applied.', $result['fallback_reason']);
        $this->assertSame($result['fallback_reason'], $result['message']);
    }

    public function testCheckForUpdateRejectsV120ForV001(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock])
            ->onlyMethods(['fetchRemoteVersionDiagnostics', 'getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn(['version' => '0.0.1']);
        $service->method('fetchRemoteVersionDiagnostics')->willReturn([
            'ok' => true,
            'data' => ['version' => '1.2.0'],
            'checkedUrl' => 'https://api.github.com/repos/ABDO-TECK/pos/releases/latest',
            'errorCode' => null,
            'details' => null,
        ]);

        $result = $service->checkForUpdate(true);

        $this->assertFalse($result['updateAvailable']);
        $this->assertSame('legacy_generation', $result['fallback_reason_code']);
    }

    public function testCheckForUpdateDiscoversCompatibleV002ForV001(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock])
            ->onlyMethods(['fetchRemoteVersionDiagnostics', 'getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn(['version' => '0.0.1']);
        $service->method('fetchRemoteVersionDiagnostics')->willReturn([
            'ok' => true,
            'data' => ['version' => '0.0.2', 'changelog' => ['Compatible patch']],
            'checkedUrl' => 'https://raw.githubusercontent.com/ABDO-TECK/pos/main/version.json',
            'errorCode' => null,
            'details' => null,
        ]);

        $result = $service->checkForUpdate(true);

        $this->assertTrue($result['has_update']);
        $this->assertSame('0.0.2', $result['latest_version']);
    }

    public function testCheckForUpdateRejectsInvalidVersionMetadata(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock])
            ->onlyMethods(['fetchRemoteVersionDiagnostics', 'getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn(['version' => '0.0.1']);
        $service->method('fetchRemoteVersionDiagnostics')->willReturn([
            'ok' => true,
            'data' => ['version' => 'not-semver'],
            'checkedUrl' => 'https://raw.githubusercontent.com/ABDO-TECK/pos/main/version.json',
            'errorCode' => null,
            'details' => null,
        ]);

        $result = $service->checkForUpdate(true);

        $this->assertFalse($result['success']);
        $this->assertSame('invalid_version_json', $result['errorCode']);
        $this->assertFalse($result['has_update']);
    }

    public function testCheckForUpdateRejectsIncompatibleManifestVersion(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock])
            ->onlyMethods(['fetchRemoteVersionDiagnostics', 'getLocalVersion'])
            ->getMock();

        $service->method('getLocalVersion')->willReturn(['version' => '0.0.1']);
        $service->method('fetchRemoteVersionDiagnostics')->willReturn([
            'ok' => true,
            'data' => [
                'version' => '0.0.2',
                'manifest' => [
                    'version' => '1.2.0',
                    'minimum_version' => '0.0.1',
                    'files' => [],
                ],
            ],
            'checkedUrl' => 'https://raw.githubusercontent.com/ABDO-TECK/pos/main/version.json',
            'errorCode' => null,
            'details' => null,
        ]);

        $result = $service->checkForUpdate(true);

        $this->assertFalse($result['has_update']);
        $this->assertSame('legacy_generation', $result['fallback_reason_code']);
        $this->assertStringContainsString('legacy series', $result['fallback_reason']);
    }

    public function testApplyUpdateRejectsLegacyReleaseBeforeDownload(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $_ENV['UPDATE_SERVER_URL'] = 'https://raw.githubusercontent.com/ABDO-TECK/pos/main/version.json';

        $storage = sys_get_temp_dir() . '/update_generation_' . bin2hex(random_bytes(4));
        @mkdir($storage, 0755, true);
        $deltaMock = $this->createMock(\App\Services\DeltaUpdateService::class);
        $deltaMock->method('getStorageDir')->willReturn($storage);
        $deltaMock->method('checkDiskSpace')->willReturn(['ok' => true, 'free_bytes' => 1024 * 1024 * 1024]);
        $deltaMock->expects($this->never())->method('downloadFilesToStaging');
        $deltaMock->expects($this->never())->method('downloadReleaseZipToStaging');

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock, $deltaMock])
            ->onlyMethods(['fetchRemoteVersion', 'getLocalVersion'])
            ->getMock();
        $service->method('getLocalVersion')->willReturn(['version' => '0.0.1']);
        $service->method('fetchRemoteVersion')->willReturn(['version' => '1.2.0']);

        $result = $service->applyUpdate(false);

        $this->assertFalse($result['ok']);
        $this->assertSame(409, $result['code']);
        $this->assertSame('legacy_generation', $result['data']['reason_code']);
        @rmdir($storage);
    }

    public function testApplyUpdateRefusesWhenAConcurrentSaleOwnsTheSharedOperationLock(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'true';
        $_ENV['UPDATE_SERVER_URL'] = 'https://raw.githubusercontent.com/ABDO-TECK/pos/main/version.json';

        $storage = sys_get_temp_dir() . '/update_sale_lock_' . bin2hex(random_bytes(4));
        @mkdir($storage, 0755, true);
        $deltaMock = $this->createMock(\App\Services\DeltaUpdateService::class);
        $deltaMock->method('getStorageDir')->willReturn($storage);
        $deltaMock->expects($this->never())->method('checkDiskSpace');

        $saleLock = new UpdateOperationLock($storage);
        $saleLease = $saleLock->acquire('sale_transaction', ['branch_id' => 7]);
        self::assertTrue($saleLease['acquired']);

        $service = $this->getMockBuilder(UpdateService::class)
            ->setConstructorArgs([$this->gitMock, $this->buildMock, $this->backupMock, $deltaMock])
            ->onlyMethods(['fetchRemoteVersion', 'getLocalVersion'])
            ->getMock();
        $service->method('getLocalVersion')->willReturn(['version' => '0.0.1']);
        $service->expects($this->never())->method('fetchRemoteVersion');

        try {
            $result = $service->applyUpdate(false);
        } finally {
            $saleLock->release();
            @rmdir($storage);
        }

        self::assertFalse($result['ok']);
        self::assertSame(409, $result['code']);
        self::assertSame('update_in_progress', $result['data']['reason_code']);
        self::assertSame('sale_transaction', $result['data']['owner']['operation']);
    }
}
