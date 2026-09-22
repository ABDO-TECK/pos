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

    public function testGetLatestReleaseClassifiesPrimaryRateLimitAndReturnsSafeDiagnostics(): void
    {
        $provider = $this->getMockBuilder(GitHubReleaseProvider::class)
            ->setConstructorArgs(['ABDO-TECK', 'pos'])
            ->onlyMethods(['executeCurlGet'])
            ->getMock();

        $provider->expects($this->once())
            ->method('executeCurlGet')
            ->willReturn([
                'ok' => false,
                'body' => '{"message":"API rate limit exceeded for 203.0.113.10."}',
                'http_code' => 403,
                'curl_error' => '',
                'curl_errno' => 0,
                'response_headers' => [
                    'x-ratelimit-limit' => '60',
                    'x-ratelimit-remaining' => '0',
                    'x-ratelimit-reset' => '1790028328',
                    'x-ratelimit-resource' => 'core',
                ],
            ]);

        $result = $provider->getLatestRelease('stable', '0.0.1');

        self::assertFalse($result['ok']);
        self::assertSame('github_primary_rate_limited', $result['error_code']);
        self::assertSame(403, $result['diagnostics']['http_code']);
        self::assertSame('0', $result['diagnostics']['rate_limit_remaining']);
        self::assertSame('1790028328', $result['diagnostics']['rate_limit_reset']);
        self::assertArrayNotHasKey('body', $result['diagnostics']);
        self::assertStringNotContainsString('203.0.113.10', (string) ($result['error'] ?? ''));
    }

    public function testGetLatestReleaseClassifiesSecondaryRateLimit(): void
    {
        $provider = $this->getMockBuilder(GitHubReleaseProvider::class)
            ->setConstructorArgs(['ABDO-TECK', 'pos'])
            ->onlyMethods(['executeCurlGet'])
            ->getMock();

        $provider->method('executeCurlGet')->willReturn([
            'ok' => false,
            'body' => '{"message":"You have exceeded a secondary rate limit."}',
            'http_code' => 403,
            'curl_error' => '',
            'curl_errno' => 0,
            'response_headers' => [
                'retry-after' => '30',
                'x-ratelimit-remaining' => '58',
            ],
        ]);

        $result = $provider->getLatestRelease('beta', '0.0.1');

        self::assertFalse($result['ok']);
        self::assertSame('github_secondary_rate_limited', $result['error_code']);
        self::assertSame('30', $result['diagnostics']['retry_after']);
        self::assertSame('58', $result['diagnostics']['rate_limit_remaining']);
    }

    public function testGetLatestReleaseDoesNotTreatEveryForbiddenResponseAsRateLimit(): void
    {
        $provider = $this->getMockBuilder(GitHubReleaseProvider::class)
            ->setConstructorArgs(['ABDO-TECK', 'pos'])
            ->onlyMethods(['executeCurlGet'])
            ->getMock();

        $provider->method('executeCurlGet')->willReturn([
            'ok' => false,
            'body' => '{"message":"Resource not accessible by integration"}',
            'http_code' => 403,
            'curl_error' => '',
            'curl_errno' => 0,
            'response_headers' => [
                'x-ratelimit-remaining' => '59',
            ],
        ]);

        $result = $provider->getLatestRelease('beta', '0.0.1');

        self::assertFalse($result['ok']);
        self::assertSame('github_http_403_forbidden', $result['error_code']);
    }

    public function testV001SelectsCompatibleV002InsteadOfLegacyReleases(): void
    {
        $provider = $this->getMockBuilder(GitHubReleaseProvider::class)
            ->setConstructorArgs(['ABDO-TECK', 'pos'])
            ->onlyMethods(['executeCurlGet'])
            ->getMock();

        $provider->method('executeCurlGet')->willReturnOnConsecutiveCalls(
            [
                'ok' => true,
                'body' => json_encode(['tag_name' => 'v1.2.0', 'prerelease' => false]),
                'http_code' => 200,
                'curl_error' => '',
                'curl_errno' => 0,
            ],
            [
                'ok' => true,
                'body' => json_encode([
                    ['tag_name' => 'v1.2.0', 'prerelease' => false],
                    ['tag_name' => 'v1.1.48', 'prerelease' => false],
                    ['tag_name' => 'v0.0.2', 'prerelease' => false],
                ]),
                'http_code' => 200,
                'curl_error' => '',
                'curl_errno' => 0,
            ],
        );

        $result = $provider->getLatestRelease('stable', '0.0.1');

        $this->assertTrue($result['ok']);
        $this->assertSame('0.0.2', $result['latest_version']);
    }

    public function testBetaDiscoversPrereleaseWhileStableExcludesIt(): void
    {
        $releaseList = json_encode([
            [
                'tag_name' => 'v0.0.4',
                'prerelease' => true,
                'published_at' => '2026-09-22T10:00:00Z',
            ],
            [
                'tag_name' => 'v0.0.3',
                'prerelease' => false,
                'published_at' => '2026-09-21T10:00:00Z',
            ],
        ]);

        $betaProvider = $this->getMockBuilder(GitHubReleaseProvider::class)
            ->setConstructorArgs(['ABDO-TECK', 'pos'])
            ->onlyMethods(['executeCurlGet'])
            ->getMock();
        $betaProvider->expects($this->once())
            ->method('executeCurlGet')
            ->willReturn([
                'ok' => true,
                'body' => $releaseList,
                'http_code' => 200,
                'curl_error' => '',
                'curl_errno' => 0,
            ]);

        $stableProvider = $this->getMockBuilder(GitHubReleaseProvider::class)
            ->setConstructorArgs(['ABDO-TECK', 'pos'])
            ->onlyMethods(['executeCurlGet'])
            ->getMock();
        $stableProvider->expects($this->exactly(2))
            ->method('executeCurlGet')
            ->willReturnOnConsecutiveCalls(
                [
                    'ok' => true,
                    'body' => json_encode(['tag_name' => 'v0.0.4', 'prerelease' => true]),
                    'http_code' => 200,
                    'curl_error' => '',
                    'curl_errno' => 0,
                ],
                [
                    'ok' => true,
                    'body' => $releaseList,
                    'http_code' => 200,
                    'curl_error' => '',
                    'curl_errno' => 0,
                ],
            );

        $beta = $betaProvider->getLatestRelease('beta', '0.0.1');
        $stable = $stableProvider->getLatestRelease('stable', '0.0.1');

        $this->assertTrue($beta['ok']);
        $this->assertSame('0.0.4', $beta['latest_version']);
        $this->assertSame('beta', $beta['channel']);
        $this->assertTrue($stable['ok']);
        $this->assertSame('0.0.3', $stable['latest_version']);
        $this->assertSame('stable', $stable['channel']);
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
