<?php

namespace Tests\Unit;

use App\Middleware\LoginRateLimiter;
use App\Middleware\RateLimitStore;
use PHPUnit\Framework\TestCase;

class LoginRateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        RateLimitStore::reset();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    public function testLoginAlwaysUsesPublicIpBucket(): void
    {
        $limiter = new RecordingLoginRateLimiter();
        $called = false;

        $limiter->handle(function () use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
        $this->assertSame('login_rl', $limiter->prefix);
        $this->assertNull($limiter->userId);
    }

    public function testLoginFailsClosedWhenSharedStoresFail(): void
    {
        RateLimitStore::configureFailuresForTesting(true, true);
        $limiter = new EmergencyLoginRateLimiter(1, 60);

        $limiter->handle(static fn (): null => null);

        $this->assertFalse($limiter->rateLimited);
        $this->assertTrue($limiter->serviceUnavailable);
    }
}

class RecordingLoginRateLimiter extends LoginRateLimiter
{
    public ?string $prefix = null;
    public ?int $userId = 123;

    public function check(
        string $prefix = 'rate_limit',
        ?int $userId = null
    ): void {
        $this->prefix = $prefix;
        $this->userId = $userId;
    }
}

class EmergencyLoginRateLimiter extends LoginRateLimiter
{
    public bool $rateLimited = false;
    public bool $serviceUnavailable = false;

    protected function send429(int $now): void
    {
        $this->rateLimited = true;
    }

    protected function send503(): void
    {
        $this->serviceUnavailable = true;
    }
}
