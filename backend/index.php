<?php

declare(strict_types=1);

// ── CORS ──────────────────────────────────────────────────────
$allowedOrigins = ['http://localhost:5173', 'http://localhost:3000', 'http://127.0.0.1:5173'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins)) {
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
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/core/Controller.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/helpers/Cache.php';
require_once __DIR__ . '/helpers/Migrations.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';
require_once __DIR__ . '/middleware/AdminMiddleware.php';
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

// ── Auto-migrations ────────────────────────────────────────────
(new Migrations())->run();

// ── Routes ─────────────────────────────────────────────────────
$router = new Router();
require_once __DIR__ . '/routes/api.php';
$router->dispatch();
