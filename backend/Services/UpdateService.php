<?php

namespace App\Services;

use App\Helpers\EnvLoader;
use App\Helpers\Logger;
use Throwable;

/**
 * UpdateService — منطق فحص وتطبيق التحديثات بالاعتماد على منصة GitHub Releases
 * مع التحقق من التوقيع الرقمي RSA، التحديثات الجزئية الذرية، والنسخ الاحتياطي والتراجع التلقائي.
 */
class UpdateService
{
    private string $repoUrl;
    private string $localVersionFile;
    private string $rootDir;
    /** @var list<string> */
    private array $allowedUpdateHosts;

    private GitService $gitService;
    private FrontendBuildService $buildService;
    private BackupService $backupService;
    private DeltaUpdateService $deltaUpdateService;
    private UpdateManifestService $manifestService;
    private ?MigrationService $migrationService;
    private GitHubReleaseProvider $githubProvider;
    private ManifestSignatureService $signatureService;

    public function __construct(
        GitService $gitService,
        FrontendBuildService $buildService,
        BackupService $backupService,
        ?DeltaUpdateService $deltaUpdateService = null,
        ?UpdateManifestService $manifestService = null,
        ?MigrationService $migrationService = null,
        ?GitHubReleaseProvider $githubProvider = null,
        ?ManifestSignatureService $signatureService = null
    ) {
        $this->rootDir          = \Phar::running() ?: (realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2));
        $normalizedPath         = str_replace('\\', '/', $this->rootDir);
        $this->localVersionFile = $normalizedPath . '/version.json';
        $this->gitService       = $gitService;
        $this->buildService     = $buildService;
        $this->backupService    = $backupService;
        $this->manifestService  = $manifestService ?? new UpdateManifestService();
        $this->deltaUpdateService = $deltaUpdateService ?? new DeltaUpdateService($this->manifestService, $this->rootDir);
        $this->migrationService = $migrationService;
        $this->githubProvider   = $githubProvider ?? new GitHubReleaseProvider();
        $this->signatureService = $signatureService ?? new ManifestSignatureService();
        $this->repoUrl          = EnvLoader::get('UPDATE_SERVER_URL', 'https://api.github.com/repos/ABDO-TECK/pos/releases/latest');

        $defaultHosts = 'api.github.com,github.com,raw.githubusercontent.com,objects.githubusercontent.com,github-releases.githubusercontent.com';
        $configuredHosts = EnvLoader::get('UPDATE_ALLOWED_HOSTS', $defaultHosts);
        $this->allowedUpdateHosts = array_values(array_unique(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', $configuredHosts . ',' . $defaultHosts)
        ), static fn (string $host): bool => $host !== '')));
    }

    public function getRootDir(): string
    {
        return $this->rootDir;
    }

    public function getDeltaUpdateService(): DeltaUpdateService
    {
        return $this->deltaUpdateService;
    }

    public function getManifestService(): UpdateManifestService
    {
        return $this->manifestService;
    }

    public function getGitHubProvider(): GitHubReleaseProvider
    {
        return $this->githubProvider;
    }

    public function getSignatureService(): ManifestSignatureService
    {
        return $this->signatureService;
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
     * جلب النسخة البعيدة من GitHub Releases أو خادم التحديثات
     */
    public function fetchRemoteVersion(): ?array
    {
        $result = $this->fetchRemoteVersionDiagnostics();
        return $result['ok'] ? $result['data'] : null;
    }

    /**
     * جلب النسخة البعيدة مع تشخيص آمن لفحص التحديثات.
     */
    protected function fetchRemoteVersionDiagnostics(): array
    {
        if (!$this->isAllowedUpdateUrl($this->repoUrl)) {
            Logger::warning('fetchRemoteVersion rejected a non-allowlisted update URL', [
                'host' => $this->updateHostForLog($this->repoUrl),
                'error_code' => 'invalid_update_url',
            ]);
            return $this->remoteFailure('invalid_update_url', 'Configured update URL is not allowed.');
        }

        // If repoUrl points specifically to GitHub releases endpoint, use GitHubReleaseProvider
        if (str_contains($this->repoUrl, '/releases')) {
            $ghRelease = $this->githubProvider->getLatestRelease();
            if ($ghRelease['ok'] && !empty($ghRelease['latest_version'])) {
                return [
                    'ok' => true,
                    'data' => [
                        'version' => $ghRelease['latest_version'],
                        'tag_name' => $ghRelease['tag_name'],
                        'released_at' => $ghRelease['published_at'],
                        'release_url' => $ghRelease['release_url'],
                        'changelog' => $ghRelease['changelog'],
                        'manifest_url' => $ghRelease['manifest_url'],
                        'signature_url' => $ghRelease['signature_url'],
                        'delta_url' => $ghRelease['delta_url'],
                        'full_package_url' => $ghRelease['full_package_url'],
                        'assets' => $ghRelease['assets'],
                    ],
                    'checkedUrl' => $this->repoUrl,
                    'errorCode' => null,
                    'details' => null,
                ];
            }
        }

        // Direct HTTP request to UPDATE_SERVER_URL
        $curlOptions = [
            CURLOPT_URL            => $this->repoUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'ABDO-TECK-POS-Updater/1.0',
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.github.v3.raw, application/json',
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

        if ($httpCode >= 200 && $httpCode < 300 && $result) {
            $data = json_decode($result, true);
            if (!is_array($data) || empty($data['version']) || !is_string($data['version'])) {
                return $this->remoteFailure('invalid_version_json', 'Update server returned invalid version data.', $httpCode);
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
        $details = $httpCode > 0
            ? "Update server returned HTTP {$httpCode}."
            : 'Unable to contact the update server.';

        Logger::warning('fetchRemoteVersion failed', [
            'http_code' => $httpCode,
            'curl_err'  => $curlErr,
            'curl_errno' => $curlErrNo,
            'host' => $this->updateHostForLog($this->repoUrl),
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
        $candidates = [
            $this->rootDir . '/backend/certs/cacert.pem',
            $this->rootDir . '/certs/cacert.pem',
            dirname(__DIR__) . '/certs/cacert.pem',
        ];

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

        if (!$this->isAllowedUpdateUrl($this->repoUrl)) {
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

    private function isAllowedUpdateUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        foreach ($this->allowedUpdateHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function updateHostForLog(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower($host) : 'invalid';
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
     * مقارنة النسخة المحلية والبعيدة من GitHub Releases والتحقق من التوقيع الرقمي RSA.
     */
    public function checkForUpdate(): array
    {
        $local   = $this->getLocalVersion();
        $enabled = EnvLoader::getBool('ENABLE_UPDATE_CHECKS', true);

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
        $checkedUrl = $remoteResult['checkedUrl'] ?? $this->repoUrl;

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
                'checkedUrl'          => $checkedUrl,
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
                'message'             => 'تعذر قراءة ملف التحديثات من الخادم.',
                'current_version'     => $currentVersion,
                'latest_version'      => null,
                'has_update'          => false,
                'currentVersion'      => $currentVersion,
                'latestVersion'       => null,
                'updateAvailable'     => false,
                'checkedUrl'          => $checkedUrl,
                'errorCode'           => 'invalid_version_json',
                'details'             => 'Missing version field in version payload.',
                'changelog'           => [],
            ];
        }

        $hasUpdate = version_compare($latestVersion, $currentVersion, '>');

        // Check if delta update is available and verified in the GitHub Release assets
        $updateType = 'full';
        $isDelta = false;
        $deltaManifest = null;
        $deltaReason = null;
        $manifestUrl = $remote['manifest_url'] ?? null;
        $signatureUrl = $remote['signature_url'] ?? null;
        $deltaUrl = $remote['delta_url'] ?? null;

        if ($hasUpdate) {
            $manifestContent = null;
            $signatureContent = null;

            // 1. Direct manifest in payload
            if (isset($remote['manifest']) && is_array($remote['manifest'])) {
                $deltaManifest = $remote['manifest'];
            } elseif ($manifestUrl) {
                // 2. Download manifest.json from GitHub Release asset
                $mfFetch = $this->githubProvider->fetchAssetContent($manifestUrl);
                if ($mfFetch['ok'] && $mfFetch['content']) {
                    $manifestContent = $mfFetch['content'];
                    $decoded = json_decode($manifestContent, true);
                    if (is_array($decoded)) {
                        $deltaManifest = $decoded;
                    }
                }
            }

            if ($deltaManifest !== null) {
                // Verify digital signature if signature URL exists or manifest string exists
                $requireSignature = EnvLoader::getBool('REQUIRE_UPDATE_SIGNATURE', false);
                $signatureValid = true;

                if ($signatureUrl) {
                    $sigFetch = $this->githubProvider->fetchAssetContent($signatureUrl);
                    if ($sigFetch['ok'] && $sigFetch['content']) {
                        $signatureContent = $sigFetch['content'];
                    }
                }

                if ($manifestContent !== null && $signatureContent !== null) {
                    $signatureValid = $this->signatureService->verifySignature($manifestContent, $signatureContent);
                    if (!$signatureValid) {
                        Logger::warning('Update manifest signature verification failed!', [
                            'version' => $latestVersion,
                            'manifest_url' => $manifestUrl,
                        ]);
                        $deltaReason = 'Manifest digital signature verification failed.';
                    }
                } elseif ($requireSignature) {
                    $signatureValid = false;
                    $deltaReason = 'Release manifest is missing required cryptographic signature.';
                }

                if ($signatureValid) {
                    $validation = $this->manifestService->validateManifest($deltaManifest);
                    if ($validation['valid'] && $validation['manifest'] !== null) {
                        $manifestData = $validation['manifest'];
                        $engineCheck = $this->manifestService->checkEngineCompatibility($clientEngineVersion, $manifestData);

                        if (!$engineCheck['compatible']) {
                            $deltaReason = $engineCheck['reason'];
                        } elseif (!empty($manifestData['migration_release']) || ($manifestData['type'] ?? '') === 'full') {
                            $updateType = 'full';
                            $isDelta = false;
                            $deltaReason = 'Release is a full bootstrap migration package.';
                        } else {
                            $compat = $this->manifestService->checkVersionCompatibility($currentVersion, $manifestData);
                            if ($compat['compatible']) {
                                $updateType = 'delta';
                                $isDelta = true;
                                $deltaManifest = $manifestData;
                            } else {
                                $deltaReason = $compat['reason'];
                            }
                        }
                    } else {
                        $deltaReason = !empty($validation['errors']) ? implode('; ', $validation['errors']) : 'Invalid manifest structure.';
                    }
                }
            }
        }

        return [
            'success'               => true,
            'status'                => $hasUpdate ? 'update_available' : 'no_update_available',
            'ok'                    => true,
            'updates_disabled'      => false,
            'updates_unreachable'   => false,
            'message'               => $hasUpdate ? 'يتوفر تحديث جديد.' : 'النظام محدّث لأحدث إصدار.',
            'current_version'       => $currentVersion,
            'latest_version'        => $latestVersion,
            'update_engine_version' => $clientEngineVersion,
            'has_update'            => $hasUpdate,
            'currentVersion'        => $currentVersion,
            'latestVersion'         => $latestVersion,
            'updateAvailable'       => $hasUpdate,
            'checkedUrl'            => $checkedUrl,
            'errorCode'             => null,
            'details'               => null,
            'source'                => 'github_release',
            'release_tag'           => $remote['tag_name'] ?? "v{$latestVersion}",
            'release_url'           => $remote['release_url'] ?? null,
            'released_at'           => $remote['released_at'] ?? null,
            'changelog'             => $remote['changelog'] ?? [],
            'requires_npm_install'  => $remote['requires_npm_install'] ?? false,
            'update_type'           => $updateType,
            'is_delta'              => $isDelta,
            'manifest_url'          => $manifestUrl,
            'signature_url'         => $signatureUrl,
            'delta_url'             => $deltaUrl,
            'files_count'           => $deltaManifest ? count($deltaManifest['files'] ?? []) : null,
            'fallback_reason'       => $deltaReason,
        ];
    }


    /**
     * تطبيق التحديث بالاعتماد على GitHub Releases أو التحديث الكامل.
     *
     * @return array ['ok' => bool, 'data' => array|null, 'error' => string|null, 'code' => int]
     */
    public function applyUpdate(bool $force): array
    {
        $output = [];
        $currentVersion = $this->getLocalVersion()['version'] ?? '0.0.0';

        $enabled = EnvLoader::getBool('ENABLE_UPDATE_CHECKS', true);
        if (!$enabled || empty($this->repoUrl)) {
            return [
                'ok'               => false,
                'updates_disabled' => true,
                'error'            => 'خادم التحديثات غير مهيأ.',
                'code'             => 403,
                'data'             => ['logs' => $output]
            ];
        }

        if (!$this->isAllowedUpdateUrl($this->repoUrl)) {
            return [
                'ok' => false,
                'error' => 'Automatic updates are not configured for an approved server.',
                'code' => 400,
                'data' => ['logs' => $output],
            ];
        }

        // فحص مساحة القرص قبل البدء
        $diskCheck = $this->deltaUpdateService->checkDiskSpace(104857600); // 100 MB
        if (!$diskCheck['ok']) {
            $err = $diskCheck['error'] ?? 'مساحة القرص غير كافية لإجراء التحديث.';
            Logger::error('Update aborted: insufficient disk space', ['free_bytes' => $diskCheck['free_bytes']]);
            return [
                'ok' => false,
                'error' => $err,
                'code' => 507,
                'data' => ['logs' => $output],
            ];
        }

        // الخطوة 1: نسخة احتياطية من قاعدة البيانات

        $output[] = '💾 إنشاء نسخة احتياطية من قاعدة البيانات...';
        $t0 = microtime(true);
        $backupFile = null;
        try {
            $backupDir = $this->getRootDir() . '/backend/storage/update-backups';
            $backupFile = $this->backupService->createBackupFile($backupDir);
            $elapsed    = round(microtime(true) - $t0, 1);
            $output[]   = "✅ تم إنشاء النسخة الاحتياطية لقاعدة البيانات: " . basename($backupFile) . " ({$elapsed}s)";
        } catch (Throwable $e) {
            $reference = bin2hex(random_bytes(8));
            Logger::error('Update: database backup failed', [
                'reference' => $reference,
                'exception' => get_class($e),
            ]);
            $this->deltaUpdateService->setUpdateState('failed', ['error' => "DB backup failed: {$reference}"]);
            return [
                'ok' => false,
                'error' => "Backup failed. Reference: {$reference}",
                'code' => 500,
                'data' => ['logs' => $output],
            ];
        }

        // الخطوة 2: جلب معلومات الإصدار البعيد
        $output[] = '🌐 الاتصال بخادم التحديثات...';
        $remote = $this->fetchRemoteVersion();
        if (!$remote) {
            return ['ok' => false, 'error' => 'تعذر الاتصال بخادم التحديثات. تحقق من اتصالك بالإنترنت.', 'code' => 502, 'data' => ['logs' => $output]];
        }
        $targetVersion = $remote['version'] ?? 'unknown';
        $releaseTag = $remote['tag_name'] ?? "v{$targetVersion}";
        $output[] = "✅ الإصدار المتاح: {$releaseTag}";

        // التحقق مما إذا كان هناك مانيفست دلتا متوافق
        $potentialManifest = $remote['manifest'] ?? (isset($remote['files']) && is_array($remote['files']) ? $remote : null);

        // Fetch manifest if URL provided
        $manifestContent = null;
        $signatureContent = null;
        if ($potentialManifest === null && !empty($remote['manifest_url'])) {
            $mfRes = $this->githubProvider->fetchAssetContent($remote['manifest_url']);
            if ($mfRes['ok'] && $mfRes['content']) {
                $manifestContent = $mfRes['content'];
                $potentialManifest = json_decode($manifestContent, true);
            }
        }

        if (!empty($remote['signature_url'])) {
            $sigRes = $this->githubProvider->fetchAssetContent($remote['signature_url']);
            if ($sigRes['ok'] && $sigRes['content']) {
                $signatureContent = $sigRes['content'];
            }
        }

        // Verify digital signature if present
        if ($manifestContent && $signatureContent) {
            if (!$this->signatureService->verifySignature($manifestContent, $signatureContent)) {
                $err = 'فشل التحقق من التوقيع الرقمي لملف التحديث (Digital Signature Mismatch). تم رفض التحديث لأسباب أمنية.';
                Logger::error('Rejected update due to invalid signature', ['tag' => $releaseTag]);
                $this->deltaUpdateService->recordHistory(
                    $currentVersion,
                    $targetVersion,
                    'delta',
                    'failed',
                    0,
                    null,
                    $err,
                    'github_release',
                    $releaseTag
                );
                return ['ok' => false, 'error' => $err, 'code' => 403, 'data' => ['logs' => $output]];
            }
            $output[] = '✅ تم التحقق بنجاح من التوقيع الرقمي RSA المعتمد لحزمة التحديث.';
        }

        if (is_array($potentialManifest)) {
            $val = $this->manifestService->validateManifest($potentialManifest);
            if ($val['valid'] && $val['manifest'] !== null) {
                $compat = $this->manifestService->checkVersionCompatibility(
                    $currentVersion,
                    $val['manifest'],
                    $force
                );

                if ($compat['compatible']) {
                    $manifest = $val['manifest'];
                    $output[] = '📦 تجهيز التحديث عبر نظام التحديث الجزئي الآمن (Delta Update)...';

                    // 1. تحميل الملفات أو حزمة ZIP إلى مجلد التجهيز
                    $deltaZipUrl = $remote['delta_url'] ?? null;
                    if ($deltaZipUrl) {
                        $downloadResult = $this->deltaUpdateService->downloadReleaseZipToStaging(
                            $manifest,
                            $deltaZipUrl,
                            $this->githubProvider
                        );
                    } else {
                        $baseUrl = $remote['download_url'] ?? dirname($this->repoUrl);
                        $downloadResult = $this->deltaUpdateService->downloadFilesToStaging($manifest, $baseUrl);
                    }

                    $output = array_merge($output, $downloadResult['logs']);

                    if ($downloadResult['ok']) {
                        // 2. أخذ لقطة احتياطية كاملة للملفات السابقة
                        $output[] = '📸 أخذ لقطة احتياطية كاملة للملفات السابقة...';
                        $snapshot = $this->deltaUpdateService->createBackupSnapshot(
                            $currentVersion,
                            $targetVersion,
                            $manifest,
                            $backupFile
                        );

                        if (!$snapshot['ok']) {
                            $err = "فشل إنشاء اللقطة الاحتياطية للملفات: {$snapshot['error']}";
                            $this->deltaUpdateService->recordHistory(
                                $currentVersion,
                                $targetVersion,
                                'delta',
                                'failed',
                                0,
                                null,
                                $err,
                                'github_release',
                                $releaseTag
                            );
                            return [
                                'ok' => false,
                                'error' => $err,
                                'code' => 500,
                                'data' => ['logs' => $output],
                            ];
                        }
                        $output[] = '✅ تم إنشاء لقطة النسخ الاحتياطي الذرية بنجاح.';

                        // 3. الاستبدال الذري للملفات مع التراجع التلقائي في حال الخطأ
                        $applyResult = $this->deltaUpdateService->applyStagedFiles($manifest, $snapshot['snapshot_path']);
                        $output = array_merge($output, $applyResult['logs']);

                        if (!$applyResult['ok']) {
                            $err = 'فشل استبدال ملفات التحديث وتم التراجع التلقائي: ' . implode('; ', $applyResult['errors']);
                            $this->deltaUpdateService->recordHistory(
                                $currentVersion,
                                $targetVersion,
                                'delta',
                                'rolled_back',
                                count($applyResult['applied_files']),
                                $snapshot['snapshot_path'],
                                $err,
                                'github_release',
                                $releaseTag
                            );
                            return [
                                'ok' => false,
                                'error' => $err,
                                'code' => 500,
                                'data' => ['logs' => $output],
                            ];
                        }

                        // 4. تطبيق تحديثات قاعدة البيانات
                        $this->deltaUpdateService->setUpdateState('migrating');
                        $output[] = '🗄️ تطبيق تحديثات قاعدة البيانات (إن وجدت)...';
                        require_once __DIR__ . '/MigrationService.php';
                        $migrationService = $this->migrationService ?? new MigrationService();
                        $migrationResult = $migrationService->runAllMigrations(true);

                        if (!empty($migrationResult['errors'])) {
                            $migrationErr = 'فشل ترحيل قاعدة البيانات: ' . implode('; ', $migrationResult['errors']);
                            $output[] = "❌ {$migrationErr}";
                            $output[] = '🔄 جاري التراجع التلقائي عن ملفات التحديث لحماية سلامة النظام...';

                            $this->deltaUpdateService->rollbackFiles($snapshot['snapshot_path']);
                            $this->deltaUpdateService->recordHistory(
                                $currentVersion,
                                $targetVersion,
                                'delta',
                                'rolled_back',
                                count($applyResult['applied_files']),
                                $snapshot['snapshot_path'],
                                $migrationErr,
                                'github_release',
                                $releaseTag
                            );

                            return [
                                'ok' => false,
                                'error' => "{$migrationErr} (تم التراجع التلقائي بنجاح).",
                                'code' => 500,
                                'data' => ['logs' => $output],
                            ];
                        }

                        if ($migrationResult['executed'] > 0) {
                            $output[] = "✅ تم تطبيق {$migrationResult['executed']} تحديث(ات) لقاعدة البيانات";
                        } else {
                            $output[] = "✅ قاعدة البيانات محدثة سلفاً";
                        }

                        $output[] = '';
                        $output[] = '🎉 تم استكمال التحديث الجزئي بنجاح إلى v' . $targetVersion;

                        $this->deltaUpdateService->recordHistory(
                            $currentVersion,
                            $targetVersion,
                            'delta',
                            'success',
                            count($applyResult['applied_files']),
                            $snapshot['snapshot_path'],
                            null,
                            'github_release',
                            $releaseTag,
                            $deltaZipUrl
                        );

                        Logger::info('Delta update completed successfully', [
                            'from' => $currentVersion,
                            'to' => $targetVersion,
                            'files_count' => count($applyResult['applied_files']),
                        ]);

                        return ['ok' => true, 'data' => [
                            'message' => 'تم استكمال التحديث بنجاح',
                            'latest_version' => $targetVersion,
                            'release_tag' => $releaseTag,
                            'update_type' => 'delta',
                            'applied_files' => $applyResult['applied_files'],
                            'changelog' => $manifest['changelog'] ?? ($remote['changelog'] ?? []),
                            'logs' => $output,
                        ]];
                    }

                    $output[] = '⚠️ تعذر إكمال تحميل ملفات التحديث الجزئي، الانتقال إلى التحديث الكامل كإجراء احتياطي...';
                } else {
                    $output[] = "ℹ️ التحديث الجزئي غير متوافق: {$compat['reason']}. جاري الانتقال للتحديث الكامل...";
                }
            }
        }

        // ══════════════════════════════════════════════════════════════
        // Fallback: Full Git Package Update Mechanism
        // ══════════════════════════════════════════════════════════════
        $output[] = '🔄 تشغيل آلية التحديث الكامل الاحتياطية (Full Update)...';

        $expectedCommit = strtolower(trim(
            EnvLoader::get('UPDATE_COMMIT_SHA', '')
        ));
        if (!preg_match('/\A[0-9a-f]{40}\z/i', $expectedCommit)) {
            return [
                'ok' => false,
                'error' => 'Full update fallback is disabled until UPDATE_COMMIT_SHA is configured.',
                'code' => 503,
                'data' => ['logs' => $output],
            ];
        }

        // فحص Git
        $output[] = '🔧 التحقق من Git...';
        $diag = $this->gitService->diagnoseGit();
        $gitDir = $this->getRootDir() . DIRECTORY_SEPARATOR . '.git';

        if (!is_dir($gitDir) && !file_exists($gitDir)) {
            [$revOut, $revCode] = $this->gitService->runGit(['rev-parse', '--git-dir']);
            if ($revCode !== 0) {
                Logger::error('Update: .git not found', $diag);
                return ['ok' => false, 'error' => 'Automatic update is unavailable for this installation.', 'code' => 400, 'data' => ['logs' => $output]];
            }
        }

        $this->gitService->ensureSafeDirectory();

        [$testOut, $testCode] = $this->gitService->runGit(['status', '--porcelain']);
        if ($testCode !== 0) {
            $this->gitService->runGit(['config', '--global', '--add', 'safe.directory', $this->getRootDir()]);
        }

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

        if (!empty($significantChanges) && !$force) {
            $output[] = '⚠️ توجد تعديلات محلية.';
            return ['ok' => false, 'error' => 'يوجد تعديلات محلية في ملفات النظام. إذا قمت بالتحديث، سيتم استبدال هذه التعديلات. هل أنت متأكد من رغبتك في المتابعة ومسح التعديلات المحلية؟', 'code' => 409, 'data' => ['logs' => $output, 'local_changes' => $significantChanges]];
        }

        // سحب التحديثات من Git
        $output[] = '📥 سحب التحديثات من خادم Git...';
        $t0 = microtime(true);

        [$fetchOut, $fetchCode] = $this->gitService->runGit(['fetch', '--depth=1', 'origin', 'main', '--force']);
        $output = array_merge($output, array_filter($fetchOut, fn($l) => trim($l) !== ''));

        if ($fetchCode !== 0) {
            Logger::error('Update: git fetch failed', ['code' => $fetchCode, 'output' => $fetchOut]);
            return ['ok' => false, 'error' => 'فشل أمر git fetch — تحقق من اتصال الإنترنت ومن إعدادات المستودع.', 'code' => 500, 'data' => ['logs' => $output]];
        }

        [$headOut, $headCode] = $this->gitService->runGit(['rev-parse', 'FETCH_HEAD']);
        $fetchedCommit = strtolower(trim(implode("\n", $headOut)));
        if ($headCode !== 0 || !hash_equals($expectedCommit, $fetchedCommit)) {
            Logger::error('Update: fetched commit did not match the pinned release', [
                'expected' => $expectedCommit,
                'fetched' => $fetchedCommit,
            ]);
            return ['ok' => false, 'error' => 'The remote release does not match the trusted release.', 'code' => 409, 'data' => ['logs' => $output]];
        }

        [, $verifyCode] = $this->gitService->runGit(['verify-commit', '--strict', $fetchedCommit]);
        if ($verifyCode !== 0) {
            Logger::error('Update: release commit signature verification failed', [
                'commit' => $fetchedCommit,
            ]);
            return ['ok' => false, 'error' => 'Release signature verification failed.', 'code' => 412, 'data' => ['logs' => $output]];
        }

        $this->gitService->runGit(['stash', '--include-untracked']);

        [$resetOut, $resetCode] = $this->gitService->runGit(['reset', '--hard', $fetchedCommit]);
        $output = array_merge($output, array_filter($resetOut, fn($l) => trim($l) !== ''));

        if ($resetCode !== 0) {
            Logger::error('Update: git reset failed', ['code' => $resetCode, 'output' => $resetOut]);
            return ['ok' => false, 'error' => 'فشل أمر git reset — تحقق من صلاحيات المجلد.', 'code' => 500, 'data' => ['logs' => $output]];
        }

        $elapsed  = round(microtime(true) - $t0, 1);
        $output[] = "✅ تم سحب التحديثات ({$elapsed}s)";

        // تثبيت حزم npm
        $requiresNpm = $remote['requires_npm_install'] ?? false;
        if ($requiresNpm) {
            $this->buildService->installDependencies($output);
        }

        // بناء الـ frontend
        $this->buildService->buildFrontend($output);

        // تطبيق المهاجرات
        $output[] = '🗄️ تطبيق تحديثات قاعدة البيانات (إن وجدت)...';
        $t0 = microtime(true);
        require_once __DIR__ . '/MigrationService.php';
        $migrationService = $this->migrationService ?? new MigrationService();
        $migrationResult = $migrationService->runAllMigrations(true);
        $elapsed = round(microtime(true) - $t0, 1);
        if ($migrationResult['executed'] > 0) {
            $output[] = "✅ تم تطبيق {$migrationResult['executed']} تحديث(ات) لقاعدة البيانات ({$elapsed}s)";
        } else {
            $output[] = "✅ قاعدة البيانات محدثة سلفاً ({$elapsed}s)";
        }

        $output[] = '';
        $output[] = '🎉 تم استكمال التحديث بنجاح إلى v' . $targetVersion;

        $this->deltaUpdateService->recordHistory(
            $currentVersion,
            $targetVersion,
            'full',
            'success',
            0,
            null,
            null,
            'git_fallback',
            $releaseTag
        );

        Logger::info('Full update applied successfully', [
            'from'    => $currentVersion,
            'to'      => $targetVersion,
        ]);

        return ['ok' => true, 'data' => [
            'message'        => 'تم استكمال التحديث بنجاح',
            'latest_version' => $targetVersion,
            'update_type'    => 'full',
            'changelog'      => $remote['changelog'] ?? [],
            'logs'           => $output,
        ]];
    }

    public function rollbackUpdate(?string $snapshotPath = null): array
    {
        return $this->deltaUpdateService->rollbackUpdate($snapshotPath);
    }
}
