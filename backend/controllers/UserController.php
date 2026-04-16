<?php

class UserController extends Controller {

    private User $userModel;

    public function __construct() {
        $this->userModel = new User();
    }

    public function index() {
        $filters = [];
        if ($this->getParam('page'))  $filters['page']  = $this->getParam('page');
        if ($this->getParam('limit')) $filters['limit'] = $this->getParam('limit');

        $result = $this->userModel->all($filters);

        if (isset($result['pagination'])) {
            return Response::success($result['data'], 'success', 200, ['pagination' => $result['pagination']]);
        } else {
            return Response::success($result['data']);
        }
    }

    public function store() {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'name'     => 'required',
            'email'    => 'required|email',
            'password' => 'required|min:6',
        ]);
        if ($errors) return Response::error('Validation failed', 422, $errors);

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
        if ($errors) return Response::error('Validation failed', 422, $errors);

        $this->userModel->update((int)$id, $data);
        return Response::success($this->userModel->findById((int)$id), 'User updated');
    }

    public function destroy(string $id) {
        $auth = $_SERVER['AUTH_USER'];
        if ((int)$id === $auth['id']) {
            return Response::error('Cannot delete yourself', 400);
        }
        $this->userModel->delete((int)$id);
        return Response::success(null, 'User deleted');
    }
}


