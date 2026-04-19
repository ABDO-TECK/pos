<?php

/**
 * Autoloader مخصص — يُحمّل الكلاسات تلقائيًا حسب اسم الملف.
 *
 * يبحث في المجلدات المسجلة عن ملف يطابق اسم الكلاس.
 * يُغني عن جميع أسطر require_once اليدوية في index.php.
 *
 * الاستخدام:
 *   require_once __DIR__ . '/core/Autoloader.php';
 *   Autoloader::register();
 */
class Autoloader
{
    /** @var list<string> قائمة المجلدات للبحث فيها */
    private static array $directories = [];

    /** @var array<string, string> كاش مسارات الكلاسات المكتشفة */
    private static array $cache = [];

    /**
     * تسجيل الـ autoloader ومجلدات البحث الافتراضية.
     */
    public static function register(): void
    {
        $base = __DIR__ . '/..';

        self::$directories = [
            realpath($base . '/config')      ?: $base . '/config',
            realpath($base . '/core')         ?: $base . '/core',
            realpath($base . '/controllers')  ?: $base . '/controllers',
            realpath($base . '/models')       ?: $base . '/models',
            realpath($base . '/middleware')    ?: $base . '/middleware',
            realpath($base . '/helpers')      ?: $base . '/helpers',
            realpath($base . '/services')     ?: $base . '/services',
        ];

        spl_autoload_register([self::class, 'loadClass']);
    }

    /**
     * إضافة مجلد إضافي للبحث.
     */
    public static function addDirectory(string $path): void
    {
        $real = realpath($path);
        if ($real !== false && !in_array($real, self::$directories, true)) {
            self::$directories[] = $real;
        }
    }

    /**
     * محاولة تحميل كلاس.
     */
    public static function loadClass(string $className): void
    {
        // إذا سبق اكتشاف المسار
        if (isset(self::$cache[$className])) {
            require_once self::$cache[$className];
            return;
        }

        // اسم الملف = اسم الكلاس (بدون namespace)
        $baseName = $className;
        if (str_contains($className, '\\')) {
            $parts    = explode('\\', $className);
            $baseName = end($parts);
        }

        $fileName = $baseName . '.php';

        foreach (self::$directories as $dir) {
            $filePath = $dir . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($filePath)) {
                self::$cache[$className] = $filePath;
                require_once $filePath;
                return;
            }
        }
    }
}
