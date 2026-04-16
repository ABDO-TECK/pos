<?php

class AuthMiddleware {

    public function handle(callable $next): mixed {
        $token = $this->extractToken();

        if (!$token) {
            return Response::unauthorized('No authentication token provided');
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT t.user_id, t.expires_at, u.role, u.is_active, u.name, u.email
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

        if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
            return Response::unauthorized('Token expired');
        }

        // Store auth user in request context
        $_SERVER['AUTH_USER'] = [
            'id'    => $row['user_id'],
            'name'  => $row['name'],
            'email' => $row['email'],
            'role'  => $row['role'],
        ];

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
