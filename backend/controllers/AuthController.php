<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\CookieHelper;
use App\Helpers\Response;
use App\Models\User;
use App\Services\AuthService;
use App\Middleware\LoginRateLimiter;


class AuthController extends Controller {

    private User $userModel;
    private AuthService $authService;

    public function __construct(User $userModel, AuthService $authService) {
        $this->userModel = $userModel;
        $this->authService = $authService;
    }

    public function login() {
        // حماية من Brute Force: 5 محاولات/دقيقة لكل IP
        (new LoginRateLimiter())->check();

        $request = new \App\Requests\LoginRequest($this->getBody());
        $data = $request->validated();

        $user = $this->userModel->findByEmail($data['email']);
        $passwordHash = $user ? $this->userModel->getPasswordHashByEmail($data['email']) : null;

        if (!$user || !$passwordHash || !password_verify($data['password'], $passwordHash)) {
            return Response::unauthorized('البريد الإلكتروني أو كلمة المرور غير صحيحة');
        }

        $token = $this->userModel->createToken($user['id']);
        $refreshToken = $this->userModel->createRefreshToken($user['id']);

        // Set HttpOnly cookie for access token
        setcookie('pos_token', $token, CookieHelper::options(time() + TOKEN_LIFETIME));

        // Set HttpOnly cookie for refresh token
        setcookie('pos_refresh_token', $refreshToken, CookieHelper::options(time() + REFRESH_TOKEN_LIFETIME, '/api/v1/refresh'));

        return Response::success([
            'user'  => [
                'id'    => $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
                'force_password_change' => $user['force_password_change'] ?? 0,
            ],
        ], 'Login successful');
    }

    public function logout() {
        $token = $this->extractToken();
        if ($token) {
            $this->userModel->deleteToken($token);
        }
        // Clear HttpOnly cookie
        setcookie('pos_token', '', CookieHelper::options(time() - 3600));

        // Delete refresh token
        $refreshToken = $_COOKIE['pos_refresh_token'] ?? null;
        if ($refreshToken) {
            $this->userModel->deleteRefreshToken($refreshToken);
        }
        setcookie('pos_refresh_token', '', CookieHelper::options(time() - 3600, '/api/v1/refresh'));
        return Response::success(null, 'Logged out successfully');
    }

    public function csrfCookie() {
        $token = bin2hex(random_bytes(32));
        // CSRF token يجب أن يكون httpOnly = false حتى يقرأه JavaScript
        setcookie('XSRF-TOKEN', $token, CookieHelper::options(time() + TOKEN_LIFETIME, '/', false));
        return Response::success(null, 'CSRF cookie set');
    }

    public function me() {
        $auth = $this->authService->user();
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

    public function refresh() {
        $refreshToken = $_COOKIE['pos_refresh_token'] ?? null;
        if (!$refreshToken) {
            return Response::unauthorized('No refresh token');
        }

        $record = $this->userModel->findRefreshToken($refreshToken);
        if (!$record || !$record['is_active']) {
            return Response::unauthorized('Invalid refresh token');
        }

        // ── Refresh Token Rotation ──
        // 1. Delete the old refresh token (invalidate it)
        $this->userModel->deleteRefreshToken($refreshToken);

        // 2. Issue a new refresh token
        $newRefreshToken = $this->userModel->createRefreshToken($record['user_id']);
        setcookie('pos_refresh_token', $newRefreshToken, CookieHelper::options(time() + REFRESH_TOKEN_LIFETIME));

        // 3. Issue a new access token
        $newToken = $this->userModel->createToken($record['user_id']);
        setcookie('pos_token', $newToken, CookieHelper::options(time() + TOKEN_LIFETIME));

        $user = $this->userModel->findById($record['user_id']);
        return Response::success(['user' => $user], 'Token refreshed');
    }
}



