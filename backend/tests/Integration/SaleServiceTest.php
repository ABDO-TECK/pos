<?php

namespace Tests\Integration;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Services\SaleService;
use PHPUnit\Framework\TestCase;

class SaleServiceTest extends TestCase
{
    /**
     * @runInSeparateProcess
     */
    public function testEnrichItemsSuccess()
    {
        $invoiceModelMock = $this->createMock(Invoice::class);
        $productModelMock = $this->createMock(Product::class);
        $customerModelMock = $this->createMock(Customer::class);

        $productModelMock->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(['id' => 1, 'price' => 10.0, 'cost' => 5.0]);

        $saleService = new SaleService($invoiceModelMock, $productModelMock, $customerModelMock);

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
        $invoiceModelMock = $this->createMock(Invoice::class);
        $productModelMock = $this->createMock(Product::class);
        $customerModelMock = $this->createMock(Customer::class);

        $productModelMock->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $saleService = new SaleService($invoiceModelMock, $productModelMock, $customerModelMock);

        $result = $saleService->enrichItems([['product_id' => 999, 'quantity' => 1]]);

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['code']);
    }

    /**
     * @runInSeparateProcess
     */
    public function testCalculateTotalsWithDiscount()
    {
        $invoiceModelMock = $this->createMock(Invoice::class);
        $productModelMock = $this->createMock(Product::class);
        $customerModelMock = $this->createMock(Customer::class);

        // Partial mock to bypass getSettings and DB dependency
        $saleService = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$invoiceModelMock, $productModelMock, $customerModelMock])
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
        $invoiceModelMock = $this->createMock(Invoice::class);
        $productModelMock = $this->createMock(Product::class);
        $customerModelMock = $this->createMock(Customer::class);

        $invoiceModelMock->expects($this->once())
            ->method('create')
            ->willReturn(1);

        $productModelMock->expects($this->once())
            ->method('decrementQuantity')
            ->with(1, 2.0);

        $saleService = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$invoiceModelMock, $productModelMock, $customerModelMock])
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
        $invoiceModelMock = $this->createMock(Invoice::class);
        $productModelMock = $this->createMock(Product::class);
        $customerModelMock = $this->createMock(Customer::class);

        $invoiceModelMock->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $saleService = new SaleService($invoiceModelMock, $productModelMock, $customerModelMock);

        $result = $saleService->deleteInvoice(999);

        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['code']);
    }
}
