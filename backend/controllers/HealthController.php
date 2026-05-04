<?php

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Response;
use Throwable;


class HealthController {
    public function check() {
        $checks = [];
        $overallHealthy = true;

        // ── 1. Database ──
        try {
            $db = Database::getInstance();
            $stmt = $db->query('SELECT 1');
            $checks['database'] = [
                'status' => 'connected',
                'latency_ms' => null,
            ];
            // قياس زمن الاستجابة
            $start = microtime(true);
            $db->query('SELECT 1');
            $checks['database']['latency_ms'] = round((microtime(true) - $start) * 1000, 2);
        } catch (Throwable $e) {
            $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
            $overallHealthy = false;
        }

        // ── 2. Disk Space ──
        $storagePath = __DIR__ . '/../storage';
        $freeBytes = @disk_free_space($storagePath);
        $totalBytes = @disk_total_space($storagePath);
        if ($freeBytes !== false && $totalBytes !== false) {
            $freeGB = round($freeBytes / (1024 * 1024 * 1024), 2);
            $totalGB = round($totalBytes / (1024 * 1024 * 1024), 2);
            $usedPercent = round((1 - $freeBytes / $totalBytes) * 100, 1);
            $checks['disk'] = [
                'status' => $freeGB < 1 ? 'warning' : 'ok',
                'free_gb' => $freeGB,
                'total_gb' => $totalGB,
                'used_percent' => $usedPercent,
            ];
            if ($freeGB < 0.5) $overallHealthy = false;
        } else {
            $checks['disk'] = ['status' => 'unknown'];
        }

        // ── 3. Memory ──
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $memoryLimit = ini_get('memory_limit');
        $checks['memory'] = [
            'status' => 'ok',
            'usage_mb' => round($memoryUsage / (1024 * 1024), 2),
            'peak_mb' => round($memoryPeak / (1024 * 1024), 2),
            'limit' => $memoryLimit,
        ];

        // ── 4. PHP Info ──
        $checks['php'] = [
            'version' => PHP_VERSION,
            'extensions' => [
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'mbstring' => extension_loaded('mbstring'),
                'json' => extension_loaded('json'),
                'redis' => extension_loaded('redis'),
            ],
        ];

        // ── Response ──
        $statusCode = $overallHealthy ? 200 : 503;
        return Response::json([
            'status' => $overallHealthy ? 'healthy' : 'unhealthy',
            'checks' => $checks,
            'timestamp' => date('c'),
            'uptime_seconds' => (int)(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']),
        ], $statusCode);
    }
}
