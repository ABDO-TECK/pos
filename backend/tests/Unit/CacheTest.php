<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Helpers\Cache;

class CacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::forget('test_key');
        Cache::forget('test_expired');
    }

    public function testSetAndGetReturnsValue()
    {
        Cache::set('test_key', ['name' => 'POS'], 60);
        $result = Cache::get('test_key');

        $this->assertIsArray($result);
        $this->assertEquals('POS', $result['name']);
    }

    public function testGetReturnsNullForMissingKey()
    {
        $result = Cache::get('nonexistent_key_xyz');
        $this->assertNull($result);
    }

    public function testForgetRemovesKey()
    {
        Cache::set('test_key', 'hello', 60);
        Cache::forget('test_key');

        $this->assertNull(Cache::get('test_key'));
    }
}
