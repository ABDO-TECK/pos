<?php
use PHPUnit\Framework\TestCase;
use App\Helpers\EnvLoader;

class EnvLoaderTest extends TestCase
{
    public function testGetReturnsDefaultWhenMissing()
    {
        $val = EnvLoader::get('NONEXISTENT_KEY_12345', 'default_val');
        $this->assertEquals('default_val', $val);
    }

    public function testGetBoolReturnsFalseForEmptyString()
    {
        $val = EnvLoader::getBool('NONEXISTENT_KEY_12345', false);
        $this->assertFalse($val);
    }

    public function testGetIntReturnsDefault()
    {
        $val = EnvLoader::getInt('NONEXISTENT_KEY_12345', 42);
        $this->assertEquals(42, $val);
    }
}
