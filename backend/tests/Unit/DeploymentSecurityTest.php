<?php

namespace Tests\Unit;

use App\Config\DeploymentSecurity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeploymentSecurityTest extends TestCase
{
    public function testDesktopAllowsLoopbackDevelopmentControls(): void
    {
        DeploymentSecurity::validate('desktop', 'root', '', false, false, true);
        $this->addToAssertionCount(1);
    }

    public function testDesktopIsRejectedInProduction(): void
    {
        $this->expectException(\RuntimeException::class);
        DeploymentSecurity::validate('desktop', 'pos_user', 'secret', true, true, false, 'production');
    }

    public function testSecureExternalDeploymentIsAccepted(): void
    {
        DeploymentSecurity::validate('web', 'pos_user', 'not-empty', true, true, false);
        DeploymentSecurity::validate('lan', 'pos_user', 'not-empty', true, true, false);
        $this->addToAssertionCount(2);
    }

    #[DataProvider('invalidExternalConfigurationProvider')]
    public function testExternalDeploymentFailsClosed(
        string $databaseUser,
        string $databasePassword,
        bool $forceHttps,
        bool $secureCookies,
        bool $appDebug
    ): void {
        $this->expectException(\RuntimeException::class);

        DeploymentSecurity::validate(
            'web',
            $databaseUser,
            $databasePassword,
            $forceHttps,
            $secureCookies,
            $appDebug,
            'production'
        );
    }

    public static function invalidExternalConfigurationProvider(): array
    {
        return [
            'root database user' => ['root', 'secret', true, true, false],
            'empty database password' => ['pos_user', '', true, true, false],
            'placeholder production password' => ['pos_user', 'CHANGE_ME_TO_A_STRONG_PASSWORD', true, true, false],
            'HTTP permitted' => ['pos_user', 'secret', false, true, false],
            'insecure cookies permitted' => ['pos_user', 'secret', true, false, false],
            'debug mode enabled' => ['pos_user', 'secret', true, true, true],
        ];
    }

    public function testUnknownDeploymentModeIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        DeploymentSecurity::validate('public', 'pos_user', 'secret', true, true, false);
    }
}
