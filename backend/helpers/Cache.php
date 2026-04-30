<?php

namespace App\Helpers;


/**
 * Simple file-based cache for read-heavy endpoints.
 *
 * يستخدم JSON بدلاً من serialize/unserialize لتفادي ثغرات
 * PHP Object Injection الأمنية.
 */
class Cache {
    private static string $dir = __DIR__ . '/../storage/cache/';

    public static function init(): void {
        if (!function_exists('apcu_fetch') && !is_dir(self::$dir)) {
            @mkdir(self::$dir, 0755, true);
        }
    }

    public static function get(string $key): mixed {
        if (function_exists('apcu_fetch')) {
            $success = false;
            $data = apcu_fetch('pos_cache_' . $key, $success);
            if ($success) return $data;
        }

        self::init();
        $file = self::path($key);
        if (!file_exists($file)) return null;

        $content = @file_get_contents($file);
        if ($content === false) return null;

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['expires'], $data['value'])) {
            // ملف كاش تالف — حذفه
            @unlink($file);
            return null;
        }

        if ($data['expires'] < time()) {
            @unlink($file);
            return null;
        }

        return $data['value'];
    }

    public static function set(string $key, mixed $value, int $ttl = 60): void {
        if (function_exists('apcu_store')) {
            apcu_store('pos_cache_' . $key, $value, $ttl);
            return;
        }

        self::init();
        $payload = json_encode([
            'value'   => $value,
            'expires' => time() + $ttl,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        @file_put_contents(self::path($key), $payload, LOCK_EX);
    }

    public static function forget(string $key): void {
        if (function_exists('apcu_delete')) {
            apcu_delete('pos_cache_' . $key);
        }
        $file = self::path($key);
        if (file_exists($file)) @unlink($file);
    }

    public static function flush(): void {
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
        self::init();
        array_map('unlink', glob(self::$dir . '*.cache') ?: []);
    }

    private static function path(string $key): string {
        return self::$dir . md5($key) . '.cache';
    }
}

