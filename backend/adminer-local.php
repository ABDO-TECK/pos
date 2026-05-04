<?php
/**
 * Adminer wrapper — يسمح بالدخول بدون كلمة مرور لـ MySQL المحلي.
 *
 * الحماية:
 * 1. يعمل فقط من 127.0.0.1 / ::1
 * 2. محظور تماماً إذا كانت APP_ENV=production
 */

// ── حماية: منع الوصول في الإنتاج ──
$appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'development';
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

if ($appEnv === 'production' || !in_array($remoteIp, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    die('403 Forbidden — Adminer is disabled in this environment.');
}

function adminer_object() {
    class AdminerNoPassword extends Adminer {
        function login($login, $password) {
            return true; // السماح بالدخول بدون كلمة مرور محلياً فقط
        }
    }
    return new AdminerNoPassword;
}

require __DIR__ . '/adminer.php';
