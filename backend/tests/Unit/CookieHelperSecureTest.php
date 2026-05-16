<?php
use PHPUnit\Framework\TestCase;
use App\Helpers\CookieHelper;

class CookieHelperSecureTest extends TestCase
{
    public function testOptionsReturnsArray()
    {
        $opts = CookieHelper::options(time() + 3600);
        $this->assertIsArray($opts);
        $this->assertArrayHasKey('expires', $opts);
        $this->assertArrayHasKey('secure', $opts);
        $this->assertArrayHasKey('httponly', $opts);
        $this->assertArrayHasKey('samesite', $opts);
    }

    public function testHttpOnlyDefaultsToTrue()
    {
        $opts = CookieHelper::options(time() + 3600);
        $this->assertTrue($opts['httponly']);
    }
}
