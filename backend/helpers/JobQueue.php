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
            // أضف handlers هنا مستقبلاً:
            // 'generate_report' => fn($p) => (new ReportService)->generate($p),
            // 'send_notification' => fn($p) => Notifier::send($p),
        ];

        if (!isset($handlers[$jobName])) {
            throw new \RuntimeException("Unknown job: {$jobName}");
        }
        return $handlers[$jobName];
    }
}
