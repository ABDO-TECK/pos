<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\LoyaltyService;
use PDO;
use PDOStatement;
use ReflectionClass;

class LoyaltyServiceTest extends TestCase
{
    private LoyaltyService $service;
    private PDO&\PHPUnit\Framework\MockObject\MockObject $pdoMock;

    protected function setUp(): void
    {
        $this->pdoMock = $this->createMock(PDO::class);
        $this->service = clone $this->getMockBuilder(LoyaltyService::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Use Reflection to set the private PDO property
        $reflection = new ReflectionClass(LoyaltyService::class);
        $property = $reflection->getProperty('db');
        $property->setAccessible(true);
        $property->setValue($this->service, $this->pdoMock);
        
        // Actually, disableOriginalConstructor() makes it a mock. We want a real instance 
        // with the db property injected.
        $this->service = $reflection->newInstanceWithoutConstructor();
        $property->setValue($this->service, $this->pdoMock);
    }

    public function testIsEnabledReturnsTrue()
    {
        $stmtMock = $this->createMock(PDOStatement::class);
        $stmtMock->method('fetchColumn')->willReturn('1');

        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('loyalty_enabled'))
            ->willReturn($stmtMock);

        $this->assertTrue($this->service->isEnabled());
    }

    public function testCalculatePoints()
    {
        $stmtMock = $this->createMock(PDOStatement::class);
        // Assuming 2 points per rial
        $stmtMock->method('fetchColumn')->willReturn('2');

        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('loyalty_points_per_rial'))
            ->willReturn($stmtMock);

        // 100.5 rials * 2 points/rial = 201 points
        $this->assertEquals(201, $this->service->calculatePoints(100.5));
    }

    public function testRedeemPointsThrowsExceptionIfInsufficient()
    {
        $stmtMock = $this->createMock(PDOStatement::class);
        $stmtMock->method('fetchColumn')->willReturn('10'); // User has 10 points

        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('loyalty_points FROM customers'))
            ->willReturn($stmtMock);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('رصيد النقاط غير كافي');

        // Trying to redeem 20 points
        $this->service->redeemPoints(1, 20);
    }
}
