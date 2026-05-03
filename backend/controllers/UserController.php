<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Models\User;
use App\Services\AuthService;


class UserController extends Controller {

    private User $userModel;
    private AuthService $authService;

    public function __construct(User $userModel, AuthService $authService) {
        $this->userModel = $userModel;
        $this->authService = $authService;
    }

    public function index() {
        $filters = [];
        if ($this->getParam('page'))  $filters['page']  = $this->getParam('page');
        if ($this->getParam('limit')) $filters['limit'] = $this->getParam('limit');

        $result = $this->userModel->all($filters);

        if (isset($result['pagination'])) {
            return Response::cacheable($result['data'], 300, null, ['pagination' => $result['pagination']]);
        } else {
            return Response::cacheable($result['data'] ?? $result, 300);
        }
    }

    public function store() {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'name'     => 'required',
            'email'    => 'required|email',
            'password' => 'required|min:6',
        ]);
        if ($errors) return Response::error('فشل التحقق من صحة البيانات', 422, $errors);

        $id   = $this->userModel->create($data);
        $user = $this->userModel->findById($id);
        return Response::success($user, 'User created', 201);
    }

    public function update(string $id) {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'name'  => 'required',
            'email' => 'required|email',
        ]);
        if ($errors) return Response::error('فشل التحقق من صحة البيانات', 422, $errors);

        $this->userModel->update((int)$id, $data);
        return Response::success($this->userModel->findById((int)$id), 'User updated');
    }

    public function destroy(string $id) {
        $auth = $this->authService->user();
        if ((int)$id === $auth['id']) {
            return Response::error('لا يمكنك حذف حسابك الخاص', 400);
        }
        $this->userModel->delete((int)$id);
        return Response::success(null, 'User deleted');
    }
}


