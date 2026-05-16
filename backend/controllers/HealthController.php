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

        // ── 5. Security Warnings ──
        $warnings = [];
        $defaultPassHash = defined('DEFAULT_PASSWORD_HASH') ? DEFAULT_PASSWORD_HASH : '';
        if ($defaultPassHash) {
            try {
                $db = Database::getInstance();
                $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE password = ?');
                $stmt->execute([$defaultPassHash]);
                $defaultPassCount = (int) $stmt->fetchColumn();
                if ($defaultPassCount > 0) {
                    $warnings[] = "⚠️ يوجد {$defaultPassCount} مستخدم(ين) بكلمة المرور الافتراضية. يرجى تغييرها فوراً.";
                }
            } catch (Throwable $e) {}
        }

        // ── Response ──
        $statusCode = $overallHealthy ? 200 : 503;
        return Response::json([
            'status' => $overallHealthy ? 'healthy' : 'unhealthy',
            'checks' => $checks,
            'warnings' => $warnings,
            'timestamp' => date('c'),
            'uptime_seconds' => (int)(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']),
        ], $statusCode);
    }

    /**
     * GET /api/health/metrics — مقاييس أداء متقدمة (للمدير فقط)
     */
    public function metrics() {
        $db = Database::getInstance();
        $metrics = [];

        // ── 1. Database Metrics ──
        try {
            // عدد الاتصالات النشطة
            $stmt = $db->query("SHOW STATUS LIKE 'Threads_connected'");
            $row = $stmt->fetch();
            $metrics['database']['active_connections'] = (int)($row['Value'] ?? 0);

            // حجم قاعدة البيانات
            $stmt = $db->query("SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = DATABASE()");
            $row = $stmt->fetch();
            $metrics['database']['size_mb'] = round(((float)($row['size'] ?? 0)) / (1024 * 1024), 2);

            // عدد الاستعلامات البطيئة
            $stmt = $db->query("SHOW STATUS LIKE 'Slow_queries'");
            $row = $stmt->fetch();
            $metrics['database']['slow_queries'] = (int)($row['Value'] ?? 0);
        } catch (\Throwable $e) {
            $metrics['database']['error'] = $e->getMessage();
        }

        // ── 2. Table Row Counts ──
        $tables = ['products', 'invoices', 'invoice_items', 'customers', 'suppliers', 'expenses', 'audit_logs'];
        $metrics['tables'] = [];
        foreach ($tables as $table) {
            try {
                $stmt = $db->query("SELECT COUNT(*) as cnt FROM {$table}");
                $metrics['tables'][$table] = (int)$stmt->fetchColumn();
            } catch (\Throwable $e) {
                $metrics['tables'][$table] = -1;
            }
        }

        // ── 3. Today's Activity ──
        try {
            $today = date('Y-m-d');

            $stmt = $db->prepare("SELECT COUNT(*) FROM invoices WHERE DATE(created_at) = ? AND deleted_at IS NULL");
            $stmt->execute([$today]);
            $metrics['today']['sales_count'] = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE DATE(created_at) = ? AND deleted_at IS NULL AND status = 'completed'");
            $stmt->execute([$today]);
            $metrics['today']['sales_total'] = round((float)$stmt->fetchColumn(), 2);

            $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE DATE(expense_date) = ?");
            $stmt->execute([$today]);
            $metrics['today']['expenses_total'] = round((float)$stmt->fetchColumn(), 2);
        } catch (\Throwable $e) {
            $metrics['today']['error'] = $e->getMessage();
        }

        // ── 4. Cache Status ──
        $metrics['cache'] = [
            'redis_available' => class_exists('Redis'),
            'apcu_available'  => function_exists('apcu_fetch'),
            'driver'          => class_exists('Redis') ? 'redis' : (function_exists('apcu_fetch') ? 'apcu' : 'file'),
        ];

        // ── 5. System ──
        $metrics['system'] = [
            'php_version'      => PHP_VERSION,
            'memory_usage_mb'  => round(memory_get_usage(true) / (1024 * 1024), 2),
            'memory_peak_mb'   => round(memory_get_peak_usage(true) / (1024 * 1024), 2),
            'memory_limit'     => ini_get('memory_limit'),
            'opcache_enabled'  => function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false),
            'timestamp'        => date('c'),
        ];

        return Response::cacheable($metrics, 30);
    }

    /**
     * GET /api/admin/health/slow-queries
     * تحليل EXPLAIN للاستعلامات الرئيسية.
     */
    public function slowQueries() {
        $db = Database::getInstance();
        $queries = [
            'invoices_by_date' => "EXPLAIN SELECT * FROM invoices WHERE created_at >= CURDATE() AND status = 'completed' ORDER BY created_at DESC LIMIT 50",
            'top_products' => "EXPLAIN SELECT p.name, SUM(ii.quantity) as sold FROM invoice_items ii JOIN products p ON p.id = ii.product_id GROUP BY ii.product_id ORDER BY sold DESC LIMIT 10",
            'low_stock' => "EXPLAIN SELECT * FROM products WHERE quantity <= low_stock_threshold AND deleted_at IS NULL",
            'customer_balance' => "EXPLAIN SELECT customer_id, SUM(CASE WHEN type='debit' THEN amount ELSE -amount END) as balance FROM customer_ledger GROUP BY customer_id",
            'expenses_by_date' => "EXPLAIN SELECT * FROM expenses WHERE expense_date >= CURDATE() - INTERVAL 30 DAY ORDER BY expense_date DESC",
        ];

        $results = [];
        foreach ($queries as $name => $sql) {
            try {
                $stmt = $db->query($sql);
                $explain = $stmt->fetchAll();
                $results[$name] = [
                    'plan' => $explain,
                    'uses_index' => !empty($explain[0]['key']),
                    'type' => $explain[0]['type'] ?? 'unknown',
                ];
            } catch (\Throwable $e) {
                $results[$name] = ['error' => $e->getMessage()];
            }
        }

        return Response::success($results);
    }
}
