<?php

class AuthController extends Controller {

    private User $userModel;

    public function __construct() {
        $this->userModel = new User();
    }

    public function login() {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'email'    => 'required|email',
            'password' => 'required|min:6',
        ]);

        if ($errors) {
            return Response::error('Validation failed', 422, $errors);
        }

        $user = $this->userModel->findByEmail($data['email']);

        if (!$user || !password_verify($data['password'], $user['password'])) {
            return Response::unauthorized('Invalid email or password');
        }

        $token = $this->userModel->createToken($user['id']);

        // Set HttpOnly cookie for strict security
        setcookie('pos_token', $token, [
            'expires'  => time() + TOKEN_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        return Response::success([
            'token' => $token, // Keep sending in body backwards-compatibility
            'user'  => [
                'id'    => $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ],
        ], 'Login successful');
    }

    public function logout() {
        $token = $this->extractToken();
        if ($token) {
            $this->userModel->deleteToken($token);
        }
        // Clear HttpOnly cookie
        setcookie('pos_token', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        return Response::success(null, 'Logged out successfully');
    }

    public function csrfCookie() {
        $token = bin2hex(random_bytes(32));
        setcookie('XSRF-TOKEN', $token, [
            'expires'  => time() + TOKEN_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => false, // Cannot be httponly because frontend js must read it
            'samesite' => 'Strict',
        ]);
        return Response::success(null, 'CSRF cookie set');
    }

    public function me() {
        $auth = $_SERVER['AUTH_USER'];
        $user = $this->userModel->findById($auth['id']);
        return Response::success($user);
    }

    private function extractToken(): ?string {
        if (!empty($_COOKIE['pos_token'])) return $_COOKIE['pos_token'];
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }
}



