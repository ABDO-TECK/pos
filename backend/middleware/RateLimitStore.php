<?php

namespace App\Middleware;

use App\Helpers\Logger;

/**
 * Shared SQLite storage for rate limiting.
 * Used by both RateLimiter and LoginRateLimiter to avoid code duplication.
 */
class RateLimitStore
{
    private static ?\PDO $db = null;

    /**
     * Get or create the shared SQLite connection for rate limiting.
     */
    public static function getDB(): ?\PDO
    {
        if (self::$db !== null) return self::$db;

        try {
            if (defined('PHPUNIT_TEST_SUITE')) {
                self::$db = new \PDO('sqlite::memory:');
                self::$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                self::$db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                    key_name TEXT PRIMARY KEY,
                    request_count INTEGER NOT NULL DEFAULT 1,
                    expires_at INTEGER NOT NULL
                )");
                return self::$db;
            }
            $storageDir = $_ENV['APP_STORAGE_DIR'] ?? (getenv('APP_STORAGE_DIR') ?: null) ?? (__DIR__ . '/../storage');
            $dbPath = $storageDir . '/rate_limit.sqlite';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            self::$db = new \PDO('sqlite:' . $dbPath);
            self::$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::$db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                key_name TEXT PRIMARY KEY,
                request_count INTEGER NOT NULL DEFAULT 1,
                expires_at INTEGER NOT NULL
            )");
            // WAL mode for better concurrent read/write performance
            self::$db->exec("PRAGMA journal_mode=WAL");
            return self::$db;
        } catch (\Throwable $e) {
            Logger::error('RateLimitStore: SQLite init failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Reset the connection (useful for testing).
     */
    public static function reset(): void
    {
        self::$db = null;
    }
}
