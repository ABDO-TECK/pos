<?php

/**
 * Simple file-based cache for read-heavy endpoints.
 */
class Cache {
    private static string $dir = __DIR__ . '/../storage/cache/';

    public static function init(): void {
        if (!is_dir(self::$dir)) {
            mkdir(self::$dir, 0755, true);
        }
    }

    public static function get(string $key): mixed {
        self::init();
        $file = self::path($key);
        if (!file_exists($file)) return null;

        $data = unserialize(file_get_contents($file));
        if ($data['expires'] < time()) {
            unlink($file);
            return null;
        }
        return $data['value'];
    }

    public static function set(string $key, mixed $value, int $ttl = 60): void {
        self::init();
        file_put_contents(self::path($key), serialize([
            'value'   => $value,
            'expires' => time() + $ttl,
        ]));
    }

    public static function forget(string $key): void {
        $file = self::path($key);
        if (file_exists($file)) unlink($file);
    }

    public static function flush(): void {
        self::init();
        array_map('unlink', glob(self::$dir . '*.cache'));
    }

    private static function path(string $key): string {
        return self::$dir . md5($key) . '.cache';
    }
}
