<?php

/**
 * Rate Limiter Middleware — حماية API من الطلبات المفرطة
 *
 * يستخدم ملفات مؤقتة مع قفل ذري (flock) لتتبع الطلبات لكل IP —
 * آمن للطلبات المتزامنة (Concurrent-safe).
 *
 * الحد الافتراضي: 120 طلب/دقيقة (مناسب لنظام POS سريع الاستخدام).
 */
class RateLimiter
{
    private int    $maxRequests;
    private int    $windowSeconds;
    private string $storageDir;

    public function __construct(int $maxRequests = 120, int $windowSeconds = 60)
    {
        $this->maxRequests   = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->storageDir    = __DIR__ . '/../storage/rate_limit';

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * فحص الحد — يُستدعى عند كل طلب API.
     * إذا تجاوز العميل الحد المسموح، يُرسل 429 ويخرج.
     */
    public function check(): void
    {
        $ip   = $this->getClientIp();
        $now  = time();

        // ── 1. استخدام APCu في حال توفره (أداء أعلى بكثير) ──
        if (function_exists('apcu_inc')) {
            $key = 'rate_limit_' . md5($ip) . '_' . floor($now / $this->windowSeconds);
            $success = false;
            $count = apcu_inc($key, 1, $success);
            
            if (!$success) {
                apcu_store($key, 1, $this->windowSeconds + 10);
                $count = 1;
            }
            
            if ($count > $this->maxRequests) {
                $retryAfter = max(1, $this->windowSeconds - ($now % $this->windowSeconds));
                header("Retry-After: $retryAfter");
                http_response_code(429);
                echo json_encode([
                    'status'      => 'error',
                    'message'     => 'تم تجاوز الحد المسموح من الطلبات. يرجى الانتظار.',
                    'retry_after' => $retryAfter,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            return;
        }

        // ── 2. التراجع (Fallback) لنظام الملفات في حال غياب APCu ──
        $file = $this->storageDir . '/' . md5($ip) . '.json';

        // ── قراءة + تحديث ذري (atomic read-modify-write) ─────
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            // فشل فتح الملف — نسمح بالمرور (fail open) بدلاً من حظر المستخدم
            return;
        }

        // قفل حصري — ينتظر حتى يتحرر القفل من طلب آخر
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return;
        }

        try {
            // قراءة المحتوى الحالي
            $content = stream_get_contents($handle);
            $data    = ($content !== '' && $content !== false)
                ? json_decode($content, true)
                : null;

            if (!is_array($data) || !isset($data['timestamps'])) {
                $data = ['timestamps' => []];
            }

            // تنظيف الطلبات القديمة خارج النافذة الزمنية
            $windowStart = $now - $this->windowSeconds;
            $data['timestamps'] = array_values(
                array_filter($data['timestamps'], fn($ts) => $ts >= $windowStart)
            );

            // فحص الحد
            if (count($data['timestamps']) >= $this->maxRequests) {
                $oldestInWindow = min($data['timestamps']);
                $retryAfter     = ($oldestInWindow + $this->windowSeconds) - $now;
                $retryAfter     = max(1, $retryAfter);

                // كتابة البيانات بدون إضافة الطلب الحالي
                $this->writeAndUnlock($handle, $file, $data);

                header("Retry-After: $retryAfter");
                http_response_code(429);
                echo json_encode([
                    'status'      => 'error',
                    'message'     => 'تم تجاوز الحد المسموح من الطلبات. يرجى الانتظار.',
                    'retry_after' => $retryAfter,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // تسجيل الطلب الحالي
            $data['timestamps'][] = $now;

            // الاحتفاظ فقط بآخر N+10 عنصر لمنع نمو الملف بلا حدود
            $maxKeep = $this->maxRequests + 10;
            if (count($data['timestamps']) > $maxKeep) {
                $data['timestamps'] = array_slice($data['timestamps'], -$maxKeep);
            }

            $this->writeAndUnlock($handle, $file, $data);
        } catch (Throwable $e) {
            // فك القفل في حال حدوث خطأ
            flock($handle, LOCK_UN);
            fclose($handle);
            // fail open — لا نحظر المستخدم بسبب خطأ داخلي
            Logger::error('RateLimiter error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * كتابة البيانات المحدّثة وتحرير القفل.
     *
     * @param resource $handle
     */
    private function writeAndUnlock($handle, string $file, array $data): void
    {
        // إعادة الكتابة من البداية
        fseek($handle, 0);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($data, JSON_UNESCAPED_UNICODE));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * استخراج IP العميل الحقيقي (يدعم proxy).
     */
    private function getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return trim($_SERVER['HTTP_X_REAL_IP']);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * تنظيف ملفات Rate Limit القديمة (يمكن استدعاؤها دورياً).
     */
    public function cleanup(): void
    {
        $files  = glob($this->storageDir . '/*.json') ?: [];
        $expiry = time() - ($this->windowSeconds * 2);
        foreach ($files as $file) {
            if (filemtime($file) < $expiry) {
                @unlink($file);
            }
        }
    }
}

