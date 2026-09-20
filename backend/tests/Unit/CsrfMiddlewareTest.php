<?php

namespace Tests\Unit;

use App\Middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;

class CsrfMiddlewareTest extends TestCase
{
    private array $originalServer = [];
    private array $originalCookie = [];
    private mixed $originalCsrfSecret = false;
    private mixed $originalCsrfSecretEnv = null;
    private mixed $originalAppEnv = false;
    private mixed $originalAppEnvEnv = null;
    private mixed $originalDeploymentMode = false;
    private mixed $originalDeploymentModeEnv = null;
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $this->originalCookie = $_COOKIE;
        $this->originalCsrfSecret = getenv('CSRF_SECRET');
        $this->originalCsrfSecretEnv = $_ENV['CSRF_SECRET'] ?? null;
        $this->originalAppEnv = getenv('APP_ENV');
        $this->originalAppEnvEnv = $_ENV['APP_ENV'] ?? null;
        $this->originalDeploymentMode = getenv('DEPLOYMENT_MODE');
        $this->originalDeploymentModeEnv = $_ENV['DEPLOYMENT_MODE'] ?? null;

        putenv('CSRF_SECRET=' . self::SECRET);
        $_ENV['CSRF_SECRET'] = self::SECRET;
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        putenv('DEPLOYMENT_MODE=desktop');
        $_ENV['DEPLOYMENT_MODE'] = 'desktop';
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/products',
        ];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_COOKIE = $this->originalCookie;

        if ($this->originalCsrfSecret === false) {
            putenv('CSRF_SECRET');
        } else {
            putenv('CSRF_SECRET=' . $this->originalCsrfSecret);
        }

        if ($this->originalCsrfSecretEnv === null) {
            unset($_ENV['CSRF_SECRET']);
        } else {
            $_ENV['CSRF_SECRET'] = $this->originalCsrfSecretEnv;
        }

        if ($this->originalAppEnv === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->originalAppEnv);
        }
        if ($this->originalAppEnvEnv === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->originalAppEnvEnv;
        }

        if ($this->originalDeploymentMode === false) {
            putenv('DEPLOYMENT_MODE');
        } else {
            putenv('DEPLOYMENT_MODE=' . $this->originalDeploymentMode);
        }
        if ($this->originalDeploymentModeEnv === null) {
            unset($_ENV['DEPLOYMENT_MODE']);
        } else {
            $_ENV['DEPLOYMENT_MODE'] = $this->originalDeploymentModeEnv;
        }
        parent::tearDown();
    }

    public function testDesktopOriginRequiresAValidSignedDoubleSubmitToken(): void
    {
        $nonce = 'desktop-nonce';
        $_SERVER['HTTP_ORIGIN'] = 'app://pos-app';
        $_SERVER['HTTP_X_XSRF_TOKEN'] = hash_hmac('sha256', $nonce, self::SECRET);
        $_COOKIE['XSRF-TOKEN'] = $nonce;

        $called = false;
        $response = (new CsrfMiddleware())->handle(function () use (&$called): array {
            $called = true;
            return ['ok' => true];
        });

        $this->assertTrue($called);
        $this->assertSame(['ok' => true], $response);
    }

    public function testDesktopOriginWithoutTokenIsRejected(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'app://pos-app';

        $response = (new CsrfMiddleware())->handle(static fn (): array => ['ok' => true]);

        $this->assertSame(403, $response['status_code']);
    }

    public function testDesktopOriginWithAlteredSignatureIsRejected(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'app://pos-app';
        $_COOKIE['XSRF-TOKEN'] = 'desktop-nonce';
        $_SERVER['HTTP_X_XSRF_TOKEN'] = str_repeat('0', 64);

        $response = (new CsrfMiddleware())->handle(static fn (): array => ['ok' => true]);

        $this->assertSame(403, $response['status_code']);
    }

    public function testForeignOriginIsRejectedEvenWithAValidSignature(): void
    {
        $nonce = 'desktop-nonce';
        $_SERVER['HTTP_ORIGIN'] = 'app://evil';
        $_SERVER['HTTP_X_XSRF_TOKEN'] = hash_hmac('sha256', $nonce, self::SECRET);
        $_COOKIE['XSRF-TOKEN'] = $nonce;

        $response = (new CsrfMiddleware())->handle(static fn (): array => ['ok' => true]);

        $this->assertSame(403, $response['status_code']);
    }

    public function testMissingOriginIsRejectedForDesktopMutation(): void
    {
        $nonce = 'desktop-nonce';
        $_SERVER['HTTP_X_XSRF_TOKEN'] = hash_hmac('sha256', $nonce, self::SECRET);
        $_COOKIE['XSRF-TOKEN'] = $nonce;

        $response = (new CsrfMiddleware())->handle(static fn (): array => ['ok' => true]);

        $this->assertSame(403, $response['status_code']);
    }
}
