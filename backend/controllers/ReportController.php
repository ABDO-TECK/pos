<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Supplier;


class ReportController extends Controller {

    private Invoice $invoiceModel;
    private Expense $expenseModel;
    private Product $productModel;
    private Supplier $supplierModel;

    public function __construct(Invoice $invoiceModel, Expense $expenseModel, Product $productModel, Supplier $supplierModel) {
        $this->invoiceModel = $invoiceModel;
        $this->expenseModel = $expenseModel;
        $this->productModel = $productModel;
        $this->supplierModel = $supplierModel;
    }

    public function daily() {
        $date    = $this->getParam('date', date('Y-m-d'));
        $summary = $this->invoiceModel->getDailySummary($date);
        $invoices = $this->invoiceModel->all(['date' => $date]);
        $totalExpenses = $this->expenseModel->getTotalExpensesForDate($date);

        if ($summary) {
            $summary['total_expenses'] = $totalExpenses;
            $summary['net_profit'] = (float)($summary['total_profit'] ?? 0) - $totalExpenses;
        }

        return Response::success([
            'date'     => $date,
            'summary'  => $summary,
            'invoices' => $invoices,
        ]);
    }

    public function monthly() {
        $month = (int)$this->getParam('month', date('n'));
        $year  = (int)$this->getParam('year', date('Y'));
        $data  = $this->invoiceModel->getMonthlySummary($month, $year);

        $totalRevenue  = array_sum(array_column($data, 'total_revenue'));
        $totalInvoices = array_sum(array_column($data, 'total_invoices'));
        $totalProfit   = $this->invoiceModel->getTotalProfitForMonth($month, $year);
        $totalCost     = $this->invoiceModel->getTotalCostForMonth($month, $year);
        $totalExpenses = $this->expenseModel->getTotalExpensesForMonth($month, $year);
        $netProfit     = $totalProfit - $totalExpenses;

        return Response::success([
            'month'           => $month,
            'year'            => $year,
            'total_revenue'   => $totalRevenue,
            'total_cost'      => $totalCost,
            'total_invoices'  => $totalInvoices,
            'total_profit'    => $totalProfit,
            'total_expenses'  => $totalExpenses,
            'net_profit'      => $netProfit,
            'daily_breakdown' => $data,
        ]);
    }

    public function topProducts() {
        $limit    = (int)$this->getParam('limit', 10);
        $fromDate = $this->getParam('from');
        $toDate   = $this->getParam('to');
        $products = $this->invoiceModel->getTopProducts($limit, $fromDate, $toDate);
        return Response::success($products);
    }

    public function profitReport() {
        $month = (int)$this->getParam('month', date('n'));
        $year  = (int)$this->getParam('year', date('Y'));

        $totals = $this->invoiceModel->getProfitReportTotals($month, $year);
        $topProfit = $this->invoiceModel->getTopProfitProducts($month, $year, 20);
        $dailyBreakdown = $this->invoiceModel->getDailyProfitBreakdown($month, $year);

        $revenue = (float)$totals['total_revenue'];
        $cost    = (float)$totals['total_cost'];
        $profit  = (float)$totals['total_profit'];
        $expenses = $this->expenseModel->getTotalExpensesForMonth($month, $year);
        $netProfit = $profit - $expenses;
        $margin  = $revenue > 0 ? round($netProfit / $revenue * 100, 2) : 0;

        return Response::success([
            'month'          => $month,
            'year'           => $year,
            'total_revenue'  => $revenue,
            'total_cost'     => $cost,
            'total_profit'   => $profit,
            'total_expenses' => $expenses,
            'net_profit'     => $netProfit,
            'profit_margin'  => $margin,
            'top_products'   => $topProfit,
            'daily_breakdown'=> $dailyBreakdown,
        ]);
    }

    public function summary() {
        $todayRevenue   = $this->invoiceModel->getTodayRevenue();
        $monthRevenue   = $this->invoiceModel->getMonthRevenue();
        $todayInvoices  = $this->invoiceModel->getTodayInvoicesCount();
        
        $totalProducts  = $this->productModel->getTotalProductsCount();
        $lowStockCount  = $this->productModel->getLowStockProductsCount();
        $totalSuppliers = $this->supplierModel->getTotalSuppliersCount();

        $monthProfit = $this->invoiceModel->getTotalProfitForMonth((int)date('n'), (int)date('Y'));
        $todayProfit = $this->invoiceModel->getTotalProfitForDate(date('Y-m-d'));
        $todayCost   = $this->invoiceModel->getTotalCostForDate(date('Y-m-d'));
        $monthCost   = $this->invoiceModel->getTotalCostForMonth((int)date('n'), (int)date('Y'));
        
        $todayExpenses = $this->expenseModel->getTotalExpensesForDate(date('Y-m-d'));
        $monthExpenses = $this->expenseModel->getTotalExpensesForMonth((int)date('n'), (int)date('Y'));

        return Response::cacheable([
            'today_revenue'  => (float)$todayRevenue,
            'month_revenue'  => (float)$monthRevenue,
            'today_cost'     => $todayCost,
            'month_cost'     => $monthCost,
            'today_profit'   => $todayProfit,
            'month_profit'   => $monthProfit,
            'today_expenses' => $todayExpenses,
            'month_expenses' => $monthExpenses,
            'today_net_profit' => $todayProfit - $todayExpenses,
            'month_net_profit' => $monthProfit - $monthExpenses,
            'today_invoices' => (int)$todayInvoices,
            'total_products' => (int)$totalProducts,
            'low_stock_count'=> (int)$lowStockCount,
            'total_suppliers'=> (int)$totalSuppliers,
        ], 120);
    }
}


