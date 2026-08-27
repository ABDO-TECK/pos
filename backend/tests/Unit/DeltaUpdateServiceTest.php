<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\DeltaUpdateService;
use App\Services\UpdateManifestService;

class DeltaUpdateServiceTest extends TestCase
{
    private DeltaUpdateService $service;
    private UpdateManifestService $manifestService;
    private string $tempRoot;
    private string $tempStorage;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/pos_root_' . bin2hex(random_bytes(4));
        $this->tempStorage = $this->tempRoot . '/backend/storage';
        @mkdir($this->tempRoot . '/backend/Helpers', 0755, true);
        @mkdir($this->tempRoot . '/backend/Services', 0755, true);
        @mkdir($this->tempStorage, 0755, true);

        // Initial files in root (version 1.1.40)
        file_put_contents($this->tempRoot . '/version.json', json_encode([
            'version' => '1.1.40',
            'released_at' => '2026-06-01',
            'changelog' => ['Initial release']
        ], JSON_PRETTY_PRINT));
        file_put_contents($this->tempRoot . '/backend/Helpers/Logger.php', '<?php echo "Original Logger v1.1.40";');
        file_put_contents($this->tempRoot . '/backend/Services/ProductService.php', '<?php echo "Original ProductService v1.1.40";');
        file_put_contents($this->tempRoot . '/backend/Services/HealthService.php', '<?php echo "Original HealthService v1.1.40";');
        file_put_contents($this->tempRoot . '/backend/Services/AuthService.php', '<?php echo "Original AuthService v1.1.40";');
        file_put_contents($this->tempRoot . '/backend/Services/SaleService.php', '<?php echo "Original SaleService v1.1.40";');

        $this->manifestService = new UpdateManifestService();
        $this->service = new DeltaUpdateService($this->manifestService, $this->tempRoot, $this->tempStorage);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempRoot);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . '/' . $item;
            is_dir($p) ? $this->deleteDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    // ── Scenario 1: Successful 1-File Update ──────────────────────────────────

    public function testScenario1SuccessfulOneFileUpdate(): void
    {
        $sourceDir = $this->tempRoot . '/release_1file';
        @mkdir($sourceDir . '/backend/Helpers', 0755, true);

        $newLogger = '<?php echo "Patched Logger v1.1.41";';
        file_put_contents($sourceDir . '/backend/Helpers/Logger.php', $newLogger);

        $manifest = [
            'version' => '1.1.41',
            'minimum_version' => '1.0.0',
            'released_at' => '2026-08-26',
            'changelog' => ['Fix logger'],
            'files' => [
                [
                    'path' => 'backend/Helpers/Logger.php',
                    'action' => 'replace',
                    'sha256' => hash('sha256', $newLogger),
                    'size' => strlen($newLogger),
                ],
            ],
            'deleted_files' => [],
        ];

        // 1. Stage
        $stageRes = $this->service->stageFromLocalFiles($manifest, $sourceDir);
        $this->assertTrue($stageRes['ok']);

        // 2. Snapshot
        $snapshot = $this->service->createBackupSnapshot('1.1.40', '1.1.41', $manifest);
        $this->assertTrue($snapshot['ok']);
        $this->assertFileExists($snapshot['snapshot_path'] . '/files/backend/Helpers/Logger.php');

        // 3. Apply
        $applyRes = $this->service->applyStagedFiles($manifest, $snapshot['snapshot_path']);
        $this->assertTrue($applyRes['ok']);
        $this->assertFalse($applyRes['rolled_back']);

        // 4. Assertions
        $this->assertSame($newLogger, file_get_contents($this->tempRoot . '/backend/Helpers/Logger.php'));
        $verData = json_decode(file_get_contents($this->tempRoot . '/version.json'), true);
        $this->assertSame('1.1.41', $verData['version']);

        // State check
        $state = $this->service->getUpdateState();
        $this->assertSame('completed', $state['state']);
    }

    // ── Scenario 2: Successful 5-Files Update ─────────────────────────────────

    public function testScenario2SuccessfulFiveFilesUpdate(): void
    {
        $sourceDir = $this->tempRoot . '/release_5files';
        @mkdir($sourceDir . '/backend/Helpers', 0755, true);
        @mkdir($sourceDir . '/backend/Services', 0755, true);

        $filesData = [
            'backend/Helpers/Logger.php' => '<?php echo "New Logger 1.1.42";',
            'backend/Services/ProductService.php' => '<?php echo "New ProductService 1.1.42";',
            'backend/Services/HealthService.php' => '<?php echo "New HealthService 1.1.42";',
            'backend/Services/AuthService.php' => '<?php echo "New AuthService 1.1.42";',
            'backend/Services/SaleService.php' => '<?php echo "New SaleService 1.1.42";',
        ];

        $manifestFiles = [];
        foreach ($filesData as $path => $content) {
            file_put_contents($sourceDir . '/' . $path, $content);
            $manifestFiles[] = [
                'path' => $path,
                'action' => 'replace',
                'sha256' => hash('sha256', $content),
                'size' => strlen($content),
            ];
        }

        $manifest = [
            'version' => '1.1.42',
            'minimum_version' => '1.0.0',
            'released_at' => '2026-08-26',
            'changelog' => ['Update 5 core services'],
            'files' => $manifestFiles,
            'deleted_files' => [],
        ];

        // Stage, Backup, Apply
        $stageRes = $this->service->stageFromLocalFiles($manifest, $sourceDir);
        $this->assertTrue($stageRes['ok']);

        $snapshot = $this->service->createBackupSnapshot('1.1.40', '1.1.42', $manifest);
        $this->assertTrue($snapshot['ok']);
        $this->assertCount(5, $snapshot['backed_up_files']);

        $applyRes = $this->service->applyStagedFiles($manifest, $snapshot['snapshot_path']);
        $this->assertTrue($applyRes['ok']);
        $this->assertCount(5, $applyRes['applied_files']);

        // Verify all 5 files updated
        foreach ($filesData as $path => $content) {
            $this->assertSame($content, file_get_contents($this->tempRoot . '/' . $path));
        }

        $verData = json_decode(file_get_contents($this->tempRoot . '/version.json'), true);
        $this->assertSame('1.1.42', $verData['version']);
    }

    // ── Scenario 3: Corrupted Staged File Detection ───────────────────────────

    public function testScenario3CorruptedStagedFileRejectedBeforeApply(): void
    {
        $sourceDir = $this->tempRoot . '/release_corrupt';
        @mkdir($sourceDir . '/backend/Helpers', 0755, true);

        $validLogger = '<?php echo "Legit Logger";';
        file_put_contents($sourceDir . '/backend/Helpers/Logger.php', $validLogger);

        $manifest = [
            'version' => '1.1.43',
            'files' => [
                [
                    'path' => 'backend/Helpers/Logger.php',
                    'action' => 'replace',
                    'sha256' => hash('sha256', $validLogger),
                    'size' => strlen($validLogger),
                ],
            ],
        ];

        $stageRes = $this->service->stageFromLocalFiles($manifest, $sourceDir);
        $this->assertTrue($stageRes['ok']);

        // Tamper with file in staging
        $stagedPath = $this->service->getStagingDir('1.1.43') . '/backend/Helpers/Logger.php';
        file_put_contents($stagedPath, '<?php echo "Tampered Malware Content";');

        // Attempt apply
        $applyRes = $this->service->applyStagedFiles($manifest);
        $this->assertFalse($applyRes['ok']);
        $this->assertStringContainsString('Pre-apply verification failed', $applyRes['errors'][0]);

        // Production file remains pristine
        $this->assertSame('<?php echo "Original Logger v1.1.40";', file_get_contents($this->tempRoot . '/backend/Helpers/Logger.php'));
    }

    // ── Scenario 4 & 5: Interrupted Update with Automatic Rollback ────────────

    public function testScenario4And5InterruptedUpdateTriggersAutomaticRollback(): void
    {
        $sourceDir = $this->tempRoot . '/release_interrupted';
        @mkdir($sourceDir . '/backend/Helpers', 0755, true);
        @mkdir($sourceDir . '/backend/Services', 0755, true);

        $newLogger = '<?php echo "New Logger";';
        $newProduct = '<?php echo "New Product";';

        file_put_contents($sourceDir . '/backend/Helpers/Logger.php', $newLogger);
        file_put_contents($sourceDir . '/backend/Services/ProductService.php', $newProduct);

        $manifest = [
            'version' => '1.1.44',
            'minimum_version' => '1.0.0',
            'released_at' => '2026-08-26',
            'files' => [
                [
                    'path' => 'backend/Helpers/Logger.php',
                    'action' => 'replace',
                    'sha256' => hash('sha256', $newLogger),
                    'size' => strlen($newLogger),
                ],
                [
                    'path' => 'backend/Services/ProductService.php',
                    'action' => 'replace',
                    'sha256' => hash('sha256', $newProduct),
                    'size' => strlen($newProduct),
                ],
            ],
        ];

        $stageRes = $this->service->stageFromLocalFiles($manifest, $sourceDir);
        $this->assertTrue($stageRes['ok']);

        // Create snapshot
        $snapshot = $this->service->createBackupSnapshot('1.1.40', '1.1.44', $manifest);
        $this->assertTrue($snapshot['ok']);

        // Tamper with the 2nd file's staging AFTER initial verification check by substituting a broken temporary file
        // To simulate a mid-replacement crash: we simulate a hash mismatch on the second file during staging copy
        $stagedProduct = $this->service->getStagingDir('1.1.44') . '/backend/Services/ProductService.php';
        file_put_contents($stagedProduct, 'broken middle payload');

        // Apply — Logger.php will be staged, but ProductService.php will fail tmp verification
        // This must trigger automatic rollback of any files already replaced!
        $applyRes = $this->service->applyStagedFiles($manifest, $snapshot['snapshot_path']);
        $this->assertFalse($applyRes['ok']);

        // Verify that production file Logger.php was restored to original 1.1.40 content
        $this->assertSame('<?php echo "Original Logger v1.1.40";', file_get_contents($this->tempRoot . '/backend/Helpers/Logger.php'));
        $this->assertSame('<?php echo "Original ProductService v1.1.40";', file_get_contents($this->tempRoot . '/backend/Services/ProductService.php'));

        // Version remains 1.1.40
        $verData = json_decode(file_get_contents($this->tempRoot . '/version.json'), true);
        $this->assertSame('1.1.40', $verData['version']);
    }

    // ── Scenario 6 & 7: Manual Rollback Success ───────────────────────────────

    public function testScenario6And7ManualRollbackRestoresPreviousState(): void
    {
        $sourceDir = $this->tempRoot . '/release_manual_rb';
        @mkdir($sourceDir . '/backend/Helpers', 0755, true);

        $newLogger = '<?php echo "Patched Logger v1.1.45";';
        file_put_contents($sourceDir . '/backend/Helpers/Logger.php', $newLogger);

        $manifest = [
            'version' => '1.1.45',
            'minimum_version' => '1.0.0',
            'released_at' => '2026-08-26',
            'changelog' => ['v1.1.45 patch'],
            'files' => [
                [
                    'path' => 'backend/Helpers/Logger.php',
                    'action' => 'replace',
                    'sha256' => hash('sha256', $newLogger),
                    'size' => strlen($newLogger),
                ],
            ],
            'deleted_files' => [],
        ];

        $this->service->stageFromLocalFiles($manifest, $sourceDir);
        $snapshot = $this->service->createBackupSnapshot('1.1.40', '1.1.45', $manifest);
        $this->service->applyStagedFiles($manifest, $snapshot['snapshot_path']);

        // Verify it was updated to 1.1.45
        $this->assertSame($newLogger, file_get_contents($this->tempRoot . '/backend/Helpers/Logger.php'));
        $this->assertSame('1.1.45', json_decode(file_get_contents($this->tempRoot . '/version.json'), true)['version']);

        // Now execute manual rollback
        $rollbackRes = $this->service->rollbackUpdate($snapshot['snapshot_path']);
        $this->assertTrue($rollbackRes['ok']);
        $this->assertSame($snapshot['snapshot_path'], $rollbackRes['snapshot']);

        // Verify pristine restoration to 1.1.40
        $this->assertSame('<?php echo "Original Logger v1.1.40";', file_get_contents($this->tempRoot . '/backend/Helpers/Logger.php'));
        $verData = json_decode(file_get_contents($this->tempRoot . '/version.json'), true);
        $this->assertSame('1.1.40', $verData['version']);

        $state = $this->service->getUpdateState();
        $this->assertSame('rolled_back', $state['state']);
    }

    // ── Scenario 8: Rollback with Added New Files & Deleted Files ─────────────

    public function testScenario8RollbackWithNewAndDeletedFiles(): void
    {
        $depFile = $this->tempRoot . '/backend/Helpers/DeprecatedHelper.php';
        file_put_contents($depFile, '<?php echo "Legacy helper";');

        $sourceDir = $this->tempRoot . '/release_new_del';
        @mkdir($sourceDir . '/backend/Services', 0755, true);

        $brandNewService = '<?php echo "Brand new BrandNewService";';
        file_put_contents($sourceDir . '/backend/Services/BrandNewService.php', $brandNewService);

        $manifest = [
            'version' => '1.1.46',
            'minimum_version' => '1.0.0',
            'released_at' => '2026-08-26',
            'files' => [
                [
                    'path' => 'backend/Services/BrandNewService.php',
                    'action' => 'add',
                    'sha256' => hash('sha256', $brandNewService),
                    'size' => strlen($brandNewService),
                ],
            ],
            'deleted_files' => ['backend/Helpers/DeprecatedHelper.php'],
        ];

        $this->service->stageFromLocalFiles($manifest, $sourceDir);
        $snapshot = $this->service->createBackupSnapshot('1.1.40', '1.1.46', $manifest);
        $this->service->applyStagedFiles($manifest, $snapshot['snapshot_path']);

        // Verify new file added and deprecated file deleted
        $this->assertFileExists($this->tempRoot . '/backend/Services/BrandNewService.php');
        $this->assertFileDoesNotExist($depFile);

        // Execute rollback
        $rb = $this->service->rollbackFiles($snapshot['snapshot_path']);
        $this->assertTrue($rb['ok']);

        // Verify brand new file was removed and deprecated file was restored
        $this->assertFileDoesNotExist($this->tempRoot . '/backend/Services/BrandNewService.php');
        $this->assertFileExists($depFile);
        $this->assertSame('<?php echo "Legacy helper";', file_get_contents($depFile));
    }

    public function testExtractZipToStagingSuccess(): void
    {
        $zipPath = $this->tempRoot . '/test_delta.zip';
        $stagingDir = $this->service->getStagingDir('1.1.47');

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('backend/Helpers/Logger.php', '<?php echo "Zipped Logger";');
        $zip->addFromString('backend/Services/ProductService.php', '<?php echo "Zipped ProductService";');
        $zip->close();

        $extractRes = $this->service->extractZipToStaging($zipPath, $stagingDir);

        $this->assertTrue($extractRes['ok']);
        $this->assertSame(2, $extractRes['extracted_count']);
        $this->assertFileExists($stagingDir . '/backend/Helpers/Logger.php');
        $this->assertSame('<?php echo "Zipped Logger";', file_get_contents($stagingDir . '/backend/Helpers/Logger.php'));
    }

    public function testExtractZipToStagingBlocksZipSlipAttack(): void
    {
        $zipPath = $this->tempRoot . '/malicious_zipslip.zip';
        $stagingDir = $this->service->getStagingDir('1.1.48');

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        // Add malicious path traversal entry
        $zip->addFromString('../../../evil.php', 'malicious code');
        $zip->close();

        $extractRes = $this->service->extractZipToStaging($zipPath, $stagingDir);

        $this->assertFalse($extractRes['ok']);
        $this->assertStringContainsString('ZipSlip attack attempt', $extractRes['errors'][0]);
        $this->assertFileDoesNotExist($this->tempRoot . '/evil.php');
    }

    public function testDetectInterruptedUpdateWhenAbandoned(): void
    {
        $this->service->setUpdateState('applying', [
            'from_version' => '1.1.47',
            'to_version' => '1.1.48',
            'backup_snapshot' => '/mock/backup/snapshot',
            'updated_at' => date('Y-m-d\TH:i:sP', time() - 600), // 10 minutes ago
        ]);

        $res = $this->service->detectInterruptedUpdate(300);

        $this->assertTrue($res['interrupted']);
        $this->assertSame('applying', $res['state']);
        $this->assertSame('/mock/backup/snapshot', $res['snapshot_path']);
        $this->assertStringContainsString('تم مقاطعة عملية تحديث سابقة', $res['message']);
    }

    public function testCheckDiskSpacePassesWhenSufficient(): void
    {
        $res = $this->service->checkDiskSpace(1024); // 1 KB
        $this->assertTrue($res['ok']);
        $this->assertNull($res['error']);
    }

    public function testCheckDiskSpaceRejectsWhenInsufficient(): void
    {
        // Require impossible amount of disk space: 10,000 Terabytes (10 Petabytes)
        $res = $this->service->checkDiskSpace(10000000000000000);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('مساحة القرص غير كافية', $res['error']);
    }
}


