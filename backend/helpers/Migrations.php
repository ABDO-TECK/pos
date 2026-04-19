<?php

/**
 * Lightweight modular migration runner.
 * Each migration is now stored in backend/database/migrations/*.php
 *
 * تحسين الأداء: يستخدم ملف flag (بصمة hash) لتجنب فحص قاعدة البيانات
 * عند كل طلب HTTP. يُعاد الفحص فقط عند:
 *   - تغيير ملفات الترحيل (إضافة/تعديل/حذف)
 *   - حذف ملف الـ flag يدوياً
 *   - أول تشغيل بعد تثبيت النظام
 */
class Migrations {

    private PDO $db;
    private array $appliedCache = [];
    private bool $cacheLoaded = false;

    /** @var string مسار ملف البصمة */
    private string $flagFile;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->flagFile = __DIR__ . '/../storage/migrations_hash.flag';
        $this->bootstrap();
    }

    private function bootstrap(): void {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name       VARCHAR(200) NOT NULL UNIQUE,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function applied(string $name): bool {
        if (!$this->cacheLoaded) {
            try {
                $stmt = $this->db->query('SELECT name FROM migrations');
                if ($stmt) {
                    $this->appliedCache = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }
            } catch (Throwable $e) {
                // Fallback if table doesn't exist yet
            }
            $this->cacheLoaded = true;
        }
        return in_array($name, $this->appliedCache, true);
    }

    private function mark(string $name): void {
        $this->db->prepare('INSERT IGNORE INTO migrations (name) VALUES (?)')->execute([$name]);
        $this->appliedCache[] = $name; // Sync cache
    }

    public function run(): void {
        $migrationsDir = __DIR__ . '/../database/migrations';
        if (!is_dir($migrationsDir)) return;

        // ── Smart skip: لا حاجة لفحص DB إذا لم تتغير ملفات الترحيل ──
        if ($this->isUpToDate($migrationsDir)) {
            // حتى لو لم تتغير الملفات، نشغّل cleanups بنسبة ضئيلة
            $this->runCleanups();
            return;
        }

        $files = glob($migrationsDir . '/*.php');
        sort($files);

        $migrationsApplied = 0;
        foreach ($files as $file) {
            $name = basename($file, '.php');

            if (!$this->applied($name)) {
                $migrationObject = require $file;
                
                if (is_callable($migrationObject)) {
                    $migrationObject($this->db);
                } elseif (is_object($migrationObject) && method_exists($migrationObject, 'up')) {
                    $migrationObject->up($this->db);
                }

                $this->mark($name);
                $migrationsApplied++;
            }
        }

        // تحديث ملف البصمة بعد التطبيق الناجح
        $this->updateFlag($migrationsDir);

        if ($migrationsApplied > 0) {
            Logger::info("Migrations applied: {$migrationsApplied}");
        }

        $this->runCleanups();
    }

    // ── Flag-based smart skip ─────────────────────────────────

    /**
     * هل الترحيلات محدّثة؟ يُقارن hash ملفات الترحيل مع آخر hash مُسجَّل.
     */
    private function isUpToDate(string $migrationsDir): bool
    {
        if (!is_file($this->flagFile)) {
            return false;
        }

        $currentHash = $this->computeHash($migrationsDir);
        $savedHash   = @file_get_contents($this->flagFile);

        return $savedHash !== false && trim($savedHash) === $currentHash;
    }

    /**
     * حفظ بصمة الملفات الحالية.
     */
    private function updateFlag(string $migrationsDir): void
    {
        $dir = dirname($this->flagFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($this->flagFile, $this->computeHash($migrationsDir), LOCK_EX);
    }

    /**
     * حساب hash مبني على أسماء الملفات + أحجامها + تواريخ التعديل.
     * أسرع من md5_file لكل ملف، ويكشف الإضافات والحذف والتعديل.
     */
    private function computeHash(string $migrationsDir): string
    {
        $files = glob($migrationsDir . '/*.php') ?: [];
        sort($files);

        $fingerprint = '';
        foreach ($files as $file) {
            $fingerprint .= basename($file) . ':' . filesize($file) . ':' . filemtime($file) . ';';
        }

        return md5($fingerprint);
    }

    // ── Periodic cleanups ─────────────────────────────────────

    private function runCleanups(): void {
        try {
            if (mt_rand(1, 100) === 1) {
                $this->db->exec('DELETE FROM tokens WHERE expires_at IS NOT NULL AND expires_at < NOW()');
            }
        } catch (Throwable) {}

        try {
            if (mt_rand(1, 200) === 1) {
                if (class_exists('Logger')) Logger::cleanup();
                if (class_exists('RateLimiter')) (new RateLimiter())->cleanup();
            }
        } catch (Throwable) {}
    }
}
