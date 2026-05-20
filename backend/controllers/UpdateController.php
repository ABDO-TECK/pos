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

    private GitService $gitService;
    private FrontendBuildService $buildService;
    private BackupService $backupService;
    private AuthService $authService;
    private \App\Services\UpdateService $updateService;

    public function __construct(GitService $gitService, FrontendBuildService $buildService, BackupService $backupService, AuthService $authService, \App\Services\UpdateService $updateService) {
        $this->gitService       = $gitService;
        $this->buildService     = $buildService;
        $this->backupService    = $backupService;
        $this->authService      = $authService;
        $this->updateService    = $updateService;
    }


    // ══════════════════════════════════════════════════════════════
    //  API Endpoints
    // ══════════════════════════════════════════════════════════════

    public function check() {
        $result = $this->updateService->checkForUpdate();
        if (!$result['ok']) {
            return Response::error($result['error'], 502);
        }
        unset($result['ok']);
        return Response::success($result);
    }

    public function changelog() {
        $remote = $this->updateService->fetchRemoteVersion();
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
            $backupDir = $this->updateService->getRootDir() . '/backend/storage/update-backups';
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
        $remote = $this->updateService->fetchRemoteVersion();
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
        $gitDir = $this->updateService->getRootDir() . DIRECTORY_SEPARATOR . '.git';

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
            $this->gitService->runGit(['config', '--global', '--add', 'safe.directory', $this->updateService->getRootDir()]);
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
            'from'    => $this->updateService->getLocalVersion()['version'] ?? '?',
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
