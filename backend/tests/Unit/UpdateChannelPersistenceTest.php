<?php

namespace Tests\Unit;

use App\Services\BackupService;
use App\Services\DeltaUpdateService;
use App\Services\FrontendBuildService;
use App\Services\GitService;
use App\Services\UpdateService;
use PHPUnit\Framework\TestCase;

class UpdateChannelPersistenceTest extends TestCase
{
    public function testSelectedChannelSurvivesVersionFileReplacementDuringDeltaHandoff(): void
    {
        $root = sys_get_temp_dir() . '/pos-channel-persistence-' . bin2hex(random_bytes(8));
        $storage = $root . '/storage';
        @mkdir($storage, 0755, true);
        file_put_contents($root . '/version.json', json_encode([
            'version' => '0.0.1',
            'update_channel' => 'stable',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $previousDeployRoot = getenv('APP_DEPLOY_ROOT');
        putenv('APP_DEPLOY_ROOT=' . $root);

        try {
            $delta = new DeltaUpdateService(null, $root, $storage);
            $service = new UpdateService(
                $this->createMock(GitService::class),
                $this->createMock(FrontendBuildService::class),
                $this->createMock(BackupService::class),
                $delta,
            );

            self::assertSame('stable', $service->getClientChannel());
            self::assertTrue($service->setClientChannel('beta')['ok']);
            self::assertSame('beta', $service->getClientChannel());

            file_put_contents($root . '/version.json', json_encode([
                'version' => '0.0.3',
                'update_channel' => 'stable',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            self::assertSame('beta', $service->getClientChannel());
        } finally {
            if ($previousDeployRoot === false) {
                putenv('APP_DEPLOY_ROOT');
            } else {
                putenv('APP_DEPLOY_ROOT=' . $previousDeployRoot);
            }

            @unlink($root . '/version.json');
            @rmdir($storage);
            @rmdir($root);
        }
    }
}
