<?php

declare(strict_types=1);

// ── Config (loads .env via EnvLoader) ─────────────────────────
require_once __DIR__ . '/config/config.php';

// ── Autoloader ────────────────────────────────────────────────
// يُحمّل الكلاسات تلقائيًا من: config, core, controllers,
// models, middleware, helpers — بدون require_once يدوي.
require_once __DIR__ . '/core/Autoloader.php';
Autoloader::register();

// ── Composer Autoloader (for mPDF) ────────────────────────────
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// ── CORS ──────────────────────────────────────────────────────
$allowedOrigins = [
    'http://localhost:5173',
    'http://localhost:3000',
    'http://127.0.0.1:5173',
    'https://localhost:5173',
    'https://127.0.0.1:5173',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$originAllowed = $origin !== '' && in_array($origin, $allowedOrigins, true);
// في وضع التطوير: السماح بأصل Vite من IP الشبكة المحلية (HTTP/HTTPS) للهاتف والكمبيوتر
if (!$originAllowed && APP_DEBUG && $origin !== '') {
    $lanOrigin = '#^https?://(localhost|127\.0\.0\.1|192\.168\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})(:\d+)?$#';
    if (preg_match($lanOrigin, $origin) === 1) {
        $originAllowed = true;
    }
}

if ($originAllowed) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Auto-Migrations ───────────────────────────────────────────
try {
    (new Migrations())->run();
} catch (Throwable $e) {
    Logger::warning('Migration runner failed', ['error' => $e->getMessage()]);
}

// ── Rate Limiting ── (120 طلب/دقيقة لكل IP)
(new RateLimiter(120, 60))->check();

// ── Error handling ─────────────────────────────────────────────
set_exception_handler(function (Throwable $e) {
    $message = APP_DEBUG ? $e->getMessage() : 'Internal server error';
    Logger::critical($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    Response::serverError($message);
});

// ── Routes ─────────────────────────────────────────────────────
$container = new Container();
$router = new Router($container);
require_once __DIR__ . '/routes/api.php';
$router->dispatch();

