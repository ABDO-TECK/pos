<?php

namespace App\Controllers;

use App\Helpers\Response;
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

        $result = $this->updateService->applyUpdate($force);

        if (!$result['ok']) {
            return Response::error(
                $result['error'],
                $result['code'] ?? 500
            );
        }

        return Response::success($result['data']);
    }
}
