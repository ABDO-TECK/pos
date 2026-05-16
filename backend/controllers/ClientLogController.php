<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Logger;
use App\Helpers\Response;


/**
 * ClientLogController — يستقبل أخطاء الواجهة الأمامية (Frontend)
 * ويحفظها في ملفات اللوج باستخدام Logger.
 *
 * يُستخدم لتتبع أخطاء JavaScript/React التي تحدث عند المستخدمين
 * والتي عادةً ما تضيع بدون نظام تسجيل مركزي.
 */
class ClientLogController extends Controller
{
    /**
     * POST /api/client-log
     *
     * Body المتوقع:
     * {
     *   "level": "error" | "warning" | "info",
     *   "message": "وصف الخطأ",
     *   "context": { ... بيانات إضافية اختيارية ... }
     * }
     */
    public function store()
    {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'level'   => 'required',
            'message' => 'required',
        ]);

        if ($errors) {
            return Response::error('Validation failed', 422, $errors);
        }

        $level   = strtoupper($data['level'] ?? 'ERROR');
        $message = '[CLIENT] ' . ($data['message'] ?? 'Unknown error');

        // تنقية وتحديد المعلومات المسموحة في السياق
        $context = [];
        if (!empty($data['context']) && is_array($data['context'])) {
            $allowed = ['url', 'stack', 'component', 'userAgent', 'timestamp', 'userId', 'extra'];
            foreach ($allowed as $key) {
                if (isset($data['context'][$key])) {
                    $context[$key] = is_string($data['context'][$key])
                        ? mb_substr($data['context'][$key], 0, 2000)
                        : $data['context'][$key];
                }
            }
        }

        // إضافة معلومات الطلب
        $context['client_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // تسجيل الخطأ حسب المستوى
        match ($level) {
            'CRITICAL' => Logger::critical($message, $context),
            'ERROR'    => Logger::error($message, $context),
            'WARNING'  => Logger::warning($message, $context),
            'INFO'     => Logger::info($message, $context),
            default    => Logger::error($message, $context),
        };

        return Response::success(null, 'Log received');
    }

    /**
     * GET /api/admin/client-logs
     * جلب السجلات لعرضها في لوحة التحكم
     */
    public function index()
    {
        $limit = (int)($_GET['limit'] ?? 100);
        $level = strtolower($_GET['level'] ?? 'all');
        $logDir = __DIR__ . '/../../logs';
        
        $logs = [];
        $files = glob($logDir . '/client-*.log');
        rsort($files); // أحدث الملفات أولاً

        foreach ($files as $file) {
            $lines = file($file);
            if ($lines === false) continue;
            
            $lines = array_reverse($lines); // الأحدث أولاً
            foreach ($lines as $line) {
                if (preg_match('/\[(.*?)\] \[(.*?)\] (.*?) (\{.*\})/', $line, $matches)) {
                    $logLevel = strtolower($matches[2]);
                    if ($level !== 'all' && strpos($logLevel, $level) === false) continue;

                    $logs[] = [
                        'id' => md5($line),
                        'created_at' => $matches[1],
                        'level' => $matches[2],
                        'message' => $matches[3],
                        'context' => $matches[4],
                    ];

                    if (count($logs) >= $limit) break 2;
                }
            }
        }

        return Response::success($logs);
    }
}
