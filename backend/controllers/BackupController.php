<?php

class BackupController extends Controller {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function download() {
        $tables = $this->db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $sql  = "-- POS Database Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Host: " . DB_HOST . " | Database: " . DB_NAME . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            // Skip internal tables
            if (in_array($table, ['settings'])) {
                // include settings in backup
            }

            // Table structure
            $createStmt = $this->db->query("SHOW CREATE TABLE `$table`")->fetch();
            $sql .= "-- Table: $table\n";
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $createStmt['Create Table'] . ";\n\n";

            // Table data
            $rows = $this->db->query("SELECT * FROM `$table`")->fetchAll();
            if (!empty($rows)) {
                $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $sql .= "INSERT INTO `$table` ($columns) VALUES\n";
                $values = [];
                foreach ($rows as $row) {
                    $escaped = array_map(function ($v) {
                        if ($v === null) return 'NULL';
                        return $this->db->quote((string)$v);
                    }, array_values($row));
                    $values[] = '(' . implode(', ', $escaped) . ')';
                }
                $sql .= implode(",\n", $values) . ";\n\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $filename = 'pos_backup_' . date('Y-m-d_H-i-s') . '.sql';

        // Override Content-Type for file download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        header('Cache-Control: no-cache');
        echo $sql;
        exit;
    }

    /**
     * استعادة قاعدة البيانات من ملف SQL (نسخة تم تصديرها من نفس النظام).
     * POST multipart: الحقل sql_file
     */
    public function restore() {
        if (empty($_FILES['sql_file']) || (int) ($_FILES['sql_file']['error'] ?? 0) !== UPLOAD_ERR_OK) {
            return Response::error('لم يتم رفع الملف أو فشل الرفع', 400);
        }

        $file = $_FILES['sql_file'];
        $name = (string) ($file['name'] ?? '');
        if (!str_ends_with(strtolower($name), '.sql')) {
            return Response::error('يجب أن يكون الملف بصيغة .sql', 400);
        }

        $maxBytes = 50 * 1024 * 1024; // 50 MB
        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            return Response::error('حجم الملف يتجاوز الحد المسموح (50 ميجابايت)', 400);
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false || strlen($content) < 30) {
            return Response::error('الملف فارغ أو غير قابل للقراءة', 400);
        }

        // إزالة BOM
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (!preg_match('/\b(DROP\s+TABLE|CREATE\s+TABLE|INSERT\s+INTO)\b/is', $content)) {
            return Response::error('محتوى الملف لا يبدو ملف SQL صالحاً لقاعدة البيانات', 400);
        }

        // منع أوامر خطرة واضحة (لا تشمل كل الحالات؛ المسؤولية على المدير)
        if (preg_match('/\b(OUTFILE|DUMPFILE|LOAD_FILE|INTO\s+OUTFILE)\b/is', $content)) {
            return Response::error('الملف يحتوي على أوامر غير مسموحة', 400);
        }

        $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($mysqli->connect_errno) {
            return Response::serverError('فشل الاتصال بقاعدة البيانات: ' . $mysqli->connect_error);
        }
        $mysqli->set_charset('utf8mb4');

        if (!$mysqli->multi_query($content)) {
            $err = $mysqli->error;
            $mysqli->close();
            return Response::error('فشل تنفيذ الاستعادة: ' . $err, 500);
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
                return Response::error('فشل أثناء الاستعادة: ' . $err, 500);
            }
        } while (true);

        $mysqli->close();
        Database::resetInstance();

        // ---------------------------------------------------------
        // الاستعادة الذكية: ترقية النسخة القديمة لتطابق الكود الحديث
        // ---------------------------------------------------------
        require_once __DIR__ . '/../services/MigrationService.php';
        $migrationService = new MigrationService();
        $migrationResult = $migrationService->runAllMigrations();

        $msg = 'تمت استعادة قاعدة البيانات بنجاح';
        if ($migrationResult['executed'] > 0) {
            $msg .= '، وتمت ترقيتها للإصدار الحديث (' . $migrationResult['executed'] . ' تحديثات).';
        }
        if (!empty($migrationResult['errors'])) {
            $msg .= ' ولكن حدثت بعض الأخطاء أثناء الترقية التلقائية.';
        }

        return Response::success(null, $msg);
    }
}


