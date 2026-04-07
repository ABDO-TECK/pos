<?php

class AuthController extends Controller {

    private User $userModel;

    public function __construct() {
        $this->userModel = new User();
    }

    public function login(): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'email'    => 'required|email',
            'password' => 'required|min:6',
        ]);

        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $user = $this->userModel->findByEmail($data['email']);

        if (!$user || !password_verify($data['password'], $user['password'])) {
            Response::unauthorized('Invalid email or password');
        }

        $token = $this->userModel->createToken($user['id']);

        Response::success([
            'token' => $token,
            'user'  => [
                'id'    => $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ],
        ], 'Login successful');
    }

    public function logout(): void {
        $token = $this->extractBearerToken();
        if ($token) {
            $this->userModel->deleteToken($token);
        }
        Response::success(null, 'Logged out successfully');
    }

    public function me(): void {
        $auth = $_SERVER['AUTH_USER'];
        $user = $this->userModel->findById($auth['id']);
        Response::success($user);
    }

    private function extractBearerToken(): ?string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }
}
