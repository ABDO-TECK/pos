<?php

/**
 * Rate Limiter Middleware — حماية API من الطلبات المفرطة
 *
 * يستخدم ملفات مؤقتة لتتبع عدد الطلبات لكل IP.
 * الحد الافتراضي: 120 طلب/دقيقة (مناسب لنظام POS سريع الاستخدام).
 */
class RateLimiter
{
    private int    $maxRequests;    // عدد الطلبات المسموح
    private int    $windowSeconds; // فترة القياس بالثواني
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
        $file = $this->storageDir . '/' . md5($ip) . '.json';
        $now  = time();

        $data = $this->readFile($file);

        // تنظيف الطلبات القديمة خارج النافذة الزمنية
        $windowStart = $now - $this->windowSeconds;
        $data['requests'] = array_values(
            array_filter($data['requests'] ?? [], fn($ts) => $ts >= $windowStart)
        );

        // فحص الحد
        if (count($data['requests']) >= $this->maxRequests) {
            $oldestInWindow = min($data['requests']);
            $retryAfter     = ($oldestInWindow + $this->windowSeconds) - $now;
            $retryAfter     = max(1, $retryAfter);

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
        $data['requests'][] = $now;
        $this->writeFile($file, $data);
    }

    /**
     * استخراج IP العميل الحقيقي (يدعم proxy).
     */
    private function getClientIp(): string
    {
        // الأولوية: X-Forwarded-For → X-Real-IP → REMOTE_ADDR
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return trim($_SERVER['HTTP_X_REAL_IP']);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private function readFile(string $path): array
    {
        if (!file_exists($path)) {
            return ['requests' => []];
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return ['requests' => []];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : ['requests' => []];
    }

    private function writeFile(string $path, array $data): void
    {
        @file_put_contents($path, json_encode($data), LOCK_EX);
    }

    /**
     * تنظيف ملفات Rate Limit القديمة (يمكن استدعاؤها دورياً).
     */
    public function cleanup(): void
    {
        $files = glob($this->storageDir . '/*.json');
        $expiry = time() - ($this->windowSeconds * 2);
        foreach ($files as $file) {
            if (filemtime($file) < $expiry) {
                @unlink($file);
            }
        }
    }
}
