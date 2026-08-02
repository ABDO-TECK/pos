<?php

namespace Tests\Unit;

use App\Middleware\Traits\ClientIpTrait;
use PHPUnit\Framework\TestCase;

class ClientIpTraitTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        parent::tearDown();
    }

    public function testTrustedProxyReturnsValidatedForwardedAddress(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.25';

        $this->assertSame('192.168.1.25', $this->clientIp());
    }

    public function testInvalidForwardedAddressFallsBackToValidatedRealIp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
        $_SERVER['HTTP_X_REAL_IP'] = '192.168.1.26';

        $this->assertSame('192.168.1.26', $this->clientIp());
    }

    public function testUntrustedPeerCannotOverrideItsAddress(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.50';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.10';
        $_SERVER['HTTP_X_REAL_IP'] = '203.0.113.11';

        $this->assertSame('192.168.1.50', $this->clientIp());
    }

    public function testMalformedRemoteAddressIsRejected(): void
    {
        $_SERVER['REMOTE_ADDR'] = "127.0.0.1\r\nX-Injected: true";

        $this->assertSame('unknown', $this->clientIp());
    }

    private function clientIp(): string
    {
        $subject = new class {
            use ClientIpTrait;

            public function resolve(): string
            {
                return $this->getClientIp();
            }
        };

        return $subject->resolve();
    }
}
