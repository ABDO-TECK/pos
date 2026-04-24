<?php

class MigrationService {

    private PDO $db;
    private string $migrationsPath;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->migrationsPath = __DIR__ . '/../../database/migrations/';
    }

    /**
     * تشغيل جميع المهاجرات التي لم يتم تشغيلها بعد.
     * @return array يحتوي على عدد المهاجرات المنفذة وأي أخطاء حدثت.
     */
    public function runAllMigrations(): array {
        $this->createMigrationsTableIfNotExists();

        if (!is_dir($this->migrationsPath)) {
            return ['executed' => 0, 'errors' => ["Migrations directory not found: {$this->migrationsPath}"]];
        }

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

        return [
            'executed' => $executed,
            'errors' => $errors
        ];
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

        // نستخدم mysqli لتنفيذ الملفات التي تحتوي على استعلامات متعددة بأمان
        $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($mysqli->connect_errno) {
            if (class_exists('Logger')) Logger::error("Migration connection failed", ['error' => $mysqli->connect_error]);
            return false;
        }
        $mysqli->set_charset('utf8mb4');

        if (!$mysqli->multi_query($content)) {
            if (class_exists('Logger')) Logger::error("Migration failed: $file", ['error' => $mysqli->error]);
            $mysqli->close();
            return false;
        }

        do {
            if ($res = $mysqli->store_result()) {
                $res->free();
            }
            if (!$mysqli->more_results()) {
                break;
            }
            if (!$mysqli->next_result()) {
                if (class_exists('Logger')) Logger::error("Migration step failed: $file", ['error' => $mysqli->error]);
                $mysqli->close();
                return false;
            }
        } while (true);

        $mysqli->close();
        Database::resetInstance(); // إعادة تهيئة اتصال PDO الأساسي لتحديث الهيكل
        return true;
    }
}
