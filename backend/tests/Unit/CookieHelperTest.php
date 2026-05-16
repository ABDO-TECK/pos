<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Helpers\CookieHelper;

class CookieHelperTest extends TestCase
{
    public function testOptionsReturnsArray()
    {
        $options = CookieHelper::options(time() + 3600);
        $this->assertIsArray($options);
        $this->assertArrayHasKey('expires', $options);
        $this->assertArrayHasKey('path', $options);
        $this->assertArrayHasKey('secure', $options);
        $this->assertArrayHasKey('httponly', $options);
        $this->assertArrayHasKey('samesite', $options);
    }

    public function testOptionsDefaultsToHttpOnly()
    {
        $options = CookieHelper::options(time() + 3600);
        $this->assertTrue($options['httponly']);
    }

    public function testOptionsCanDisableHttpOnly()
    {
        $options = CookieHelper::options(time() + 3600, '/', false);
        $this->assertFalse($options['httponly']);
    }

    public function testOptionsDefaultPath()
    {
        $options = CookieHelper::options(time() + 3600);
        $this->assertEquals('/', $options['path']);
    }

    public function testOptionsCustomPath()
    {
        $options = CookieHelper::options(time() + 3600, '/api/v1/refresh');
        $this->assertEquals('/api/v1/refresh', $options['path']);
    }

    public function testOptionsExpiresMatchesInput()
    {
        $expires = time() + 7200;
        $options = CookieHelper::options($expires);
        $this->assertEquals($expires, $options['expires']);
    }
}
