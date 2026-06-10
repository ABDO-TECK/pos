<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Services\ReportService;

class ReportController extends Controller {

    private ReportService $reportService;

    public function __construct(ReportService $reportService) {
        $this->reportService = $reportService;
    }

    public function daily() {
        $date = $this->getParam('date', date('Y-m-d'));
        $data = $this->reportService->getDailySummary($date);
        return Response::success($data);
    }

    public function monthly() {
        $month = (int)$this->getParam('month', date('n'));
        $year  = (int)$this->getParam('year', date('Y'));
        $data  = $this->reportService->getMonthlySummary($month, $year);
        return Response::success($data);
    }

    public function topProducts() {
        $limit    = (int)$this->getParam('limit', 10);
        $fromDate = $this->getParam('from');
        $toDate   = $this->getParam('to');
        $products = $this->reportService->getTopProducts($limit, $fromDate, $toDate);
        return Response::success($products);
    }

    public function profitReport() {
        $month = (int)$this->getParam('month', date('n'));
        $year  = (int)$this->getParam('year', date('Y'));
        $data = $this->reportService->getProfitReport($month, $year);
        return Response::success($data);
    }

    public function summary() {
        $summary = $this->reportService->getDashboardSummary();
        return Response::cacheable($summary, 120);
    }
}


