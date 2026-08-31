<?php

namespace Tests\Unit;

use App\Services\GitHubReleaseProvider;
use App\Services\UpdateRuntimePaths;
use PHPUnit\Framework\TestCase;

class UpdateRuntimePathsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/pos_runtime_paths_test_' . uniqid('', true);
        @mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testSourceRuntimeResolvesPhysicalCaBundle(): void
    {
        $caPath = UpdateRuntimePaths::getCaBundlePath();

        $this->assertNotNull($caPath, 'CA bundle path should resolve in source environment');
        $this->assertStringStartsNotWith('phar://', $caPath, 'CA bundle path must not be a phar:// stream wrapper');
        $this->assertTrue(is_file($caPath), 'CA bundle must exist on the physical filesystem');
        $this->assertStringEndsWith('backend/certs/cacert.pem', str_replace('\\', '/', $caPath));
    }

    public function testPackagedLayoutSimulationResolvesBesidePhar(): void
    {
        $backendDir = $this->tempDir . '/backend';
        $certsDir = $backendDir . '/certs';
        @mkdir($certsDir, 0777, true);

        $fakePhar = $backendDir . '/backend.phar';
        $fakeCert = $certsDir . '/cacert.pem';
        file_put_contents($fakePhar, 'fake phar');
        file_put_contents($fakeCert, '-----BEGIN CERTIFICATE----- fake');

        $resolved = UpdateRuntimePaths::getCaBundlePath($this->tempDir);

        $this->assertNotNull($resolved);
        $this->assertStringStartsNotWith('phar://', $resolved);
        $this->assertSame(
            str_replace('\\', '/', $fakeCert),
            str_replace('\\', '/', $resolved)
        );
        $this->assertTrue(is_file($resolved));
    }

    public function testMissingCertReturnsNull(): void
    {
        $emptyRoot = $this->tempDir . '/empty_root';
        @mkdir($emptyRoot, 0777, true);

        $resolved = UpdateRuntimePaths::getCaBundlePath($emptyRoot);
        $this->assertNull($resolved, 'Expected null when physical CA cert is missing in deployed root');
    }

    public function testGitHubReleaseProviderRejectsMissingCaBundleGracefully(): void
    {
        $provider = new GitHubReleaseProvider(
            'ABDO-TECK',
            'pos',
            null,
            $this->tempDir . '/nonexistent_cacert.pem'
        );

        $res = $provider->getLatestRelease();

        $this->assertFalse($res['ok']);
        $this->assertSame('github_ssl_error', $res['error_code']);
        $this->assertStringContainsString('CA certificate bundle not found', $res['error']);
    }

    public function testGitHubReleaseProviderRejectsPharStreamWrapperCaPath(): void
    {
        $provider = new GitHubReleaseProvider(
            'ABDO-TECK',
            'pos',
            null,
            'phar://C:/fake/path/backend.phar/certs/cacert.pem'
        );

        $res = $provider->getLatestRelease();

        $this->assertFalse($res['ok']);
        $this->assertSame('github_ssl_error', $res['error_code']);
        $this->assertStringContainsString('CA certificate bundle not found', $res['error']);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
