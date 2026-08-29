<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\UpdateManifestService;

class UpdateManifestServiceTest extends TestCase
{
    private UpdateManifestService $service;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->service = new UpdateManifestService();
        $this->tempDir = sys_get_temp_dir() . '/manifest_test_' . bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tempDir);
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

    public function testValidateValidManifest(): void
    {
        $manifest = [
            'version' => '1.1.47',
            'minimum_version' => '1.0.0',
            'files' => [
                [
                    'path' => 'backend/Helpers/Logger.php',
                    'action' => 'replace',
                    'sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
                    'size' => 100,
                ],
            ],
            'deleted_files' => ['backend/Helpers/OldHelper.php'],
        ];

        $res = $this->service->validateManifest($manifest);
        $this->assertTrue($res['valid']);
        $this->assertEmpty($res['errors']);
        $this->assertNotNull($res['manifest']);
        $this->assertSame('1.1.47', $res['manifest']['version']);
    }

    public function testValidateInvalidJson(): void
    {
        $res = $this->service->validateManifest('{"invalid_json":');
        $this->assertFalse($res['valid']);
        $this->assertContains('Manifest is not valid JSON.', $res['errors']);
    }

    public function testValidateMissingVersion(): void
    {
        $manifest = [
            'files' => [],
        ];
        $res = $this->service->validateManifest($manifest);
        $this->assertFalse($res['valid']);
        $this->assertContains('Manifest is missing a valid "version" string.', $res['errors']);
    }

    public function testValidateInvalidSemver(): void
    {
        $manifest = [
            'version' => 'invalid-version',
            'files' => [],
        ];
        $res = $this->service->validateManifest($manifest);
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('is not a valid semantic version', $res['errors'][0]);
    }

    public function testValidateInvalidSha256(): void
    {
        $manifest = [
            'version' => '1.1.47',
            'files' => [
                [
                    'path' => 'backend/Helpers/Logger.php',
                    'sha256' => 'invalid_short_hash',
                ],
            ],
        ];
        $res = $this->service->validateManifest($manifest);
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('invalid or missing SHA-256', $res['errors'][0]);
    }

    public function testCheckVersionCompatibility(): void
    {
        $manifest = [
            'version' => '1.1.47',
            'minimum_version' => '1.1.0',
        ];

        // 1. Current version newer than minimum, older than target -> Compatible
        $res1 = $this->service->checkVersionCompatibility('1.1.40', $manifest);
        $this->assertTrue($res1['compatible']);
        $this->assertNull($res1['reason']);

        // 2. Current version older than minimum -> Incompatible (requires full update)
        $res2 = $this->service->checkVersionCompatibility('1.0.9', $manifest);
        $this->assertFalse($res2['compatible']);
        $this->assertStringContainsString('below the minimum required version', $res2['reason']);

        // 3. Current version equal to or higher than target -> Incompatible
        $res3 = $this->service->checkVersionCompatibility('1.1.47', $manifest);
        $this->assertFalse($res3['compatible']);
        $this->assertStringContainsString('is not newer than current version', $res3['reason']);

        // 4. Same version allowed with downgrade flag
        $res4 = $this->service->checkVersionCompatibility('1.1.47', $manifest, true);
        $this->assertTrue($res4['compatible']);
    }

    public function testPathSafetyChecks(): void
    {
        $root = $this->tempDir;

        // Safe paths
        $this->assertTrue($this->service->isPathSafe('backend/Helpers/Logger.php', $root));
        $this->assertTrue($this->service->isPathSafe('frontend/dist/index.html', $root));
        $this->assertTrue($this->service->isPathSafe('database/migrations/045_add_index.sql', $root));
        $this->assertTrue($this->service->isPathSafe('version.json', $root));

        // Path traversal
        $this->assertFalse($this->service->isPathSafe('../outside.php', $root));
        $this->assertFalse($this->service->isPathSafe('backend/../../outside.php', $root));
        $this->assertFalse($this->service->isPathSafe('backend/./../outside.php', $root));

        // Absolute paths / drive letters
        $this->assertFalse($this->service->isPathSafe('/etc/passwd', $root));
        $this->assertFalse($this->service->isPathSafe('C:/Windows/System32/cmd.exe', $root));
        $this->assertFalse($this->service->isPathSafe('phar://something/evil.php', $root));

        // Blocked paths
        $this->assertFalse($this->service->isPathSafe('.env', $root));
        $this->assertFalse($this->service->isPathSafe('.git/config', $root));
        $this->assertFalse($this->service->isPathSafe('backend/certs/private.key', $root));
        $this->assertFalse($this->service->isPathSafe('backend/storage/secrets.json', $root));
    }

    public function testPackagedRootOnlyAcceptsDeployedArtifacts(): void
    {
        @mkdir($this->tempDir . '/backend', 0755, true);
        file_put_contents($this->tempDir . '/backend/backend.phar', 'fixture');

        $this->assertTrue($this->service->isPathSafe('backend/backend.phar', $this->tempDir));
        $this->assertTrue($this->service->isPathSafe('frontend/dist/assets/app.js', $this->tempDir));
        $this->assertFalse($this->service->isPathSafe('backend/Services/UpdateService.php', $this->tempDir));
        $this->assertFalse($this->service->isPathSafe('electron/main.js', $this->tempDir));
    }

    public function testVerifyFileHash(): void
    {
        $testFile = $this->tempDir . '/test.txt';
        file_put_contents($testFile, 'Hello POS Delta Update');
        $expectedHash = hash('sha256', 'Hello POS Delta Update');

        $this->assertTrue($this->service->verifyFileHash($testFile, $expectedHash));
        $this->assertTrue($this->service->verifyFileHash($testFile, strtoupper($expectedHash))); // case-insensitive
        $this->assertFalse($this->service->verifyFileHash($testFile, '0000000000000000000000000000000000000000000000000000000000000000'));
        $this->assertFalse($this->service->verifyFileHash($this->tempDir . '/non_existent.txt', $expectedHash));
    }

    public function testVerifyStagedFilesSuccessAndFailure(): void
    {
        $stagingDir = $this->tempDir . '/staging';
        @mkdir($stagingDir . '/backend/Helpers', 0755, true);

        $fileContent = '<?php echo "Logger v2";';
        $filePath = $stagingDir . '/backend/Helpers/Logger.php';
        file_put_contents($filePath, $fileContent);
        $correctHash = hash('sha256', $fileContent);

        $filesManifest = [
            [
                'path' => 'backend/Helpers/Logger.php',
                'action' => 'replace',
                'sha256' => $correctHash,
                'size' => strlen($fileContent),
            ],
        ];

        // 1. Success case
        $res = $this->service->verifyStagedFiles($stagingDir, $filesManifest);
        $this->assertTrue($res['ok']);
        $this->assertSame(1, $res['verified_count']);
        $this->assertEmpty($res['failed_files']);

        // 2. Hash mismatch case
        $filesManifestMismatch = [
            [
                'path' => 'backend/Helpers/Logger.php',
                'action' => 'replace',
                'sha256' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
                'size' => strlen($fileContent),
            ],
        ];
        $resFail = $this->service->verifyStagedFiles($stagingDir, $filesManifestMismatch);
        $this->assertFalse($resFail['ok']);
        $this->assertCount(1, $resFail['failed_files']);
        $this->assertStringContainsString('SHA-256 mismatch', $resFail['failed_files'][0]['reason']);

        // 3. Missing file in staging
        $filesManifestMissing = [
            [
                'path' => 'backend/Services/Missing.php',
                'action' => 'replace',
                'sha256' => $correctHash,
            ],
        ];
        $resMissing = $this->service->verifyStagedFiles($stagingDir, $filesManifestMissing);
        $this->assertFalse($resMissing['ok']);
        $this->assertStringContainsString('missing from staging directory', $resMissing['failed_files'][0]['reason']);
    }
}
