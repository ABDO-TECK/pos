<?php

namespace App\Middleware;

use App\Helpers\Logger;

/**
 * LoginRateLimiter — حماية من Brute Force لصفحة الدخول.
 * حد أقصى: 5 محاولات / دقيقة لكل IP.
 * يدعم الاستخدام كـ Middleware (عبر handle) أو مباشرةً (عبر check).
 */
class LoginRateLimiter
{
    private int $maxAttempts;
    private int $windowSeconds;

    public function __construct(int $maxAttempts = 5, int $windowSeconds = 60)
    {
        $this->maxAttempts   = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
    }

    public function handle(callable $next): mixed
    {
        $this->check();
        return $next();
    }

    public function check(): void
    {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $now = time();

        // 1. APCu (أسرع)
        if (function_exists('apcu_inc')) {
            $key = 'login_rl_' . md5($ip) . '_' . floor($now / $this->windowSeconds);
            $success = false;
            $count = apcu_inc($key, 1, $success);
            if (!$success) {
                apcu_store($key, 1, $this->windowSeconds + 10);
                $count = 1;
            }
            if ($count > $this->maxAttempts) {
                $this->send429($now);
            }
            return;
        }

        // 2. SQLite fallback
        $key = 'login_' . md5($ip) . '_' . floor($now / $this->windowSeconds);
        $db = $this->getSQLiteDB();
        if (!$db) return;

        try {
            $stmt = $db->prepare("SELECT request_count FROM rate_limits WHERE key_name = :key");
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                if ((int)$row['request_count'] >= $this->maxAttempts) {
                    $this->send429($now);
                }
                $db->prepare("UPDATE rate_limits SET request_count = request_count + 1 WHERE key_name = :key")
                   ->execute([':key' => $key]);
            } else {
                $db->prepare("INSERT OR IGNORE INTO rate_limits (key_name, request_count, expires_at) VALUES (:key, 1, :exp)")
                   ->execute([':key' => $key, ':exp' => $now + $this->windowSeconds + 10]);
            }
        } catch (\Throwable $e) {
            // fail open
        }
    }

    private function send429(int $now): void
    {
        $retryAfter = max(1, $this->windowSeconds - ($now % $this->windowSeconds));
        header("Retry-After: $retryAfter");
        http_response_code(429);
        echo json_encode([
            'status'      => 'error',
            'message'     => 'تم تجاوز الحد المسموح لمحاولات تسجيل الدخول. يرجى الانتظار.',
            'retry_after' => $retryAfter,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function getSQLiteDB(): ?\PDO
    {
        static $db = null;
        if ($db !== null) return $db;
        try {
            $dbPath = __DIR__ . '/../storage/rate_limit.sqlite';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $db = new \PDO('sqlite:' . $dbPath);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                key_name TEXT PRIMARY KEY,
                request_count INTEGER NOT NULL DEFAULT 1,
                expires_at INTEGER NOT NULL
            )");
            $db->exec("PRAGMA journal_mode=WAL");
            return $db;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
