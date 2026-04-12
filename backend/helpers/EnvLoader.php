<?php

/**
 * محمّل ملفات البيئة (.env) — خفيف ولا يعتمد على مكتبات خارجية.
 *
 * يقرأ ملف .env ويحقن المتغيرات في $_ENV و getenv().
 * يتجاهل الأسطر الفارغة والتعليقات (#).
 * يدعم القيم بين علامات اقتباس مزدوجة أو مفردة.
 *
 * الاستخدام:
 *   EnvLoader::load(__DIR__ . '/../.env');
 *   $val = EnvLoader::get('DB_HOST', 'localhost');
 */
class EnvLoader
{
    /** @var array<string, string> القيم المحمّلة */
    private static array $values = [];

    /** @var bool هل جرى التحميل مسبقاً */
    private static bool $loaded = false;

    /**
     * تحميل ملف .env.
     * إذا كان الملف غير موجود يتم تجاهله بصمت (مناسب للإنتاج).
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (!is_file($path) || !is_readable($path)) {
            self::$loaded = true;
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            self::$loaded = true;
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // تجاهل التعليقات والأسطر الفارغة
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // يجب أن يحتوي على =
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            // إزالة علامات الاقتباس المحيطة
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // تحويل القيم الخاصة
            $value = match (strtolower($value)) {
                'true'   => '1',
                'false'  => '0',
                'null'   => '',
                '(empty)' => '',
                default  => $value,
            };

            self::$values[$key] = $value;

            // تعيين في البيئة (لا نتجاوز متغيرات النظام الموجودة)
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
            }
        }

        self::$loaded = true;
    }

    /**
     * قراءة قيمة من البيئة — يبحث بالترتيب: $_ENV → getenv() → القيم المحمّلة → الافتراضي.
     */
    public static function get(string $key, string $default = ''): string
    {
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }

        return self::$values[$key] ?? $default;
    }

    /**
     * قراءة كرقم صحيح.
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $val = self::get($key, '');
        return $val !== '' ? (int) $val : $default;
    }

    /**
     * قراءة كعدد عشري.
     */
    public static function getFloat(string $key, float $default = 0.0): float
    {
        $val = self::get($key, '');
        return $val !== '' ? (float) $val : $default;
    }

    /**
     * قراءة كقيمة منطقية.
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $val = self::get($key, '');
        if ($val === '') {
            return $default;
        }
        return in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true);
    }
}
