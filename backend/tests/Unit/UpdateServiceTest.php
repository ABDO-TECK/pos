<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\UpdateService;
use App\Services\GitService;
use App\Services\FrontendBuildService;
use App\Services\BackupService;

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
    }

    protected function tearDown(): void
    {
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

    public function testCheckForUpdateWhenDisabled(): void
    {
        $_ENV['ENABLE_UPDATE_CHECKS'] = 'false';

        $service = new UpdateService($this->gitMock, $this->buildMock, $this->backupMock);
        $res = $service->checkForUpdate();

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
}
