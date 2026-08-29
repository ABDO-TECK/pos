<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BackupService;
use App\Services\MigrationSafetyBackupService;
use PHPUnit\Framework\TestCase;

final class MigrationSafetyBackupServiceTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/pos_migration_safety_' . bin2hex(random_bytes(5));
        mkdir($this->storage, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->storage);
    }

    public function testCreatesVerifiesAndRestoresDedicatedRecoveryBackup(): void
    {
        $backup = $this->createMock(BackupService::class);
        $backup->method('createBackupFile')->willReturnCallback(function (string $directory): string {
            $path = $directory . '/pre_update_fixture.sql';
            file_put_contents($path, "-- POS Auto-Update Backup\nSET FOREIGN_KEY_CHECKS=0;\nCREATE TABLE `orders` (id INT);\nSET FOREIGN_KEY_CHECKS=1;\n");
            return $path;
        });
        $backup->expects($this->once())
            ->method('restoreFromSql')
            ->with($this->stringContains('CREATE TABLE `orders`'), false)
            ->willReturn(['ok' => true]);

        $service = new MigrationSafetyBackupService($backup, $this->storage);
        $created = $service->createMigrationSafetyBackup('1.1.47', '1.1.48', 'tx-fixture');

        self::assertTrue($created['ok']);
        self::assertFileExists($created['backup_path']);
        self::assertFileExists($created['metadata_path']);
        self::assertTrue($service->verifyBackup($created['backup_path'], 'tx-fixture')['ok']);
        self::assertTrue($service->restoreMigrationSafetyBackup($created['backup_path'], 'tx-fixture')['ok']);
    }

    public function testRejectsCorruptRecoveryBackupBeforeRestore(): void
    {
        $backup = $this->createMock(BackupService::class);
        $backup->method('createBackupFile')->willReturnCallback(function (string $directory): string {
            $path = $directory . '/pre_update_fixture.sql';
            file_put_contents($path, "-- POS Auto-Update Backup\nSET FOREIGN_KEY_CHECKS=0;\nCREATE TABLE `orders` (id INT);\n");
            return $path;
        });
        $backup->expects($this->never())->method('restoreFromSql');

        $service = new MigrationSafetyBackupService($backup, $this->storage);
        $created = $service->createMigrationSafetyBackup('1.1.47', '1.1.48', 'tx-corrupt');
        self::assertTrue($created['ok']);
        file_put_contents($created['backup_path'], 'corrupt');

        $result = $service->restoreMigrationSafetyBackup($created['backup_path'], 'tx-corrupt');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('backup', $result['error']);
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        $entries = scandir($directory) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $directory . '/' . $entry;
            if (is_dir($path)) $this->deleteDirectory($path);
            else @unlink($path);
        }
        @rmdir($directory);
    }
}
