<?php

namespace App\Helpers;

use App\Config\Database;
use App\Services\AuthService;
use App\Helpers\Logger;

class AuditLog
{
    /**
     * تسجيل عملية في سجل التدقيق.
     *
     * @param string     $action     اسم العملية (مثل: delete_invoice, update_stock)
     * @param string     $entityType نوع الكيان (مثل: invoice, product)
     * @param int|null   $entityId   ID الكيان المتأثر
     * @param mixed      $oldValue   القيمة القديمة (سيتم تحويلها إلى JSON)
     * @param mixed      $newValue   القيمة الجديدة (سيتم تحويلها إلى JSON)
     */
    public static function log(
        string $action,
        string $entityType,
        ?int   $entityId = null,
        mixed  $oldValue = null,
        mixed  $newValue = null
    ): void {
        try {
            $db   = Database::getInstance();
            $user = AuthService::user();

            $stmt = $db->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address)
                 VALUES (:user_id, :action, :entity_type, :entity_id, :old_value, :new_value, :ip_address)'
            );

            $stmt->execute([
                'user_id'     => $user['id'] ?? null,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'old_value'   => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
                'new_value'   => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // لا نوقف التطبيق إذا فشل التسجيل — نسجل في Logger فقط
            Logger::error('Audit log failed', ['error' => $e->getMessage()]);
        }
    }
}
