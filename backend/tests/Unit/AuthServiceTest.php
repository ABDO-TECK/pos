<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\AuthService;

class AuthServiceTest extends TestCase
{
    private AuthService $service;

    protected function setUp(): void
    {
        $this->service = new AuthService();
    }

    public function testInitialUserIsNull(): void
    {
        $this->assertNull($this->service->user());
        $this->assertNull($this->service->id());
        $this->assertNull($this->service->role());
        $this->assertFalse($this->service->check());
    }

    public function testSetUserStoresData(): void
    {
        $userData = [
            'id'   => 1,
            'name' => 'أحمد',
            'email' => 'ahmed@test.com',
            'role' => 'admin',
        ];

        $this->service->setUser($userData);

        $this->assertEquals($userData, $this->service->user());
        $this->assertEquals(1, $this->service->id());
        $this->assertEquals('admin', $this->service->role());
        $this->assertTrue($this->service->check());
    }

    public function testSetUserWithCashierRole(): void
    {
        $this->service->setUser([
            'id'   => 2,
            'name' => 'سارة',
            'email' => 'sara@test.com',
            'role' => 'cashier',
        ]);

        $this->assertEquals('cashier', $this->service->role());
        $this->assertEquals(2, $this->service->id());
        $this->assertTrue($this->service->check());
    }

    public function testIdReturnsNullWhenNoIdKey(): void
    {
        $this->service->setUser(['name' => 'test', 'role' => 'admin']);
        $this->assertNull($this->service->id());
    }

    public function testRoleReturnsNullWhenNoRoleKey(): void
    {
        $this->service->setUser(['id' => 1, 'name' => 'test']);
        $this->assertNull($this->service->role());
    }
}
