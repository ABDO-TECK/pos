<?php

namespace Tests\Integration;

use App\Controllers\SupplierController;
use App\Repositories\ProductRepository;
use App\Repositories\SupplierRepository;
use App\Services\AuthService;
use App\Services\InventoryService;
use App\Services\SupplierService;
use PDO;
use PHPUnit\Framework\TestCase;

class SupplierControllerTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @runInSeparateProcess
     */
    public function testStoreSupplierSuccess()
    {
        $supplierModelMock = $this->createMock(SupplierRepository::class);
        $productModelMock = $this->createMock(ProductRepository::class);
        $inventoryServiceMock = $this->createMock(InventoryService::class);
        $supplierServiceMock = $this->createMock(SupplierService::class);
        $authServiceMock = $this->createMock(AuthService::class);

        $supplierModelMock->expects($this->once())
            ->method('create')
            ->willReturn(1);

        $supplierModelMock->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(['id' => 1, 'name' => 'مورد تجريبي', 'phone' => '05555']);

        $controller = $this->getMockBuilder(SupplierController::class)
            ->setConstructorArgs([$supplierModelMock, $supplierServiceMock, $authServiceMock, $this->db])
            ->onlyMethods(['getBody'])
            ->getMock();

        $controller->expects($this->once())
            ->method('getBody')
            ->willReturn(['name' => 'مورد تجريبي', 'phone' => '05555']);

        $response = $controller->store();

        $this->assertEquals(201, $response['status_code']);
        $this->assertEquals('success', $response['body']['status']);
        $this->assertEquals(1, $response['body']['data']['id']);
    }

    /**
     * @runInSeparateProcess
     */
    public function testStoreSupplierValidationFails()
    {
        $supplierModelMock = $this->createMock(SupplierRepository::class);
        $productModelMock = $this->createMock(ProductRepository::class);
        $inventoryServiceMock = $this->createMock(InventoryService::class);
        $supplierServiceMock = $this->createMock(SupplierService::class);
        $authServiceMock = $this->createMock(AuthService::class);

        $controller = $this->getMockBuilder(SupplierController::class)
            ->setConstructorArgs([$supplierModelMock, $supplierServiceMock, $authServiceMock, $this->db])
            ->onlyMethods(['getBody'])
            ->getMock();

        $controller->expects($this->once())
            ->method('getBody')
            ->willReturn([]); // Missing name

        $response = $controller->store();

        $this->assertEquals(422, $response['status_code']);
        $this->assertEquals('error', $response['body']['status']);
    }

    /**
     * @runInSeparateProcess
     */
    public function testDestroySupplierNotFound()
    {
        $supplierModelMock = $this->createMock(SupplierRepository::class);
        $productModelMock = $this->createMock(ProductRepository::class);
        $inventoryServiceMock = $this->createMock(InventoryService::class);
        $supplierServiceMock = $this->createMock(SupplierService::class);
        $authServiceMock = $this->createMock(AuthService::class);

        $supplierModelMock->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $controller = new SupplierController($supplierModelMock, $supplierServiceMock, $authServiceMock, $this->db);

        $response = $controller->destroy('999');

        $this->assertEquals(404, $response['status_code']);
    }

    /**
     * @runInSeparateProcess
     */
    public function testAddPaymentSuccess()
    {
        $supplierModelMock = $this->createMock(SupplierRepository::class);
        $productModelMock = $this->createMock(ProductRepository::class);
        $inventoryServiceMock = $this->createMock(InventoryService::class);
        $supplierServiceMock = $this->createMock(SupplierService::class);
        $authServiceMock = $this->createMock(AuthService::class);

        $authServiceMock->expects($this->once())
            ->method('user')
            ->willReturn(['id' => 1]);

        $supplierServiceMock->expects($this->once())
            ->method('addPayment')
            ->willReturn(['supplier' => ['id' => 1], 'entries' => [], 'balance' => 0.0]);

        $controller = $this->getMockBuilder(SupplierController::class)
            ->setConstructorArgs([$supplierModelMock, $supplierServiceMock, $authServiceMock, $this->db])
            ->onlyMethods(['getBody'])
            ->getMock();

        $controller->expects($this->once())
            ->method('getBody')
            ->willReturn(['amount' => 100.0, 'type' => 'credit']);

        $response = $controller->addPayment('1');

        $this->assertEquals(200, $response['status_code']);
        $this->assertStringContainsString('تم تسجيل الدفعة', $response['body']['message']);
    }
}
