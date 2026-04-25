<?php

class UpdateController extends Controller {

    private string $repoUrl = 'https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main';
    private string $localVersionFile;
    private string $rootDir;

    public function __construct() {
        $this->rootDir          = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
        $this->localVersionFile = $this->rootDir . DIRECTORY_SEPARATOR . 'version.json';
    }

    // ══════════════════════════════════════════════════════════════
    //  Git helpers
    // ══════════════════════════════════════════════════════════════

    /**
     * حل مسار Git — يبحث في المواقع الشائعة على Windows.
     */
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
            $pf   . '\\Git\\cmd\\git.exe',
            $pf   . '\\Git\\bin\\git.exe',
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
     * تشغيل أمر Git عبر proc_open (بدون shell).
     *
     * @return array{0: string[], 1: int}  [output_lines, exit_code]
     */
    private function runGit(array $gitArgs, ?string $cwd = null) {
        $git = $this->resolveGitExecutable();
        $dir = $cwd ?? $this->rootDir;
        $cmd = array_merge([$git, '-C', $dir], $gitArgs);

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes   = [];
        $process = @proc_open($cmd, $descriptorspec, $pipes, null, null, ['bypass_shell' => true]);

        if (!is_resource($process)) {
            return [['تعذر تشغيل Git. ثبّت Git للويندوز، أو عرّف متغير البيئة GIT_BINARY_PATH.'], 127];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code   = proc_close($process);

        $merged = trim((string)$stdout . "\n" . (string)$stderr);
        $lines  = $merged === '' ? [] : preg_split('/\r\n|\r|\n/', $merged);

        return [$lines, $code];
    }

    /**
     * التأكد من أن Git يثق بمجلد المشروع (safe.directory).
     * يحاول الإضافة تلقائياً إذا لم يكن موجوداً.
     */
    private function ensureSafeDirectory() {
        $dir = str_replace('\\', '/', $this->rootDir);

        // فحص هل المجلد مسجّل بالفعل
        [$lines, $code] = $this->runGit(['config', '--global', '--get-all', 'safe.directory']);
        if ($code === 0) {
            foreach ($lines as $line) {
                $l = trim($line);
                if ($l === $dir || $l === '*') {
                    return; // موجود بالفعل
                }
            }
        }

        // محاولة إضافته — global
        $this->runGit(['config', '--global', '--add', 'safe.directory', $dir]);

        // محاولة system-level (قد تفشل بدون صلاحيات admin — غير حرج)
        $this->runGit(['config', '--system', '--add', 'safe.directory', $dir]);
    }

    /**
     * فحص شامل لحالة Git — يُرجع مصفوفة تشخيصية.
     */
    private function diagnoseGit() {
        $diag = [];

        // 1. هل المجلد .git موجود
        $gitDir = $this->rootDir . DIRECTORY_SEPARATOR . '.git';
        $diag['git_dir_exists']  = is_dir($gitDir);
        $diag['git_dir_path']    = $gitDir;
        $diag['root_dir']        = $this->rootDir;

        // 2. هل أداة Git متاحة
        $git = $this->resolveGitExecutable();
        $diag['git_executable']  = $git;
        $diag['git_file_exists'] = ($git === 'git') ? 'PATH lookup' : is_file($git);

        // 3. هل git status يعمل
        [$statusOut, $statusCode] = $this->runGit(['status', '--porcelain']);
        $diag['git_status_code']  = $statusCode;
        $diag['git_status_out']   = implode(' | ', array_slice($statusOut, 0, 3));

        // 4. هل هناك remote
        [$remoteOut, $remoteCode] = $this->runGit(['remote', '-v']);
        $diag['git_remote_code']  = $remoteCode;
        $diag['git_remote_out']   = implode(' | ', array_slice($remoteOut, 0, 2));

        // 5. PHP process user
        $diag['php_user'] = function_exists('get_current_user') ? get_current_user() : 'unknown';
        $diag['php_sapi'] = PHP_SAPI;

        return $diag;
    }

    // ══════════════════════════════════════════════════════════════
    //  Version helpers
    // ══════════════════════════════════════════════════════════════

    private function getLocalVersion() {
        if (!file_exists($this->localVersionFile)) {
            return ['version' => '0.0.0', 'released_at' => null, 'changelog' => []];
        }
        $content = @file_get_contents($this->localVersionFile);
        $data    = $content ? json_decode($content, true) : null;
        return is_array($data) ? $data : ['version' => '0.0.0', 'released_at' => null, 'changelog' => []];
    }

    private function fetchRemoteVersion(): ?array {
        $certPath = __DIR__ . '/../certs/cacert.pem';
        
        // Auto-download cacert.pem if missing (e.g. fresh clone on XAMPP windows)
        if (!file_exists($certPath)) {
            if (!is_dir(dirname($certPath))) {
                mkdir(dirname($certPath), 0777, true);
            }
            $certContent = @file_get_contents('https://curl.se/ca/cacert.pem');
            if ($certContent) {
                file_put_contents($certPath, $certContent);
            }
        }

        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL            => $this->repoUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'ABDO-TECK-POS-Updater/1.0',
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.github.v3.raw',
                'Cache-Control: no-cache',
            ],
        ];

        if (file_exists($certPath)) {
            $curlOptions[CURLOPT_CAINFO] = $certPath;
        }

        curl_setopt_array($ch, $curlOptions);
        $result   = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && $result) {
            $data = json_decode($result, true);
            return is_array($data) ? $data : null;
        }

        Logger::warning('fetchRemoteVersion failed', [
            'http_code' => $httpCode,
            'curl_err'  => $curlErr,
        ]);
        return null;
    }

    // ══════════════════════════════════════════════════════════════
    //  Database backup
    // ══════════════════════════════════════════════════════════════

    private function doDatabaseBackup(): string {
        $db     = Database::getInstance();
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $sql  = "-- POS Auto-Update Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $createStmt = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
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

            $rows = $db->query("SELECT * FROM `$table`")->fetchAll();
            if (!empty($rows)) {
                $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $sql .= "INSERT INTO `$table` ($columns) VALUES\n";
                $values = [];
                foreach ($rows as $row) {
                    $escaped = array_map(function ($v) use ($db) {
                        return $v === null ? 'NULL' : $db->quote((string)$v);
                    }, array_values($row));
                    $values[] = '(' . implode(', ', $escaped) . ')';
                }
                $sql .= implode(",\n", $values) . ";\n\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $backupDir = $this->rootDir . '/backend/storage/update-backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0777, true);
        }
        $filename = $backupDir . '/pre_update_' . date('Y-m-d_H-i-s') . '.sql';
        file_put_contents($filename, $sql);

        // الاحتفاظ بآخر 10 نسخ فقط
        $files = glob($backupDir . '/pre_update_*.sql') ?: [];
        rsort($files);
        foreach (array_slice($files, 10) as $old) {
            @unlink($old);
        }

        return $filename;
    }

    // ══════════════════════════════════════════════════════════════
    //  API Endpoints
    // ══════════════════════════════════════════════════════════════

    /**
     * GET /api/update/check
     * التحقق من وجود تحديث جديد.
     */
    public function check() {
        $local  = $this->getLocalVersion();
        $remote = $this->fetchRemoteVersion();

        if (!$remote) {
            return Response::error(
                'تعذر الاتصال بخادم التحديثات. تحقق من اتصالك بالإنترنت وأن ملف version.json موجود على GitHub.',
                502
            );
        }

        $hasUpdate = version_compare($remote['version'], $local['version'], '>');

        return Response::success([
            'current_version'      => $local['version'],
            'latest_version'       => $remote['version'],
            'has_update'           => $hasUpdate,
            'released_at'          => $remote['released_at'] ?? null,
            'changelog'            => $remote['changelog'] ?? [],
            'requires_npm_install' => $remote['requires_npm_install'] ?? false,
        ]);
    }

    /**
     * GET /api/update/changelog
     */
    public function changelog() {
        $remote = $this->fetchRemoteVersion();
        return Response::success($remote['changelog'] ?? []);
    }

    /**
     * POST /api/update/apply
     * تطبيق التحديث: نسخة احتياطية → git pull → npm install (اختياري) → بناء frontend.
     */
    public function apply() {
        $output = [];
        $stepTimings = [];

        // ───────────────────────────────────────────────────────────
        // الخطوة 0: تشخيص البيئة
        // ───────────────────────────────────────────────────────────
        $output[] = '🔍 فحص البيئة...';
        $diag = $this->diagnoseGit();
        Logger::info('Update: environment diagnosis', $diag);

        // ───────────────────────────────────────────────────────────
        // الخطوة 1: نسخة احتياطية من قاعدة البيانات
        // ───────────────────────────────────────────────────────────
        $output[] = '💾 إنشاء نسخة احتياطية من قاعدة البيانات...';
        $t0 = microtime(true);
        try {
            $backupFile = $this->doDatabaseBackup();
            $elapsed    = round(microtime(true) - $t0, 1);
            $output[]   = "✅ تم إنشاء النسخة الاحتياطية: " . basename($backupFile) . " ({$elapsed}s)";
        } catch (Throwable $e) {
            Logger::error('Update: backup failed', ['error' => $e->getMessage()]);
            return Response::error(
                'فشل إنشاء نسخة احتياطية من قاعدة البيانات: ' . $e->getMessage(),
                500,
                ['logs' => $output]
            );
        }

        // ───────────────────────────────────────────────────────────
        // الخطوة 2: جلب معلومات الإصدار البعيد
        // ───────────────────────────────────────────────────────────
        $output[] = '🌐 الاتصال بخادم التحديثات...';
        $remote = $this->fetchRemoteVersion();
        if (!$remote) {
            return Response::error(
                'تعذر الاتصال بخادم التحديثات. تحقق من اتصالك بالإنترنت.',
                502,
                ['logs' => $output]
            );
        }
        $output[] = '✅ الإصدار المتاح: v' . ($remote['version'] ?? '?');
        $requiresNpm = $remote['requires_npm_install'] ?? false;

        // ───────────────────────────────────────────────────────────
        // الخطوة 3: فحص Git
        // ───────────────────────────────────────────────────────────
        $output[] = '🔧 التحقق من Git...';
        $gitDir = $this->rootDir . DIRECTORY_SEPARATOR . '.git';

        if (!is_dir($gitDir) && !file_exists($gitDir)) {
            // محاولة أخيرة: فحص بالأمر مباشرة
            [$revOut, $revCode] = $this->runGit(['rev-parse', '--git-dir']);
            if ($revCode !== 0) {
                Logger::error('Update: .git not found', $diag);
                file_put_contents($this->rootDir . '/backend/git_debug_error.log', json_encode([
                    'diag' => $diag,
                    'output' => $output,
                    'gitDir' => $gitDir,
                    'is_dir' => is_dir($gitDir),
                    'file_exists' => file_exists($gitDir),
                    'revOut' => $revOut
                ], JSON_PRETTY_PRINT));
                return Response::error(
                    'لا يمكن التحديث التلقائي: المجلد ليس مستنسخاً عبر Git (لا يوجد .git).' . "\n"
                    . 'الحل: افتح Terminal وشغّل:' . "\n"
                    . 'cd C:\\xampp\\htdocs && git clone https://github.com/ABDO-TECK/pos.git',
                    400,
                    ['logs' => $output, 'diagnostics' => $diag]
                );
            }
        }
        $output[] = '✅ مجلد .git موجود';

        // ───────────────────────────────────────────────────────────
        // الخطوة 3.1: ضمان safe.directory
        // ───────────────────────────────────────────────────────────
        $output[] = '🔐 ضبط صلاحيات Git (safe.directory)...';
        $this->ensureSafeDirectory();

        // فحص سريع أن Git يعمل فعلاً
        [$testOut, $testCode] = $this->runGit(['status', '--porcelain']);
        if ($testCode !== 0) {
            $errMsg = implode(' ', $testOut);
            // محاولة ثانية: إضافة safe.directory بمسار Windows-style
            $this->runGit(['config', '--global', '--add', 'safe.directory', $this->rootDir]);
            [$testOut2, $testCode2] = $this->runGit(['status', '--porcelain']);
            if ($testCode2 !== 0) {
                // محاولة أخيرة: wildcard (السماح بكل المجلدات)
                $this->runGit(['config', '--global', '--add', 'safe.directory', '*']);
                [$testOut3, $testCode3] = $this->runGit(['status', '--porcelain']);
                if ($testCode3 !== 0) {
                    Logger::error('Update: git status failed after safe.directory fix', [
                        'output' => $testOut3, 'code' => $testCode3, 'diag' => $diag
                    ]);
                    return Response::error(
                        'Git لا يعمل تحت Apache. ' . implode(' ', $testOut3),
                        500,
                        ['logs' => $output, 'diagnostics' => $diag]
                    );
                }
            }
        }
        $output[] = '✅ Git يعمل بشكل سليم';

        // ───────────────────────────────────────────────────────────
        // الخطوة 4: سحب التحديثات (git fetch + reset)
        // ───────────────────────────────────────────────────────────
        $output[] = '📥 سحب التحديثات من GitHub...';
        $t0 = microtime(true);

        [$fetchOut, $fetchCode] = $this->runGit(['fetch', 'origin', 'main', '--force']);
        $output = array_merge($output, array_filter($fetchOut, fn($l) => trim($l) !== ''));

        if ($fetchCode !== 0) {
            Logger::error('Update: git fetch failed', ['code' => $fetchCode, 'output' => $fetchOut]);
            return Response::error(
                'فشل أمر git fetch — تحقق من اتصال الإنترنت ومن إعدادات المستودع.',
                500,
                ['logs' => $output]
            );
        }

        // حفظ التعديلات المحلية (stash) قبل reset
        $this->runGit(['stash', '--include-untracked']);

        [$resetOut, $resetCode] = $this->runGit(['reset', '--hard', 'origin/main']);
        $output = array_merge($output, array_filter($resetOut, fn($l) => trim($l) !== ''));

        if ($resetCode !== 0) {
            Logger::error('Update: git reset failed', ['code' => $resetCode, 'output' => $resetOut]);
            return Response::error(
                'فشل أمر git reset — تحقق من صلاحيات المجلد.',
                500,
                ['logs' => $output]
            );
        }

        $elapsed  = round(microtime(true) - $t0, 1);
        $output[] = "✅ تم سحب التحديثات ({$elapsed}s)";

        // ───────────────────────────────────────────────────────────
        // الخطوة 5: تثبيت حزم npm (إذا لزم)
        // ───────────────────────────────────────────────────────────
        if ($requiresNpm) {
            $output[] = '📦 تثبيت حزم npm...';
            $t0 = microtime(true);

            $frontendDir = $this->rootDir . DIRECTORY_SEPARATOR . 'frontend';
            if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
                $npmCmd = 'cmd /c "cd /d ' . escapeshellarg($frontendDir) . ' && npm install --no-audit --no-fund 2>&1"';
            } else {
                $npmCmd = 'cd ' . escapeshellarg($frontendDir) . ' && npm install --no-audit --no-fund 2>&1';
            }

            $npmOut = [];
            exec($npmCmd, $npmOut, $npmRet);

            $elapsed = round(microtime(true) - $t0, 1);
            if ($npmRet !== 0) {
                Logger::warning('Update: npm install failed', ['output' => $npmOut, 'code' => $npmRet]);
                $output[] = "⚠️ npm install فشل (رمز: {$npmRet}) — ({$elapsed}s)";
                $output[] = '  ↳ شغّل: cd frontend && npm install يدوياً';
            } else {
                $output[] = "✅ تم تثبيت الحزم ({$elapsed}s)";
            }
        }

        // ───────────────────────────────────────────────────────────
        // الخطوة 6: بناء الـ frontend للإنتاج (frontend-dist)
        // ───────────────────────────────────────────────────────────
        $frontendDir = $this->rootDir . DIRECTORY_SEPARATOR . 'frontend';
        $distDir     = $this->rootDir . DIRECTORY_SEPARATOR . 'frontend-dist';

        if (is_dir($frontendDir . DIRECTORY_SEPARATOR . 'node_modules')) {
            $output[] = '🏗️ بناء واجهة الإنتاج (frontend-dist)...';
            $t0 = microtime(true);

            if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
                $buildCmd = 'cmd /c "cd /d ' . escapeshellarg($frontendDir) . ' && npm run build 2>&1"';
            } else {
                $buildCmd = 'cd ' . escapeshellarg($frontendDir) . ' && npm run build 2>&1';
            }

            $buildOut = [];
            exec($buildCmd, $buildOut, $buildRet);

            $elapsed = round(microtime(true) - $t0, 1);
            if ($buildRet !== 0) {
                Logger::warning('Update: npm build failed', ['output' => $buildOut, 'code' => $buildRet]);
                $output[] = "⚠️ بناء الواجهة فشل — ({$elapsed}s)";
            } else {
                // نسخ dist إلى frontend-dist
                $srcDist = $frontendDir . DIRECTORY_SEPARATOR . 'dist';
                if (is_dir($srcDist) && is_dir($distDir)) {
                    $this->copyDirectory($srcDist, $distDir);
                    $output[] = "✅ تم بناء ونسخ الواجهة ({$elapsed}s)";
                } else {
                    $output[] = "✅ تم البناء ({$elapsed}s)";
                }
            }
        }

        // ───────────────────────────────────────────────────────────
        // الخطوة 7: تطبيق المهاجرات (Migrations)
        // ───────────────────────────────────────────────────────────
        $output[] = '🗄️ تطبيق تحديثات قاعدة البيانات (إن وجدت)...';
        $t0 = microtime(true);
        require_once __DIR__ . '/../services/MigrationService.php';
        $migrationResult = (new MigrationService())->runAllMigrations(true); // force run
        $elapsed = round(microtime(true) - $t0, 1);
        if ($migrationResult['executed'] > 0) {
            $output[] = "✅ تم تطبيق {$migrationResult['executed']} تحديث(ات) لقاعدة البيانات ({$elapsed}s)";
        } else {
            $output[] = "✅ قاعدة البيانات محدثة سلفاً ({$elapsed}s)";
        }
        if (!empty($migrationResult['errors'])) {
            $output[] = "⚠️ حدثت أخطاء أثناء التحديث:";
            foreach ($migrationResult['errors'] as $err) {
                $output[] = "  ↳ $err";
            }
        }

        // ───────────────────────────────────────────────────────────
        // النتيجة النهائية
        // ───────────────────────────────────────────────────────────
        $output[] = '';
        $output[] = '🎉 تم استكمال التحديث بنجاح إلى v' . ($remote['version'] ?? '?');

        Logger::info('Update applied successfully', [
            'from'    => $this->getLocalVersion()['version'] ?? '?',
            'to'      => $remote['version'] ?? '?',
        ]);

        return Response::success([
            'message'        => 'تم استكمال التحديث بنجاح',
            'latest_version' => $remote['version'] ?? 'unknown',
            'changelog'      => $remote['changelog'] ?? [],
            'logs'           => $output,
        ]);
    }

    /**
     * نسخ محتوى مجلد إلى آخر (استبدال).
     */
    private function copyDirectory(string $src, string $dst) {
        $dir = opendir($src);
        if (!$dir) return;

        if (!is_dir($dst)) {
            mkdir($dst, 0777, true);
        }

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;

            $srcPath = $src . DIRECTORY_SEPARATOR . $file;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $file;

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
    }
}


