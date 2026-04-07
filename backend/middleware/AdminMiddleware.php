<?php

class AdminMiddleware {

    public function handle(callable $next): void {
        $user = $_SERVER['AUTH_USER'] ?? null;
        if (!$user || $user['role'] !== 'admin') {
            Response::forbidden('Admin access required');
        }
        $next();
    }
}
