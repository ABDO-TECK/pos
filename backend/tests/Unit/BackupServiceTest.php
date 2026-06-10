<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\BackupService;
use PDO;

class BackupServiceTest extends TestCase
{
    private PDO|\PHPUnit\Framework\MockObject\MockObject $pdoMock;
    private BackupService $service;
    
    protected function setUp(): void
    {
        // Mock PDO
        $this->pdoMock = $this->createMock(PDO::class);
        $this->service = new BackupService($this->pdoMock);
    }

    public function testBackupFileNameFormatAndCreation(): void
    {
        // Since createBackupFile calls generateBackupSql which queries the DB,
        // we'll mock generateBackupSql indirectly by partially mocking BackupService.
        
        // Actually, mocking private methods is deprecated. 
        // We'll mock the PDO query instead to return empty tables.
        
        $pdoStatementMock = $this->createMock(\PDOStatement::class);
        $pdoStatementMock->method('fetchAll')->willReturn([]);
        
        $this->pdoMock->method('query')->willReturn($pdoStatementMock);
        
        $backupDir = sys_get_temp_dir() . '/pos_backups_test';
        
        $filePath = $this->service->createBackupFile($backupDir);
        
        $this->assertFileExists($filePath);
        $this->assertStringContainsString('pre_update_', basename($filePath));
        $this->assertStringEndsWith('.sql', $filePath);
        
        // Cleanup
        @unlink($filePath);
        @rmdir($backupDir);
    }

    // ── Validate uploaded SQL file ──

    public function testValidateRejectsEmptyFile()
    {
        $file = ['error' => UPLOAD_ERR_NO_FILE, 'name' => '', 'size' => 0, 'tmp_name' => ''];
        $result = $this->service->validateUploadedSqlFile($file);

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['code']);
    }

    public function testValidateRejectsNonSqlExtension()
    {
        $file = [
            'error'    => UPLOAD_ERR_OK,
            'name'     => 'backup.txt',
            'size'     => 100,
            'tmp_name' => tempnam(sys_get_temp_dir(), 'test'),
        ];
        file_put_contents($file['tmp_name'], 'some content');

        $result = $this->service->validateUploadedSqlFile($file);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('.sql', $result['error']);
        @unlink($file['tmp_name']);
    }

    public function testValidateRejectsOversizedFile()
    {
        $file = [
            'error'    => UPLOAD_ERR_OK,
            'name'     => 'backup.sql',
            'size'     => 60 * 1024 * 1024, // 60 MB > 50 MB limit
            'tmp_name' => tempnam(sys_get_temp_dir(), 'test'),
        ];
        file_put_contents($file['tmp_name'], 'SELECT 1;');

        $result = $this->service->validateUploadedSqlFile($file);

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['code']);
        @unlink($file['tmp_name']);
    }

    public function testValidateRejectsDangerousCommands()
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, "CREATE TABLE test (id INT); SELECT * INTO OUTFILE '/tmp/hack';");

        $file = [
            'error'    => UPLOAD_ERR_OK,
            'name'     => 'backup.sql',
            'size'     => filesize($tmpFile),
            'tmp_name' => $tmpFile,
        ];

        $result = $this->service->validateUploadedSqlFile($file);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('غير مسموحة', $result['error']);
        @unlink($tmpFile);
    }

    public function testValidateAcceptsValidSqlFile()
    {
        $sql = "DROP TABLE IF EXISTS `test`;\nCREATE TABLE `test` (id INT);\nINSERT INTO `test` VALUES (1);";
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, $sql);

        $file = [
            'error'    => UPLOAD_ERR_OK,
            'name'     => 'backup.sql',
            'size'     => filesize($tmpFile),
            'tmp_name' => $tmpFile,
        ];

        $result = $this->service->validateUploadedSqlFile($file);

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('content', $result);
        $this->assertStringContainsString('CREATE TABLE', $result['content']);
        @unlink($tmpFile);
    }
}
