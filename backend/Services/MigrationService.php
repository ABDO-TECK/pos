<?php

namespace App\Services;

use App\Config\Database;
use App\Helpers\Logger;
use PDO;
use PDOException;


class MigrationService {

    private const MIGRATION_LOCK_NAME = 'pos_schema_migrations';
    private const MIGRATION_LOCK_TIMEOUT_SECONDS = 60;

    private PDO $db;
    private string $migrationsPath;
    private string $flagFile;

    public function __construct() {
        $this->db = Database::getInstance();
        $pharRunning = \Phar::running(false);
        $storageDir = $_ENV['APP_STORAGE_DIR'] ?? (getenv('APP_STORAGE_DIR') ?: null) ?? ($pharRunning ? dirname($pharRunning) . '/storage' : __DIR__ . '/../storage');
        $this->flagFile = rtrim($storageDir, '/\\') . '/migrations_hash.flag';

        if ($pharRunning) {
            $this->migrationsPath = 'phar://' . str_replace('\\', '/', $pharRunning) . '/database/migrations/';
        } else {
            $this->migrationsPath = realpath(__DIR__ . '/../../database/migrations/') . DIRECTORY_SEPARATOR;
        }
    }

    /**
     * تشغيل جميع المهاجرات التي لم يتم تشغيلها بعد.
     * @return array يحتوي على عدد المهاجرات المنفذة وأي أخطاء حدثت.
     */
    public function runAllMigrations(bool $force = false): array {
        if (!is_dir($this->migrationsPath)) {
            return [
                'executed' => 0,
                'errors' => ["Migrations directory not found: {$this->migrationsPath}"],
                'skipped' => false,
            ];
        }

        if (!$this->acquireMigrationLock()) {
            return [
                'executed' => 0,
                'errors' => ['Timed out waiting for the database migration lock.'],
                'skipped' => false,
            ];
        }

        try {
            return $this->runPendingMigrations($force);
        } finally {
            $this->releaseMigrationLock();
        }
    }

    private function runPendingMigrations(bool $force): array {

        // ── Smart skip: لا حاجة للتنفيذ إذا لم تتغير الملفات ──
        if (!$force && $this->isUpToDate()) {
            return ['executed' => 0, 'errors' => [], 'skipped' => true];
        }

        $this->createMigrationsTableIfNotExists();

        $files = scandir($this->migrationsPath);
        $migrations = [];
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                $migrations[] = $file;
            }
        }
        sort($migrations); // ترتيب تصاعدي لضمان التنفيذ التسلسلي

        $executed = 0;
        $errors = [];

        foreach ($migrations as $migration) {
            if (!$this->hasMigrationRun($migration)) {
                $success = $this->executeMigration($migration);
                if ($success) {
                    $this->recordMigration($migration);
                    $executed++;
                } else {
                    $errors[] = "Failed to execute migration: $migration";
                    break; // التوقف عند أول خطأ لضمان عدم تداخل التحديثات
                }
            }
        }

        if (empty($errors)) {
            $this->updateFlag();
        }

        return [
            'executed' => $executed,
            'errors' => $errors,
            'skipped' => false
        ];
    }

    private function acquireMigrationLock(): bool {
        $stmt = $this->db->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds)');
        $stmt->bindValue(':lock_name', self::MIGRATION_LOCK_NAME, PDO::PARAM_STR);
        $stmt->bindValue(':timeout_seconds', self::MIGRATION_LOCK_TIMEOUT_SECONDS, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    private function releaseMigrationLock(): void {
        try {
            $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $stmt->execute(['lock_name' => self::MIGRATION_LOCK_NAME]);
        } catch (\Throwable $e) {
            Logger::warning('Failed to release the database migration lock', [
                'reference' => bin2hex(random_bytes(8)),
                'exception' => get_class($e),
            ]);
        }
    }

    // ── Flag-based smart skip ─────────────────────────────────

    private function isUpToDate(): bool {
        if (!is_file($this->flagFile)) {
            return false;
        }

        $currentHash = $this->computeHash();
        $savedHash   = @file_get_contents($this->flagFile);

        return $savedHash !== false && trim($savedHash) === $currentHash;
    }

    private function updateFlag(): void {
        $dir = dirname($this->flagFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($this->flagFile, $this->computeHash(), LOCK_EX);
    }

    private function computeHash(): string {
        if (!is_dir($this->migrationsPath)) {
            return md5('');
        }

        $files = scandir($this->migrationsPath);
        if ($files === false) {
            return md5('');
        }

        $migrations = [];
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                $migrations[] = $file;
            }
        }
        sort($migrations);

        $fingerprint = '';
        foreach ($migrations as $file) {
            $fullPath = $this->migrationsPath . $file;
            $fingerprint .= $file . ':' . filesize($fullPath) . ':' . filemtime($fullPath) . ';';
        }

        return md5($fingerprint);
    }

    private function createMigrationsTableIfNotExists(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS schema_versions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                version VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    private function hasMigrationRun(string $version): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM schema_versions WHERE version = ?");
        $stmt->execute([$version]);
        return (bool) $stmt->fetchColumn();
    }

    private function recordMigration(string $version): void {
        $stmt = $this->db->prepare("INSERT INTO schema_versions (version) VALUES (?)");
        $stmt->execute([$version]);
    }

    private function executeMigration(string $file): bool {
        $path = $this->migrationsPath . $file;
        $content = file_get_contents($path);
        if (empty(trim($content))) return true; // ملف فارغ، يعتبر منفذ بنجاح

        // ── تقسيم الملف إلى أوامر فردية ──
        // بدلاً من إرسال كل الأوامر دفعة واحدة (والتوقف عند أول خطأ)،
        // ننفّذ كل أمر على حدة حتى لا تمنع أخطاء "عمود/جدول موجود"
        // بقية الأوامر المهمة من التنفيذ.
        $statements = $this->splitSqlStatements($content);

        foreach ($statements as $sql) {
            $sql = trim($sql);
            if ($sql === '') continue;

            try {
                $this->db->exec($sql);
            } catch (PDOException $e) {
                $errorInfo = $e->errorInfo ?? [];
                $errno = $errorInfo[1] ?? $e->getCode();

                // Ignore only explicit duplicate-object errors and privilege
                // errors for optional features (EVENT scheduler, GLOBAL vars).
                // Error 1005 is intentionally not ignored because it also
                // represents broken foreign keys and other real schema failures.
                $ignorable = [1060, 1061, 1050, 1068, 1826, 1227, 1044];
                if (in_array((int) $errno, $ignorable, true)) {
                    // خطأ متوقع ومتجاهل — نستمر بالأمر التالي
                    continue;
                }

                Logger::error("Migration failed: $file", [
                    'reference' => bin2hex(random_bytes(8)),
                    'exception' => get_class($e),
                    'code' => (int) $e->getCode(),
                ]);
                return false;
            }
        }

        Database::resetInstance();
        return true;
    }

    /**
     * تقسيم محتوى SQL إلى أوامر فردية بتقسيم على الفاصلة المنقوطة.
     * يتجاهل الأسطر الفارغة والتعليقات.
     *
     * @return string[]
     */
    private function splitSqlStatements(string $content): array {
        // إزالة التعليقات السطرية (-- ...)
        $content = preg_replace('/--.*$/m', '', $content);

        // تقسيم على الفاصلة المنقوطة
        $parts = explode(';', $content);

        $statements = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $statements[] = $part;
            }
        }

        return $statements;
    }
}
