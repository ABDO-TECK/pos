<?php

namespace App\Config;

use App\Helpers\Logger;
use PDO;
use PDOException;


class Database {
    private static ?PDO $instance = null;

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

        $code = (int) $e->errorInfo[1] ?? 0;
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
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => defined('DB_PERSISTENT') && DB_PERSISTENT,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,
            PDO::ATTR_TIMEOUT            => 5,
        ];

        $lastError = null;
        for ($attempt = 1; $attempt <= self::$maxRetries; $attempt++) {
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
                Logger::warning("Database connection attempt {$attempt}/" . self::$maxRetries . " failed", [
                    'error' => $e->getMessage()
                ]);
                if ($attempt < self::$maxRetries) {
                    sleep(self::$retryDelay);
                }
            }
        }

        // فشل الاتصال بعد كل المحاولات
        Logger::critical('Database connection failed after all retries', [
            'error' => $lastError?->getMessage()
        ]);
        throw $lastError;
    }

    /** بعد استعادة النسخة الاحتياطية يُفضّل إعادة الاتصال */
    public static function resetInstance(): void {
        self::$instance = null;
    }
}
