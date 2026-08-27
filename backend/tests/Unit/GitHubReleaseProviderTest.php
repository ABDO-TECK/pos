<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\GitHubReleaseProvider;

class GitHubReleaseProviderTest extends TestCase
{
    public function testGetLatestReleaseParsesPayloadCorrectly(): void
    {
        $mockProvider = $this->getMockBuilder(GitHubReleaseProvider::class)
            ->setConstructorArgs(['ABDO-TECK', 'pos'])
            ->onlyMethods(['executeCurlGet'])
            ->getMock();

        $sampleReleaseJson = json_encode([
            'tag_name' => 'v1.1.48',
            'html_url' => 'https://github.com/ABDO-TECK/pos/releases/tag/v1.1.48',
            'published_at' => '2026-08-27T08:00:00Z',
            'body' => "- Fix logger timestamp\n- Optimize product barcode queries",
            'prerelease' => false,
            'assets' => [
                [
                    'name' => 'manifest.json',
                    'browser_download_url' => 'https://github.com/ABDO-TECK/pos/releases/download/v1.1.48/manifest.json',
                ],
                [
                    'name' => 'manifest.sig',
                    'browser_download_url' => 'https://github.com/ABDO-TECK/pos/releases/download/v1.1.48/manifest.sig',
                ],
                [
                    'name' => 'delta-1.1.47-to-1.1.48.zip',
                    'browser_download_url' => 'https://github.com/ABDO-TECK/pos/releases/download/v1.1.48/delta-1.1.47-to-1.1.48.zip',
                ],
                [
                    'name' => 'POS-Setup-1.1.48.exe',
                    'browser_download_url' => 'https://github.com/ABDO-TECK/pos/releases/download/v1.1.48/POS-Setup-1.1.48.exe',
                ],
            ],
        ]);

        $mockProvider->method('executeCurlGet')->willReturn([
            'ok' => true,
            'body' => $sampleReleaseJson,
            'http_code' => 200,
            'curl_error' => '',
            'curl_errno' => 0,
        ]);

        $res = $mockProvider->getLatestRelease();

        $this->assertTrue($res['ok']);
        $this->assertSame('1.1.48', $res['latest_version']);
        $this->assertSame('v1.1.48', $res['tag_name']);
        $this->assertSame('https://github.com/ABDO-TECK/pos/releases/download/v1.1.48/manifest.json', $res['manifest_url']);
        $this->assertSame('https://github.com/ABDO-TECK/pos/releases/download/v1.1.48/manifest.sig', $res['signature_url']);
        $this->assertSame('https://github.com/ABDO-TECK/pos/releases/download/v1.1.48/delta-1.1.47-to-1.1.48.zip', $res['delta_url']);
        $this->assertSame('https://github.com/ABDO-TECK/pos/releases/download/v1.1.48/POS-Setup-1.1.48.exe', $res['full_package_url']);
        $this->assertCount(2, $res['changelog']);
    }

    public function testGetLatestReleaseHandlesHttp404(): void
    {
        $mockProvider = $this->getMockBuilder(GitHubReleaseProvider::class)
            ->setConstructorArgs(['ABDO-TECK', 'pos'])
            ->onlyMethods(['executeCurlGet'])
            ->getMock();

        $mockProvider->method('executeCurlGet')->willReturn([
            'ok' => false,
            'body' => '{"message": "Not Found"}',
            'http_code' => 404,
            'curl_error' => 'Not Found',
            'curl_errno' => 0,
        ]);

        $res = $mockProvider->getLatestRelease();

        $this->assertFalse($res['ok']);
        $this->assertSame('github_release_not_found', $res['error_code']);
    }

    public function testGetLatestReleaseHandlesTimeout(): void
    {
        $mockProvider = $this->getMockBuilder(GitHubReleaseProvider::class)
            ->setConstructorArgs(['ABDO-TECK', 'pos'])
            ->onlyMethods(['executeCurlGet'])
            ->getMock();

        $mockProvider->method('executeCurlGet')->willReturn([
            'ok' => false,
            'body' => false,
            'http_code' => 0,
            'curl_error' => 'Operation timed out after 15000 milliseconds',
            'curl_errno' => 28,
        ]);

        $res = $mockProvider->getLatestRelease();

        $this->assertFalse($res['ok']);
        $this->assertSame('github_network_timeout', $res['error_code']);
    }

    public function testAllowedUrlValidation(): void
    {
        $provider = new GitHubReleaseProvider('ABDO-TECK', 'pos');

        $this->assertTrue($provider->isAllowedUrl('https://api.github.com/repos/ABDO-TECK/pos/releases/latest'));
        $this->assertTrue($provider->isAllowedUrl('https://github.com/ABDO-TECK/pos/releases/download/v1.1.48/manifest.json'));
        $this->assertTrue($provider->isAllowedUrl('https://objects.githubusercontent.com/github-production-release-asset-2e65be/123'));
        $this->assertTrue($provider->isAllowedUrl('https://raw.githubusercontent.com/ABDO-TECK/pos/main/version.json'));

        $this->assertFalse($provider->isAllowedUrl('http://api.github.com')); // HTTP disallowed
        $this->assertFalse($provider->isAllowedUrl('https://evil-server.com/manifest.json'));
        $this->assertFalse($provider->isAllowedUrl('ftp://github.com/file'));
    }

    public function testFetchAssetContentRejectsUnapprovedHost(): void
    {
        $provider = new GitHubReleaseProvider('ABDO-TECK', 'pos');
        $res = $provider->fetchAssetContent('https://untrusted-host.com/hack.json');

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('not in the allowed update hosts', $res['error']);
    }
}
