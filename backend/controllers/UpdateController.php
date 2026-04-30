<?php

namespace App\Controllers;

use App\Config\config;
use App\Core\Controller;
use App\Helpers\Cache;
use App\Helpers\Logger;
use App\Helpers\Response;
use App\Services\BackupService;
use App\Services\FrontendBuildService;
use App\Services\GitService;
use App\Services\MigrationService;
use App\Services\AuthService;
use App\Helpers\EnvLoader;
use Throwable;


class UpdateController extends Controller {

    private string $repoUrl = 'https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main';
    private string $localVersionFile;
    private string $rootDir;
    private GitService $gitService;
    private FrontendBuildService $buildService;
    private BackupService $backupService;
    private AuthService $authService;

    public function __construct(GitService $gitService, FrontendBuildService $buildService, BackupService $backupService, AuthService $authService) {
        $this->rootDir          = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
        $this->localVersionFile = $this->rootDir . DIRECTORY_SEPARATOR . 'version.json';
        $this->gitService       = $gitService;
        $this->buildService     = $buildService;
        $this->backupService    = $backupService;
        $this->authService      = $authService;
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
    //  API Endpoints
    // ══════════════════════════════════════════════════════════════

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

    public function changelog() {
        $remote = $this->fetchRemoteVersion();
        return Response::success($remote['changelog'] ?? []);
    }

    public function apply() {
        if (!EnvLoader::getBool('ENABLE_AUTO_UPDATE', false)) {
            return Response::error('التحديث التلقائي معطل. الرجاء تفعيله من ملف .env (ENABLE_AUTO_UPDATE=true)', 403);
        }

        $user = $this->authService->user();
        if (!$user || $user['role'] !== 'admin') {
            return Response::error('صلاحيات غير كافية لإجراء التحديث.', 403);
        }

        $output = [];
        $stepTimings = [];

        // الخطوة 0: تشخيص البيئة
        $output[] = '🔍 فحص البيئة...';
        $diag = $this->gitService->diagnoseGit();
        Logger::info('Update: environment diagnosis', $diag);

        // الخطوة 1: نسخة احتياطية من قاعدة البيانات
        $output[] = '💾 إنشاء نسخة احتياطية من قاعدة البيانات...';
        $t0 = microtime(true);
        try {
            $backupDir = $this->rootDir . '/backend/storage/update-backups';
            $backupFile = $this->backupService->createBackupFile($backupDir);
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

        // الخطوة 2: جلب معلومات الإصدار البعيد
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

        // الخطوة 3: فحص Git
        $output[] = '🔧 التحقق من Git...';
        $gitDir = $this->rootDir . DIRECTORY_SEPARATOR . '.git';

        if (!is_dir($gitDir) && !file_exists($gitDir)) {
            [$revOut, $revCode] = $this->gitService->runGit(['rev-parse', '--git-dir']);
            if ($revCode !== 0) {
                Logger::error('Update: .git not found', $diag);
                Logger::error('Update: git debug info (not a clone)', [
                    'diag' => $diag,
                    'output' => $output,
                    'gitDir' => $gitDir,
                    'is_dir' => is_dir($gitDir),
                    'file_exists' => file_exists($gitDir),
                    'revOut' => $revOut
                ]);
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

        // الخطوة 3.1: ضمان safe.directory
        $output[] = '🔐 ضبط صلاحيات Git (safe.directory)...';
        $this->gitService->ensureSafeDirectory();

        [$testOut, $testCode] = $this->gitService->runGit(['status', '--porcelain']);
        if ($testCode !== 0) {
            $errMsg = implode(' ', $testOut);
            $this->gitService->runGit(['config', '--global', '--add', 'safe.directory', $this->rootDir]);
            [$testOut2, $testCode2] = $this->gitService->runGit(['status', '--porcelain']);
            if ($testCode2 !== 0) {
                $this->gitService->runGit(['config', '--global', '--add', 'safe.directory', '*']);
                [$testOut3, $testCode3] = $this->gitService->runGit(['status', '--porcelain']);
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

        // الخطوة 3.2: التأكد من عدم وجود تعديلات محلية تمنع التحديث
        $body = $this->getBody();
        $force = isset($body['force']) && filter_var($body['force'], FILTER_VALIDATE_BOOLEAN);

        [$statusOut, $statusCode] = $this->gitService->runGit(['status', '--porcelain']);
        // تصفية الملفات غير المهمة (مخرجات البناء، ملفات مؤقتة، ملفات غير متتبعة)
        $ignoredPatterns = [
            'frontend/dist/',
            'frontend/node_modules/',
            'backend/storage/',
            'backend/cache/',
            'backend/logs/',
            'backend/vendor/',
            '.env',
        ];
        $significantChanges = array_filter($statusOut, function($line) use ($ignoredPatterns) {
            $line = trim($line);
            if ($line === '') return false;
            // الملفات غير المتتبعة (untracked) ليست مهمة — git reset لا يمسها
            if (str_starts_with($line, '??')) return false;
            foreach ($ignoredPatterns as $pattern) {
                if (str_contains($line, $pattern)) return false;
            }
            return true;
        });
        $hasLocalChanges = !empty($significantChanges);

        if ($hasLocalChanges && !$force) {
            $output[] = '⚠️ توجد تعديلات محلية.';
            return Response::error(
                'يوجد تعديلات محلية في ملفات النظام. إذا قمت بالتحديث، سيتم استبدال هذه التعديلات. هل أنت متأكد من رغبتك في المتابعة ومسح التعديلات المحلية؟',
                409,
                ['logs' => $output, 'local_changes' => $significantChanges]
            );
        }

        // الخطوة 4: سحب التحديثات (git fetch + reset)
        $output[] = '📥 سحب التحديثات من GitHub...';
        $t0 = microtime(true);

        [$fetchOut, $fetchCode] = $this->gitService->runGit(['fetch', 'origin', 'main', '--force']);
        $output = array_merge($output, array_filter($fetchOut, fn($l) => trim($l) !== ''));

        if ($fetchCode !== 0) {
            Logger::error('Update: git fetch failed', ['code' => $fetchCode, 'output' => $fetchOut]);
            return Response::error(
                'فشل أمر git fetch — تحقق من اتصال الإنترنت ومن إعدادات المستودع.',
                500,
                ['logs' => $output]
            );
        }

        $this->gitService->runGit(['stash', '--include-untracked']);

        [$resetOut, $resetCode] = $this->gitService->runGit(['reset', '--hard', 'origin/main']);
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

        // الخطوة 5: تثبيت حزم npm
        if ($requiresNpm) {
            $this->buildService->installDependencies($output);
        }

        // الخطوة 6: بناء الـ frontend
        $this->buildService->buildFrontend($output);

        // الخطوة 7: تطبيق المهاجرات
        $output[] = '🗄️ تطبيق تحديثات قاعدة البيانات (إن وجدت)...';
        $t0 = microtime(true);
        require_once __DIR__ . '/../services/MigrationService.php';
        $migrationResult = (new MigrationService())->runAllMigrations(true);
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
}
