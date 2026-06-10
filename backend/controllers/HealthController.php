<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Services\HealthService;


class HealthController {

    private HealthService $healthService;

    public function __construct(HealthService $healthService) {
        $this->healthService = $healthService;
    }

    public function check() {
        $result = $this->healthService->runHealthCheck();

        $statusCode = $result['healthy'] ? 200 : 503;
        return Response::json([
            'status'          => $result['healthy'] ? 'healthy' : 'unhealthy',
            'checks'          => $result['checks'],
            'warnings'        => $result['warnings'],
            'timestamp'       => date('c'),
            'uptime_seconds'  => (int)(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']),
        ], $statusCode);
    }

    /**
     * GET /api/health/metrics — مقاييس أداء متقدمة (للمدير فقط)
     */
    public function metrics() {
        $metrics = $this->healthService->getMetrics();
        return Response::cacheable($metrics, 30);
    }

    /**
     * GET /api/admin/health/slow-queries
     * تحليل EXPLAIN للاستعلامات الرئيسية.
     */
    public function slowQueries() {
        $results = $this->healthService->analyzeSlowQueries();
        return Response::success($results);
    }

    /**
     * GET /api/system/network-info — جلب معلومات عناوين الشبكة لتشغيل النظام من الهاتف
     */
    public function networkInfo() {
        $info = $this->healthService->getNetworkInfo();
        return Response::success($info);
    }
}
