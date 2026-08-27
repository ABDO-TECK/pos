<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Controller;
use App\Helpers\EnvLoader;
use App\Helpers\JobQueue;
use App\Helpers\Logger;
use App\Helpers\Response;
use App\Services\AuthService;
use App\Services\UpdateService;
use Throwable;

class UpdateController extends Controller
{
    private AuthService $authService;
    private UpdateService $updateService;

    public function __construct(AuthService $authService, UpdateService $updateService)
    {
        $this->authService   = $authService;
        $this->updateService = $updateService;
    }

    // ══════════════════════════════════════════════════════════════
    //  1. GET /api/updates/status & /api/update/status
    // ══════════════════════════════════════════════════════════════

    public function status(?string $id = null)
    {
        // If an ID is passed, check background job status for backward compatibility
        if ($id !== null && $id !== '') {
            $job = JobQueue::find($this->resolveId($id));
            if (!$job || $job['job_name'] !== 'apply_update') {
                return Response::notFound('Update job not found');
            }
            return Response::success($job);
        }

        $local = $this->updateService->getLocalVersion();
        $currentVersion = $local['version'] ?? '0.0.0';
        $state = $this->updateService->getDeltaUpdateService()->getUpdateState();

        // Check if there is cached or active release info
        $remote = $this->updateService->fetchRemoteVersion();
        $latestVersion = $remote['version'] ?? null;
        $hasUpdate = $latestVersion ? version_compare($latestVersion, $currentVersion, '>') : false;

        $type = 'full';
        $filesCount = null;
        if (!empty($remote['manifest']['files']) && is_array($remote['manifest']['files'])) {
            $type = 'delta';
            $filesCount = count($remote['manifest']['files']);
        } elseif (!empty($remote['delta_url'])) {
            $type = 'delta';
        }

        $releaseInfo = [
            'title' => $remote['tag_name'] ?? ($latestVersion ? "Release v{$latestVersion}" : null),
            'tag_name' => $remote['tag_name'] ?? ($latestVersion ? "v{$latestVersion}" : null),
            'changelog' => $remote['changelog'] ?? [],
            'released_at' => $remote['released_at'] ?? null,
            'files_count' => $filesCount,
            'release_url' => $remote['release_url'] ?? null,
            'download_url' => $remote['delta_url'] ?? ($remote['download_url'] ?? null),
            'full_package_url' => $remote['full_package_url'] ?? null,
        ];

        $interruptedUpdate = $this->updateService->getDeltaUpdateService()->detectInterruptedUpdate();

        return Response::success([
            'current_version'    => $currentVersion,
            'latest_version'     => $latestVersion,
            'update_available'   => $hasUpdate,
            'type'               => $type,
            'release_info'       => $releaseInfo,
            'update_state'       => $state,
            'interrupted_update' => $interruptedUpdate,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  2. POST /api/updates/check & GET /api/update/check
    // ══════════════════════════════════════════════════════════════

    public function check()
    {
        $result = $this->updateService->checkForUpdate();
        return Response::success($result);
    }

    public function changelog()
    {
        $remote = $this->updateService->fetchRemoteVersion();
        return Response::success($remote['changelog'] ?? []);
    }

    // ══════════════════════════════════════════════════════════════
    //  3. POST /api/updates/apply & POST /api/update/apply
    // ══════════════════════════════════════════════════════════════

    public function apply()
    {
        if (!EnvLoader::getBool('ENABLE_AUTO_UPDATE', true)) {
            return Response::error('التحديث التلقائي معطل. الرجاء تفعيله من ملف .env (ENABLE_AUTO_UPDATE=true)', 403);
        }

        $user = $this->authService->user();
        $body  = $this->getBody();
        $force = isset($body['force']) && filter_var($body['force'], FILTER_VALIDATE_BOOLEAN);

        $startTime = microtime(true);
        Logger::info('Admin update apply initiated', [
            'user_id'  => $user['id'] ?? null,
            'username' => $user['username'] ?? 'system',
            'role'     => $user['role'] ?? 'unknown',
            'force'    => $force,
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);

        // Execute update
        $result = $this->updateService->applyUpdate($force);
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        if (!$result['ok']) {
            Logger::error('Admin update apply failed', [
                'user_id'     => $user['id'] ?? null,
                'duration_ms' => $durationMs,
                'error'       => $result['error'] ?? 'Unknown error',
            ]);

            $code = $result['code'] ?? 500;
            return Response::error(
                $result['error'] ?? 'فشل تطبيق التحديث.',
                $code,
                $result['data'] ?? null
            );
        }

        Logger::info('Admin update apply completed successfully', [
            'user_id'        => $user['id'] ?? null,
            'latest_version' => $result['data']['latest_version'] ?? null,
            'duration_ms'    => $durationMs,
        ]);

        return Response::success(
            $result['data'] ?? [],
            'تم تطبيق التحديث بنجاح'
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  4. GET /api/updates/history
    // ══════════════════════════════════════════════════════════════

    public function history()
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->query('SELECT * FROM update_history ORDER BY id DESC LIMIT 50');
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return Response::success($rows);
        } catch (Throwable $e) {
            Logger::warning('Could not retrieve update history: ' . $e->getMessage());
            return Response::success([]);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  5. POST /api/updates/rollback
    // ══════════════════════════════════════════════════════════════

    public function rollback()
    {
        $user = $this->authService->user();
        $body = $this->getBody();
        $snapshotPath = isset($body['snapshot_path']) && is_string($body['snapshot_path'])
            ? trim($body['snapshot_path'])
            : null;

        Logger::info('Admin update rollback initiated', [
            'user_id'       => $user['id'] ?? null,
            'username'      => $user['username'] ?? 'system',
            'snapshot_path' => $snapshotPath,
        ]);

        $result = $this->updateService->rollbackUpdate($snapshotPath);

        if (!$result['ok']) {
            Logger::error('Admin update rollback failed', [
                'user_id' => $user['id'] ?? null,
                'error'   => $result['error'] ?? 'Unknown error',
            ]);

            return Response::error(
                $result['error'] ?? 'فشل التراجع عن التحديث.',
                500,
                ['logs' => $result['logs'] ?? []]
            );
        }

        Logger::info('Admin update rollback completed successfully', [
            'user_id'  => $user['id'] ?? null,
            'snapshot' => $result['snapshot'] ?? null,
        ]);

        return Response::success(
            $result,
            'تم التراجع عن التحديث بنجاح وإعادة النظام لحالته السابقة'
        );
    }


    // ══════════════════════════════════════════════════════════════
    //  6. GET /api/updates/snapshots
    // ══════════════════════════════════════════════════════════════

    public function snapshots()
    {
        $backupsDir = str_replace('\\', '/', $this->updateService->getDeltaUpdateService()->getBackupsDir());
        $snapshots = [];

        if (is_dir($backupsDir)) {
            $entries = scandir($backupsDir) ?: [];
            $dirs = [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (str_starts_with($entry, 'patch_') && is_dir($backupsDir . '/' . $entry)) {
                    $dirs[] = $backupsDir . '/' . $entry;
                }
            }
            rsort($dirs);

            foreach ($dirs as $dir) {
                $dirNorm = str_replace('\\', '/', $dir);
                $metaFile = $dirNorm . '/metadata.json';
                $meta = [];

                if (is_file($metaFile)) {
                    $content = @file_get_contents($metaFile);
                    if ($content) {
                        $meta = json_decode($content, true) ?: [];
                    }
                }

                $snapshots[] = [
                    'snapshot_name' => basename($dirNorm),
                    'snapshot_path' => $dirNorm,
                    'from_version'  => $meta['from_version'] ?? 'unknown',
                    'to_version'    => $meta['to_version'] ?? 'unknown',
                    'timestamp'     => $meta['timestamp'] ?? date('Y-m-d H:i:s', filemtime($dir)),
                    'files_count'   => count($meta['files'] ?? []),
                    'has_db_backup' => !empty($meta['db_backup_path']),
                    'db_backup_path'=> $meta['db_backup_path'] ?? null,
                ];
            }
        }

        return Response::success($snapshots);
    }


}
