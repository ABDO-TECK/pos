<?php

namespace Tests\Unit;

use App\Controllers\UpdateController;
use App\Middleware\PermissionMiddleware;
use App\Services\AuthService;
use App\Services\DeltaUpdateService;
use App\Services\UpdateService;
use PHPUnit\Framework\TestCase;

class UpdateControllerTest extends TestCase
{
    private $authMock;
    private $updateServiceMock;
    private $deltaServiceMock;
    private string $backupsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupsDir = sys_get_temp_dir() . '/test_update_backups';

        $this->authMock = $this->createMock(AuthService::class);
        $this->authMock->method('user')->willReturn([
            'id' => 1,
            'username' => 'admin',
            'role' => 'admin',
        ]);

        $this->deltaServiceMock = $this->createMock(DeltaUpdateService::class);
        $this->deltaServiceMock->method('getUpdateState')->willReturn([
            'state' => 'completed',
            'updated_at' => '2026-08-27T10:00:00Z',
            'applied_files' => ['backend/Helpers/Logger.php'],
            'backup_snapshot' => null,
            'error' => null,
        ]);
        $this->deltaServiceMock->method('getBackupsDir')->willReturnCallback(fn () => $this->backupsDir);

        $this->updateServiceMock = $this->createMock(UpdateService::class);
        $this->updateServiceMock->method('getDeltaUpdateService')->willReturn($this->deltaServiceMock);
        $this->updateServiceMock->method('getLocalVersion')->willReturn([
            'version' => '1.1.48',
            'released_at' => '2026-08-26',
            'changelog' => [],
        ]);
    }


    public function testStatusReturnsVersionAndReleaseInfo(): void
    {
        $this->updateServiceMock->method('fetchRemoteVersion')->willReturn([
            'version' => '1.1.49',
            'tag_name' => 'v1.1.49',
            'released_at' => '2026-08-27',
            'changelog' => ['Fixed logging', 'Added features'],
            'manifest' => [
                'files' => [
                    ['path' => 'backend/Helpers/Logger.php'],
                ],
            ],
            'delta_url' => 'https://github.com/ABDO-TECK/pos/releases/download/v1.1.49/delta.zip',
        ]);

        $controller = new UpdateController($this->authMock, $this->updateServiceMock);
        $response = $controller->status();

        $this->assertIsArray($response);
        $this->assertSame(200, $response['status_code']);
        $body = $response['body'];
        $this->assertSame('success', $body['status']);
        $this->assertSame('1.1.48', $body['data']['current_version']);
        $this->assertSame('1.1.49', $body['data']['latest_version']);
        $this->assertTrue($body['data']['update_available']);
        $this->assertSame('delta', $body['data']['type']);
        $this->assertSame('v1.1.49', $body['data']['release_info']['tag_name']);
        $this->assertSame(1, $body['data']['release_info']['files_count']);
        $this->assertSame('completed', $body['data']['update_state']['state']);
    }

    public function testCheckInitiatesUpdateCheck(): void
    {
        $this->updateServiceMock->method('checkForUpdate')->willReturn([
            'ok' => true,
            'status' => 'update_available',
            'has_update' => true,
            'current_version' => '1.1.48',
            'latest_version' => '1.1.49',
            'update_type' => 'delta',
            'is_delta' => true,
            'files_count' => 2,
        ]);

        $controller = new UpdateController($this->authMock, $this->updateServiceMock);
        $response = $controller->check();

        $this->assertIsArray($response);
        $this->assertSame(200, $response['status_code']);
        $body = $response['body'];
        $this->assertSame('success', $body['status']);
        $this->assertSame('1.1.49', $body['data']['latest_version']);
        $this->assertTrue($body['data']['has_update']);
    }

    public function testApplyExecutesUpdateSuccessfully(): void
    {
        $this->updateServiceMock->method('applyUpdate')->willReturn([
            'ok' => true,
            'code' => 200,
            'error' => null,
            'data' => [
                'message' => 'Update applied',
                'latest_version' => '1.1.49',
                'update_type' => 'delta',
                'applied_files' => ['backend/Helpers/Logger.php'],
                'logs' => ['Update completed successfully'],
            ],
        ]);

        $controller = new UpdateController($this->authMock, $this->updateServiceMock);
        $response = $controller->apply();

        $this->assertIsArray($response);
        $this->assertSame(200, $response['status_code']);
        $body = $response['body'];
        $this->assertSame('success', $body['status']);
        $this->assertSame('1.1.49', $body['data']['latest_version']);
        $this->assertSame('delta', $body['data']['update_type']);
    }

    public function testApplyReturnsErrorOnFailure(): void
    {
        $this->updateServiceMock->method('applyUpdate')->willReturn([
            'ok' => false,
            'code' => 500,
            'error' => 'Migration failed and rolled back',
            'data' => [
                'logs' => ['Failed executing migration'],
            ],
        ]);

        $controller = new UpdateController($this->authMock, $this->updateServiceMock);
        $response = $controller->apply();

        $this->assertIsArray($response);
        $this->assertSame(500, $response['status_code']);
        $body = $response['body'];
        $this->assertSame('error', $body['status']);
        $this->assertSame('Migration failed and rolled back', $body['message']);
    }

    public function testHistoryRetrievalReturnsArray(): void
    {
        $controller = new UpdateController($this->authMock, $this->updateServiceMock);
        $response = $controller->history();

        $this->assertIsArray($response);
        $this->assertSame(200, $response['status_code']);
        $body = $response['body'];
        $this->assertSame('success', $body['status']);
        $this->assertIsArray($body['data']);
    }

    public function testRollbackDelegatesToUpdateService(): void
    {
        $this->updateServiceMock->method('rollbackUpdate')->willReturn([
            'ok' => true,
            'snapshot' => '/mock/snapshot',
            'logs' => ['Rollback completed'],
            'error' => null,
        ]);

        $controller = new UpdateController($this->authMock, $this->updateServiceMock);
        $response = $controller->rollback();

        $this->assertIsArray($response);
        $this->assertSame(200, $response['status_code']);
        $body = $response['body'];
        $this->assertSame('success', $body['status']);
        $this->assertSame('/mock/snapshot', $body['data']['snapshot']);
    }

    public function testSnapshotsListing(): void
    {
        $tempDir = str_replace('\\', '/', sys_get_temp_dir()) . '/test_snapshots_' . bin2hex(random_bytes(4));
        @mkdir($tempDir . '/patch_1.1.47_to_1.1.48_20260826', 0755, true);

        file_put_contents(
            $tempDir . '/patch_1.1.47_to_1.1.48_20260826/metadata.json',
            json_encode([
                'from_version' => '1.1.47',
                'to_version' => '1.1.48',
                'timestamp' => '2026-08-26 12:00:00',
                'files' => ['backend/Helpers/Logger.php'],
                'db_backup_path' => '/storage/pre_update.sql',
            ])
        );

        $this->backupsDir = $tempDir;

        $controller = new UpdateController($this->authMock, $this->updateServiceMock);
        $response = $controller->snapshots();

        $this->assertIsArray($response);
        $this->assertSame(200, $response['status_code']);
        $body = $response['body'];
        $this->assertSame('success', $body['status']);
        $this->assertCount(1, $body['data']);
        $this->assertSame('1.1.47', $body['data'][0]['from_version']);
        $this->assertSame('1.1.48', $body['data'][0]['to_version']);
        $this->assertTrue($body['data'][0]['has_db_backup']);

        // Clean up temp
        @unlink($tempDir . '/patch_1.1.47_to_1.1.48_20260826/metadata.json');
        @rmdir($tempDir . '/patch_1.1.47_to_1.1.48_20260826');
        @rmdir($tempDir);
    }

    public function testPermissionMiddlewareBlocksUnauthorizedUser(): void
    {
        $nonAdminAuthMock = $this->createMock(AuthService::class);
        $nonAdminAuthMock->method('user')->willReturn([
            'id' => 2,
            'username' => 'cashier',
            'role' => 'cashier',
        ]);

        $middleware = new PermissionMiddleware($nonAdminAuthMock, 'updates.apply');
        $called = false;
        $response = $middleware->handle(function () use (&$called) {
            $called = true;
            return 'OK';
        });

        $this->assertFalse($called);
        $this->assertIsArray($response);
        $this->assertSame(403, $response['status_code']);
        $this->assertSame('error', $response['body']['status']);
        $this->assertStringContainsString('updates.apply', $response['body']['message']);
    }

    public function testPermissionMiddlewareAllowsAdminUser(): void
    {
        $adminAuthMock = $this->createMock(AuthService::class);
        $adminAuthMock->method('user')->willReturn([
            'id' => 1,
            'username' => 'admin',
            'role' => 'admin',
        ]);

        $middleware = new PermissionMiddleware($adminAuthMock, 'updates.apply');
        $called = false;
        $response = $middleware->handle(function () use (&$called) {
            $called = true;
            return 'OK';
        });

        $this->assertTrue($called);
        $this->assertSame('OK', $response);
    }
}
