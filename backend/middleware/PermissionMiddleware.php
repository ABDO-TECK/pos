<?php

namespace App\Middleware;

use App\Config\Database;
use App\Helpers\Response;
use App\Services\AuthService;

/**
 * PermissionMiddleware — فحص صلاحيات RBAC من قاعدة البيانات.
 *
 * الاستخدام في Routes:
 *   PermissionMiddleware::require('products.create')
 *
 * Admin يتجاوز كل الفحوصات (backward compatible).
 * إذا لم يتم تمرير اسم صلاحية، يتصرف كـ AdminMiddleware (يقبل admin فقط).
 */
class PermissionMiddleware
{
    private AuthService $authService;
    private string $permission;

    public function __construct(AuthService $authService, string $permission = '')
    {
        $this->authService = $authService;
        $this->permission  = $permission;
    }

    /**
     * Factory method — تُنشئ callable يمكن استخدامه في مصفوفة middlewares.
     *
     * مثال:
     *   $router->post('/api/products', [ProductController::class, 'store', [
     *       AuthMiddleware::class,
     *       PermissionMiddleware::require('products.create'),
     *   ]]);
     *
     * @param string $permission اسم الصلاحية (مثل: 'products.create')
     * @return array [className, permission] — يعالجها Router لبناء الـ middleware
     */
    public static function require(string $permission): array
    {
        return [self::class, $permission];
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

        // إذا لم يتم تحديد صلاحية معينة → يعمل كـ AdminMiddleware (admin فقط)
        if ($this->permission === '') {
            return Response::forbidden('Admin access required');
        }

        // فحص الصلاحية من قاعدة البيانات
        if (!$this->hasPermission($user['role'], $this->permission)) {
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
            // إذا فشل الاستعلام (مثلاً الجداول غير موجودة) → ارفض الوصول
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}
