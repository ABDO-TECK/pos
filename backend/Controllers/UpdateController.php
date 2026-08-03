<?php

namespace App\Controllers;

use App\Helpers\Response;
use App\Helpers\JobQueue;
use App\Services\AuthService;
use App\Helpers\EnvLoader;
use App\Core\Controller;

class UpdateController extends Controller {

    private AuthService $authService;
    private \App\Services\UpdateService $updateService;

    public function __construct(AuthService $authService, \App\Services\UpdateService $updateService) {
        $this->authService      = $authService;
        $this->updateService    = $updateService;
    }


    // ══════════════════════════════════════════════════════════════
    //  API Endpoints
    // ══════════════════════════════════════════════════════════════

    public function check() {
        $result = $this->updateService->checkForUpdate();
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

        $body  = $this->getBody();
        $force = isset($body['force']) && filter_var($body['force'], FILTER_VALIDATE_BOOLEAN);

        $jobId = JobQueue::dispatchUnique(
            'apply_update',
            [
                'force' => $force,
                'requested_by' => (int) $user['id'],
            ],
            10,
            1
        );

        return Response::success(
            [
                'job_id' => $jobId,
                'status' => 'queued',
            ],
            'Update queued',
            202
        );
    }

    public function status(string $id) {
        $job = JobQueue::find($this->resolveId($id));
        if (!$job || $job['job_name'] !== 'apply_update') {
            return Response::notFound('Update job not found');
        }

        return Response::success($job);
    }
}
