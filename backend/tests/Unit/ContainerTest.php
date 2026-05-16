<?php
use PHPUnit\Framework\TestCase;
use App\Core\Container;

class ContainerTest extends TestCase
{
    public function testBindResolvesCorrectClass()
    {
        $c = new Container();
        $c->bind('FakeInterface', \App\Models\Product::class);
        $instance = $c->get('FakeInterface');
        $this->assertInstanceOf(\App\Models\Product::class, $instance);
    }

    public function testSingletonReturnsSameInstance()
    {
        $c = new Container();
        $obj = new \stdClass();
        $obj->id = 42;
        $c->singleton('myObj', $obj);
        $this->assertSame($obj, $c->get('myObj'));
    }

    public function testGetReturnsCachedInstance()
    {
        $c = new Container();
        $a = $c->get(\App\Services\AuthService::class);
        $b = $c->get(\App\Services\AuthService::class);
        $this->assertSame($a, $b);
    }
}
