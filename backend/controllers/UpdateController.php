<?php

class UpdateController extends Controller {

    private string $repoUrl = 'https://raw.githubusercontent.com/ABDO-TECK/pos/main/version.json';
    private string $commitsUrl = 'https://api.github.com/repos/ABDO-TECK/pos/commits?sha=main&per_page=15';
    private string $localVersionFile = __DIR__ . '/../../version.json';

    /** مسار تنفيذ Git (يُحلّ تلقائياً على Windows لأن Apache غالباً لا يرى Git في PATH). */
    private function resolveGitExecutable(): string {
        $custom = getenv('GIT_BINARY_PATH');
        if (is_string($custom) && $custom !== '' && is_file($custom)) {
            return $custom;
        }
        if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
            return 'git';
        }
        $pf   = getenv('ProgramFiles') ?: 'C:\\Program Files';
        $pf86 = getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)';
        foreach ([
            $pf . '\\Git\\cmd\\git.exe',
            $pf . '\\Git\\bin\\git.exe',
            $pf86 . '\\Git\\cmd\\git.exe',
            $pf86 . '\\Git\\bin\\git.exe',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return 'git';
    }

    /**
     * تشغيل أمر Git دون الاعتماد على shell و`cd` (أنسب لـ Windows + Apache).
     *
     * @return array{0: string[], 1: int} أسطر المخرجات، رمز الخروج
     */
    private function runGit(string $rootDir, array $gitArgs): array {
        $git = $this->resolveGitExecutable();
        $cmd = array_merge([$git, '-C', $rootDir], $gitArgs);
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open(
            $cmd,
            $descriptorspec,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return [['تعذر تشغيل Git. ثبّت Git للويندوز، أو عرّف متغير البيئة GIT_BINARY_PATH لمسار git.exe الكامل (مثلاً C:\\Program Files\\Git\\cmd\\git.exe).'], 127];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $merged = trim((string) $stdout . "\n" . (string) $stderr);
        $lines = $merged === '' ? [] : preg_split('/\r\n|\r|\n/', $merged);

        return [$lines, $code];
    }

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
            $createStmt = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $ddl = $createStmt['Create Table'] ?? $createStmt['create table'] ?? null;
            if ($ddl === null && is_array($createStmt)) {
                $vals = array_values($createStmt);
                $ddl = $vals[1] ?? '';
            }
            if ($ddl === null || $ddl === '') {
                throw new RuntimeException("تعذر قراءة هيكل الجدول: $table");
            }
            $sql .= "-- Table: $table\n";
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $ddl . ";\n\n";

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
            Response::error('فشل إنشاء نسخة احتياطية من قاعدة البيانات قبل التحديث: ' . $e->getMessage(), 500);
        }

        // 2. Fetch and Check
        $remote = $this->fetchRemoteVersion();
        if (!$remote) {
            Logger::error('Update Check Failed', ['error' => 'Could not fetch remote version.json. Check internet connection.']);
            Response::error('تعذر الاتصال بخادم التحديثات أو الملف غير صالح. يرجى التحقق من اتصالك بالإنترنت.', 500);
        }
        $requiresNpm = $remote['requires_npm_install'] ?? false;

        // 3. Git Operations (proc_open + git -C: يعمل مع Apache على Windows حتى لو git غير في PATH)
        if (!is_dir($rootDir . DIRECTORY_SEPARATOR . '.git')) {
            Response::error(
                'لا يمكن التحديث التلقائي: المجلد ليس مستنسخاً عبر Git (لا يوجد .git). انسخ المشروع بـ git clone أو حدّث الملفات يدوياً.',
                400,
                ['logs' => $output]
            );
        }

        [$fetchOut, $fetchCode] = $this->runGit($rootDir, ['fetch', 'origin', 'main']);
        $output = array_merge($output, $fetchOut);
        if ($fetchCode !== 0) {
            Logger::error('Update Git fetch failed', ['code' => $fetchCode, 'output' => $output]);
            Response::error('فشل أمر git fetch (تحقق من الشبكة ومن تثبيت Git).', 500, ['logs' => $output]);
        }

        [$resetOut, $resetCode] = $this->runGit($rootDir, ['reset', '--hard', 'origin/main']);
        $output = array_merge($output, $resetOut);
        if ($resetCode !== 0) {
            Logger::error('Update Git reset failed', ['code' => $resetCode, 'output' => $output]);
            Response::error('فشل أمر git reset --hard (تحقق من صلاحيات المجلد ومن حالة المستودع).', 500, ['logs' => $output]);
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
