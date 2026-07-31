<?php

namespace Tests\Integration;

use App\Repositories\CustomerRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\ProductRepository;
use App\Repositories\InventoryEventRepository;
use App\Services\SaleService;
use PHPUnit\Framework\TestCase;

class SaleServiceTest extends TestCase
{
    /**
     * @runInSeparateProcess
     */
    public function testEnrichItemsSuccess()
    {
        $invoiceRepoMock = $this->createMock(InvoiceRepository::class);
        $productRepoMock = $this->createMock(ProductRepository::class);
        $customerRepoMock = $this->createMock(CustomerRepository::class);
        $inventoryEventMock = $this->createMock(InventoryEventRepository::class);

        $productRepoMock->expects($this->once())
            ->method('findByIds')
            ->with([1])
            ->willReturn([1 => [
                'id' => 1,
                'price' => 10.0,
                'cost' => 5.0,
                'quantity' => 10.0,
            ]]);

        $saleService = new SaleService($invoiceRepoMock, $productRepoMock, $customerRepoMock, $inventoryEventMock, $this->createMock(\PDO::class));

        $result = $saleService->enrichItems([['product_id' => 1, 'quantity' => 2]]);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['items']);
        $this->assertEquals(1, $result['items'][0]['product_id']);
        $this->assertEquals(2, $result['items'][0]['quantity']);
        $this->assertEquals(10.0, $result['items'][0]['price']);
        $this->assertEquals(5.0, $result['items'][0]['unit_cost']);
    }

    /**
     * @runInSeparateProcess
     */
    public function testEnrichItemsFailsForMissingProduct()
    {
        $invoiceRepoMock = $this->createMock(InvoiceRepository::class);
        $productRepoMock = $this->createMock(ProductRepository::class);
        $customerRepoMock = $this->createMock(CustomerRepository::class);
        $inventoryEventMock = $this->createMock(InventoryEventRepository::class);

        $productRepoMock->expects($this->once())
            ->method('findByIds')
            ->with([999])
            ->willReturn([]);

        $saleService = new SaleService($invoiceRepoMock, $productRepoMock, $customerRepoMock, $inventoryEventMock, $this->createMock(\PDO::class));

        $result = $saleService->enrichItems([['product_id' => 999, 'quantity' => 1]]);

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['code']);
    }

    /**
     * @runInSeparateProcess
     */
    public function testCalculateTotalsWithDiscount()
    {
        $invoiceRepoMock = $this->createMock(InvoiceRepository::class);
        $productRepoMock = $this->createMock(ProductRepository::class);
        $customerRepoMock = $this->createMock(CustomerRepository::class);
        $inventoryEventMock = $this->createMock(InventoryEventRepository::class);

        // Partial mock to bypass getSettings and DB dependency
        $saleService = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$invoiceRepoMock, $productRepoMock, $customerRepoMock, $inventoryEventMock, $this->createMock(\PDO::class)])
            ->onlyMethods(['getSettings'])
            ->getMock();

        $saleService->expects($this->once())
            ->method('getSettings')
            ->willReturn(['tax_enabled' => '0', 'tax_rate' => '15']);

        $enrichedItems = [
            ['product_id' => 1, 'quantity' => 2, 'price' => 50.0] // subtotal: 100
        ];

        $totals = $saleService->calculateTotals($enrichedItems, 10.0, ['amount_paid' => 90.0]);

        $this->assertEquals(100.0, $totals['subtotal']);
        $this->assertEquals(10.0, $totals['discount']);
        $this->assertEquals(0.0, $totals['tax']);
        $this->assertEquals(90.0, $totals['total']);
        $this->assertEquals(90.0, $totals['amount_paid']);
        $this->assertEquals(0.0, $totals['amount_due']);
    }

    /**
     * @runInSeparateProcess
     */
    public function testProcessSaleSuccess()
    {
        $invoiceRepoMock = $this->createMock(InvoiceRepository::class);
        $productRepoMock = $this->createMock(ProductRepository::class);
        $customerRepoMock = $this->createMock(CustomerRepository::class);
        $inventoryEventMock = $this->createMock(InventoryEventRepository::class);

        $invoiceRepoMock->expects($this->once())
            ->method('create')
            ->willReturn(1);
        $invoiceRepoMock->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(['id' => 1, 'status' => 'completed', 'items' => []]);

        $productRepoMock->expects($this->once())
            ->method('batchDecrementQuantity')
            ->with([['product_id' => 1, 'quantity' => 2.0]]);

        $saleService = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$invoiceRepoMock, $productRepoMock, $customerRepoMock, $inventoryEventMock, $this->createMock(\PDO::class)])
            ->onlyMethods(['getSettings'])
            ->getMock();

        $enrichedItems = [
            ['product_id' => 1, 'quantity' => 2, 'price' => 10.0, 'unit_cost' => 5.0, 'product' => ['id' => 1]]
        ];

        $totals = [
            'subtotal' => 20.0, 'discount' => 0.0, 'tax' => 0.0, 'total' => 20.0,
            'amount_paid' => 20.0, 'change_due' => 0.0, 'amount_due' => 0.0,
            'customer_id' => null, 'is_credit_sale' => false, 'deposit' => 0.0
        ];

        $data = [
            'idempotency_key' => '78d7d8e8-a99d-4b95-aa4a-62c86a40093e',
            'payment_method' => 'cash',
        ];
        $authUser = ['id' => 1];

        $result = $saleService->processSale($enrichedItems, $totals, $data, $authUser);

        $this->assertTrue($result['ok']);
        $this->assertEquals(1, $result['invoice_id']);
    }

    public function testProcessSaleCreatesCustomerAndLinksInvoiceLedger(): void
    {
        $invoiceRepoMock = $this->createMock(InvoiceRepository::class);
        $productRepoMock = $this->createMock(ProductRepository::class);
        $customerRepoMock = $this->createMock(CustomerRepository::class);
        $inventoryEventMock = $this->createMock(InventoryEventRepository::class);
        $ledgerEntries = [];

        $customerRepoMock->expects($this->once())
            ->method('create')
            ->with([
                'name' => 'New Customer',
                'phone' => '01000000000',
                'address' => 'Cairo',
                'initial_balance' => 0,
            ])
            ->willReturn(42);

        $invoiceRepoMock->expects($this->once())
            ->method('create')
            ->with($this->callback(
                fn (array $invoice): bool => $invoice['customer_id'] === 42
            ))
            ->willReturn(77);
        $invoiceRepoMock->expects($this->once())
            ->method('findById')
            ->with(77)
            ->willReturn(['id' => 77, 'customer_id' => 42, 'status' => 'completed', 'items' => []]);

        $customerRepoMock->expects($this->exactly(2))
            ->method('addLedgerEntry')
            ->willReturnCallback(function (array $entry) use (&$ledgerEntries): int {
                $ledgerEntries[] = $entry;
                return count($ledgerEntries);
            });

        $productRepoMock->expects($this->once())
            ->method('batchDecrementQuantity')
            ->with([['product_id' => 1, 'quantity' => 1.0]]);

        $saleService = new SaleService(
            $invoiceRepoMock,
            $productRepoMock,
            $customerRepoMock,
            $inventoryEventMock,
            $this->createMock(\PDO::class)
        );

        $result = $saleService->processSale(
            [[
                'product_id' => 1,
                'quantity' => 1.0,
                'price' => 100.0,
                'unit_cost' => 60.0,
                'product' => ['id' => 1, 'quantity' => 5.0],
            ]],
            [
                'subtotal' => 100.0,
                'discount' => 0.0,
                'tax' => 0.0,
                'total' => 100.0,
                'amount_paid' => 25.0,
                'change_due' => 0.0,
                'amount_due' => 75.0,
                'customer_id' => null,
                'is_credit_sale' => true,
                'deposit' => 25.0,
            ],
            [
                'idempotency_key' => '4bf806fb-55e5-4adf-a820-e0055012f26e',
                'payment_method' => 'credit',
                'new_customer' => [
                    'name' => 'New Customer',
                    'phone' => '01000000000',
                    'address' => 'Cairo',
                ],
            ],
            ['id' => 9],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(77, $result['invoice_id']);
        $this->assertSame(42, $result['customer_id']);
        $this->assertCount(2, $ledgerEntries);
        $this->assertSame(42, $ledgerEntries[0]['customer_id']);
        $this->assertSame(77, $ledgerEntries[0]['invoice_id']);
        $this->assertSame('debit', $ledgerEntries[0]['type']);
        $this->assertSame(100.0, $ledgerEntries[0]['amount']);
        $this->assertSame(77, $ledgerEntries[1]['invoice_id']);
        $this->assertSame('credit', $ledgerEntries[1]['type']);
        $this->assertSame(25.0, $ledgerEntries[1]['amount']);
    }

    /**
     * @runInSeparateProcess
     */
    public function testDeleteInvoiceNotFound()
    {
        $invoiceRepoMock = $this->createMock(InvoiceRepository::class);
        $productRepoMock = $this->createMock(ProductRepository::class);
        $customerRepoMock = $this->createMock(CustomerRepository::class);
        $inventoryEventMock = $this->createMock(InventoryEventRepository::class);
        $dbMock = $this->createMock(\PDO::class);
        $inTransaction = false;

        $dbMock->method('inTransaction')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                return $inTransaction;
            });
        $dbMock->method('beginTransaction')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = true;
                return true;
            });
        $dbMock->expects($this->once())
            ->method('rollBack')
            ->willReturnCallback(function () use (&$inTransaction): bool {
                $inTransaction = false;
                return true;
            });

        $invoiceRepoMock->expects($this->once())
            ->method('findByIdForUpdate')
            ->with(999)
            ->willReturn(null);

        $saleService = new SaleService(
            $invoiceRepoMock,
            $productRepoMock,
            $customerRepoMock,
            $inventoryEventMock,
            $dbMock
        );

        $result = $saleService->deleteInvoice(999);

        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['code']);
    }
}
