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
            ->willReturn([1 => ['id' => 1, 'price' => 10.0, 'cost' => 5.0]]);

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

        $data = ['payment_method' => 'cash'];
        $authUser = ['id' => 1];

        $result = $saleService->processSale($enrichedItems, $totals, $data, $authUser);

        $this->assertTrue($result['ok']);
        $this->assertEquals(1, $result['invoice_id']);
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

        $invoiceRepoMock->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $saleService = new SaleService($invoiceRepoMock, $productRepoMock, $customerRepoMock, $inventoryEventMock, $this->createMock(\PDO::class));

        $result = $saleService->deleteInvoice(999);

        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['code']);
    }
}
