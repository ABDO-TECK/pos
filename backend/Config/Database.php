<?php

namespace App\Config;

use App\Helpers\Logger;
use PDO;
use PDOException;


class Database {
    private static ?PDO $instance = null;
    private static ?PDO $migrationInstance = null;

    /** عدد محاولات إعادة الاتصال */
    private static int $maxRetries = 3;

    /** الانتظار بين المحاولات (بالثواني) */
    private static int $retryDelay = 1;

    /** عدد محاولات إعادة الاتصال التلقائي عند فشل استعلام */
    private static int $reconnectAttempts = 0;

    private function __construct() {}

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }

        return self::$instance;
    }

    public static function getMigrationConnection(): PDO {
        if (self::$migrationInstance === null) {
            self::$migrationInstance = self::createMigrationConnection();
        }

        return self::$migrationInstance;
    }

    /**
     * إعادة الاتصال تلقائياً عند فشل استعلام بسبب انقطاع الاتصال.
     * تُستدعى من الكود الذي يلتقط PDOException أثناء تنفيذ استعلام.
     *
     * مثال الاستخدام:
     *   try {
     *       $db->query('...');
     *   } catch (PDOException $e) {
     *       if (Database::isConnectionLost($e)) {
     *           $db = Database::reconnect();
     *           $db->query('...');  // إعادة المحاولة
     *       } else {
     *           throw $e;
     *       }
     *   }
     *
     * @return PDO اتصال جديد
     * @throws PDOException إذا فشلت إعادة الاتصال
     */
    public static function reconnect(): PDO
    {
        Logger::warning('Database connection lost, reconnecting...');
        self::$instance = null;
        self::$reconnectAttempts++;

        // حماية من حلقة لا نهائية: أقصى 2 محاولة إعادة اتصال لكل طلب
        if (self::$reconnectAttempts > 2) {
            Logger::critical('Too many reconnect attempts, aborting.');
            throw new PDOException('Database reconnect limit exceeded');
        }

        self::$instance = self::createConnection();
        return self::$instance;
    }

    /**
     * فحص هل الخطأ يعني انقطاع الاتصال بقاعدة البيانات.
     *
     * @param PDOException $e الاستثناء المُلتقط
     * @return bool true إذا كان الخطأ بسبب انقطاع الاتصال
     */
    public static function isConnectionLost(PDOException $e): bool
    {
        $lostCodes = [
            2006,  // MySQL server has gone away
            2013,  // Lost connection to MySQL server during query
            2003,  // Can't connect to MySQL server
            2002,  // Connection refused
        ];

        $code = (int) ($e->errorInfo[1] ?? 0);
        if (in_array($code, $lostCodes, true)) {
            return true;
        }

        // فحص نص الرسالة كخط دفاع أخير
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'server has gone away')
            || str_contains($msg, 'lost connection')
            || str_contains($msg, 'connection refused')
            || str_contains($msg, 'broken pipe');
    }

    /**
     * إنشاء اتصال جديد مع إعادة المحاولة.
     */
    private static function createConnection(): PDO {
        $port = defined('DB_PORT') ? DB_PORT : '3306';
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $maxRetries = PHP_SAPI === 'cli' ? self::$maxRetries : 1;
        $connectionTimeout = PHP_SAPI === 'cli' ? 5 : 2;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => defined('DB_PERSISTENT') && DB_PERSISTENT,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,
            PDO::ATTR_TIMEOUT            => $connectionTimeout,
        ];

        $lastError = null;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                // تعيين timeout للجلسة
                $pdo->exec("SET SESSION wait_timeout = 3600");
                $pdo->exec("SET SESSION interactive_timeout = 3600");

                if ($attempt > 1) {
                    Logger::info("Database connected after {$attempt} attempts");
                }
                return $pdo;
            } catch (PDOException $e) {
                $lastError = $e;
                Logger::warning("Database connection attempt {$attempt}/{$maxRetries} failed", [
                    'exception' => get_class($e),
                    'code' => (int) $e->getCode(),
                ]);
                if ($attempt < $maxRetries) {
                    sleep(self::$retryDelay);
                }
            }
        }

        // فشل الاتصال بعد كل المحاولات
        Logger::critical('Database connection failed after all retries', [
            'exception' => $lastError ? get_class($lastError) : PDOException::class,
            'code' => $lastError ? (int) $lastError->getCode() : 0,
        ]);
        throw $lastError;
    }

    /** بعد استعادة النسخة الاحتياطية يُفضّل إعادة الاتصال */
    public static function resetInstance(): void {
        self::$instance = null;
        self::$migrationInstance = null;
    }

    public static function resetMigrationInstance(): void {
        self::$migrationInstance = null;
    }

    /**
     * إنشاء اتصال مخصص للمهاجرات (DDL/Schema Migrations) باستخدام حساب DB_MIGRATION_USER.
     */
    private static function createMigrationConnection(): PDO {
        $host = defined('DB_MIGRATION_HOST') && DB_MIGRATION_HOST !== ''
            ? DB_MIGRATION_HOST
            : (defined('DB_HOST') ? DB_HOST : 'localhost');
        $port = defined('DB_MIGRATION_PORT') && DB_MIGRATION_PORT !== ''
            ? DB_MIGRATION_PORT
            : (defined('DB_PORT') ? DB_PORT : '3306');
        $name = defined('DB_MIGRATION_NAME') && DB_MIGRATION_NAME !== ''
            ? DB_MIGRATION_NAME
            : (defined('DB_NAME') ? DB_NAME : 'pos_db');
        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=' . $charset;

        $user = defined('DB_MIGRATION_USER') ? trim((string) DB_MIGRATION_USER) : '';
        $pass = defined('DB_MIGRATION_PASS') ? (string) DB_MIGRATION_PASS : '';
        $appEnv = defined('APP_ENV') ? strtolower(trim((string) APP_ENV)) : 'development';
        $isProduction = $appEnv === 'production';
        $isPackaged = (bool) \Phar::running(false);

        if ($user === '') {
            if ($isProduction || $isPackaged) {
                throw new \RuntimeException(
                    'Database migration credentials (DB_MIGRATION_USER) must be explicitly configured in production or packaged environments.'
                );
            }
            Logger::notice('DB_MIGRATION_USER is not set; falling back to DB_USER in development/test environment.');
            $user = defined('DB_USER') ? DB_USER : 'root';
            $pass = defined('DB_PASS') ? DB_PASS : '';
        }

        $maxRetries = PHP_SAPI === 'cli' ? self::$maxRetries : 1;
        $connectionTimeout = PHP_SAPI === 'cli' ? 5 : 2;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,
            PDO::ATTR_TIMEOUT            => $connectionTimeout,
        ];

        $lastError = null;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $pdo = new PDO($dsn, $user, $pass, $options);
                $pdo->exec("SET SESSION wait_timeout = 3600");
                $pdo->exec("SET SESSION interactive_timeout = 3600");

                if ($attempt > 1) {
                    Logger::info("Migration database connected after {$attempt} attempts");
                }
                return $pdo;
            } catch (PDOException $e) {
                $lastError = $e;
                Logger::warning("Migration database connection attempt {$attempt}/{$maxRetries} failed", [
                    'exception' => get_class($e),
                    'code' => (int) $e->getCode(),
                ]);
                if ($attempt < $maxRetries) {
                    sleep(self::$retryDelay);
                }
            }
        }

        Logger::critical('Migration database connection failed after all retries', [
            'exception' => $lastError ? get_class($lastError) : PDOException::class,
            'code' => $lastError ? (int) $lastError->getCode() : 0,
        ]);
        throw $lastError;
    }
}
