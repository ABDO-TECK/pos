<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\ProductService;
use App\Repositories\ProductRepository;

class ProductServiceTest extends TestCase
{
    private ProductService $service;
    private ProductRepository $productRepoMock;

    protected function setUp(): void
    {
        $this->productRepoMock = $this->createMock(ProductRepository::class);
        $this->service = new ProductService($this->productRepoMock);
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
}
