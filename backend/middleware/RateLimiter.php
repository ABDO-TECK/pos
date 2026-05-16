<?php

namespace App\Middleware;

use App\Helpers\Logger;
use Throwable;


/**
 * Rate Limiter Middleware — حماية API من الطلبات المفرطة
 *
 * يستخدم SQLite كطبقة تخزين (Fallback) بعد APCu —
 * آمن للطلبات المتزامنة (Concurrent-safe).
 *
 * الحد الافتراضي: 120 طلب/دقيقة (مناسب لنظام POS سريع الاستخدام).
 */
class RateLimiter
{
    private int    $maxRequests;
    private int    $windowSeconds;

    public function __construct(int $maxRequests = 200, int $windowSeconds = 60)
    {
        $this->maxRequests   = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * فحص الحد — يُستدعى عند كل طلب API.
     * إذا تجاوز العميل الحد المسموح، يُرسل 429 ويخرج.
     */
    public function check(string $prefix = 'rate_limit', ?int $userId = null): void
    {
        $ip   = $this->getClientIp();
        $identifier = $userId ? "user_{$userId}" : "ip_{$ip}";
        $now  = time();

        // ── 1. استخدام APCu في حال توفره (أداء أعلى بكثير) ──
        if (function_exists('apcu_inc')) {
            $key = $prefix . '_' . md5($identifier) . '_' . floor($now / $this->windowSeconds);
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

        // ── 2. التراجع (Fallback) لـ SQLite في حال غياب APCu ──
        $key = $prefix . '_' . md5($identifier);
        $windowKey = $key . '_' . floor($now / $this->windowSeconds);

        $db = $this->getSQLiteDB();
        if (!$db) return; // fail open

        try {
            // تنظيف السجلات المنتهية (مرة كل 100 طلب تقريباً)
            if (random_int(1, 100) === 1) {
                $db->exec("DELETE FROM rate_limits WHERE expires_at < " . $now);
            }

            // جلب أو إنشاء السجل
            $stmt = $db->prepare("SELECT request_count FROM rate_limits WHERE key_name = :key");
            $stmt->execute([':key' => $windowKey]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                $count = (int)$row['request_count'];
                if ($count >= $this->maxRequests) {
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
                $db->prepare("UPDATE rate_limits SET request_count = request_count + 1 WHERE key_name = :key")
                   ->execute([':key' => $windowKey]);
            } else {
                $expiresAt = $now + $this->windowSeconds + 10;
                $db->prepare("INSERT OR IGNORE INTO rate_limits (key_name, request_count, expires_at) VALUES (:key, 1, :exp)")
                   ->execute([':key' => $windowKey, ':exp' => $expiresAt]);
            }
        } catch (\Throwable $e) {
            // fail open
            Logger::error('RateLimiter SQLite error', ['error' => $e->getMessage()]);
        }
    }

    /**
     * إنشاء/فتح قاعدة بيانات SQLite للـ Rate Limiting.
     */
    private function getSQLiteDB(): ?\PDO
    {
        static $db = null;
        if ($db !== null) return $db;

        try {
            $dbPath = __DIR__ . '/../storage/rate_limit.sqlite';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            $db = new \PDO('sqlite:' . $dbPath);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                key_name TEXT PRIMARY KEY,
                request_count INTEGER NOT NULL DEFAULT 1,
                expires_at INTEGER NOT NULL
            )");
            // تفعيل WAL mode للأداء
            $db->exec("PRAGMA journal_mode=WAL");
            return $db;
        } catch (\Throwable $e) {
            Logger::error('RateLimiter: SQLite init failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * استخراج IP العميل الحقيقي (يدعم proxy).
     */
    private function getClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $trustedProxies = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : ['127.0.0.1', '::1'];

        if (in_array($remoteAddr, $trustedProxies, true)) {
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                return trim($ips[0]);
            }
            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                return trim($_SERVER['HTTP_X_REAL_IP']);
            }
        }
        
        return $remoteAddr;
    }

    /**
     * تنظيف ملفات Rate Limit القديمة (يمكن استدعاؤها دورياً).
     */
    public function cleanup(): void
    {
        $db = $this->getSQLiteDB();
        if ($db) {
            try {
                $db->exec("DELETE FROM rate_limits WHERE expires_at < " . time());
            } catch (\Throwable $e) {}
        }
        // تنظيف الملفات القديمة (فترة انتقالية)
        $files = glob(__DIR__ . '/../storage/rate_limit/*.json') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}
