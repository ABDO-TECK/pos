<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Middleware\DeleteRateLimiter;

class DeleteRateLimiterTest extends TestCase
{
    public function testNonDeleteRequestsPassThrough(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $auth = clone $this->createMock(\App\Services\AuthService::class);
        $limiter = new DeleteRateLimiter($auth);
        $called = false;
        $limiter->handle(function () use (&$called) {
            $called = true;
            return null;
        });
        $this->assertTrue($called, 'GET requests must pass through without rate limiting');
    }

    public function testPostRequestsPassThrough(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $auth = clone $this->createMock(\App\Services\AuthService::class);
        $limiter = new DeleteRateLimiter($auth);
        $called = false;
        $limiter->handle(function () use (&$called) {
            $called = true;
            return null;
        });
        $this->assertTrue($called, 'POST requests must pass through without rate limiting');
    }
}
