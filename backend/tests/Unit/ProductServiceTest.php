<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\ProductService;
use App\Repositories\ProductRepository;
use App\Models\PriceHistory;
use App\Services\AuthService;

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
        (new AuthService())->setBranchId(1);
    }

    protected function tearDown(): void
    {
        (new AuthService())->setBranchId(1);
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

    public function testCatalogSnapshotUsesCursorWithoutCountAndResumes(): void
    {
        $this->productRepoMock->expects($this->once())
            ->method('getCatalogVersion')
            ->willReturn(1205);
        $this->productRepoMock->expects($this->exactly(2))
            ->method('getCatalogSnapshotPage')
            ->willReturnOnConsecutiveCalls(
                [
                    'data' => [['id' => 500, 'name' => 'P500']],
                    'has_more' => true,
                    'last_id' => 500,
                ],
                [
                    'data' => [['id' => 501, 'name' => 'P501']],
                    'has_more' => false,
                    'last_id' => 501,
                ]
            );

        $first = $this->service->syncCatalog(null, 500);
        $second = $this->service->syncCatalog($first['pagination']['next_checkpoint'], 500);

        $this->assertSame('snapshot', $first['pagination']['mode']);
        $this->assertTrue($first['pagination']['has_more']);
        $this->assertFalse($second['pagination']['has_more']);
        $this->assertSame(1205, $second['catalog_version']);
        $this->assertSame(501, $second['data'][0]['id']);
    }

    public function testCatalogCheckpointCannotCrossBranches(): void
    {
        $this->productRepoMock->method('getCatalogVersion')->willReturn(5);
        $this->productRepoMock->method('getCatalogSnapshotPage')->willReturn([
            'data' => [],
            'has_more' => false,
            'last_id' => 0,
        ]);

        $first = $this->service->syncCatalog(null);
        (new AuthService())->setBranchId(2);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('another branch');
        $this->service->syncCatalog($first['pagination']['next_checkpoint']);
    }

    public function testCatalogDeltaReturnsUpdatesAndDeleteTombstones(): void
    {
        $this->productRepoMock->expects($this->exactly(2))
            ->method('getCatalogVersion')
            ->willReturnOnConsecutiveCalls(2, 4);
        $this->productRepoMock->method('getCatalogSnapshotPage')->willReturn([
            'data' => [],
            'has_more' => false,
            'last_id' => 0,
        ]);
        $this->productRepoMock->expects($this->once())
            ->method('getCatalogChangePage')
            ->with(2, 500)
            ->willReturn([
                'data' => [
                    ['id' => 10, 'name' => 'Updated', '_deleted' => false],
                    ['id' => 11, '_deleted' => true, 'deleted_at' => '2026-07-28 12:00:00'],
                ],
                'has_more' => false,
                'last_sequence' => 4,
            ]);

        $snapshot = $this->service->syncCatalog(null);
        $delta = $this->service->syncCatalog($snapshot['pagination']['next_checkpoint']);

        $this->assertSame('delta', $delta['pagination']['mode']);
        $this->assertCount(2, $delta['data']);
        $this->assertSame('Updated', $delta['data'][0]['name']);
        $this->assertTrue($delta['data'][1]['_deleted']);
    }
}
