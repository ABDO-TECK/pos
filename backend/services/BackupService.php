<?php

namespace App\Services;

use App\Config\Database;
use App\Core\Container;
use App\Services\MigrationService;
use mysqli;
use App\Contracts\BackupServiceInterface;
use PDO;
use RuntimeException;


class BackupService implements BackupServiceInterface {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Create a backup file in the specified directory.
     * Returns the full path to the backup file.
     */
    public function createBackupFile(string $backupDir): string {
        $sql = $this->generateBackupSql();

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0777, true);
        }
        $filename = rtrim($backupDir, '/\\') . '/pre_update_' . date('Y-m-d_H-i-s') . '.sql';
        file_put_contents($filename, $sql);

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
        $tables = $this->db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        echo "-- POS Database Backup\n";
        echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        echo "-- Host: " . DB_HOST . " | Database: " . DB_NAME . "\n\n";
        echo "SET FOREIGN_KEY_CHECKS=0;\n\n";
        flush();

        foreach ($tables as $table) {
            // Table structure
            $createStmt = $this->db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
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
            $stmt = $this->db->query("SELECT * FROM `$table`");
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
                    return $v === null ? 'NULL' : $this->db->quote((string)$v);
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

        // منع أوامر خطرة
        if (preg_match('/\b(OUTFILE|DUMPFILE|LOAD_FILE|INTO\s+OUTFILE)\b/is', $content)) {
            return ['ok' => false, 'error' => 'الملف يحتوي على أوامر غير مسموحة', 'code' => 400];
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
        // ── استخدام mysqli بدلاً من PDO ──
        // السبب: PDO لا يدعم multi_query() اللازمة لتنفيذ ملف SQL كامل
        $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($mysqli->connect_errno) {
            return ['ok' => false, 'error' => 'فشل الاتصال بقاعدة البيانات: ' . $mysqli->connect_error, 'code' => 500];
        }
        $mysqli->set_charset('utf8mb4');

        // تعطيل الوضع الصارم لتجنب أخطاء "Data truncated"
        $mysqli->query("SET sql_mode=''");
        $mysqli->query("SET FOREIGN_KEY_CHECKS=0");

        if (!$mysqli->multi_query($sqlContent)) {
            $err = $mysqli->error;
            $mysqli->close();
            return ['ok' => false, 'error' => 'فشل تنفيذ الاستعادة: ' . $err, 'code' => 500];
        }

        do {
            if ($res = $mysqli->store_result()) {
                $res->free();
            }
            if (!$mysqli->more_results()) {
                break;
            }
            if (!$mysqli->next_result()) {
                $err = $mysqli->error;
                $mysqli->close();
                return ['ok' => false, 'error' => 'فشل أثناء الاستعادة: ' . $err, 'code' => 500];
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
        $flagFile = realpath(__DIR__ . '/../storage/migrations_hash.flag');
        if ($flagFile && is_file($flagFile)) {
            @unlink($flagFile);
        }

        // تشغيل جميع الهجرات
        Database::resetInstance();
        require_once __DIR__ . '/../core/Container.php';
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
     * Generate backup SQL string in memory.
     */
    private function generateBackupSql(): string {
        $tables = $this->db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $sql  = "-- POS Auto-Update Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $createStmt = $this->db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $ddl = $createStmt['Create Table'] ?? null;
            if ($ddl === null && is_array($createStmt)) {
                $vals = array_values($createStmt);
                $ddl  = $vals[1] ?? '';
            }
            if (!$ddl) {
                throw new RuntimeException("تعذر قراءة هيكل الجدول: $table");
            }

            $sql .= "-- Table: $table\n";
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $ddl . ";\n\n";

            $rows = $this->db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $sql .= "INSERT INTO `$table` ($columns) VALUES\n";
                $values = [];
                foreach ($rows as $row) {
                    $escaped = array_map(function ($v) {
                        return $v === null ? 'NULL' : $this->db->quote((string)$v);
                    }, array_values($row));
                    $values[] = '(' . implode(', ', $escaped) . ')';
                }
                $sql .= implode(",\n", $values) . ";\n\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        return $sql;
    }
}
