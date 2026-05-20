<?php

namespace App\Helpers;

/**
 * JobQueue — نظام طوابير بسيط يعتمد على قاعدة البيانات.
 *
 * الاستخدام:
 *   JobQueue::dispatch('generate_report', ['month' => 5, 'year' => 2026]);
 *
 * التنفيذ (عبر cron أو CLI):
 *   php backend/cli/process-jobs.php
 */
class JobQueue
{
    /**
     * إضافة مهمة جديدة للطابور.
     */
    public static function dispatch(string $job, array $payload = [], int $priority = 0): int
    {
        $db = \App\Config\Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO job_queue (job_name, payload, priority, status, created_at)
             VALUES (:job, :payload, :priority, :status, NOW())'
        );
        $stmt->execute([
            'job'      => $job,
            'payload'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'priority' => $priority,
            'status'   => 'pending',
        ]);
        return (int) $db->lastInsertId();
    }

    /**
     * جلب وتنفيذ المهمة التالية.
     * @return bool true إذا تم تنفيذ مهمة
     */
    public static function processNext(): bool
    {
        $db = \App\Config\Database::getInstance();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT * FROM job_queue
                 WHERE status = "pending" AND attempts < max_attempts
                 ORDER BY priority DESC, id ASC
                 LIMIT 1
                 FOR UPDATE SKIP LOCKED'
            );
            $stmt->execute();
            $job = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$job) {
                $db->commit();
                return false;
            }

            // تحديث الحالة
            $db->prepare('UPDATE job_queue SET status = "processing", attempts = attempts + 1 WHERE id = ?')
               ->execute([$job['id']]);
            $db->commit();

            // تنفيذ
            try {
                $handler = self::resolveHandler($job['job_name']);
                $handler(json_decode($job['payload'], true) ?? []);
                $db->prepare('UPDATE job_queue SET status = "completed", completed_at = NOW() WHERE id = ?')
                   ->execute([$job['id']]);
            } catch (\Throwable $e) {
                Logger::error("Job failed: {$job['job_name']}", [
                    'id' => $job['id'], 'error' => $e->getMessage()
                ]);
                $newStatus = ((int)$job['attempts'] + 1 >= (int)$job['max_attempts']) ? 'failed' : 'pending';
                $db->prepare('UPDATE job_queue SET status = ?, last_error = ? WHERE id = ?')
                   ->execute([$newStatus, $e->getMessage(), $job['id']]);
            }

            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            Logger::error('Job queue error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private static function resolveHandler(string $jobName): callable
    {
        $handlers = [
            'backup_database' => function (array $p) {
                $service = new \App\Services\BackupService();
                $service->createBackup();
            },
            'cleanup_old_logs' => function (array $p) {
                $dir = __DIR__ . '/../logs/';
                $days = $p['days'] ?? 30;
                $cutoff = strtotime("-{$days} days");
                foreach (glob($dir . '*.log') as $file) {
                    if (filemtime($file) < $cutoff) {
                        @unlink($file);
                    }
                }
            },
            'cleanup_old_jobs' => function (array $p) {
                $db = \App\Config\Database::getInstance();
                $days = $p['days'] ?? 7;
                $db->prepare(
                    "DELETE FROM job_queue WHERE status IN ('completed','failed') AND created_at < NOW() - INTERVAL ? DAY"
                )->execute([$days]);
            },
            'send_low_stock_alert' => function (array $p) {
                $productId = $p['product_id'] ?? 0;
                $quantity  = $p['quantity'] ?? 0;
                $name      = $p['name'] ?? 'Unknown';
                Logger::warning("Low stock alert: {$name} (ID: {$productId}) — {$quantity} remaining");
            },
            'log_audit_event' => function (array $p) {
                Logger::info('Audit: ' . ($p['action'] ?? 'unknown'), $p);
            },
            'earn_loyalty_points' => function (array $p) {
                $customerId = $p['customer_id'] ?? 0;
                $invoiceId  = $p['invoice_id'] ?? 0;
                $total      = (float)($p['total'] ?? 0);
                if ($customerId <= 0 || $invoiceId <= 0 || $total <= 0) return;
                
                $loyalty = new \App\Services\LoyaltyService();
                $points  = $loyalty->earnPoints($customerId, $invoiceId, $total);
                if ($points > 0) {
                    Logger::info("Loyalty: earned {$points} points", [
                        'customer_id' => $customerId,
                        'invoice_id'  => $invoiceId,
                    ]);
                }
            },
        ];

        if (!isset($handlers[$jobName])) {
            throw new \RuntimeException("Unknown job: {$jobName}");
        }
        return $handlers[$jobName];
    }
}
