<?php
/**
 * CLI Worker — ينفذ المهام المعلقة في job_queue.
 *
 * التشغيل:
 *   php backend/cli/process-jobs.php           (دورة واحدة)
 *   php backend/cli/process-jobs.php --daemon  (مستمر)
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: CLI only.');
}

require_once __DIR__ . '/../Config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Helpers\JobQueue;
use App\Helpers\Logger;

$daemon = in_array('--daemon', $argv, true);

echo "[JobWorker] Started at " . date('Y-m-d H:i:s') . "\n";

do {
    try {
        $processed = JobQueue::processNext();
        if ($processed) {
            echo "[JobWorker] Processed 1 job at " . date('H:i:s') . "\n";
        } elseif ($daemon) {
            // لا مهام — انتظر 5 ثوانٍ قبل المحاولة التالية
            sleep(5);
        }
    } catch (\Throwable $e) {
        Logger::error('JobWorker error', ['error' => $e->getMessage()]);
        echo "[JobWorker] Error: {$e->getMessage()}\n";
        if ($daemon) sleep(10);
    }
} while ($daemon);

echo "[JobWorker] Done.\n";
