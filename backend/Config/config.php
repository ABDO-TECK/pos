<?php

namespace App\Config;

use App\Helpers\EnvLoader;


// ── تحميل ملف البيئة (.env) ───────────────────────────────────
require_once __DIR__ . '/../Helpers/EnvLoader.php';
$envPath = getenv('ENV_PATH') ?: __DIR__ . '/../.env';
EnvLoader::load($envPath);
// في بيئة الإنتاج: تحقق من المتغيرات الإلزامية
if (EnvLoader::get('APP_ENV', 'development') === 'production') {
    EnvLoader::validate([
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
    ]);
    // تحذير أمني: استخدام root في الإنتاج
    if (EnvLoader::get('DB_USER') === 'root') {
        if (class_exists('App\Helpers\Logger')) {
            \App\Helpers\Logger::warning(
                '⚠️ SECURITY: Using root DB user in production is dangerous. '
                . 'Create a dedicated database user with limited privileges.'
            );
        }
    }
}

// ── Database ──────────────────────────────────────────────────
define('DB_HOST',    EnvLoader::get('DB_HOST', 'localhost'));
define('DB_NAME',    EnvLoader::get('DB_NAME', 'pos_db'));
define('DB_USER',    EnvLoader::get('DB_USER', 'root'));
define('DB_PASS',    EnvLoader::get('DB_PASS', ''));
define('DB_CHARSET', EnvLoader::get('DB_CHARSET', 'utf8mb4'));
define('DB_PORT',    EnvLoader::get('DB_PORT', '3306'));
define('DB_PERSISTENT', EnvLoader::getBool('DB_PERSISTENT', false));

// ── Application ───────────────────────────────────────────────
define('APP_ENV',   EnvLoader::get('APP_ENV', 'development'));
define('APP_DEBUG', EnvLoader::getBool('APP_DEBUG', false));

// ── Auth ──────────────────────────────────────────────────────
define('TOKEN_LIFETIME', EnvLoader::getInt('TOKEN_LIFETIME', 60 * 60));           // ساعة واحدة
define('REFRESH_TOKEN_LIFETIME', EnvLoader::getInt('REFRESH_TOKEN_LIFETIME', 60 * 60 * 24 * 30)); // 30 يوم

// ── Inventory ─────────────────────────────────────────────────
define('LOW_STOCK_THRESHOLD', EnvLoader::getInt('LOW_STOCK_THRESHOLD', 5));

// ── Tax ───────────────────────────────────────────────────────
define('TAX_RATE', EnvLoader::getFloat('TAX_RATE', 0.15));

// ── Frontend ──────────────────────────────────────────────────
define('FRONTEND_URL', EnvLoader::get('FRONTEND_URL', 'http://localhost:5173'));
define('INVOICE_DEFAULT_LIMIT', (int) EnvLoader::get('INVOICE_DEFAULT_LIMIT', '1000'));

// ── Security ──────────────────────────────────────────────────
define('ALLOW_WEB_RESTORE', EnvLoader::getBool('ALLOW_WEB_RESTORE', false));
define('TRUSTED_PROXIES', array_map('trim', explode(',', EnvLoader::get('TRUSTED_PROXIES', '127.0.0.1,::1'))));


// ── Production Security Warnings ──────────────────────────────
if (APP_ENV === 'production' && !EnvLoader::getBool('FORCE_HTTPS', false)) {
    // تسجيل تحذير أمني في اللوج — لا نوقف التطبيق لكن ننبه المطور
    if (class_exists('App\Helpers\Logger')) {
        \App\Helpers\Logger::warning(
            '⚠️ SECURITY: FORCE_HTTPS is disabled in production. '
            . 'Set FORCE_HTTPS=true in .env for secure deployment.'
        );
    }
}
if (APP_ENV === 'production' && !EnvLoader::getBool('SECURE_COOKIES', false)) {
    if (class_exists('App\Helpers\Logger')) {
        \App\Helpers\Logger::warning(
            '⚠️ SECURITY: SECURE_COOKIES is disabled in production. '
            . 'Set SECURE_COOKIES=true in .env for secure cookies.'
        );
    }
}

if (EnvLoader::getBool('POS_LAN_ENABLED', false)) {
    $missingSecurityControls = [];
    if (!EnvLoader::getBool('FORCE_HTTPS', false)) {
        $missingSecurityControls[] = 'FORCE_HTTPS=true';
    }
    if (!EnvLoader::getBool('SECURE_COOKIES', false)) {
        $missingSecurityControls[] = 'SECURE_COOKIES=true';
    }
    if ($missingSecurityControls !== []) {
        throw new \RuntimeException(
            'LAN mode requires: ' . implode(', ', $missingSecurityControls)
        );
    }
}
