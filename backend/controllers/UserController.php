<?php

class UserController extends Controller {

    private User $userModel;

    public function __construct() {
        $this->userModel = new User();
    }

    public function index(): void {
        $filters = [];
        if ($this->getParam('page'))  $filters['page']  = $this->getParam('page');
        if ($this->getParam('limit')) $filters['limit'] = $this->getParam('limit');

        $result = $this->userModel->all($filters);

        if (isset($result['pagination'])) {
            Response::success($result['data'], 'success', 200, ['pagination' => $result['pagination']]);
        } else {
            Response::success($result['data']);
        }
    }

    public function store(): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'name'     => 'required',
            'email'    => 'required|email',
            'password' => 'required|min:6',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $id   = $this->userModel->create($data);
        $user = $this->userModel->findById($id);
        Response::success($user, 'User created', 201);
    }

    public function update(string $id): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'name'  => 'required',
            'email' => 'required|email',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $this->userModel->update((int)$id, $data);
        Response::success($this->userModel->findById((int)$id), 'User updated');
    }

    public function destroy(string $id): void {
        $auth = $_SERVER['AUTH_USER'];
        if ((int)$id === $auth['id']) {
            Response::error('Cannot delete yourself', 400);
        }
        $this->userModel->delete((int)$id);
        Response::success(null, 'User deleted');
    }
}
