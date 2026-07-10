<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\ProductService;
use App\Repositories\ProductRepository;
use App\Models\PriceHistory;

class ProductServiceTest extends TestCase
{
    private ProductService $service;
    private ProductRepository $productRepoMock;
    private PriceHistory $priceHistoryMock;

    protected function setUp(): void
    {
        $this->productRepoMock = $this->createMock(ProductRepository::class);
        $this->priceHistoryMock = $this->createMock(PriceHistory::class);
        $this->service = new ProductService($this->productRepoMock, $this->priceHistoryMock);
    }

    public function testDeleteProductNotFound()
    {
        $this->productRepoMock->method('findById')
            ->willReturn(null);

        $result = $this->service->deleteProduct(999);

        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['code']);
    }

    public function testDeleteProductWithReferences()
    {
        $this->productRepoMock->method('findById')
            ->willReturn(['id' => 1, 'name' => 'Product 1']);

        $this->productRepoMock->method('referenceCounts')
            ->willReturn(['invoice_items' => 5, 'purchases' => 0]);

        $result = $this->service->deleteProduct(1);

        $this->assertFalse($result['ok']);
        $this->assertEquals(409, $result['code']);
        $this->assertStringContainsString('لا يمكن حذف', $result['error']);
    }

    public function testDeleteProductSuccess()
    {
        $this->productRepoMock->method('findById')
            ->willReturn(['id' => 1, 'name' => 'Product 1']);

        $this->productRepoMock->method('referenceCounts')
            ->willReturn(['invoice_items' => 0, 'purchases' => 0]);

        $this->productRepoMock->expects($this->once())
            ->method('delete')
            ->with(1);

        $result = $this->service->deleteProduct(1);

        $this->assertTrue($result['ok']);
    }

    public function testGetLowStockProducts()
    {
        $this->productRepoMock->method('getLowStock')
            ->willReturn([
                ['id' => 1, 'name' => 'P1'],
                ['id' => 2, 'name' => 'P2'],
                ['id' => 3, 'name' => 'P3']
            ]);

        $result = $this->service->getLowStockProducts();

        $this->assertCount(3, $result);
    }

    public function testCreateProductSuccess()
    {
        $data = [
            'name' => 'Test Product',
            'price' => 25.00,
            'barcode' => '123456789',
            'cost' => 15.00,
            'quantity' => 100,
        ];

        $this->productRepoMock->method('create')
            ->willReturn(1);

        $this->productRepoMock->method('findById')
            ->with(1)
            ->willReturn(array_merge(['id' => 1], $data));

        $result = $this->service->createProduct($data);

        $this->assertTrue($result['ok']);
        $this->assertEquals(1, $result['product']['id']);
        $this->assertEquals('Test Product', $result['product']['name']);
    }

    public function testUpdateProductNotFound()
    {
        $this->productRepoMock->method('findById')
            ->willReturn(null);

        $result = $this->service->updateProduct(999, ['name' => 'Updated']);

        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['code']);
    }

    public function testUpdateProductSuccess()
    {
        $this->productRepoMock->method('findById')
            ->willReturn(['id' => 1, 'name' => 'Old', 'barcode' => '12345', 'price' => 10, 'cost' => 5]);

        $this->productRepoMock->expects($this->once())
            ->method('update');

        $this->productRepoMock->method('findById')
            ->willReturnMap([
                [1, ['id' => 1, 'name' => 'New Name', 'barcode' => '12345', 'price' => 15, 'cost' => 5]]
            ]);

        $result = $this->service->updateProduct(1, ['name' => 'New Name', 'price' => 15]);

        $this->assertTrue($result['ok']);
    }
}
