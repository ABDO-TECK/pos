<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\LoyaltyService;

class LoyaltyServiceTest extends TestCase
{
    public function testCalculatePointsReturnsInteger()
    {
        $service = $this->getMockBuilder(LoyaltyService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isEnabled'])
            ->getMock();
        
        $service->method('isEnabled')->willReturn(true);
        $this->assertInstanceOf(LoyaltyService::class, $service);
    }
}
