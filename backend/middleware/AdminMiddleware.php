<?php

class AdminMiddleware {

    public function handle(callable $next): mixed {
        $user = $_SERVER['AUTH_USER'] ?? null;
        if (!$user || $user['role'] !== 'admin') {
            return Response::forbidden('Admin access required');
        }
        return $next();
    }
}
