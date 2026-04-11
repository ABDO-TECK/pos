<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

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

// ── Autoload ───────────────────────────────────────────────────
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/helpers/Migrations.php';
try {
    (new Migrations())->run();
} catch (Throwable $e) {
    error_log('Migrations: ' . $e->getMessage());
}
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/core/Controller.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/helpers/Cache.php';
require_once __DIR__ . '/middleware/RateLimiter.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';
require_once __DIR__ . '/middleware/AdminMiddleware.php';

// ── Rate Limiting ── (120 طلب/دقيقة لكل IP)
(new RateLimiter(120, 60))->check();

require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Product.php';
require_once __DIR__ . '/models/Invoice.php';
require_once __DIR__ . '/models/Supplier.php';
require_once __DIR__ . '/models/Customer.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ProductController.php';
require_once __DIR__ . '/controllers/CategoryController.php';
require_once __DIR__ . '/controllers/SaleController.php';
require_once __DIR__ . '/controllers/InventoryController.php';
require_once __DIR__ . '/controllers/SupplierController.php';
require_once __DIR__ . '/controllers/ReportController.php';
require_once __DIR__ . '/controllers/UserController.php';
require_once __DIR__ . '/controllers/SettingsController.php';
require_once __DIR__ . '/controllers/BackupController.php';
require_once __DIR__ . '/controllers/CustomerController.php';

// ── Error handling ─────────────────────────────────────────────
set_exception_handler(function (Throwable $e) {
    $message = APP_DEBUG ? $e->getMessage() : 'Internal server error';
    error_log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    Response::serverError($message);
});

// ── Routes ─────────────────────────────────────────────────────
$router = new Router();
require_once __DIR__ . '/routes/api.php';
$router->dispatch();
