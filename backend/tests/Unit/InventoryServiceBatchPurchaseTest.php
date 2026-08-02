<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Repositories\SupplierRepository;
use App\Services\InventoryService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class InventoryServiceBatchPurchaseTest extends TestCase
{
    public function testBulkPurchaseUsesFixedCountBatchOperations(): void
    {
        $product = $this->createMock(Product::class);
        $supplier = $this->createMock(SupplierRepository::class);
        $db = $this->transactionalPdoMock();

        $supplier->method('findById')->with(4)->willReturn(['id' => 4, 'branch_id' => 1]);
        $supplier->expects(self::once())
            ->method('createPurchaseInvoice')
            ->with(self::callback(static fn(array $invoice): bool =>
                $invoice['total'] === 32.0
                && $invoice['discount'] === 3.0
                && $invoice['shipping_cost'] === 4.0
            ))
            ->willReturn(91);
        $supplier->expects(self::once())
            ->method('createPurchases')
            ->with(self::callback(static fn(array $rows): bool =>
                count($rows) === 2
                && $rows[0]['purchase_invoice_id'] === 91
                && $rows[1]['supplier_id'] === 4
            ))
            ->willReturn(1001);
        $supplier->expects(self::exactly(2))
            ->method('addLedgerEntry')
            ->with(self::callback(static fn(array $entry): bool => $entry['amount'] === 32.0));

        $product->expects(self::once())
            ->method('findByIds')
            ->with([10, 20])
            ->willReturn([10 => ['id' => 10], 20 => ['id' => 20]]);
        $product->expects(self::once())
            ->method('batchIncrementQuantity')
            ->with([
                ['product_id' => 10, 'quantity' => 2.0],
                ['product_id' => 20, 'quantity' => 3.0],
            ]);
        $product->expects(self::once())
            ->method('batchUpdateCosts')
            ->with([['product_id' => 10, 'cost' => 5.0]]);
        $product->expects(self::never())->method('findById');
        $product->expects(self::never())->method('incrementQuantity');

        $service = new InventoryService($product, $supplier, $db);
        $result = $service->processBulkPurchase([
            'supplier_id' => 4,
            'payment_type' => 'cash',
            'discount' => 3,
            'shipping_cost' => 4,
            'items' => [
                ['product_id' => 10, 'quantity' => 2, 'cost' => 5, 'update_cost' => true],
                ['product_id' => 20, 'quantity' => 3, 'cost' => 7],
            ],
        ], ['id' => 7]);

        self::assertTrue($result['ok']);
        self::assertSame(91, $result['invoice_id']);
        self::assertSame(2, $result['items_processed']);
    }

    public function testReplacementAggregatesLegacyRowsAndReversesStockInOneBatch(): void
    {
        $product = $this->createMock(Product::class);
        $supplier = $this->createMock(SupplierRepository::class);
        $db = $this->transactionalPdoMock();
        $deleteStatement = $this->createMock(PDOStatement::class);
        $deleteStatement->expects(self::once())->method('execute')->with([55, 1]);
        $db->expects(self::once())->method('prepare')->willReturn($deleteStatement);

        $supplier->method('findById')->with(4)->willReturn(['id' => 4, 'branch_id' => 1]);
        $supplier->method('getPurchaseInvoiceHeaderForUpdate')->with(55)->willReturn([
            'id' => 55,
            'supplier_id' => 4,
        ]);
        $supplier->method('getPurchaseInvoiceItems')->with(55)->willReturn([
            ['product_id' => 10, 'quantity' => 1.5],
            ['product_id' => 10, 'quantity' => 2.5],
        ]);
        $supplier->expects(self::once())->method('deletePurchaseInvoiceItems')->with(55);
        $supplier->expects(self::once())->method('updatePurchaseInvoiceTotals')->with(55, self::isType('array'));
        $supplier->expects(self::once())->method('createPurchases')->willReturn(1002);
        $supplier->expects(self::exactly(2))->method('addLedgerEntry');

        $product->method('findByIds')->with([10])->willReturn([10 => ['id' => 10]]);
        $product->expects(self::once())
            ->method('batchDecrementQuantity')
            ->with([['product_id' => 10, 'quantity' => 4.0]]);
        $product->expects(self::once())
            ->method('batchIncrementQuantity')
            ->with([['product_id' => 10, 'quantity' => 2.0]]);
        $product->expects(self::once())->method('batchUpdateCosts')->with([]);
        $product->expects(self::never())->method('decrementQuantity');

        $service = new InventoryService($product, $supplier, $db);
        $result = $service->processBulkPurchase([
            'supplier_id' => 4,
            'replace_invoice_id' => 55,
            'payment_type' => 'cash',
            'items' => [
                ['product_id' => 10, 'quantity' => 2, 'cost' => 5],
            ],
        ], ['id' => 7]);

        self::assertTrue($result['ok']);
        self::assertTrue($result['is_update']);
    }

    public function testRejectsSupplierDiscountAboveItemSubtotal(): void
    {
        $service = new InventoryService(
            $this->createMock(Product::class),
            $this->createMock(SupplierRepository::class),
            $this->createMock(PDO::class)
        );

        $result = $service->processBulkPurchase([
            'supplier_id' => 4,
            'discount' => 11,
            'items' => [
                ['product_id' => 10, 'quantity' => 1, 'cost' => 10],
            ],
        ], ['id' => 7]);

        self::assertFalse($result['ok']);
        self::assertSame(422, $result['code']);
    }

    private function transactionalPdoMock(): PDO
    {
        $db = $this->createMock(PDO::class);
        $db->method('inTransaction')->willReturn(false);
        $db->expects(self::once())->method('beginTransaction')->willReturn(true);
        $db->expects(self::once())->method('commit')->willReturn(true);
        $db->expects(self::never())->method('rollBack');
        return $db;
    }
}
