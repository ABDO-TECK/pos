<?php

namespace App\Helpers;

use App\Config\Database;
use App\Services\AuthService;
use App\Helpers\Logger;

class AuditLog
{
    private const SENSITIVE_KEYS = [
        'password',
        'current_password',
        'password_confirmation',
    ];

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
        ?int   $userId,
        string $action,
        string $entityType,
        ?int   $entityId = null,
        mixed  $oldValue = null,
        mixed  $newValue = null
    ): void {
        try {
            $db   = Database::getInstance();
            $oldValue = self::withoutSensitiveFields($oldValue);
            $newValue = self::withoutSensitiveFields($newValue);

            $stmt = $db->prepare(
                'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address)
                 VALUES (:user_id, :action, :entity_type, :entity_id, :old_value, :new_value, :ip_address)'
            );

            $stmt->execute([
                'user_id'     => $userId,
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

    private static function withoutSensitiveFields(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalizedKey = strtolower($key);
                if (
                    in_array($normalizedKey, self::SENSITIVE_KEYS, true)
                    || str_ends_with($normalizedKey, '_password')
                ) {
                    continue;
                }
            }

            $sanitized[$key] = self::withoutSensitiveFields($item);
        }

        return $sanitized;
    }
}
