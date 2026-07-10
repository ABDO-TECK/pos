<?php

namespace App\Services;

use App\Helpers\Cache;
use App\Helpers\Logger;
use Throwable;

/**
 * UpdateService — منطق فحص التحديثات ومقارنة الإصدارات.
 *
 * يستخرج Business Logic من UpdateController ليبقى الكونترولر
 * مسؤولاً فقط عن HTTP request/response.
 */
class UpdateService
{
    private string $repoUrl;
    private string $localVersionFile;
    private string $rootDir;

    private GitService $gitService;
    private FrontendBuildService $buildService;
    private BackupService $backupService;

    public function __construct(
        GitService $gitService,
        FrontendBuildService $buildService,
        BackupService $backupService
    ) {
        $this->rootDir          = \Phar::running() ?: (realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2));
        // Standardize separators to forward slashes for phar wrapper compatibility
        $normalizedPath         = str_replace('\\', '/', $this->rootDir);
        $this->localVersionFile = $normalizedPath . '/version.json';
        $this->gitService       = $gitService;
        $this->buildService     = $buildService;
        $this->backupService    = $backupService;
        $this->repoUrl          = \App\Helpers\EnvLoader::get('UPDATE_SERVER_URL', 'https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main');
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    /**
     * قراءة النسخة المحلية من version.json
     */
    public function getLocalVersion(): array
    {
        if (!file_exists($this->localVersionFile)) {
            return ['version' => '0.0.0', 'released_at' => null, 'changelog' => []];
        }
        $content = @file_get_contents($this->localVersionFile);
        $data    = $content ? json_decode($content, true) : null;
        return is_array($data) ? $data : ['version' => '0.0.0', 'released_at' => null, 'changelog' => []];
    }

    /**
     * جلب النسخة البعيدة من GitHub
     */
    public function fetchRemoteVersion(): ?array
    {
        $result = $this->fetchRemoteVersionDiagnostics();
        return $result['ok'] ? $result['data'] : null;
    }

    /**
     * جلب النسخة البعيدة مع تشخيص آمن لفحص التحديثات اليدوي.
     */
    protected function fetchRemoteVersionDiagnostics(): array
    {
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

        $certPath = $this->resolveCurlCaBundlePath();
        if (file_exists($certPath)) {
            $curlOptions[CURLOPT_CAINFO] = $certPath;
        }

        $response = $this->executeRemoteVersionRequest($curlOptions);
        $result   = $response['body'];
        $httpCode = (int) $response['httpCode'];
        $curlErr  = (string) $response['curlErr'];
        $curlErrNo = (int) $response['curlErrNo'];

        if ($httpCode === 200 && $result) {
            $data = json_decode($result, true);
            if (!is_array($data) || empty($data['version']) || !is_string($data['version'])) {
                return $this->remoteFailure('invalid_version_json', 'GitHub returned invalid version.json.', $httpCode);
            }

            return [
                'ok' => true,
                'data' => $data,
                'checkedUrl' => $this->repoUrl,
                'errorCode' => null,
                'details' => null,
            ];
        }

        $errorCode = $this->classifyRemoteFailure($httpCode, $curlErr, $curlErrNo);
        $details = $curlErr !== ''
            ? $curlErr
            : ($httpCode > 0 ? "GitHub returned HTTP {$httpCode}." : 'No response received from GitHub.');

        Logger::warning('fetchRemoteVersion failed', [
            'http_code' => $httpCode,
            'curl_err'  => $curlErr,
            'curl_errno' => $curlErrNo,
            'checked_url' => $this->repoUrl,
            'error_code' => $errorCode,
        ]);
        return $this->remoteFailure($errorCode, $details, $httpCode);
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @return array{body: string|false, httpCode: int, curlErr: string, curlErrNo: int}
     */
    protected function executeRemoteVersionRequest(array $curlOptions): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, $curlOptions);
        $result = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        $curlErrNo = curl_errno($ch);
        curl_close($ch);

        return [
            'body' => $result,
            'httpCode' => $httpCode,
            'curlErr' => $curlErr,
            'curlErrNo' => $curlErrNo,
        ];
    }

    private function resolveCurlCaBundlePath(): string
    {
        $pharPath = \Phar::running();
        $candidates = [];

        if ($pharPath !== '') {
            $filesystemPharPath = preg_replace('#^phar://#', '', str_replace('\\', '/', $pharPath));
            if (is_string($filesystemPharPath) && $filesystemPharPath !== '') {
                $candidates[] = dirname($filesystemPharPath) . '/certs/cacert.pem';
            }
        }

        $candidates[] = str_replace('\\', '/', dirname(__DIR__) . '/certs/cacert.pem');
        $candidates[] = str_replace('\\', '/', $this->rootDir . '/backend/certs/cacert.pem');
        $candidates[] = str_replace('\\', '/', $this->rootDir . '/certs/cacert.pem');

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? '';
    }

    private function classifyRemoteFailure(int $httpCode, string $curlErr, int $curlErrNo): string
    {
        $lowerError = strtolower($curlErr);

        if (!filter_var($this->repoUrl, FILTER_VALIDATE_URL)) {
            return 'invalid_update_url';
        }

        if ($curlErrNo === 28 || str_contains($lowerError, 'timed out')) {
            return 'github_network_timeout';
        }

        if ($curlErrNo !== 0 && (str_contains($lowerError, 'ssl') || str_contains($lowerError, 'certificate'))) {
            return 'github_ssl_error';
        }

        if ($httpCode === 404) {
            return 'github_http_404';
        }

        if ($httpCode === 403) {
            return 'github_http_403_rate_limited';
        }

        return $curlErrNo !== 0 ? 'github_network_timeout' : 'invalid_version_json';
    }

    private function remoteFailure(string $errorCode, string $details, int $httpCode = 0): array
    {
        return [
            'ok' => false,
            'data' => null,
            'checkedUrl' => $this->repoUrl,
            'errorCode' => $errorCode,
            'details' => $httpCode > 0 && !str_contains($details, 'HTTP')
                ? "HTTP {$httpCode}: {$details}"
                : $details,
        ];
    }

    /**
     * مقارنة النسخة المحلية والبعيدة
     */
    public function checkForUpdate(): array
    {
        $local   = $this->getLocalVersion();
        $enabled = \App\Helpers\EnvLoader::getBool('ENABLE_UPDATE_CHECKS', true);

        if (!$enabled || empty($this->repoUrl)) {
            return [
                'success'             => false,
                'status'              => 'invalid_update_url',
                'ok'                  => true,
                'updates_disabled'    => true,
                'updates_unreachable' => false,
                'message'             => 'خادم التحديثات غير مهيأ.',
                'current_version'     => $local['version'] ?? '0.0.0',
                'latest_version'      => null,
                'has_update'          => false,
                'currentVersion'      => $local['version'] ?? '0.0.0',
                'latestVersion'       => null,
                'updateAvailable'     => false,
                'checkedUrl'          => $this->repoUrl,
                'errorCode'           => 'invalid_update_url',
                'details'             => $enabled ? 'UPDATE_SERVER_URL is empty.' : 'ENABLE_UPDATE_CHECKS is disabled.',
                'changelog'           => [],
            ];
        }

        $remoteResult = $this->fetchRemoteVersionDiagnostics();
        $remote = $remoteResult['ok'] ? $remoteResult['data'] : null;

        if (!$remote) {
            return [
                'success'             => false,
                'status'              => $remoteResult['errorCode'] ?? 'github_network_timeout',
                'ok'                  => true,
                'updates_disabled'    => false,
                'updates_unreachable' => true,
                'message'             => 'تعذر الاتصال بخادم التحديثات. تحقق من الاتصال أو إعدادات الخادم.',
                'current_version'     => $local['version'] ?? '0.0.0',
                'latest_version'      => null,
                'has_update'          => false,
                'currentVersion'      => $local['version'] ?? '0.0.0',
                'latestVersion'       => null,
                'updateAvailable'     => false,
                'checkedUrl'          => $remoteResult['checkedUrl'] ?? $this->repoUrl,
                'errorCode'           => $remoteResult['errorCode'] ?? 'github_network_timeout',
                'details'             => $remoteResult['details'] ?? null,
                'changelog'           => [],
            ];
        }

        $currentVersion = $local['version'] ?? '0.0.0';
        $latestVersion = $remote['version'] ?? null;
        if (!is_string($latestVersion) || $latestVersion === '') {
            return [
                'success'             => false,
                'status'              => 'invalid_version_json',
                'ok'                  => true,
                'updates_disabled'    => false,
                'updates_unreachable' => true,
                'message'             => 'تعذر قراءة ملف التحديثات من GitHub.',
                'current_version'     => $currentVersion,
                'latest_version'      => null,
                'has_update'          => false,
                'currentVersion'      => $currentVersion,
                'latestVersion'       => null,
                'updateAvailable'     => false,
                'checkedUrl'          => $remoteResult['checkedUrl'] ?? $this->repoUrl,
                'errorCode'           => 'invalid_version_json',
                'details'             => 'Missing version field in version.json.',
                'changelog'           => [],
            ];
        }

        $hasUpdate = version_compare($latestVersion, $currentVersion, '>');

        return [
            'success'             => true,
            'status'              => $hasUpdate ? 'update_available' : 'no_update_available',
            'ok'                  => true,
            'updates_disabled'    => false,
            'updates_unreachable' => false,
            'message'             => $hasUpdate ? 'يتوفر تحديث جديد.' : 'النظام محدّث لأحدث إصدار.',
            'current_version'     => $currentVersion,
            'latest_version'      => $latestVersion,
            'has_update'          => $hasUpdate,
            'currentVersion'      => $currentVersion,
            'latestVersion'       => $latestVersion,
            'updateAvailable'     => $hasUpdate,
            'checkedUrl'          => $remoteResult['checkedUrl'] ?? $this->repoUrl,
            'errorCode'           => null,
            'details'             => null,
            'released_at'         => $remote['released_at'] ?? null,
            'changelog'           => $remote['changelog'] ?? [],
            'requires_npm_install'=> $remote['requires_npm_install'] ?? false,
        ];
    }

    /**
     * تنفيذ عملية التحديث بالكامل.
     * @return array ['ok' => bool, 'data' => array|null, 'error' => string|null, 'code' => int]
     */
    public function applyUpdate(bool $force): array
    {
        $output = [];

        $enabled = \App\Helpers\EnvLoader::getBool('ENABLE_UPDATE_CHECKS', true);
        if (!$enabled || empty($this->repoUrl)) {
            return [
                'ok'               => false,
                'updates_disabled' => true,
                'error'            => 'خادم التحديثات غير مهيأ.',
                'code'             => 403,
                'data'             => ['logs' => $output]
            ];
        }

        // الخطوة 0: تشخيص البيئة
        $output[] = '🔍 فحص البيئة...';
        $diag = $this->gitService->diagnoseGit();
        Logger::info('Update: environment diagnosis', $diag);

        // الخطوة 1: نسخة احتياطية من قاعدة البيانات
        $output[] = '💾 إنشاء نسخة احتياطية من قاعدة البيانات...';
        $t0 = microtime(true);
        try {
            $backupDir = $this->getRootDir() . '/backend/storage/update-backups';
            $backupFile = $this->backupService->createBackupFile($backupDir);
            $elapsed    = round(microtime(true) - $t0, 1);
            $output[]   = "✅ تم إنشاء النسخة الاحتياطية: " . basename($backupFile) . " ({$elapsed}s)";
        } catch (Throwable $e) {
            Logger::error('Update: backup failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'فشل إنشاء نسخة احتياطية من قاعدة البيانات: ' . $e->getMessage(), 'code' => 500, 'data' => ['logs' => $output]];
        }

        // الخطوة 2: جلب معلومات الإصدار البعيد
        $output[] = '🌐 الاتصال بخادم التحديثات...';
        $remote = $this->fetchRemoteVersion();
        if (!$remote) {
            return ['ok' => false, 'error' => 'تعذر الاتصال بخادم التحديثات. تحقق من اتصالك بالإنترنت.', 'code' => 502, 'data' => ['logs' => $output]];
        }
        $output[] = '✅ الإصدار المتاح: v' . ($remote['version'] ?? '?');
        $requiresNpm = $remote['requires_npm_install'] ?? false;

        // الخطوة 3: فحص Git
        $output[] = '🔧 التحقق من Git...';
        $gitDir = $this->getRootDir() . DIRECTORY_SEPARATOR . '.git';

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
                return ['ok' => false, 'error' => 'لا يمكن التحديث التلقائي: المجلد ليس مستنسخاً عبر Git (لا يوجد .git).' . "\n" . 'الحل: افتح Terminal وشغّل:' . "\n" . 'cd C:\xampp\htdocs && git clone https://github.com/ABDO-TECK/pos.git', 'code' => 400, 'data' => ['logs' => $output, 'diagnostics' => $diag]];
            }
        }
        $output[] = '✅ مجلد .git موجود';

        // الخطوة 3.1: ضمان safe.directory
        $output[] = '🔐 ضبط صلاحيات Git (safe.directory)...';
        $this->gitService->ensureSafeDirectory();

        [$testOut, $testCode] = $this->gitService->runGit(['status', '--porcelain']);
        if ($testCode !== 0) {
            $errMsg = implode(' ', $testOut);
            $this->gitService->runGit(['config', '--global', '--add', 'safe.directory', $this->getRootDir()]);
            [$testOut2, $testCode2] = $this->gitService->runGit(['status', '--porcelain']);
            if ($testCode2 !== 0) {
                // Do NOT use '*' — it disables ownership checks for ALL repos on this machine.
                // Instead, try adding the specific directory with --system scope.
                $this->gitService->runGit(['config', '--system', '--add', 'safe.directory', $this->getRootDir()]);
                [$testOut3, $testCode3] = $this->gitService->runGit(['status', '--porcelain']);
                if ($testCode3 !== 0) {
                    Logger::error('Update: git status failed after safe.directory fix', [
                        'output' => $testOut3, 'code' => $testCode3, 'diag' => $diag
                    ]);
                    return ['ok' => false, 'error' => 'Git لا يعمل تحت Apache. ' . implode(' ', $testOut3), 'code' => 500, 'data' => ['logs' => $output, 'diagnostics' => $diag]];
                }
            }
        }
        $output[] = '✅ Git يعمل بشكل سليم';

        // الخطوة 3.2: التأكد من عدم وجود تعديلات محلية تمنع التحديث
        [$statusOut, $statusCode] = $this->gitService->runGit(['status', '--porcelain']);
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
            if (str_starts_with($line, '??')) return false;
            foreach ($ignoredPatterns as $pattern) {
                if (str_contains($line, $pattern)) return false;
            }
            return true;
        });
        $hasLocalChanges = !empty($significantChanges);

        if ($hasLocalChanges && !$force) {
            $output[] = '⚠️ توجد تعديلات محلية.';
            return ['ok' => false, 'error' => 'يوجد تعديلات محلية في ملفات النظام. إذا قمت بالتحديث، سيتم استبدال هذه التعديلات. هل أنت متأكد من رغبتك في المتابعة ومسح التعديلات المحلية؟', 'code' => 409, 'data' => ['logs' => $output, 'local_changes' => $significantChanges]];
        }

        // الخطوة 4: سحب التحديثات
        $output[] = '📥 سحب التحديثات من GitHub...';
        $t0 = microtime(true);

        [$fetchOut, $fetchCode] = $this->gitService->runGit(['fetch', 'origin', 'main', '--force']);
        $output = array_merge($output, array_filter($fetchOut, fn($l) => trim($l) !== ''));

        if ($fetchCode !== 0) {
            Logger::error('Update: git fetch failed', ['code' => $fetchCode, 'output' => $fetchOut]);
            return ['ok' => false, 'error' => 'فشل أمر git fetch — تحقق من اتصال الإنترنت ومن إعدادات المستودع.', 'code' => 500, 'data' => ['logs' => $output]];
        }

        $this->gitService->runGit(['stash', '--include-untracked']);

        [$resetOut, $resetCode] = $this->gitService->runGit(['reset', '--hard', 'origin/main']);
        $output = array_merge($output, array_filter($resetOut, fn($l) => trim($l) !== ''));

        if ($resetCode !== 0) {
            Logger::error('Update: git reset failed', ['code' => $resetCode, 'output' => $resetOut]);
            return ['ok' => false, 'error' => 'فشل أمر git reset — تحقق من صلاحيات المجلد.', 'code' => 500, 'data' => ['logs' => $output]];
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
        require_once __DIR__ . '/MigrationService.php';
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

        return ['ok' => true, 'data' => [
            'message'        => 'تم استكمال التحديث بنجاح',
            'latest_version' => $remote['version'] ?? 'unknown',
            'changelog'      => $remote['changelog'] ?? [],
            'logs'           => $output,
        ]];
    }
}
