<?php

namespace App\Services;

use App\Helpers\EnvLoader;
use App\Helpers\Logger;
use Throwable;

class GitHubReleaseProvider
{
    private string $owner;
    private string $repo;
    private ?string $token;
    private ?string $caCertPath;
    private int $timeout;
    /** @var list<string> */
    private array $allowedHosts;

    public function __construct(
        ?string $owner = null,
        ?string $repo = null,
        ?string $token = null,
        ?string $caCertPath = null,
        int $timeout = 15
    ) {
        $this->owner = $owner ?? EnvLoader::get('UPDATE_REPO_OWNER', 'ABDO-TECK');
        $this->repo = $repo ?? EnvLoader::get('UPDATE_REPO_NAME', 'pos');
        $this->token = $token ?? EnvLoader::get('GITHUB_TOKEN');
        $this->timeout = $timeout;
        $this->caCertPath = $caCertPath ?? $this->resolveCaCertPath();

        $defaultHosts = 'api.github.com,github.com,raw.githubusercontent.com,objects.githubusercontent.com,github-releases.githubusercontent.com';
        $configuredHosts = EnvLoader::get('UPDATE_ALLOWED_HOSTS', $defaultHosts);
        $this->allowedHosts = array_values(array_unique(array_filter(array_map(
            static fn (string $h): string => strtolower(trim($h)),
            explode(',', $configuredHosts . ',' . $defaultHosts)
        ), static fn (string $h): bool => $h !== '')));
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function getRepo(): string
    {
        return $this->repo;
    }

    /**
     * Fetch the latest stable release from GitHub Releases API.
     *
     * @return array{
     *   ok: bool,
     *   latest_version: ?string,
     *   tag_name: ?string,
     *   release_url: ?string,
     *   published_at: ?string,
     *   changelog: list<string>,
     *   manifest_url: ?string,
     *   signature_url: ?string,
     *   delta_url: ?string,
     *   full_package_url: ?string,
     *   assets: array<string, string>,
     *   error: ?string,
     *   error_code: ?string
     * }
     */
    /**
     * Fetch the latest release from GitHub Releases API for a specified channel.
     *
     * @param string $channel Target channel ('stable', 'beta', 'rc')
     * @return array{
     *   ok: bool,
     *   latest_version: ?string,
     *   tag_name: ?string,
     *   release_url: ?string,
     *   published_at: ?string,
     *   changelog: list<string>,
     *   manifest_url: ?string,
     *   signature_url: ?string,
     *   delta_url: ?string,
     *   full_package_url: ?string,
     *   assets: array<string, string>,
     *   channel: string,
     *   error: ?string,
     *   error_code: ?string
     * }
     */
    public function getLatestRelease(string $channel = 'stable'): array
    {
        $channel = strtolower(trim($channel ?: 'stable'));
        if (!in_array($channel, ['stable', 'beta', 'rc'], true)) {
            $channel = 'stable';
        }

        // If stable channel, attempt GitHub /releases/latest endpoint first (official latest non-prerelease)
        if ($channel === 'stable') {
            $apiUrl = "https://api.github.com/repos/{$this->owner}/{$this->repo}/releases/latest";
            $res = $this->fetchSingleReleaseUrl($apiUrl);
            if ($res['ok']) {
                $tagLower = strtolower($res['tag_name'] ?? '');
                if (!str_contains($tagLower, 'beta') && !str_contains($tagLower, 'rc')) {
                    $res['channel'] = 'stable';
                    return $res;
                }
            }
        }

        // Fetch recent releases list to find the latest matching channel
        $listUrl = "https://api.github.com/repos/{$this->owner}/{$this->repo}/releases?per_page=10";
        if (!$this->isAllowedUrl($listUrl)) {
            $err = "Configured GitHub repository URL '{$listUrl}' is not in the allowed update hosts.";
            Logger::error($err);
            return $this->failureResult('invalid_update_url', $err);
        }

        $headers = [
            'User-Agent: ABDO-TECK-POS-Updater/1.0',
            'Accept: application/vnd.github.v3+json, application/json',
            'Cache-Control: no-cache',
        ];
        if ($this->token && trim($this->token) !== '') {
            $headers[] = 'Authorization: Bearer ' . trim($this->token);
        }

        $response = $this->executeCurlGet($listUrl, $headers);
        if (!$response['ok']) {
            $errorCode = $this->classifyError($response['http_code'], $response['curl_error'], $response['curl_errno']);
            $msg = $response['http_code'] > 0
                ? "GitHub API returned HTTP {$response['http_code']}: {$response['curl_error']}"
                : "Failed to connect to GitHub Releases API: {$response['curl_error']}";

            Logger::warning('GitHubReleaseProvider fetch releases list failed', [
                'repo' => "{$this->owner}/{$this->repo}",
                'http_code' => $response['http_code'],
                'error_code' => $errorCode,
            ]);

            return $this->failureResult($errorCode, $msg);
        }

        $releases = json_decode((string) $response['body'], true);
        if (!is_array($releases) || empty($releases)) {
            return $this->failureResult('no_releases_found', 'No releases found in the configured GitHub repository.');
        }

        // Find highest compatible release matching requested channel
        foreach ($releases as $rel) {
            if (!is_array($rel) || empty($rel['tag_name'])) {
                continue;
            }

            $tag = (string) $rel['tag_name'];
            $tagLower = strtolower($tag);
            $isPrerelease = !empty($rel['prerelease']);
            $isBeta = $isPrerelease || str_contains($tagLower, 'beta');
            $isRc = str_contains($tagLower, 'rc');

            $relChannel = 'stable';
            if ($isBeta) {
                $relChannel = 'beta';
            } elseif ($isRc) {
                $relChannel = 'rc';
            }

            // Channel Filter Check:
            // - stable: only accepts stable
            // - rc: accepts stable, rc
            // - beta: accepts stable, rc, beta
            if ($channel === 'stable' && $relChannel !== 'stable') {
                continue;
            }
            if ($channel === 'rc' && $relChannel === 'beta') {
                continue;
            }

            $mapped = $this->mapReleaseData($rel);
            $mapped['channel'] = $relChannel;
            return $mapped;
        }

        return $this->failureResult('no_matching_release', "No release found matching channel '{$channel}'.");
    }

    /**
     * Fetch and parse a single release endpoint.
     */
    private function fetchSingleReleaseUrl(string $apiUrl): array
    {
        if (!$this->isAllowedUrl($apiUrl)) {
            return $this->failureResult('invalid_update_url', 'Invalid update URL.');
        }

        $headers = [
            'User-Agent: ABDO-TECK-POS-Updater/1.0',
            'Accept: application/vnd.github.v3+json, application/json',
            'Cache-Control: no-cache',
        ];
        if ($this->token && trim($this->token) !== '') {
            $headers[] = 'Authorization: Bearer ' . trim($this->token);
        }

        $response = $this->executeCurlGet($apiUrl, $headers);
        if (!$response['ok']) {
            $errorCode = $this->classifyError($response['http_code'], $response['curl_error'], $response['curl_errno']);
            return $this->failureResult($errorCode, $response['curl_error']);
        }

        $data = json_decode((string) $response['body'], true);
        if (!is_array($data) || empty($data['tag_name'])) {
            return $this->failureResult('invalid_release_json', 'Invalid release payload.');
        }

        return $this->mapReleaseData($data);
    }

    /**
     * Map raw GitHub API release JSON to structured provider array.
     */
    public function mapReleaseData(array $data): array
    {
        $tagName = (string) ($data['tag_name'] ?? '');
        $cleanVersion = preg_replace('/^v/i', '', trim($tagName));
        $releaseUrl = (string) ($data['html_url'] ?? '');
        $publishedAt = (string) ($data['published_at'] ?? '');

        // Extract changelog lines from body
        $changelog = [];
        if (!empty($data['body']) && is_string($data['body'])) {
            $lines = preg_split('/\r\n|\r|\n/', trim($data['body'])) ?: [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $changelog[] = preg_replace('/^[\*\-]\s+/', '', $trimmed);
                }
            }
        }

        // Map release assets
        $manifestUrl = null;
        $signatureUrl = null;
        $deltaUrl = null;
        $fullPackageUrl = null;
        $assetsMap = [];

        foreach ($data['assets'] ?? [] as $asset) {
            $name = (string) ($asset['name'] ?? '');
            $downloadUrl = (string) ($asset['browser_download_url'] ?? '');
            if ($name === '' || $downloadUrl === '') {
                continue;
            }

            $assetsMap[$name] = $downloadUrl;
            $lowerName = strtolower($name);

            if ($lowerName === 'manifest.json') {
                $manifestUrl = $downloadUrl;
            } elseif ($lowerName === 'manifest.sig' || $lowerName === 'signature.sig' || str_ends_with($lowerName, '.sig')) {
                $signatureUrl = $downloadUrl;
            } elseif (
                str_starts_with($lowerName, 'delta-') && str_ends_with($lowerName, '.zip')
                || $lowerName === 'delta.zip'
                || str_starts_with($lowerName, 'patch-') && str_ends_with($lowerName, '.zip')
            ) {
                $deltaUrl = $downloadUrl;
            } elseif (str_ends_with($lowerName, '.zip') || str_ends_with($lowerName, '.exe') || str_ends_with($lowerName, '.phar') || $lowerName === 'backend.phar') {
                $fullPackageUrl = $downloadUrl;
            }
        }

        return [
            'ok' => true,
            'latest_version' => $cleanVersion,
            'tag_name' => $tagName,
            'release_url' => $releaseUrl,
            'published_at' => $publishedAt,
            'changelog' => $changelog,
            'manifest_url' => $manifestUrl,
            'signature_url' => $signatureUrl,
            'delta_url' => $deltaUrl,
            'full_package_url' => $fullPackageUrl,
            'assets' => $assetsMap,
            'channel' => 'stable',
            'error' => null,
            'error_code' => null,
        ];
    }


    /**
     * Download text asset into string (e.g. manifest.json, manifest.sig).
     *
     * @param string $url Asset download URL
     * @return array{ok: bool, content: ?string, error: ?string}
     */
    public function fetchAssetContent(string $url): array
    {
        if (!$this->isAllowedUrl($url)) {
            $err = "Asset URL '{$url}' is not in the allowed update hosts.";
            Logger::warning($err);
            return ['ok' => false, 'content' => null, 'error' => $err];
        }

        $headers = [
            'User-Agent: ABDO-TECK-POS-Updater/1.0',
            'Accept: application/octet-stream, application/json, text/plain, */*',
        ];

        if ($this->token && trim($this->token) !== '') {
            $headers[] = 'Authorization: Bearer ' . trim($this->token);
        }

        $res = $this->executeCurlGet($url, $headers, true);
        if (!$res['ok']) {
            return ['ok' => false, 'content' => null, 'error' => $res['curl_error'] ?: "HTTP {$res['http_code']}"];
        }

        return ['ok' => true, 'content' => (string) $res['body'], 'error' => null];
    }

    /**
     * Stream download asset directly to file path.
     *
     * @param string $url Asset URL
     * @param string $destPath Destination file path
     * @return array{ok: bool, error: ?string}
     */
    public function downloadAssetFile(string $url, string $destPath): array
    {
        if (!$this->isAllowedUrl($url)) {
            $err = "Download URL '{$url}' is not in the allowed update hosts.";
            Logger::warning($err);
            return ['ok' => false, 'error' => $err];
        }

        $destDir = dirname($destPath);
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
            return ['ok' => false, 'error' => "Cannot create target directory: {$destDir}"];
        }

        $fp = @fopen($destPath, 'w+b');
        if (!$fp) {
            return ['ok' => false, 'error' => "Cannot open destination file for writing: {$destPath}"];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'ABDO-TECK-POS-Updater/1.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTPS,
        ]);

        if ($this->caCertPath && file_exists($this->caCertPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caCertPath);
        }

        if ($this->token && trim($this->token) !== '') {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . trim($this->token)]);
        }

        $success = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode < 200 || $httpCode >= 400) {
            @unlink($destPath);
            $reason = $httpCode > 0 ? "HTTP {$httpCode}" : $curlErr;
            return ['ok' => false, 'error' => "Asset download failed: {$reason}"];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Execute standard cURL GET request.
     */
    protected function executeCurlGet(string $url, array $headers = [], bool $followRedirects = false): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'ABDO-TECK-POS-Updater/1.0',
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS      => $followRedirects ? 5 : 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($this->caCertPath && file_exists($this->caCertPath)) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caCertPath);
        }

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = (string) curl_error($ch);
        $curlErrNo = (int) curl_errno($ch);
        curl_close($ch);

        $ok = ($httpCode >= 200 && $httpCode < 300) && ($body !== false);

        return [
            'ok' => $ok,
            'body' => $body,
            'http_code' => $httpCode,
            'curl_error' => $curlErr,
            'curl_errno' => $curlErrNo,
        ];
    }

    public function isAllowedUrl(string $url): bool
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

        foreach ($this->allowedHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function classifyError(int $httpCode, string $curlErr, int $curlErrNo): string
    {
        $lower = strtolower($curlErr);
        if ($httpCode === 404) {
            return 'github_release_not_found';
        }
        if ($httpCode === 403) {
            return 'github_rate_limited';
        }
        if ($curlErrNo === 28 || str_contains($lower, 'timed out')) {
            return 'github_network_timeout';
        }
        if ($curlErrNo !== 0 && (str_contains($lower, 'ssl') || str_contains($lower, 'certificate'))) {
            return 'github_ssl_error';
        }

        return 'github_connection_failed';
    }

    private function failureResult(string $code, string $message): array
    {
        return [
            'ok' => false,
            'latest_version' => null,
            'tag_name' => null,
            'release_url' => null,
            'published_at' => null,
            'changelog' => [],
            'manifest_url' => null,
            'signature_url' => null,
            'delta_url' => null,
            'full_package_url' => null,
            'assets' => [],
            'error' => $message,
            'error_code' => $code,
        ];
    }

    private function resolveCaCertPath(): ?string
    {
        $baseDir = str_replace('\\', '/', realpath(__DIR__ . '/../../') ?: dirname(__DIR__, 2));
        $candidates = [
            $baseDir . '/backend/certs/cacert.pem',
            $baseDir . '/certs/cacert.pem',
            dirname(__DIR__) . '/certs/cacert.pem',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
