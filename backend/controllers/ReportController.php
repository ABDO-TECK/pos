<?php

class ReportController extends Controller {

    private Invoice $invoiceModel;

    public function __construct() {
        $this->invoiceModel = new Invoice();
    }

    public function daily(): void {
        $date    = $this->getParam('date', date('Y-m-d'));
        $summary = $this->invoiceModel->getDailySummary($date);
        $invoices = $this->invoiceModel->all(['date' => $date]);
        Response::success([
            'date'     => $date,
            'summary'  => $summary,
            'invoices' => $invoices,
        ]);
    }

    public function monthly(): void {
        $month = (int)$this->getParam('month', date('n'));
        $year  = (int)$this->getParam('year', date('Y'));
        $data  = $this->invoiceModel->getMonthlySummary($month, $year);

        $totalRevenue  = array_sum(array_column($data, 'total_revenue'));
        $totalInvoices = array_sum(array_column($data, 'total_invoices'));
        $totalProfit   = $this->invoiceModel->getTotalProfitForMonth($month, $year);

        Response::success([
            'month'           => $month,
            'year'            => $year,
            'total_revenue'   => $totalRevenue,
            'total_invoices'  => $totalInvoices,
            'total_profit'    => $totalProfit,
            'daily_breakdown' => $data,
        ]);
    }

    public function topProducts(): void {
        $limit    = (int)$this->getParam('limit', 10);
        $fromDate = $this->getParam('from');
        $toDate   = $this->getParam('to');
        $products = $this->invoiceModel->getTopProducts($limit, $fromDate, $toDate);
        Response::success($products);
    }

    public function profitReport(): void {
        $db    = Database::getInstance();
        $month = (int)$this->getParam('month', date('n'));
        $year  = (int)$this->getParam('year', date('Y'));

        // تكلفة وربح من unit_cost المخزن لكل بند (لحظة البيع) — وليس products.cost الحالي
        $profitRow = $db->prepare(
            'SELECT
                COALESCE(SUM(ii.price * ii.quantity), 0)                      AS total_revenue,
                COALESCE(SUM(ii.unit_cost * ii.quantity), 0)                  AS total_cost,
                COALESCE(SUM((ii.price - ii.unit_cost) * ii.quantity), 0)      AS total_profit
             FROM invoice_items ii
             JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"
             WHERE MONTH(inv.created_at) = ? AND YEAR(inv.created_at) = ?'
        );
        $profitRow->execute([$month, $year]);
        $totals = $profitRow->fetch();

        // أفضل المنتجات ربحاً (مجمّع حسب المنتج)
        $topProfit = $db->prepare(
            'SELECT
                p.id,
                p.name,
                MAX(p.price) AS price,
                SUM(ii.quantity) AS total_sold,
                SUM(ii.price * ii.quantity) AS revenue,
                SUM(ii.unit_cost * ii.quantity) AS cost,
                SUM((ii.price - ii.unit_cost) * ii.quantity) AS profit,
                ROUND(
                    CASE WHEN SUM(ii.price * ii.quantity) > 0
                    THEN 100 * SUM((ii.price - ii.unit_cost) * ii.quantity) / SUM(ii.price * ii.quantity)
                    ELSE 0 END,
                    2
                ) AS margin_pct
             FROM invoice_items ii
             JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"
             JOIN products p   ON p.id  = ii.product_id
             WHERE MONTH(inv.created_at) = ? AND YEAR(inv.created_at) = ?
             GROUP BY p.id, p.name
             ORDER BY profit DESC
             LIMIT 20'
        );
        $topProfit->execute([$month, $year]);

        // تفصيل يومي
        $dailyBreakdown = $db->prepare(
            'SELECT
                DATE(inv.created_at) AS date,
                COALESCE(SUM(ii.price * ii.quantity), 0) AS revenue,
                COALESCE(SUM(ii.unit_cost * ii.quantity), 0) AS cost,
                COALESCE(SUM((ii.price - ii.unit_cost) * ii.quantity), 0) AS profit
             FROM invoice_items ii
             JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"
             WHERE MONTH(inv.created_at) = ? AND YEAR(inv.created_at) = ?
             GROUP BY DATE(inv.created_at)
             ORDER BY date ASC'
        );
        $dailyBreakdown->execute([$month, $year]);

        $revenue = (float)$totals['total_revenue'];
        $cost    = (float)$totals['total_cost'];
        $profit  = (float)$totals['total_profit'];
        $margin  = $revenue > 0 ? round($profit / $revenue * 100, 2) : 0;

        Response::success([
            'month'          => $month,
            'year'           => $year,
            'total_revenue'  => $revenue,
            'total_cost'     => $cost,
            'total_profit'   => $profit,
            'profit_margin'  => $margin,
            'top_products'   => $topProfit->fetchAll(),
            'daily_breakdown'=> $dailyBreakdown->fetchAll(),
        ]);
    }

    public function summary(): void {
        $db = Database::getInstance();

        $todayRevenue = $db->query(
            'SELECT COALESCE(SUM(total),0) FROM invoices WHERE DATE(created_at) = CURDATE() AND status="completed"'
        )->fetchColumn();

        $monthRevenue = $db->query(
            'SELECT COALESCE(SUM(total),0) FROM invoices WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status="completed"'
        )->fetchColumn();

        $totalProducts = $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $lowStockCount = $db->query('SELECT COUNT(*) FROM products WHERE quantity <= low_stock_threshold')->fetchColumn();
        $totalSuppliers = $db->query('SELECT COUNT(*) FROM suppliers')->fetchColumn();

        $todayInvoices = $db->query(
            'SELECT COUNT(*) FROM invoices WHERE DATE(created_at) = CURDATE() AND status="completed"'
        )->fetchColumn();

        $monthProfit = $this->invoiceModel->getTotalProfitForMonth((int)date('n'), (int)date('Y'));
        $todayProfit = $this->invoiceModel->getTotalProfitForDate(date('Y-m-d'));

        Response::success([
            'today_revenue'  => (float)$todayRevenue,
            'month_revenue'  => (float)$monthRevenue,
            'today_profit'   => $todayProfit,
            'month_profit'   => $monthProfit,
            'today_invoices' => (int)$todayInvoices,
            'total_products' => (int)$totalProducts,
            'low_stock_count'=> (int)$lowStockCount,
            'total_suppliers'=> (int)$totalSuppliers,
        ]);
    }
}
