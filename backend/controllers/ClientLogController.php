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
}
