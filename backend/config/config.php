<?php

namespace App\Config;

use App\Helpers\EnvLoader;


// ── تحميل ملف البيئة (.env) ───────────────────────────────────
require_once __DIR__ . '/../Helpers/EnvLoader.php';
EnvLoader::load(__DIR__ . '/../.env');
// في بيئة الإنتاج: تحقق من المتغيرات الإلزامية
if (EnvLoader::get('APP_ENV', 'development') === 'production') {
    EnvLoader::validate([
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
    ]);
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
define('APP_DEBUG', EnvLoader::getBool('APP_DEBUG', true));

// ── Auth ──────────────────────────────────────────────────────
define('TOKEN_LIFETIME', EnvLoader::getInt('TOKEN_LIFETIME', 60 * 60));           // ساعة واحدة
define('REFRESH_TOKEN_LIFETIME', EnvLoader::getInt('REFRESH_TOKEN_LIFETIME', 60 * 60 * 24 * 30)); // 30 يوم

// ── Inventory ─────────────────────────────────────────────────
define('LOW_STOCK_THRESHOLD', EnvLoader::getInt('LOW_STOCK_THRESHOLD', 5));

// ── Tax ───────────────────────────────────────────────────────
define('TAX_RATE', EnvLoader::getFloat('TAX_RATE', 0.15));

// ── Frontend ──────────────────────────────────────────────────
define('FRONTEND_URL', EnvLoader::get('FRONTEND_URL', 'http://localhost:5173'));

// ── Security ──────────────────────────────────────────────────
define('ALLOW_WEB_RESTORE', EnvLoader::getBool('ALLOW_WEB_RESTORE', true));
define('TRUSTED_PROXIES', array_map('trim', explode(',', EnvLoader::get('TRUSTED_PROXIES', '127.0.0.1,::1'))));
define('DEFAULT_PASSWORD_HASH', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

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
