<?php

namespace App\Services;

use App\Config\Database;
use App\Core\Container;
use App\Services\MigrationService;
use mysqli;
use App\Contracts\BackupServiceInterface;
use App\Helpers\Logger;
use PDO;
use RuntimeException;


class BackupService implements BackupServiceInterface {
    private ?PDO $db = null;

    public function __construct() {
    }

    public function setDb(PDO $db): void {
        $this->db = $db;
    }

    private function getDb(): PDO {
        if ($this->db === null) {
            $this->db = Database::getInstance();
        }
        return $this->db;
    }

    /**
     * Create a backup file in the specified directory.
     * Returns the full path to the backup file.
     */
    public function createBackupFile(string $backupDir): string {
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }
        $filename = rtrim($backupDir, '/\\') . '/pre_update_' . date('Y-m-d_H-i-s') . '.sql';
        $this->generateBackupSqlToFile($filename);

        // Keep only last 10 backups
        $files = glob(rtrim($backupDir, '/\\') . '/pre_update_*.sql') ?: [];
        rsort($files);
        foreach (array_slice($files, 10) as $old) {
            @unlink($old);
        }

        return $filename;
    }

    /**
     * تدفق (Stream) النسخة الاحتياطية لقاعدة البيانات مباشرة للعميل للتحميل.
     * يولد أمر التصدير ويطبعه فوراً لتقليل استهلاك الذاكرة.
     *
     * @return void
     */
    public function streamBackup(): void {
        $tables = $this->getDb()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        echo "-- POS Database Backup\n";
        echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        echo "-- POS System Database Backup\n\n";
        echo "SET FOREIGN_KEY_CHECKS=0;\n\n";
        flush();

        foreach ($tables as $table) {
            // Validate table name to prevent SQL injection from a compromised DB
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                echo "-- SKIPPED suspicious table name: " . addslashes($table) . "\n";
                flush();
                continue;
            }
            // Table structure
            $createStmt = $this->getDb()->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $ddl = $createStmt['Create Table'] ?? null;
            if ($ddl === null && is_array($createStmt)) {
                $vals = array_values($createStmt);
                $ddl  = $vals[1] ?? '';
            }
            if (!$ddl) {
                continue;
            }

            echo "-- Table: $table\n";
            echo "DROP TABLE IF EXISTS `$table`;\n";
            echo $ddl . ";\n\n";
            flush();

            // Table data (Streamed row by row)
            $stmt = $this->getDb()->query("SELECT * FROM `$table`");
            $firstRow = true;
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($firstRow) {
                    $columns = '`' . implode('`, `', array_keys($row)) . '`';
                    echo "INSERT INTO `$table` ($columns) VALUES\n";
                    $firstRow = false;
                } else {
                    echo ",\n";
                }

                $escaped = array_map(function ($v) {
                    return $v === null ? 'NULL' : $this->getDb()->quote((string)$v);
                }, array_values($row));
                
                echo '(' . implode(', ', $escaped) . ')';
            }
            
            if (!$firstRow) {
                echo ";\n\n";
            }
            flush();
        }

        echo "SET FOREIGN_KEY_CHECKS=1;\n";
        flush();
    }

    // ── Restore ─────────────────────────────────────────────

    /**
     * التحقق من صحة ملف SQL المرفوع.
     *
     * @param array $file   مصفوفة $_FILES['sql_file']
     * @return array ['ok' => true, 'content' => string] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function validateUploadedSqlFile(array $file): array
    {
        if (empty($file) || (int) ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'لم يتم رفع الملف أو فشل الرفع', 'code' => 400];
        }

        $name = (string) ($file['name'] ?? '');
        if (!str_ends_with(strtolower($name), '.sql')) {
            return ['ok' => false, 'error' => 'يجب أن يكون الملف بصيغة .sql', 'code' => 400];
        }

        $maxBytes = 50 * 1024 * 1024; // 50 MB
        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            return ['ok' => false, 'error' => 'حجم الملف يتجاوز الحد المسموح (50 ميجابايت)', 'code' => 400];
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false || strlen($content) < 30) {
            return ['ok' => false, 'error' => 'الملف فارغ أو غير قابل للقراءة', 'code' => 400];
        }

        // إزالة BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // تنظيف UNSIGNED لمنع خطأ Foreign Key errno 150
        $content = preg_replace('/\b((?:INT|BIGINT|TINYINT|SMALLINT|MEDIUMINT)(?:\s*\([\d]+\))?)\s+UNSIGNED\b/i', '$1', $content);

        if (!preg_match('/\b(DROP\s+TABLE|CREATE\s+TABLE|INSERT\s+INTO)\b/is', $content)) {
            return ['ok' => false, 'error' => 'محتوى الملف لا يبدو ملف SQL صالحاً لقاعدة البيانات', 'code' => 400];
        }

        // منع أوامر خطرة — قائمة موسعة
        // Strip SQL comments FIRST to prevent bypass via DR/**/OP or DR--\nOP
        $stripped = preg_replace('/\/\*.*?\*\//s', ' ', $content);   // block comments
        $stripped = preg_replace('/--[^\n]*/', ' ', $stripped);       // line comments

        $dangerousPatterns = [
            '/\b(OUTFILE|DUMPFILE|LOAD_FILE|INTO\s+OUTFILE)\b/is',
            '/\b(GRANT|REVOKE|CREATE\s+USER|ALTER\s+USER|DROP\s+USER)\b/is',
            '/\b(LOAD\s+DATA|SOURCE)\b/is',
            '/\b(SLEEP|BENCHMARK|GET_LOCK)\b/is',
            '/\b(DROP\s+DATABASE)\b/is',
            '/\b(SYSTEM|SHUTDOWN)\b/is',
        ];
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $stripped)) {
                return ['ok' => false, 'error' => 'الملف يحتوي على أوامر غير مسموحة', 'code' => 400];
            }
        }

        return ['ok' => true, 'content' => $content];
    }

    /**
     * تنفيذ استعادة قاعدة البيانات من محتوى SQL + إعادة تشغيل الترحيلات.
     *
     * @param string $sqlContent  محتوى SQL المُنظّف
     * @return array ['ok' => true, 'message' => string] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function restoreFromSql(string $sqlContent): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('SQL restore is CLI-only');
        }

        // ── استخدام mysqli بدلاً من PDO ──
        // السبب: PDO لا يدعم multi_query() اللازمة لتنفيذ ملف SQL كامل
        $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($mysqli->connect_errno) {
            $reference = bin2hex(random_bytes(8));
            Logger::error('Backup restore database connection failed', [
                'reference' => $reference,
                'code' => $mysqli->connect_errno,
            ]);
            return ['ok' => false, 'error' => "Database restore failed. Reference: {$reference}", 'code' => 500];
        }
        $mysqli->set_charset('utf8mb4');

        // Relax strict mode only where needed — keep basic safety checks active
        $mysqli->query("SET sql_mode='NO_ENGINE_SUBSTITUTION'");
        $mysqli->query("SET FOREIGN_KEY_CHECKS=0");

        if (!$mysqli->multi_query($sqlContent)) {
            $reference = bin2hex(random_bytes(8));
            Logger::error('Backup restore execution failed', [
                'reference' => $reference,
                'code' => $mysqli->errno,
            ]);
            $mysqli->close();
            return ['ok' => false, 'error' => "Database restore failed. Reference: {$reference}", 'code' => 500];
        }

        do {
            if ($res = $mysqli->store_result()) {
                $res->free();
            }
            if (!$mysqli->more_results()) {
                break;
            }
            if (!$mysqli->next_result()) {
                $reference = bin2hex(random_bytes(8));
                Logger::error('Backup restore result processing failed', [
                    'reference' => $reference,
                    'code' => $mysqli->errno,
                ]);
                $mysqli->close();
                return ['ok' => false, 'error' => "Database restore failed. Reference: {$reference}", 'code' => 500];
            }
        } while (true);

        $mysqli->close();
        Database::resetInstance();

        // ── الاستعادة الذكية: ترقية النسخة القديمة ──
        $freshDb = Database::getInstance();
        try {
            $freshDb->exec('DELETE FROM schema_versions');
        } catch (\Throwable $e) {
            // الجدول قد لا يكون موجوداً
        }

        // حذف flag الـ Smart Skip
        $pharRunning = \Phar::running(false);
        $storageDir = $_ENV['APP_STORAGE_DIR'] ?? (getenv('APP_STORAGE_DIR') ?: null) ?? ($pharRunning ? dirname($pharRunning) . '/storage' : __DIR__ . '/../storage');
        $flagFile = rtrim($storageDir, '/\\') . '/migrations_hash.flag';
        if ($flagFile && is_file($flagFile)) {
            @unlink($flagFile);
        }

        // تشغيل جميع الهجرات
        Database::resetInstance();
        $container = new Container();
        $migrationService = $container->get(MigrationService::class);
        $migrationResult = $migrationService->runAllMigrations(true);

        $msg = 'تمت استعادة قاعدة البيانات بنجاح';
        if ($migrationResult['executed'] > 0) {
            $msg .= '، وتمت ترقيتها للإصدار الحديث (' . $migrationResult['executed'] . ' تحديثات).';
        }
        if (!empty($migrationResult['errors'])) {
            $msg .= ' ولكن حدثت بعض الأخطاء أثناء الترقية التلقائية.';
        }

        return ['ok' => true, 'message' => $msg];
    }

    /**
     * Generate backup SQL directly to a file using streaming (row-by-row).
     * This avoids loading the entire database into memory.
     */
    private function generateBackupSqlToFile(string $filePath): void {
        $fh = fopen($filePath, 'w');
        if ($fh === false) {
            throw new RuntimeException("Cannot open backup file for writing: $filePath");
        }

        try {
            $tables = $this->getDb()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

            fwrite($fh, "-- POS Auto-Update Backup\n");
            fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                // Validate table name to prevent SQL injection from a compromised DB
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                    fwrite($fh, "-- SKIPPED suspicious table name: " . addslashes($table) . "\n");
                    continue;
                }
                $createStmt = $this->getDb()->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                $ddl = $createStmt['Create Table'] ?? null;
                if ($ddl === null && is_array($createStmt)) {
                    $vals = array_values($createStmt);
                    $ddl  = $vals[1] ?? '';
                }
                if (!$ddl) {
                    throw new RuntimeException("تعذر قراءة هيكل الجدول: $table");
                }

                fwrite($fh, "-- Table: $table\n");
                fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n");
                fwrite($fh, $ddl . ";\n\n");

                // Stream rows one at a time — no fetchAll()
                $stmt = $this->getDb()->query("SELECT * FROM `$table`");
                $firstRow = true;

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($firstRow) {
                        $columns = '`' . implode('`, `', array_keys($row)) . '`';
                        fwrite($fh, "INSERT INTO `$table` ($columns) VALUES\n");
                        $firstRow = false;
                    } else {
                        fwrite($fh, ",\n");
                    }

                    $escaped = array_map(function ($v) {
                        return $v === null ? 'NULL' : $this->getDb()->quote((string)$v);
                    }, array_values($row));

                    fwrite($fh, '(' . implode(', ', $escaped) . ')');
                }

                if (!$firstRow) {
                    fwrite($fh, ";\n\n");
                }
            }

            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($fh);
        }
    }
}
