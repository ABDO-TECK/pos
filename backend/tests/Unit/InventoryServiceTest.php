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
    private \PDO&MockObject $dbMock;

    protected function setUp(): void
    {
        $this->productMock  = $this->createMock(Product::class);
        $this->supplierMock = $this->createMock(SupplierRepository::class);
        $this->dbMock = $this->createMock(\PDO::class);

        $this->service = new InventoryService($this->productMock, $this->supplierMock, $this->dbMock);
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

    public function testTwoSerializedPurchaseDeletesDecrementStockOnce(): void
    {
        $inTransaction = false;
        $this->dbMock->method('inTransaction')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                return $inTransaction;
            });
        $this->dbMock->expects($this->exactly(2))
            ->method('beginTransaction')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = true;
                return true;
            });
        $this->dbMock->expects($this->once())
            ->method('commit')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = false;
                return true;
            });
        $this->dbMock->expects($this->once())
            ->method('rollBack')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = false;
                return true;
            });

        $ledgerStatement = $this->createMock(\PDOStatement::class);
        $ledgerStatement->method('execute')->willReturn(true);
        $this->dbMock->method('prepare')->willReturn($ledgerStatement);

        $this->supplierMock->expects($this->exactly(2))
            ->method('getPurchaseInvoiceHeaderForUpdate')
            ->with(15)
            ->willReturnOnConsecutiveCalls(['id' => 15], null);
        $this->supplierMock->expects($this->once())
            ->method('getPurchaseInvoiceItems')
            ->with(15)
            ->willReturn([['product_id' => 3, 'quantity' => 4.0]]);
        $this->productMock->expects($this->once())
            ->method('decrementQuantity')
            ->with(3, 4.0);
        $this->supplierMock->expects($this->once())
            ->method('deletePurchaseInvoice')
            ->with(15)
            ->willReturn(1);

        $first = $this->service->deletePurchaseInvoice(15);
        $second = $this->service->deletePurchaseInvoice(15);

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame(404, $second['code']);
    }

    public function testPurchaseDeleteRollsBackWhenStockIsInsufficient(): void
    {
        $inTransaction = false;
        $this->dbMock->method('inTransaction')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                return $inTransaction;
            });
        $this->dbMock->expects($this->once())
            ->method('beginTransaction')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = true;
                return true;
            });
        $this->dbMock->expects($this->once())
            ->method('rollBack')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = false;
                return true;
            });
        $this->dbMock->expects($this->never())->method('commit');

        $this->supplierMock->method('getPurchaseInvoiceHeaderForUpdate')
            ->willReturn(['id' => 21]);
        $this->supplierMock->method('getPurchaseInvoiceItems')
            ->willReturn([['product_id' => 8, 'quantity' => 2.0]]);
        $this->productMock->method('decrementQuantity')
            ->willThrowException(new \RuntimeException('Insufficient stock or out-of-scope product'));
        $this->supplierMock->expects($this->never())->method('deletePurchaseInvoice');

        $result = $this->service->deletePurchaseInvoice(21);

        $this->assertFalse($result['ok']);
        $this->assertSame(409, $result['code']);
        $this->assertSame('Insufficient stock to delete purchase', $result['error']);
    }

    public function testPurchaseDeleteReturnsConflictWhenLockedHeaderDeleteAffectsNoRows(): void
    {
        $inTransaction = false;
        $this->dbMock->method('inTransaction')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                return $inTransaction;
            });
        $this->dbMock->method('beginTransaction')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = true;
                return true;
            });
        $this->dbMock->expects($this->once())
            ->method('rollBack')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = false;
                return true;
            });
        $this->dbMock->expects($this->never())->method('commit');

        $ledgerStatement = $this->createMock(\PDOStatement::class);
        $ledgerStatement->method('execute')->willReturn(true);
        $this->dbMock->method('prepare')->willReturn($ledgerStatement);
        $this->supplierMock->method('getPurchaseInvoiceHeaderForUpdate')
            ->willReturn(['id' => 22]);
        $this->supplierMock->method('getPurchaseInvoiceItems')->willReturn([]);
        $this->supplierMock->method('deletePurchaseInvoice')->willReturn(0);

        $result = $this->service->deletePurchaseInvoice(22);

        $this->assertFalse($result['ok']);
        $this->assertSame(409, $result['code']);
    }

    public function testPurchaseReplacementLocksHeaderBeforeReadingItems(): void
    {
        $inTransaction = false;
        $sequence = 0;
        $this->dbMock->method('inTransaction')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                return $inTransaction;
            });
        $this->dbMock->expects($this->once())
            ->method('beginTransaction')
            ->willReturnCallback(function () use (&$inTransaction, &$sequence): bool {
                $inTransaction = true;
                $sequence = 1;
                return true;
            });
        $this->dbMock->expects($this->once())
            ->method('commit')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = false;
                return true;
            });

        $ledgerStatement = $this->createMock(\PDOStatement::class);
        $ledgerStatement->method('execute')->willReturn(true);
        $this->dbMock->method('prepare')->willReturn($ledgerStatement);

        $this->supplierMock->method('findById')->willReturn(['id' => 4]);
        $this->supplierMock->expects($this->once())
            ->method('getPurchaseInvoiceHeaderForUpdate')
            ->willReturnCallback(function () use (&$sequence): array {
                $this->assertSame(1, $sequence);
                $sequence = 2;
                return ['id' => 30];
            });
        $this->supplierMock->expects($this->once())
            ->method('getPurchaseInvoiceItems')
            ->willReturnCallback(function () use (&$sequence): array {
                $this->assertSame(2, $sequence);
                $sequence = 3;
                return [];
            });
        $this->supplierMock->method('updatePurchaseInvoiceTotals');
        $this->supplierMock->method('createPurchase');
        $this->supplierMock->method('addLedgerEntry')->willReturn(1);
        $this->productMock->method('findById')->willReturn(['id' => 9, 'quantity' => 5.0]);

        $result = $this->service->processBulkPurchase([
            'supplier_id' => 4,
            'replace_invoice_id' => 30,
            'payment_type' => 'cash',
            'items' => [['product_id' => 9, 'quantity' => 1.0, 'cost' => 10.0]],
        ], ['id' => 2]);

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $sequence);
    }
}
