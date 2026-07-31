<?php

use PHPUnit\Framework\TestCase;
use App\Middleware\RateLimiter;
use App\Middleware\RateLimitStore;

class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        RateLimitStore::reset();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    public function testCheckDoesNotBlockUnderLimit()
    {
        $limiter = new RateLimiter(5, 60);
        ob_start();
        $limiter->check('test_rl', 99999);
        $output = ob_get_clean();
        $this->assertEmpty($output, 'First request should not be rate limited');
    }

    public function testSharedStoreFailureFailsSensitiveEndpointClosed(): void
    {
        RateLimitStore::configureFailuresForTesting(true, true);
        $limiter = new InspectableRateLimiter(1, 60);

        $limiter->check('write_rl', 42);

        $this->assertFalse($limiter->rateLimited);
        $this->assertTrue($limiter->serviceUnavailable);
    }

    public function testOrdinaryReadRemainsAvailableWhenEmergencyPoolIsFull(): void
    {
        RateLimitStore::configureFailuresForTesting(true, true, 1);
        $limiter = new InspectableRateLimiter();

        $limiter->check('read_rl', 1);
        $limiter->check('read_rl', 2);

        $this->assertFalse($limiter->serviceUnavailable);
        $this->assertFalse($limiter->rateLimited);
    }

    public function testOnlyReadLimiterUsesAvailabilityFirstPolicy(): void
    {
        $limiter = new InspectableRateLimiter();

        $this->assertTrue($limiter->availabilityFirst('read_rl'));
        $this->assertFalse($limiter->availabilityFirst('write_rl'));
        $this->assertFalse($limiter->availabilityFirst('delete_rl'));
        $this->assertFalse($limiter->availabilityFirst('ep_refresh'));
    }

    public function testAuthenticatedUsersOnOneIpHaveIndependentBuckets(): void
    {
        $limiter = new InspectableRateLimiter();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->assertNotSame(
            $limiter->storageKey('read_rl', 101, 1_800_000_000),
            $limiter->storageKey('read_rl', 202, 1_800_000_000)
        );
    }

    public function testAuthenticatedUserKeepsBucketWhenIpChanges(): void
    {
        $limiter = new InspectableRateLimiter();
        $_SERVER['REMOTE_ADDR'] = '10.0.0.10';
        $firstKey = $limiter->storageKey('read_rl', 101, 1_800_000_000);

        $_SERVER['REMOTE_ADDR'] = '10.0.0.11';
        $secondKey = $limiter->storageKey('read_rl', 101, 1_800_000_000);

        $this->assertSame($firstKey, $secondKey);
    }

    public function testPublicRequestsUseIpBuckets(): void
    {
        $limiter = new InspectableRateLimiter();
        $_SERVER['REMOTE_ADDR'] = '10.0.0.10';
        $firstKey = $limiter->storageKey('public_rl', null, 1_800_000_000);

        $_SERVER['REMOTE_ADDR'] = '10.0.0.11';
        $secondKey = $limiter->storageKey('public_rl', null, 1_800_000_000);

        $this->assertNotSame($firstKey, $secondKey);
    }
}

class InspectableRateLimiter extends RateLimiter
{
    public bool $rateLimited = false;
    public bool $serviceUnavailable = false;

    public function storageKey(string $prefix, ?int $userId, int $now): string
    {
        return $this->buildStorageKey($prefix, $userId, $now);
    }

    public function availabilityFirst(string $prefix): bool
    {
        return $this->isAvailabilityFirst($prefix);
    }

    protected function send429(int $now): void
    {
        $this->rateLimited = true;
    }

    protected function send503(): void
    {
        $this->serviceUnavailable = true;
    }
}
