<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\SaleService;
use App\Repositories\InvoiceRepository;
use App\Repositories\ProductRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\InventoryEventRepository;

class SaleServiceTest extends TestCase
{
    private SaleService $service;
    private InvoiceRepository $invoiceMock;
    private ProductRepository $productMock;
    private CustomerRepository $customerMock;
    private InventoryEventRepository $inventoryEventMock;

    protected function setUp(): void
    {
        $this->invoiceMock        = $this->createMock(InvoiceRepository::class);
        $this->productMock        = $this->createMock(ProductRepository::class);
        $this->customerMock       = $this->createMock(CustomerRepository::class);
        $this->inventoryEventMock = $this->createMock(InventoryEventRepository::class);

        $this->service = new SaleService(
            $this->invoiceMock,
            $this->productMock,
            $this->customerMock,
            $this->inventoryEventMock,
            $this->createMock(\PDO::class)
        );
    }

    public function testEnrichItemsWithValidProducts()
    {
        $this->productMock->method('findByIds')
            ->willReturn([1 => ['id' => 1, 'price' => 10.50, 'cost' => 7.00, 'quantity' => 100]]);

        $items = [['product_id' => 1, 'quantity' => 3]];
        
        $result = $this->service->enrichItems($items);

        $this->assertTrue($result['ok']);
        $this->assertEquals(10.50, $result['items'][0]['price']);
        $this->assertEquals(7.00, $result['items'][0]['unit_cost']);
        $this->assertEquals(3, $result['items'][0]['quantity']);
    }

    public function testEnrichItemsWithMissingProduct()
    {
        $this->productMock->method('findByIds')
            ->willReturn([]);

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
        $this->productMock->method('findByIds')
            ->willReturn([1 => ['id' => 1, 'price' => 10.00, 'cost' => 5.00, 'quantity' => 100]]);

        $items = [['product_id' => 1, 'quantity' => 1, 'price' => 8.00]];

        $result = $this->service->enrichItems($items, true);

        $this->assertTrue($result['ok']);
        $this->assertEquals(8.00, $result['items'][0]['price']);
    }

    public function testCalculateTotalsWithoutTax()
    {
        $service = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$this->invoiceMock, $this->productMock, $this->customerMock, $this->inventoryEventMock, $this->createMock(\PDO::class)])
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
            ->setConstructorArgs([$this->invoiceMock, $this->productMock, $this->customerMock, $this->inventoryEventMock, $this->createMock(\PDO::class)])
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

    public function testCalculateTotalsIncludesShippingCost(): void
    {
        $service = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$this->invoiceMock, $this->productMock, $this->customerMock, $this->inventoryEventMock, $this->createMock(\PDO::class)])
            ->onlyMethods(['getSettings'])
            ->getMock();

        $service->method('getSettings')->willReturn(['tax_enabled' => '0', 'tax_rate' => '15']);

        $totals = $service->calculateTotals(
            [['price' => 100, 'quantity' => 1, 'unit_cost' => 50]],
            0,
            ['payment_method' => 'cash', 'amount_paid' => 125, 'shipping_cost' => 25],
        );

        $this->assertSame(25.0, $totals['shipping_cost']);
        $this->assertSame(125.0, $totals['total']);
        $this->assertSame(125.0, $totals['amount_paid']);
        $this->assertSame(0, $totals['amount_due']);
    }

    public function testCalculateTotalsWithCreditSale()
    {
        $service = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$this->invoiceMock, $this->productMock, $this->customerMock, $this->inventoryEventMock, $this->createMock(\PDO::class)])
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

    public function testEnrichItemsWithMultipleProducts()
    {
        $this->productMock->method('findByIds')
            ->willReturn([
                1 => ['id' => 1, 'price' => 10, 'cost' => 5, 'quantity' => 100],
                2 => ['id' => 2, 'price' => 20, 'cost' => 12, 'quantity' => 100],
            ]);

        $items = [
            ['product_id' => 1, 'quantity' => 2],
            ['product_id' => 2, 'quantity' => 1],
        ];

        $result = $this->service->enrichItems($items);
        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['items']);
        $this->assertEquals(10, $result['items'][0]['price']);
        $this->assertEquals(20, $result['items'][1]['price']);
    }

    public function testEnrichItemsRejectsZeroQuantity()
    {
        $items = [['product_id' => 1, 'quantity' => 0]];
        $result = $this->service->enrichItems($items);
        $this->assertFalse($result['ok']);
    }

    public function testEnrichItemsRejectsNegativeQuantity()
    {
        $items = [['product_id' => 1, 'quantity' => -5]];
        $result = $this->service->enrichItems($items);
        $this->assertFalse($result['ok']);
    }

    public function testEnrichItemsRejectsUnauthorizedPriceOverride()
    {
        $this->productMock->method('findByIds')
            ->willReturn([1 => ['id' => 1, 'price' => 10, 'cost' => 5, 'quantity' => 100]]);

        $result = $this->service->enrichItems([
            ['product_id' => 1, 'quantity' => 1, 'price' => 1],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(403, $result['code']);
    }

    public function testEnrichItemsRejectsInsufficientStock()
    {
        $this->productMock->method('findByIds')
            ->willReturn([1 => ['id' => 1, 'price' => 10, 'cost' => 5, 'quantity' => 2]]);

        $result = $this->service->enrichItems([
            ['product_id' => 1, 'quantity' => 3],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(409, $result['code']);
    }

    public function testEnrichItemsAllowsNegativeStockWhenPolicyIsDisabled(): void
    {
        $service = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$this->invoiceMock, $this->productMock, $this->customerMock, $this->inventoryEventMock, $this->createMock(\PDO::class)])
            ->onlyMethods(['getSettings'])
            ->getMock();
        $service->method('getSettings')->willReturn([
            'prevent_negative_stock' => '0',
            'tax_enabled' => '0',
            'tax_rate' => '15',
        ]);
        $this->productMock->method('findByIds')
            ->willReturn([1 => ['id' => 1, 'price' => 10, 'cost' => 5, 'quantity' => 2]]);

        $result = $service->enrichItems([
            ['product_id' => 1, 'quantity' => 3],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(3.0, $result['items'][0]['quantity']);
    }

    public function testCalculateTotalsChangeDue()
    {
        $service = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$this->invoiceMock, $this->productMock, $this->customerMock, $this->inventoryEventMock, $this->createMock(\PDO::class)])
            ->onlyMethods(['getSettings'])
            ->getMock();
        $service->method('getSettings')->willReturn(['tax_enabled' => '0', 'tax_rate' => '0']);

        $items = [['price' => 50, 'quantity' => 1, 'unit_cost' => 30]];
        $data = ['payment_method' => 'cash', 'amount_paid' => 100];
        $totals = $service->calculateTotals($items, 0, $data);

        $this->assertEquals(50, $totals['change_due']);
    }

    public function testCalculateTotalsWithDiscount()
    {
        $service = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$this->invoiceMock, $this->productMock, $this->customerMock, $this->inventoryEventMock, $this->createMock(\PDO::class)])
            ->onlyMethods(['getSettings'])
            ->getMock();
        $service->method('getSettings')->willReturn(['tax_enabled' => '0', 'tax_rate' => '0']);

        $items = [['price' => 100, 'quantity' => 2, 'unit_cost' => 50]];
        $data = ['payment_method' => 'cash', 'amount_paid' => 180];
        $totals = $service->calculateTotals($items, 20, $data);

        $this->assertEquals(200, $totals['subtotal']);
        $this->assertEquals(20, $totals['discount']);
        $this->assertEquals(180, $totals['total']);
    }

    public function testCalculateTotalsWithTaxAndDiscount()
    {
        $service = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$this->invoiceMock, $this->productMock, $this->customerMock, $this->inventoryEventMock, $this->createMock(\PDO::class)])
            ->onlyMethods(['getSettings'])
            ->getMock();
        $service->method('getSettings')->willReturn(['tax_enabled' => '1', 'tax_rate' => '15']);

        // subtotal=200, discount=50, taxable=150, tax=22.50, total=172.50
        $items = [['price' => 100, 'quantity' => 2, 'unit_cost' => 50]];
        $data = ['payment_method' => 'cash', 'amount_paid' => 200];
        $totals = $service->calculateTotals($items, 50, $data);

        $this->assertEquals(200, $totals['subtotal']);
        $this->assertEquals(50, $totals['discount']);
        $this->assertEquals(22.50, $totals['tax']);
        $this->assertEquals(172.50, $totals['total']);
        $this->assertEquals(27.50, $totals['change_due']); // 200 - 172.50
        $this->assertEquals(0, $totals['amount_due']);
    }

    public function testCalculateTotalsCreditSaleWithoutCustomer()
    {
        $service = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$this->invoiceMock, $this->productMock, $this->customerMock, $this->inventoryEventMock, $this->createMock(\PDO::class)])
            ->onlyMethods(['getSettings'])
            ->getMock();
        $service->method('getSettings')->willReturn(['tax_enabled' => '0', 'tax_rate' => '15']);

        $items = [['price' => 100, 'quantity' => 1, 'unit_cost' => 50]];
        $data = ['payment_method' => 'credit', 'amount_paid' => 0];

        $totals = $service->calculateTotals($items, 0, $data);

        $this->assertTrue($totals['is_credit_sale']);
        $this->assertNull($totals['customer_id']); // no customer_id provided
        $this->assertEquals(100, $totals['amount_due']);
        $this->assertEquals(0, $totals['deposit']);
    }

    public function testCalculateTotalsWithZeroAmountPaid()
    {
        $service = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$this->invoiceMock, $this->productMock, $this->customerMock, $this->inventoryEventMock, $this->createMock(\PDO::class)])
            ->onlyMethods(['getSettings'])
            ->getMock();
        $service->method('getSettings')->willReturn(['tax_enabled' => '0', 'tax_rate' => '0']);

        $items = [['price' => 50, 'quantity' => 2, 'unit_cost' => 30]];
        // When amount_paid is not set, it defaults to total
        $data = ['payment_method' => 'cash'];

        $totals = $service->calculateTotals($items, 0, $data);

        $this->assertEquals(100, $totals['total']);
        $this->assertEquals(100, $totals['amount_paid']); // defaults to total
        $this->assertEquals(0, $totals['change_due']);
        $this->assertEquals(0, $totals['amount_due']);
    }

    public function testEnrichItemsWithEmptyArray()
    {
        $result = $this->service->enrichItems([]);
        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['items']);
    }

    public function testGetLowStockAlertsReturnsAlerts()
    {
        $enrichedItems = [
            ['product_id' => 1, 'quantity' => 5, 'price' => 10, 'unit_cost' => 5],
            ['product_id' => 2, 'quantity' => 3, 'price' => 20, 'unit_cost' => 12],
        ];

        $this->productMock->method('getLowStockByProductIds')
            ->with([1, 2])
            ->willReturn([['id' => 1, 'name' => 'Low Product', 'quantity' => 2]]);

        $alerts = $this->service->getLowStockAlerts($enrichedItems);

        $this->assertCount(1, $alerts);
        $this->assertEquals(1, $alerts[0]['id']);
    }

    public function testGetLowStockAlertsWithEmptyItems()
    {
        $alerts = $this->service->getLowStockAlerts([]);
        $this->assertEmpty($alerts);
    }

    public function testGetInvoiceRepositoryReturnsRepository()
    {
        $repo = $this->service->getInvoiceRepository();
        $this->assertInstanceOf(InvoiceRepository::class, $repo);
    }

    public function testCalculateTotalsOnUpdateCapsAmountPaid()
    {
        $service = $this->getMockBuilder(SaleService::class)
            ->setConstructorArgs([$this->invoiceMock, $this->productMock, $this->customerMock, $this->inventoryEventMock, $this->createMock(\PDO::class)])
            ->onlyMethods(['getSettings'])
            ->getMock();
            
        $service->method('getSettings')->willReturn(['tax_enabled' => '0', 'tax_rate' => '0']);

        $items = [
            ['price' => 45, 'quantity' => 1, 'unit_cost' => 20]
        ];
        $discount = 0;
        $data = ['payment_method' => 'cash', 'amount_paid' => 110, 'invoice_id' => 3];

        $totals = $service->calculateTotals($items, $discount, $data);

        $this->assertEquals(45, $totals['total']);
        $this->assertEquals(45, $totals['amount_paid']);
        $this->assertEquals(0, $totals['change_due']);
    }
}
