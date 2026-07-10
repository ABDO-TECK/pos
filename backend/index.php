<?php

declare(strict_types=1);

use App\Core\Autoloader;
use App\Core\Container;
use App\Core\Router;
use App\Core\ValidationException;
use App\Helpers\Response;
use App\Helpers\Logger;
use App\Middleware\RateLimiter;
use App\Helpers\ErrorCodes;

// ── Config (loads .env via EnvLoader) ─────────────────────────
require_once __DIR__ . '/Config/config.php';

// ── Composer Autoloader ───────────────────────────────────────
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die("Composer dependencies not installed. Please run 'composer install'.");
}
require_once __DIR__ . '/vendor/autoload.php';

// ── Event System ──────────────────────────────────────────────
\App\Helpers\CacheSubscriber::register();

use App\Services\LoyaltyService;
\App\Helpers\EventDispatcher::listen('sale.completed', function(array $data) {
    if (!empty($data['customer_id']) && !empty($data['invoice_id'])) {
        $db = \App\Config\Database::getInstance();
        $repo = new \App\Repositories\LoyaltyRepository($db);
        (new LoyaltyService($repo, $db))->earnPoints(
            (int)$data['customer_id'],
            (int)$data['invoice_id'],
            (float)($data['total'] ?? 0)
        );
    }
});

// ── CORS + Preflight ──────────────────────────────────────────
(new \App\Middleware\CorsMiddleware())->handle(fn() => null);

header('Content-Type: application/json; charset=UTF-8');

// ── Security Headers ──────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');



// ── Rate Limiting ── (200 طلب / دقيقة per-IP كحد أقصى عام)
// هذا الحد العام يعمل per-IP قبل المصادقة كخط دفاع أول.
// بعد المصادقة، يتم تطبيق حدود أدق per-user عبر:
//   - ReadRateLimiter:   200/دقيقة (GET)    — per-user أو per-IP
//   - WriteRateLimiter:  60/دقيقة  (POST/PUT) — per-user أو per-IP
//   - DeleteRateLimiter: 30/دقيقة  (DELETE)   — per-user أو per-IP
(new RateLimiter(200, 60))->check();

// ── Error handling ─────────────────────────────────────────────
set_exception_handler(function (Throwable $e) {
    if ($e instanceof ValidationException) {
        $resp = Response::error($e->getMessage(), 422, $e->getErrors(), ErrorCodes::VALIDATION_FAILED);
        http_response_code(422);
        echo json_encode($resp['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    $message = (APP_DEBUG && APP_ENV !== 'production') ? $e->getMessage() : 'Internal server error';
    Logger::critical($e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
    $resp = Response::serverError($message);
    http_response_code(500);
    echo json_encode($resp['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
});

// ── Routes ─────────────────────────────────────────────────────
$container = new \App\Core\Container();
require_once __DIR__ . '/Config/bindings.php';
$router = new Router($container);
require_once __DIR__ . '/routes/api.php';
$router->dispatch();
