<?php

namespace App\Config;

use App\Helpers\EnvLoader;


// ── تحميل ملف البيئة (.env) ───────────────────────────────────
require_once __DIR__ . '/../Helpers/EnvLoader.php';
EnvLoader::load(__DIR__ . '/../.env');
// ملاحظة: لا حاجة لـ validate() — جميع المتغيرات لها قيم افتراضية أدناه.
// إذا لم يوجد .env، يعمل النظام بالإعدادات الافتراضية (مناسب لـ XAMPP).

// ── Database ──────────────────────────────────────────────────
define('DB_HOST',    EnvLoader::get('DB_HOST', 'localhost'));
define('DB_NAME',    EnvLoader::get('DB_NAME', 'pos_db'));
define('DB_USER',    EnvLoader::get('DB_USER', 'root'));
define('DB_PASS',    EnvLoader::get('DB_PASS', ''));
define('DB_CHARSET', EnvLoader::get('DB_CHARSET', 'utf8mb4'));
define('DB_PORT',    EnvLoader::get('DB_PORT', '3306'));

// ── Application ───────────────────────────────────────────────
define('APP_ENV',   EnvLoader::get('APP_ENV', 'development'));
define('APP_DEBUG', EnvLoader::getBool('APP_DEBUG', true));

// ── Auth ──────────────────────────────────────────────────────
define('TOKEN_LIFETIME', EnvLoader::getInt('TOKEN_LIFETIME', 60 * 60 * 24 * 7));

// ── Inventory ─────────────────────────────────────────────────
define('LOW_STOCK_THRESHOLD', EnvLoader::getInt('LOW_STOCK_THRESHOLD', 5));

// ── Tax ───────────────────────────────────────────────────────
define('TAX_RATE', EnvLoader::getFloat('TAX_RATE', 0.15));

// ── Frontend ──────────────────────────────────────────────────
define('FRONTEND_URL', EnvLoader::get('FRONTEND_URL', 'http://localhost:5173'));

// ── Security ──────────────────────────────────────────────────
define('ALLOW_WEB_RESTORE', EnvLoader::getBool('ALLOW_WEB_RESTORE', true));
define('TRUSTED_PROXIES', array_map('trim', explode(',', EnvLoader::get('TRUSTED_PROXIES', '127.0.0.1,::1'))));
