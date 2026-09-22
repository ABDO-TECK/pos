<?php

namespace Tests\Unit;

use App\Services\BackupService;
use App\Services\DeltaUpdateService;
use App\Services\FrontendBuildService;
use App\Services\GitHubReleaseProvider;
use App\Services\GitService;
use App\Services\UpdateService;
use PHPUnit\Framework\TestCase;

class UpdateGitHubFailureBackoffTest extends TestCase
{
    public function testRateLimitFailureIsCachedDuringBackoffEvenForForcedChecks(): void
    {
        $root = sys_get_temp_dir() . '/pos-github-backoff-' . bin2hex(random_bytes(8));
        $storage = $root . '/storage';
        @mkdir($storage, 0755, true);
        file_put_contents($root . '/version.json', json_encode([
            'version' => '0.0.1',
            'update_channel' => 'stable',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $envKeys = ['APP_DEPLOY_ROOT', 'UPDATE_SERVER_URL', 'ENABLE_UPDATE_CHECKS'];
        $previous = [];
        foreach ($envKeys as $key) {
            $previous[$key] = getenv($key);
        }
        putenv('APP_DEPLOY_ROOT=' . $root);
        putenv('UPDATE_SERVER_URL=https://api.github.com/repos/ABDO-TECK/pos/releases/latest');
        putenv('ENABLE_UPDATE_CHECKS=true');

        $provider = $this->createMock(GitHubReleaseProvider::class);
        $provider->expects(self::once())
            ->method('getLatestRelease')
            ->willReturn([
                'ok' => false,
                'error' => 'GitHub API rate limit exceeded.',
                'error_code' => 'github_primary_rate_limited',
                'diagnostics' => [
                    'http_code' => 403,
                    'rate_limit_remaining' => '0',
                    'rate_limit_reset' => (string) (time() + 120),
                ],
            ]);

        try {
            $service = new UpdateService(
                $this->createMock(GitService::class),
                $this->createMock(FrontendBuildService::class),
                $this->createMock(BackupService::class),
                new DeltaUpdateService(null, $root, $storage),
                null,
                null,
                $provider,
            );

            $first = $service->checkForUpdate(true, true);
            $second = $service->checkForUpdate(true, true);

            self::assertFalse($first['success']);
            self::assertTrue($first['updates_unreachable']);
            self::assertSame('github_primary_rate_limited', $first['status']);
            self::assertFalse($second['success']);
            self::assertTrue($second['updates_unreachable']);
            self::assertSame('github_primary_rate_limited', $second['status']);
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }

            @unlink($root . '/version.json');
            @unlink($storage . '/remote_version_failure_cache_stable.json');
            @rmdir($storage);
            @rmdir($root);
        }
    }
}
