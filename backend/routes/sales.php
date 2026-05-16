<?php
use App\Controllers\SaleController;
use App\Controllers\InventoryController;
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

// Sales
$router->post('/api/sales',          [SaleController::class, 'store',   [AuthMiddleware::class]]);
$router->get('/api/sales',           [SaleController::class, 'index',   [AuthMiddleware::class]]);
$router->get('/api/sales/{id}',      [SaleController::class, 'show',    [AuthMiddleware::class]]);
$router->put('/api/sales/{id}/status',[SaleController::class, 'updateStatus', [AuthMiddleware::class]]);
$router->delete('/api/sales/{id}',  [SaleController::class, 'destroy', [AuthMiddleware::class, AdminMiddleware::class]]);

// Inventory
$router->get('/api/inventory',          [InventoryController::class, 'index',   [AuthMiddleware::class]]);
$router->get('/api/inventory/low-stock',[InventoryController::class, 'lowStock',[AuthMiddleware::class]]);
$router->put('/api/inventory/{id}',     [InventoryController::class, 'adjust',  [AuthMiddleware::class, AdminMiddleware::class]]);
