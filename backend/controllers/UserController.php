<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Helpers\Messages;


class UserController extends Controller {

    private UserRepository $userRepo;
    private AuthService $authService;

    public function __construct(UserRepository $userRepo, AuthService $authService) {
        $this->userRepo = $userRepo;
        $this->authService = $authService;
    }

    public function index() {
        $filters = [];
        $filters += $this->getPaginationParams();

        $result = $this->userRepo->all($filters);

        if (isset($result['pagination'])) {
            return Response::cacheable($result['data'], 300, null, ['pagination' => $result['pagination']]);
        } else {
            return Response::cacheable($result['data'] ?? $result, 300);
        }
    }

    public function store() {
        $request = new \App\Requests\UserStoreRequest($this->getBody());
        $data = $request->validated();

        return $this->withTransaction(function () use ($data) {
            $id   = $this->userRepo->create($data);
            $user = $this->userRepo->findById($id);
            return Response::success($user, Messages::USER_CREATED, 201);
        });
    }

    public function update(string $id) {
        $id = $this->resolveId($id);
        $auth = $this->authService->user();
        $isSelf = $id === (int)$auth['id'];
        $isAdmin = $auth['role'] === 'admin';

        // Non-admin users can ONLY update their own profile
        if (!$isAdmin && !$isSelf) {
            \App\Helpers\Logger::warning('Unauthorized user update attempt', [
                'attacker_id' => $auth['id'],
                'target_id'   => $id,
                'role'         => $auth['role'],
            ]);
            return Response::error(Messages::ACCESS_DENIED, 403);
        }

        $request = new \App\Requests\UserUpdateRequest($this->getBody());
        $data = $request->validated();

        // Non-admin users cannot change role, is_active, or email
        if (!$isAdmin) {
            unset($data['role']);
            unset($data['is_active']);
            unset($data['email']); // prevent email changes by non-admins
        }

        return $this->withTransaction(function () use ($id, $data) {
            $this->userRepo->update($id, $data);
            \App\Middleware\PermissionMiddleware::clearPermissionCache();
            return Response::success($this->userRepo->findById($id), Messages::USER_UPDATED);
        });
    }

    public function destroy(string $id) {
        $id = $this->resolveId($id);
        $auth = $this->authService->user();
        if ($id === $auth['id']) {
            return Response::error(Messages::CANNOT_DELETE_SELF, 400);
        }
        return $this->withTransaction(function () use ($id) {
            $this->userRepo->delete($id);
            return Response::success(null, Messages::USER_DELETED);
        });
    }
}


