<?php

declare(strict_types=1);

use App\Core\Autoloader;
use App\Core\Container;
use App\Core\Router;
use App\Core\ValidationException;
use App\Helpers\Response;
use App\Helpers\Logger;
use App\Middleware\RateLimiter;
use App\Config\ErrorCodes;

// ── Config (loads .env via EnvLoader) ─────────────────────────
require_once __DIR__ . '/Config/config.php';

// ── Composer Autoloader ───────────────────────────────────────
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die("Composer dependencies not installed. Please run 'composer install'.");
}
require_once __DIR__ . '/vendor/autoload.php';

// ── CORS ──────────────────────────────────────────────────────
$allowedOrigins = [
    'http://localhost:5173',
    'http://localhost:3000',
    'http://127.0.0.1:5173',
    'https://localhost:5173',
    'https://127.0.0.1:5173',
    'file://',
    'app://.',
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

// السماح بطلبات بدون Origin (Electron file:// protocol)
if ($origin === '' && php_sapi_name() === 'cli-server') {
    $originAllowed = true;
}

if ($originAllowed) {
    // If origin is empty, we don't have a specific origin to reflect.
    // However, some fetch calls might require an exact match or * won't work with credentials.
    // We can use '*' if no origin is provided, but since credentials might be true, we should be careful.
    if ($origin !== '') {
        header("Access-Control-Allow-Origin: $origin");
    } else {
        header("Access-Control-Allow-Origin: *");
    }
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-XSRF-TOKEN');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}



// ── Rate Limiting ── (120 طلب/دقيقة لكل IP)
(new RateLimiter(120, 60))->check();

// ── Error handling ─────────────────────────────────────────────
set_exception_handler(function (Throwable $e) {
    if ($e instanceof ValidationException) {
        $resp = Response::error($e->getMessage(), 422, $e->getErrors(), ErrorCodes::VALIDATION_FAILED);
        http_response_code(422);
        echo json_encode($resp['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    $message = APP_DEBUG ? $e->getMessage() : 'Internal server error';
    Logger::critical($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    $resp = Response::serverError($message);
    http_response_code(500);
    echo json_encode($resp['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

// ── Routes ─────────────────────────────────────────────────────
$container = new Container();
$router = new Router($container);
require_once __DIR__ . '/routes/api.php';
$router->dispatch();

