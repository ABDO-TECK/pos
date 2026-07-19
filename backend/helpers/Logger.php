<?php

namespace App\Helpers;


/**
 * Logger — نظام تسجيل أحداث بمستويات متعددة.
 *
 * يكتب إلى ملفات يومية في مجلد logs/ مع دعم:
 * - مستويات: DEBUG, INFO, WARNING, ERROR, CRITICAL
 * - تدوير تلقائي للملفات (يحتفظ بآخر 30 يومًا)
 * - سياق إضافي (context array)
 * - تنسيق موحّد لكل سطر
 *
 * الاستخدام:
 *   Logger::info('عملية بيع جديدة', ['invoice_id' => 42]);
 *   Logger::error('فشل الاتصال بقاعدة البيانات', ['host' => 'localhost']);
 */
class Logger
{
    /** مستويات التسجيل */
    public const DEBUG    = 'DEBUG';
    public const INFO     = 'INFO';
    public const WARNING  = 'WARNING';
    public const ERROR    = 'ERROR';
    public const CRITICAL = 'CRITICAL';

    /** @var string مجلد الملفات */
    private static string $logDir = '';

    /** @var int عدد الأيام التي يُحتفظ بملفاتها */
    private static int $retainDays = 30;

    /** @var int الحد الأقصى لحجم ملف اللوج (5 ميجابايت) */
    private static int $maxFileSize = 5 * 1024 * 1024;

    /** @var string|null الحد الأدنى للتسجيل (null = تسجيل كل شيء) */
    private static ?string $minLevel = null;

    /** ترتيب المستويات (للمقارنة) */
    private const LEVEL_ORDER = [
        self::DEBUG    => 0,
        self::INFO     => 1,
        self::WARNING  => 2,
        self::ERROR    => 3,
        self::CRITICAL => 4,
    ];

    /**
     * تهيئة مجلد الملفات (يُستدعى تلقائيًا).
     */
    private static function init(): void
    {
        if (self::$logDir === '') {
            $logsPath = $_ENV['LOGS_PATH'] ?? getenv('LOGS_PATH');
            if (!$logsPath) {
                $storageDir = $_ENV['APP_STORAGE_DIR'] ?? getenv('APP_STORAGE_DIR');
                if ($storageDir) {
                    $logsPath = $storageDir . '/logs';
                } else {
                    $logsPath = __DIR__ . '/../logs';
                }
            }
            self::$logDir = $logsPath;
        }

        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }
    }

    /**
     * تعيين الحد الأدنى للتسجيل.
     * مثال: Logger::setMinLevel(Logger::WARNING) → يُسجَّل WARNING + ERROR + CRITICAL فقط.
     */
    public static function setMinLevel(string $level): void
    {
        self::$minLevel = $level;
    }

    /**
     * تسجيل رسالة بمستوى محدد.
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        // فحص الحد الأدنى
        if (self::$minLevel !== null) {
            $minOrder = self::LEVEL_ORDER[self::$minLevel] ?? 0;
            $curOrder = self::LEVEL_ORDER[$level] ?? 0;
            if ($curOrder < $minOrder) {
                return;
            }
        }

        self::init();

        $date     = date('Y-m-d');
        $time     = date('Y-m-d H:i:s');
        $filePath = self::resolveLogPath($date);

        // بناء السطر بتنسيق JSON (Structured Logging)
        $logData = [
            'timestamp' => $time,
            'level'     => $level,
            'message'   => $message,
            'context'   => $context
        ];
        $line = json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        // كتابة ذرية (مع قفل)
        @file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);

        // أيضًا إرسال إلى error_log للتوافق مع أدوات المراقبة
        if (in_array($level, [self::ERROR, self::CRITICAL], true)) {
            $contextStr = !empty($context) ? ' - Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            error_log("[POS][{$level}] {$message}{$contextStr}");
        }
    }

    // ── Shorthand methods ─────────────────────────────────────────

    public static function debug(string $message, array $context = []): void
    {
        self::log(self::DEBUG, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log(self::INFO, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log(self::WARNING, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log(self::ERROR, $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::log(self::CRITICAL, $message, $context);
    }

    // ── Maintenance ───────────────────────────────────────────────

    /**
     * تحديد مسار ملف اللوج مع تدوير تلقائي عند تجاوز الحد.
     * pos-2026-05-05.log → pos-2026-05-05.1.log → pos-2026-05-05.2.log ...
     */
    private static function resolveLogPath(string $date): string
    {
        $basePath = self::$logDir . "/pos-{$date}.log";

        // إذا كان الملف أصغر من الحد، استخدمه مباشرة
        if (!file_exists($basePath) || filesize($basePath) < self::$maxFileSize) {
            return $basePath;
        }

        // ابحث عن أعلى رقم تدوير موجود
        $rotationIndex = 1;
        while (file_exists(self::$logDir . "/pos-{$date}.{$rotationIndex}.log")) {
            $currentPath = self::$logDir . "/pos-{$date}.{$rotationIndex}.log";
            if (filesize($currentPath) < self::$maxFileSize) {
                return $currentPath;
            }
            $rotationIndex++;
        }

        return self::$logDir . "/pos-{$date}.{$rotationIndex}.log";
    }

    /**
     * حذف ملفات اللوج القديمة (أقدم من $retainDays يوم).
     * يمكن استدعاؤها دوريًا أو ضمن Migrations.
     */
    public static function cleanup(): int
    {
        self::init();
        $deleted = 0;
        $files   = array_merge(
            glob(self::$logDir . '/pos-*.log') ?: [],
            glob(self::$logDir . '/pos-*.*.log') ?: []
        );
        $cutoff  = time() - (self::$retainDays * 86400);

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
