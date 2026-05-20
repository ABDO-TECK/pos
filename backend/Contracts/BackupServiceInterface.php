<?php
namespace App\Contracts;

interface BackupServiceInterface
{
    public function createBackupFile(string $backupDir): string;
    public function streamBackup(): void;
}
