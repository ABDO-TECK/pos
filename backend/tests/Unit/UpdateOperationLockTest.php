<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UpdateOperationLock;
use PHPUnit\Framework\TestCase;

final class UpdateOperationLockTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/pos-update-lock-' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0755, true);
    }

    protected function tearDown(): void
    {
        $lockPath = $this->storage . '/update-operation.lock';
        if (is_file($lockPath)) {
            @unlink($lockPath);
        }
        @rmdir($this->storage);
    }

    public function testOnlyOneOwnerCanHoldTheSharedOperationLock(): void
    {
        $first = new UpdateOperationLock($this->storage);
        $second = new UpdateOperationLock($this->storage);

        $firstLease = $first->acquire('backend_delta_apply', ['target_version' => '0.0.2']);
        self::assertTrue($firstLease['acquired']);

        $secondLease = $second->acquire('electron_full_download', ['target_version' => '0.0.2']);
        self::assertFalse($secondLease['acquired']);
        self::assertSame('update_in_progress', $secondLease['reason_code']);
        self::assertSame('backend_delta_apply', $secondLease['owner']['operation']);

        self::assertTrue($first->release());
        self::assertTrue($second->acquire('electron_full_download')['acquired']);
        self::assertTrue($second->release());
    }

    public function testStaleOwnerIsRecoveredWithDiagnostics(): void
    {
        file_put_contents($this->storage . '/update-operation.lock', json_encode([
            'owner_id' => 'stale-owner',
            'operation' => 'backend_delta_apply',
            'pid' => 1234,
            'time' => time() - UpdateOperationLock::TTL_SECONDS - 1,
            'started_at' => gmdate('c', time() - UpdateOperationLock::TTL_SECONDS - 1),
            'updated_at' => gmdate('c', time() - UpdateOperationLock::TTL_SECONDS - 1),
            'context' => ['target_version' => '0.0.2'],
        ]));

        $lock = new UpdateOperationLock($this->storage);
        $lease = $lock->acquire('electron_delta_install');

        self::assertTrue($lease['acquired']);
        self::assertSame('stale-owner', $lease['recovered_from']['owner_id']);
        self::assertTrue($lock->release());
    }
}
