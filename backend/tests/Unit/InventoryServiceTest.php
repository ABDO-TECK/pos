<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\InventoryService;
use App\Models\Product;
use App\Repositories\SupplierRepository;
use PHPUnit\Framework\MockObject\MockObject;

class InventoryServiceTest extends TestCase
{
    private InventoryService $service;
    private Product&MockObject $productMock;
    private SupplierRepository&MockObject $supplierMock;

    protected function setUp(): void
    {
        $this->productMock  = $this->createMock(Product::class);
        $this->supplierMock = $this->createMock(SupplierRepository::class);

        $this->service = new InventoryService($this->productMock, $this->supplierMock);
    }

    public function testBulkPurchaseRejectsEmptySupplierId()
    {
        $data = ['supplier_id' => null, 'items' => []];
        $authUser = ['id' => 1];

        $result = $this->service->processBulkPurchase($data, $authUser);

        $this->assertFalse($result['ok']);
        $this->assertEquals(422, $result['code']);
    }

    public function testBulkPurchaseRejectsEmptyItems()
    {
        $data = ['supplier_id' => 1, 'items' => []];
        $authUser = ['id' => 1];

        $result = $this->service->processBulkPurchase($data, $authUser);

        $this->assertFalse($result['ok']);
        $this->assertEquals(422, $result['code']);
    }

    public function testBulkPurchaseRejectsNonExistentSupplier()
    {
        $this->supplierMock->method('findById')
            ->willReturn(null);

        $data = [
            'supplier_id' => 999,
            'items' => [
                ['product_id' => 1, 'quantity' => 5, 'cost' => 10]
            ]
        ];
        $authUser = ['id' => 1];

        $result = $this->service->processBulkPurchase($data, $authUser);

        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['code']);
    }

    public function testBulkPurchaseRejectsInvalidItemData()
    {
        $this->supplierMock->method('findById')->willReturn(['id' => 1, 'name' => 'Test']);

        $data = [
            'supplier_id' => 1,
            'items' => [
                ['product_id' => null, 'quantity' => 5, 'cost' => 10]
            ]
        ];
        $authUser = ['id' => 1];

        $result = $this->service->processBulkPurchase($data, $authUser);
        $this->assertFalse($result['ok']);
    }

    public function testBulkPurchaseRejectsZeroQuantityItem()
    {
        $this->supplierMock->method('findById')->willReturn(['id' => 1, 'name' => 'Test']);

        $data = [
            'supplier_id' => 1,
            'items' => [
                ['product_id' => 1, 'quantity' => 0, 'cost' => 10]
            ]
        ];
        $authUser = ['id' => 1];

        $result = $this->service->processBulkPurchase($data, $authUser);
        $this->assertFalse($result['ok']);
    }

    public function testBulkPurchaseRejectsNegativeCost()
    {
        $this->supplierMock->method('findById')->willReturn(['id' => 1, 'name' => 'Test']);

        $data = [
            'supplier_id' => 1,
            'items' => [
                ['product_id' => 1, 'quantity' => 5, 'cost' => -10]
            ]
        ];
        $authUser = ['id' => 1];

        $result = $this->service->processBulkPurchase($data, $authUser);
        $this->assertFalse($result['ok']);
    }

    public function testBulkPurchaseRejectsStringQuantity()
    {
        $this->supplierMock->method('findById')->willReturn(['id' => 1, 'name' => 'Test']);

        $data = [
            'supplier_id' => 1,
            'items' => [
                ['product_id' => 1, 'quantity' => 'abc', 'cost' => 10]
            ]
        ];
        $authUser = ['id' => 1];

        $result = $this->service->processBulkPurchase($data, $authUser);
        $this->assertFalse($result['ok']);
    }

    public function testBulkPurchaseRejectsMissingCost()
    {
        $this->supplierMock->method('findById')->willReturn(['id' => 1, 'name' => 'Test']);

        $data = [
            'supplier_id' => 1,
            'items' => [
                ['product_id' => 1, 'quantity' => 5]
                // cost is missing
            ]
        ];
        $authUser = ['id' => 1];

        $result = $this->service->processBulkPurchase($data, $authUser);
        $this->assertFalse($result['ok']);
    }
}
