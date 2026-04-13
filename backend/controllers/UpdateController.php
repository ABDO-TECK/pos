<?php

class UpdateController extends Controller {

    private string $repoUrl = 'https://raw.githubusercontent.com/ABDO-TECK/pos/main/version.json';
    private string $commitsUrl = 'https://api.github.com/repos/ABDO-TECK/pos/commits?sha=main&per_page=15';
    private string $localVersionFile = __DIR__ . '/../../version.json';

    public function __construct() {
        // Only Admins can invoke this controller (enforced via middleware in api.php)
    }

    private function getLocalVersion(): array {
        if (!file_exists($this->localVersionFile)) {
            return ['version' => '0.0.0', 'released_at' => null];
        }
        $content = file_get_contents($this->localVersionFile);
        $data = json_decode($content, true);
        return is_array($data) ? $data : ['version' => '0.0.0', 'released_at' => null];
    }

    private function fetchRemoteVersion(): ?array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->repoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ABDO-TECK-POS-Updater');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $result) {
            $data = json_decode($result, true);
            return is_array($data) ? $data : null;
        }
        return null;
    }

    public function check(): void {
        $local = $this->getLocalVersion();
        $remote = $this->fetchRemoteVersion();

        if (!$remote) {
            Response::error('تعذر الاتصال بخادم التحديثات أو ملف version.json غير موجود على GitHub (تأكد من رفعه).', 500);
        }

        $hasUpdate = version_compare($remote['version'], $local['version'], '>');

        Response::success([
            'current_version' => $local['version'],
            'latest_version'  => $remote['version'],
            'has_update'      => $hasUpdate,
            'released_at'     => $remote['released_at'] ?? null,
            'changelog'       => $remote['changelog'] ?? [],
            'requires_npm_install' => $remote['requires_npm_install'] ?? false
        ]);
    }

    public function changelog(): void {
        $remote = $this->fetchRemoteVersion();
        Response::success($remote['changelog'] ?? []);
    }

    private function doDatabaseBackup(string $rootDir): string {
        $db = Database::getInstance();
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $sql  = "-- Auto-Update Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $createStmt = $db->query("SHOW CREATE TABLE `$table`")->fetch();
            $sql .= "-- Table: $table\n";
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $createStmt['Create Table'] . ";\n\n";

            $rows = $db->query("SELECT * FROM `$table`")->fetchAll();
            if (!empty($rows)) {
                $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $sql .= "INSERT INTO `$table` ($columns) VALUES\n";
                $values = [];
                foreach ($rows as $row) {
                    $escaped = array_map(function ($v) use ($db) {
                        if ($v === null) return 'NULL';
                        return $db->quote((string)$v);
                    }, array_values($row));
                    $values[] = '(' . implode(', ', $escaped) . ')';
                }
                $sql .= implode(",\n", $values) . ";\n\n";
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $backupDir = $rootDir . '/backend/storage/update-backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0777, true);
        }
        $filename = $backupDir . '/pre_update_' . date('Y-m-d_H-i-s') . '.sql';
        file_put_contents($filename, $sql);
        return $filename;
    }

    public function apply(): void {
        $rootDir = realpath(__DIR__ . '/../../');
        $output = [];

        // 1. Database Backup
        try {
            $backupFile = $this->doDatabaseBackup($rootDir);
            $output[] = "Database backup created: " . basename($backupFile);
        } catch (Throwable $e) {
            Logger::error('Update Backup Failed', ['error' => $e->getMessage()]);
            Response::error('فشل إنشاء نسخة احتياطية من قاعدة البيانات לפני التحديث', 500);
        }

        // 2. Fetch and Check
        $remote = $this->fetchRemoteVersion();
        $requiresNpm = $remote['requires_npm_install'] ?? false;

        // 3. Git Operations
        $commands = [
            "cd " . escapeshellarg($rootDir),
            "git fetch origin main",
            "git reset --hard origin/main"
        ];
        
        $cmd = implode(' && ', $commands);
        $returnVar = 0;
        exec($cmd . ' 2>&1', $execOut, $returnVar);
        $output = array_merge($output, $execOut);

        if ($returnVar !== 0) {
            Logger::error('Update Git Failed', ['output' => $output]);
            Response::error('حدث خطأ أثناء تنزيل التحديثات', 500, ['logs' => $output]);
        }

        // 4. NPM Install if required
        if ($requiresNpm) {
            $npmCommand = "cd " . escapeshellarg($rootDir . "/frontend") . " && npm install";
            $output[] = "Running npm install...";
            exec($npmCommand . ' 2>&1', $npmOut, $npmRet);
            $output = array_merge($output, $npmOut);
            if ($npmRet !== 0) {
                Logger::error('Update NPM Failed', ['output' => $output]);
                // We don't fail the entire update, but we warn the user
            } else {
                $output[] = "NPM install completed successfully.";
            }
        }

        Response::success([
            'message' => 'تم استكمال التحديث بنجاح',
            'latest_version' => $remote['version'] ?? 'unknown',
            'logs'    => $output
        ]);
    }

}
