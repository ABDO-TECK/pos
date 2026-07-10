<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\LoyaltyService;
use App\Repositories\LoyaltyRepository;
use PDO;

class LoyaltyServiceTest extends TestCase
{
    private LoyaltyService $service;
    private LoyaltyRepository&\PHPUnit\Framework\MockObject\MockObject $repoMock;
    private PDO&\PHPUnit\Framework\MockObject\MockObject $pdoMock;

    protected function setUp(): void
    {
        $this->pdoMock = $this->createMock(PDO::class);
        $this->repoMock = $this->createMock(LoyaltyRepository::class);
        $this->service = new LoyaltyService($this->repoMock, $this->pdoMock);
    }

    public function testIsEnabledReturnsTrue()
    {
        $this->repoMock->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $this->assertTrue($this->service->isEnabled());
    }

    public function testCalculatePoints()
    {
        // Assuming 2 points per rial
        $this->repoMock->expects($this->once())
            ->method('getPointsPerRial')
            ->willReturn(2);

        // 100.5 rials * 2 points/rial = 201 points
        $this->assertEquals(201, $this->service->calculatePoints(100.5));
    }

    public function testRedeemPointsThrowsExceptionIfInsufficient()
    {
        // Setup transaction mock behavior
        $this->pdoMock->expects($this->once())
            ->method('beginTransaction');
        $this->pdoMock->expects($this->once())
            ->method('rollBack');

        $this->repoMock->expects($this->once())
            ->method('getCustomerPoints')
            ->with(1)
            ->willReturn(10); // User has 10 points

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('رصيد النقاط غير كافي');

        // Trying to redeem 20 points
        $this->service->redeemPoints(1, 20);
    }
}
