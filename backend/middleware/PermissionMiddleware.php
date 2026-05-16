<?php

namespace App\Middleware;

use App\Config\Database;
use App\Helpers\Response;
use App\Services\AuthService;

class PermissionMiddleware
{
    private AuthService $authService;
    private string $permission;

    public function __construct(AuthService $authService, string $permission = '')
    {
        $this->authService = $authService;
        $this->permission  = $permission;
    }

    public function handle(callable $next): mixed
    {
        $user = $this->authService->user();
        if (!$user) {
            return Response::unauthorized();
        }

        // Admin يتجاوز كل الفحوصات (backward compatible)
        if ($user['role'] === 'admin') {
            return $next();
        }

        // فحص الصلاحية من قاعدة البيانات
        if ($this->permission && !$this->hasPermission($user['role'], $this->permission)) {
            return Response::forbidden("ليس لديك صلاحية: {$this->permission}");
        }

        return $next();
    }

    private function hasPermission(string $role, string $permission): bool
    {
        static $cache = [];
        $key = "{$role}:{$permission}";
        if (isset($cache[$key])) return $cache[$key];

        try {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT 1 FROM role_permissions rp
                 JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role = ? AND p.name = ?
                 LIMIT 1'
            );
            $stmt->execute([$role, $permission]);
            $cache[$key] = (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}
