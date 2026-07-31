<?php

namespace Tests\Unit;

use App\Helpers\NetworkHelper;
use PHPUnit\Framework\TestCase;

class NetworkHelperTest extends TestCase
{
    public function testAcceptsLoopbackAndPrivateOrigins(): void
    {
        self::assertTrue(NetworkHelper::isLanOrigin('http://localhost:5173'));
        self::assertTrue(NetworkHelper::isLanOrigin('https://127.0.0.1:8443'));
        self::assertTrue(NetworkHelper::isLanOrigin('https://10.20.30.40'));
        self::assertTrue(NetworkHelper::isLanOrigin('https://172.31.255.254'));
        self::assertTrue(NetworkHelper::isLanOrigin('https://192.168.1.50'));
    }

    public function testRejectsMalformedAndPublicOrigins(): void
    {
        self::assertFalse(NetworkHelper::isLanOrigin('https://192.168.999.1'));
        self::assertFalse(NetworkHelper::isLanOrigin('https://172.32.0.1'));
        self::assertFalse(NetworkHelper::isLanOrigin('https://8.8.8.8'));
        self::assertFalse(NetworkHelper::isLanOrigin('https://192.168.1.2/path'));
        self::assertFalse(NetworkHelper::isLanOrigin('javascript://192.168.1.2'));
    }
}
