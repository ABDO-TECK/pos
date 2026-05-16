<?php
namespace Tests\Unit;

use App\Helpers\EventDispatcher;
use PHPUnit\Framework\TestCase;

class EventDispatcherTest extends TestCase
{
    protected function setUp(): void {
        EventDispatcher::clearAll();
    }

    public function testDispatchCallsListener(): void {
        $called = false;
        EventDispatcher::listen('test.event', function () use (&$called) {
            $called = true;
        });
        EventDispatcher::dispatch('test.event');
        $this->assertTrue($called);
    }

    public function testDispatchPassesData(): void {
        $received = [];
        EventDispatcher::listen('test.data', function (array $data) use (&$received) {
            $received = $data;
        });
        EventDispatcher::dispatch('test.data', ['id' => 42]);
        $this->assertEquals(['id' => 42], $received);
    }

    public function testListenerErrorDoesNotStopExecution(): void {
        $secondCalled = false;
        EventDispatcher::listen('test.error', function () {
            throw new \RuntimeException('fail');
        });
        EventDispatcher::listen('test.error', function () use (&$secondCalled) {
            $secondCalled = true;
        });
        EventDispatcher::dispatch('test.error');
        $this->assertTrue($secondCalled);
    }

    public function testNoListenersDoesNotThrow(): void {
        EventDispatcher::dispatch('nonexistent.event');
        $this->assertTrue(true); // لم يحدث خطأ
    }
}
