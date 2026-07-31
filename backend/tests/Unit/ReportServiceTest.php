<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\ReportService;
use App\Repositories\InvoiceRepository;
use App\Repositories\ExpenseRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SupplierRepository;

class ReportServiceTest extends TestCase
{
    private ReportService $service;
    private $invoiceMock;
    private $expenseMock;
    private $productMock;
    private $supplierMock;

    protected function setUp(): void
    {
        $this->invoiceMock = $this->createMock(InvoiceRepository::class);
        $this->expenseMock = $this->createMock(ExpenseRepository::class);
        $this->productMock = $this->createMock(ProductRepository::class);
        $this->supplierMock = $this->createMock(SupplierRepository::class);

        $this->service = new ReportService(
            $this->invoiceMock,
            $this->expenseMock,
            $this->productMock,
            $this->supplierMock
        );
    }

    public function testGetDailySummary()
    {
        $date = '2026-07-03';
        $summaryData = [
            'total_sales' => 1000.00,
            'total_profit' => 300.00,
            'total_invoices' => 1205
        ];
        $invoicePage = [
            'data' => [
                ['id' => 1105, 'total' => 100.00]
            ],
            'pagination' => [
                'type' => 'page',
                'page' => 12,
                'limit' => 100,
                'total' => 1205,
                'pages' => 13,
                'has_more' => true,
                'truncated' => true,
            ],
        ];

        $this->invoiceMock->method('getDailySummary')
            ->with($date)
            ->willReturn($summaryData);

        $this->invoiceMock->method('all')
            ->with(['date' => $date, 'page' => 12, 'limit' => 100])
            ->willReturn($invoicePage);

        $this->expenseMock->method('getTotalExpensesForDate')
            ->with($date)
            ->willReturn(50.00);

        $result = $this->service->getDailySummary($date, 12, 100);

        $this->assertEquals($date, $result['date']);
        $this->assertEquals($invoicePage['data'], $result['invoices']);
        $this->assertEquals(1205, $result['pagination']['total']);
        $this->assertTrue($result['pagination']['has_more']);
        $this->assertEquals(1205, $result['summary']['total_invoices']);
        $this->assertEquals(50.00, $result['summary']['total_expenses']);
        $this->assertEquals(250.00, $result['summary']['net_profit']); // 300 - 50
    }

    public function testGetMonthlySummary()
    {
        $month = 7;
        $year = 2026;
        $dailyBreakdown = [
            ['date' => '2026-07-01', 'total_revenue' => 500, 'total_invoices' => 5]
        ];

        $this->invoiceMock->method('getMonthlySummary')
            ->with($month, $year)
            ->willReturn($dailyBreakdown);

        $this->invoiceMock->method('getTotalProfitForMonth')
            ->with($month, $year)
            ->willReturn(300.00);

        $this->invoiceMock->method('getTotalCostForMonth')
            ->with($month, $year)
            ->willReturn(700.00);

        $this->expenseMock->method('getTotalExpensesForMonth')
            ->with($month, $year)
            ->willReturn(100.00);

        $result = $this->service->getMonthlySummary($month, $year);

        $this->assertEquals($month, $result['month']);
        $this->assertEquals($year, $result['year']);
        $this->assertEquals(500, $result['total_revenue']);
        $this->assertEquals(700, $result['total_cost']);
        $this->assertEquals(300, $result['total_profit']);
        $this->assertEquals(100, $result['total_expenses']);
        $this->assertEquals(200, $result['net_profit']); // 300 - 100
        $this->assertEquals($dailyBreakdown, $result['daily_breakdown']);
    }

    public function testGetTopProducts()
    {
        $limit = 5;
        $expected = [
            ['id' => 1, 'name' => 'Product 1', 'sold_quantity' => 100]
        ];

        $this->invoiceMock->method('getTopProducts')
            ->with($limit, null, null)
            ->willReturn($expected);

        $result = $this->service->getTopProducts($limit);
        $this->assertEquals($expected, $result);
    }

    public function testGetProfitReport()
    {
        $month = 7;
        $year = 2026;
        $totals = ['total_revenue' => 1000.00, 'total_cost' => 600.00, 'total_profit' => 400.00];
        $topProfit = [['id' => 1, 'name' => 'P1', 'profit' => 100.00]];
        $dailyBreakdown = [['date' => '2026-07-01', 'profit' => 200.00]];

        $this->invoiceMock->method('getProfitReportTotals')
            ->with($month, $year)
            ->willReturn($totals);

        $this->invoiceMock->method('getTopProfitProducts')
            ->with($month, $year, 20)
            ->willReturn($topProfit);

        $this->invoiceMock->method('getDailyProfitBreakdown')
            ->with($month, $year)
            ->willReturn($dailyBreakdown);

        $this->expenseMock->method('getTotalExpensesForMonth')
            ->with($month, $year)
            ->willReturn(100.00);

        $result = $this->service->getProfitReport($month, $year);

        $this->assertEquals(1000.00, $result['total_revenue']);
        $this->assertEquals(600.00, $result['total_cost']);
        $this->assertEquals(400.00, $result['total_profit']);
        $this->assertEquals(100.00, $result['total_expenses']);
        $this->assertEquals(300.00, $result['net_profit']); // 400 - 100
        $this->assertEquals(30.00, $result['profit_margin']); // 300 / 1000 * 100
    }

    public function testGetDashboardSummary()
    {
        $this->invoiceMock->method('getTodayRevenue')->willReturn(500.00);
        $this->invoiceMock->method('getMonthRevenue')->willReturn(15000.00);
        $this->invoiceMock->method('getTodayInvoicesCount')->willReturn(15);
        $this->productMock->method('getTotalProductsCount')->willReturn(150);
        $this->productMock->method('getLowStockProductsCount')->willReturn(12);
        $this->supplierMock->method('getTotalSuppliersCount')->willReturn(8);
        $this->invoiceMock->method('getTotalProfitForMonth')->willReturn(3500.00);
        $this->invoiceMock->method('getTotalProfitForDate')->willReturn(120.00);
        $this->invoiceMock->method('getTotalCostForDate')->willReturn(380.00);
        $this->invoiceMock->method('getTotalCostForMonth')->willReturn(11500.00);
        $this->expenseMock->method('getTotalExpensesForDate')->willReturn(20.00);
        $this->expenseMock->method('getTotalExpensesForMonth')->willReturn(800.00);

        $result = $this->service->getDashboardSummary();

        $this->assertEquals(500.00, $result['today_revenue']);
        $this->assertEquals(15000.00, $result['month_revenue']);
        $this->assertEquals(100.00, $result['today_net_profit']); // 120 - 20
        $this->assertEquals(2700.00, $result['month_net_profit']); // 3500 - 800
        $this->assertEquals(150, $result['total_products']);
        $this->assertEquals(12, $result['low_stock_count']);
        $this->assertEquals(8, $result['total_suppliers']);
    }
}
