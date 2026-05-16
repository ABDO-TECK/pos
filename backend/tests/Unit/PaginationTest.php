<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Core\Controller;

/**
 * Concrete subclass to test protected getPaginationParams()
 */
class TestableController extends Controller
{
    public function callGetPaginationParams(int $defaultLimit = 20, int $maxLimit = 500): array
    {
        return $this->getPaginationParams($defaultLimit, $maxLimit);
    }
}

class PaginationTest extends TestCase
{
    private TestableController $ctrl;

    protected function setUp(): void
    {
        // Suppress session errors during testing
        @session_start();
        $this->ctrl = new TestableController();
    }

    protected function tearDown(): void
    {
        unset($_GET['page'], $_GET['limit']);
    }

    public function testNoPaginationReturnsNulls(): void
    {
        $p = $this->ctrl->callGetPaginationParams();
        $this->assertNull($p['page']);
        $this->assertNull($p['limit']);
    }

    public function testPageOnlyUsesDefaultLimit(): void
    {
        $_GET['page'] = '2';
        $p = $this->ctrl->callGetPaginationParams();
        $this->assertEquals(2, $p['page']);
        $this->assertEquals(20, $p['limit']);
    }

    public function testCustomLimit(): void
    {
        $_GET['page'] = '1';
        $_GET['limit'] = '50';
        $p = $this->ctrl->callGetPaginationParams();
        $this->assertEquals(50, $p['limit']);
    }

    public function testLimitCappedAtMax(): void
    {
        $_GET['page'] = '1';
        $_GET['limit'] = '9999';
        $p = $this->ctrl->callGetPaginationParams();
        $this->assertEquals(500, $p['limit']);
    }

    public function testNegativePageBecomesOne(): void
    {
        $_GET['page'] = '-5';
        $_GET['limit'] = '10';
        $p = $this->ctrl->callGetPaginationParams();
        $this->assertEquals(1, $p['page']);
    }
}
