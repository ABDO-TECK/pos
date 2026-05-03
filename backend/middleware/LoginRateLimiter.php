<?php

namespace App\Middleware;

/**
 * LoginRateLimiter — حماية من Brute Force لصفحة الدخول.
 *
 * حد أقصى: 5 محاولات / دقيقة لكل IP.
 * يدعم الاستخدام كـ Middleware (عبر handle) أو مباشرةً (عبر check).
 */
class LoginRateLimiter
{
    private int    $maxAttempts;
    private int    $windowSeconds;
    private string $storageDir;

    public function __construct(int $maxAttempts = 5, int $windowSeconds = 60)
    {
        $this->maxAttempts   = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->storageDir    = __DIR__ . '/../storage/login_rate_limit';

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Middleware interface — يُستخدم من Router.
     * يفحص الحد ثم يمرر التنفيذ للـ handler التالي.
     */
    public function handle(callable $next): mixed
    {
        $this->check();
        return $next();
    }

    /**
     * فحص هل تجاوز الـ IP الحد المسموح.
     * يُرجع true إذا مسموح، أو يُرسل 429 ويخرج إذا تجاوز الحد.
     */
    public function check(): void
    {
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $now  = time();
        $file = $this->storageDir . '/' . md5($ip) . '.json';

        $handle = @fopen($file, 'c+');
        if ($handle === false) return;

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return;
        }

        try {
            $content = stream_get_contents($handle);
            $data    = ($content !== '' && $content !== false)
                ? json_decode($content, true) : null;

            if (!is_array($data) || !isset($data['timestamps'])) {
                $data = ['timestamps' => []];
            }

            // تنظيف المحاولات القديمة
            $windowStart = $now - $this->windowSeconds;
            $data['timestamps'] = array_values(
                array_filter($data['timestamps'], fn($ts) => $ts >= $windowStart)
            );

            if (count($data['timestamps']) >= $this->maxAttempts) {
                $retryAfter = max(1, $this->windowSeconds - ($now % $this->windowSeconds));
                
                // كتابة البيانات بدون إضافة المحاولة الحالية
                fseek($handle, 0);
                ftruncate($handle, 0);
                fwrite($handle, json_encode($data, JSON_UNESCAPED_UNICODE));
                fflush($handle);
                flock($handle, LOCK_UN);
                fclose($handle);

                header("Retry-After: $retryAfter");
                http_response_code(429);
                echo json_encode([
                    'status'      => 'error',
                    'message'     => 'تم تجاوز الحد المسموح لمحاولات تسجيل الدخول. يرجى الانتظار.',
                    'retry_after' => $retryAfter,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // تسجيل المحاولة الحالية
            $data['timestamps'][] = $now;

            fseek($handle, 0);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($data, JSON_UNESCAPED_UNICODE));
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
        } catch (\Throwable $e) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
