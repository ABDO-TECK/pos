<?php

namespace App\Middleware;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\User;
use App\Services\AuthService;


class AuthMiddleware {
    private AuthService $authService;

    public function __construct(AuthService $authService) {
        $this->authService = $authService;
    }

    public function handle(callable $next): mixed {
        $token = $this->extractToken();

        if (!$token) {
            return Response::unauthorized('No authentication token provided');
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT t.user_id, t.expires_at, u.role, u.is_active, u.name, u.email, u.force_password_change
             FROM tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token = ?'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) {
            return Response::unauthorized('Invalid token');
        }

        if (!$row['is_active']) {
            return Response::unauthorized('Account is disabled');
        }

        if (!empty($row['force_password_change'])) {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '';
            $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
            // Allow user to update themselves or logout — match any URI prefix variation
            $userId = $row['user_id'];
            $isUpdatingSelf = ($requestMethod === 'PUT' && preg_match('#/users/' . $userId . '($|\?)#', $requestUri));
            $isLoggingOut = ($requestMethod === 'POST' && strpos($requestUri, '/logout') !== false);
            
            if (!$isUpdatingSelf && !$isLoggingOut) {
                return Response::error('يجب تغيير كلمة المرور الافتراضية أولاً', 403, ['force_password_change' => true]);
            }
        }

        $expiresAtTime = strtotime($row['expires_at']);
        if ($row['expires_at'] && $expiresAtTime < time()) {
            return Response::unauthorized('Token expired');
        }

        if ($expiresAtTime - time() < (TOKEN_LIFETIME / 2)) {
            $userModel = new User($db);
            $newExpiry = time() + TOKEN_LIFETIME;
            $userModel->extendToken($token, date('Y-m-d H:i:s', $newExpiry));
            
            setcookie('pos_token', $token, [
                'expires'  => $newExpiry,
                'path'     => '/',
                'domain'   => '',
                'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        // Store auth user in request context
        $this->authService->setUser([
            'id'    => $row['user_id'],
            'name'  => $row['name'],
            'email' => $row['email'],
            'role'  => $row['role'],
        ]);

        return $next();
    }

    private function extractToken(): ?string {
        if (!empty($_COOKIE['pos_token'])) {
            return $_COOKIE['pos_token'];
        }

        // 1. Standard $_SERVER key (works when .htaccess passes the header)
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // 2. Fallback: REDIRECT_HTTP_AUTHORIZATION (some Apache configs)
        if (empty($header)) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }

        // 3. Fallback: apache_request_headers() (works when mod_php is used)
        if (empty($header) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }
}
