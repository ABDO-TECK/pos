<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Container;
use App\Core\Controller;
use App\Helpers\Cache;
use App\Helpers\Response;
use App\Services\BackupService;
use App\Services\MigrationService;
use PDO;
use mysqli;


class BackupController extends Controller {

    private PDO $db;
    private BackupService $backupService;

    public function __construct(PDO $db, BackupService $backupService) {
        $this->db = $db;
        $this->backupService = $backupService;
    }

    public function download() {
        $filename = 'pos_backup_' . date('Y-m-d_H-i-s') . '.sql';

        // Set headers for streaming download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');
        
        // Disable output buffering to stream directly
        if (ob_get_level()) ob_end_clean();

        $this->backupService->streamBackup();
        exit;
    }

    /**
     * استعادة قاعدة البيانات من ملف SQL (نسخة تم تصديرها من نفس النظام).
     * POST multipart: الحقل sql_file
     */
    public function restore() {
        if (!defined('ALLOW_WEB_RESTORE') || !ALLOW_WEB_RESTORE) {
            return Response::error('استعادة النسخة الاحتياطية من الويب معطلة لأسباب أمنية. يرجى استخدام سطر الأوامر (CLI).', 403);
        }

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

        // تنظيف الهيكل من التضاربات المحتملة بين النسخ القديمة والحديثة
        // (إزالة UNSIGNED من أنواع البيانات لمنع خطأ Foreign Key errno 150)
        $content = preg_replace('/\b((?:INT|BIGINT|TINYINT|SMALLINT|MEDIUMINT)(?:\s*\([\d]+\))?)\s+UNSIGNED\b/i', '$1', $content);

        if (!preg_match('/\b(DROP\s+TABLE|CREATE\s+TABLE|INSERT\s+INTO)\b/is', $content)) {
            return Response::error('محتوى الملف لا يبدو ملف SQL صالحاً لقاعدة البيانات', 400);
        }

        // منع أوامر خطرة واضحة (لا تشمل كل الحالات؛ المسؤولية على المدير)
        if (preg_match('/\b(OUTFILE|DUMPFILE|LOAD_FILE|INTO\s+OUTFILE)\b/is', $content)) {
            return Response::error('الملف يحتوي على أوامر غير مسموحة', 400);
        }

        // ── استخدام mysqli بدلاً من PDO ──
        // السبب: PDO لا يدعم multi_query() اللازمة لتنفيذ ملف SQL كامل
        // يحتوي عدة أوامر (DROP TABLE, CREATE TABLE, INSERT) دفعة واحدة.
        // هذا هو الاستخدام الوحيد لـ mysqli في المشروع بالكامل.
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
        // 1. مسح سجل الهجرات القديم لأن ملف الاستعادة قد يحتوي على سجلات
        //    تقول "تم تنفيذ الهجرة" رغم أن الأعمدة/الجداول غير موجودة فعلياً.
        $freshDb = Database::getInstance();
        try {
            $freshDb->exec('DELETE FROM schema_versions');
        } catch (\Throwable $e) {
            // الجدول قد لا يكون موجوداً — سيُنشأ في الهجرات
        }

        // 2. حذف ملف flag الـ Smart Skip لإجبار إعادة فحص الهجرات
        $flagFile = realpath(__DIR__ . '/../storage/migrations_hash.flag');
        if ($flagFile && is_file($flagFile)) {
            @unlink($flagFile);
        }

        // 3. تشغيل جميع الهجرات بـ force=true لضمان تطبيقها
        //    (الهجرات آمنة: تتجاهل أخطاء "عمود/جدول موجود مسبقاً")
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

        return Response::success(null, $msg);
    }
}


