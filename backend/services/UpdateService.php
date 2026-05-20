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
    private string $repoUrl = 'https://api.github.com/repos/ABDO-TECK/pos/contents/version.json?ref=main';
    private string $localVersionFile;
    private string $rootDir;

    public function __construct()
    {
        $this->rootDir          = realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2);
        $this->localVersionFile = $this->rootDir . DIRECTORY_SEPARATOR . 'version.json';
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

    /**
     * مقارنة النسخة المحلية والبعيدة
     */
    public function checkForUpdate(): array
    {
        $local  = $this->getLocalVersion();
        $remote = $this->fetchRemoteVersion();

        if (!$remote) {
            return ['ok' => false, 'error' => 'تعذر الاتصال بخادم التحديثات.'];
        }

        return [
            'ok'                   => true,
            'current_version'      => $local['version'],
            'latest_version'       => $remote['version'],
            'has_update'           => version_compare($remote['version'], $local['version'], '>'),
            'released_at'          => $remote['released_at'] ?? null,
            'changelog'            => $remote['changelog'] ?? [],
            'requires_npm_install' => $remote['requires_npm_install'] ?? false,
        ];
    }
}
