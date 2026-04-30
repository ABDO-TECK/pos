<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\SaleService;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Customer;

class SaleServiceTest extends TestCase
{
    private SaleService $service;
    private Invoice $invoiceMock;
    private Product $productMock;
    private Customer $customerMock;

    protected function setUp(): void
    {
        $this->invoiceMock  = $this->createMock(Invoice::class);
        $this->productMock  = $this->createMock(Product::class);
        $this->customerMock = $this->createMock(Customer::class);

        $this->service = new SaleService(
            $this->invoiceMock,
            $this->productMock,
            $this->customerMock
        );
    }

    public function testEnrichItemsWithValidProducts()
    {
        $this->productMock->method('findById')
            ->willReturn(['id' => 1, 'price' => 10.50, 'cost' => 7.00]);

        $items = [['product_id' => 1, 'quantity' => 3]];
        
        $result = $this->service->enrichItems($items);

        $this->assertTrue($result['ok']);
        $this->assertEquals(10.50, $result['items'][0]['price']);
        $this->assertEquals(7.00, $result['items'][0]['unit_cost']);
        $this->assertEquals(3, $result['items'][0]['quantity']);
    }

    public function testEnrichItemsWithMissingProduct()
    {
        $this->productMock->method('findById')
            ->willReturn(null);

        $items = [['product_id' => 999, 'quantity' => 1]];

        $result = $this->service->enrichItems($items);

        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['code']);
    }

    public function testEnrichItemsWithInvalidData()
    {
        $items = [['product_id' => null, 'quantity' => 0]];

        $result = $this->service->enrichItems($items);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Invalid item data', $result['error']);
    }

    public function testEnrichItemsUsesCustomPrice()
    {
        $this->productMock->method('findById')
            ->willReturn(['id' => 1, 'price' => 10.00, 'cost' => 5.00]);

        $items = [['product_id' => 1, 'quantity' => 1, 'price' => 8.00]];

        $result = $this->service->enrichItems($items);

        $this->assertTrue($result['ok']);
        $this->assertEquals(8.00, $result['items'][0]['price']);
    }

    public function testCalculateTotalsWithoutTax()
    {
        $service = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$this->invoiceMock, $this->productMock, $this->customerMock])
            ->onlyMethods(['getSettings'])
            ->getMock();
            
        $service->method('getSettings')->willReturn(['tax_enabled' => '0', 'tax_rate' => '15']);

        $items = [
            ['price' => 10, 'quantity' => 2, 'unit_cost' => 5]
        ];
        $discount = 5;
        $data = ['payment_method' => 'cash', 'amount_paid' => 15];

        $totals = $service->calculateTotals($items, $discount, $data);

        $this->assertEquals(20, $totals['subtotal']);
        $this->assertEquals(5, $totals['discount']);
        $this->assertEquals(0, $totals['tax']);
        $this->assertEquals(15, $totals['total']);
        $this->assertEquals(0, $totals['change_due']);
    }

    public function testCalculateTotalsWithTax()
    {
        $service = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$this->invoiceMock, $this->productMock, $this->customerMock])
            ->onlyMethods(['getSettings'])
            ->getMock();
            
        $service->method('getSettings')->willReturn(['tax_enabled' => '1', 'tax_rate' => '15']);

        $items = [
            ['price' => 100, 'quantity' => 1, 'unit_cost' => 50]
        ];
        $discount = 0;
        $data = ['payment_method' => 'cash', 'amount_paid' => 115];

        $totals = $service->calculateTotals($items, $discount, $data);

        $this->assertEquals(100, $totals['subtotal']);
        $this->assertEquals(0, $totals['discount']);
        $this->assertEquals(15, $totals['tax']);
        $this->assertEquals(115, $totals['total']);
    }

    public function testCalculateTotalsWithCreditSale()
    {
        $service = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$this->invoiceMock, $this->productMock, $this->customerMock])
            ->onlyMethods(['getSettings'])
            ->getMock();
            
        $service->method('getSettings')->willReturn(['tax_enabled' => '0', 'tax_rate' => '15']);

        $items = [
            ['price' => 100, 'quantity' => 1, 'unit_cost' => 50]
        ];
        $discount = 0;
        $data = ['payment_method' => 'credit', 'amount_paid' => 50, 'customer_id' => 1];

        $totals = $service->calculateTotals($items, $discount, $data);

        $this->assertEquals(100, $totals['total']);
        $this->assertTrue($totals['is_credit_sale']);
        $this->assertEquals(50, $totals['amount_due']);
        $this->assertEquals(50, $totals['deposit']);
        $this->assertEquals(1, $totals['customer_id']);
    }
}
