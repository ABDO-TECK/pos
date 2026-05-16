<?php
use App\Controllers\ProductController;
use App\Controllers\CategoryController;
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

// Categories
$router->get('/api/categories',      [CategoryController::class, 'index',   [AuthMiddleware::class]]);
$router->post('/api/categories',     [CategoryController::class, 'store',   [AuthMiddleware::class, AdminMiddleware::class]]);
$router->put('/api/categories/{id}', [CategoryController::class, 'update',  [AuthMiddleware::class, AdminMiddleware::class]]);
$router->delete('/api/categories/{id}', [CategoryController::class, 'destroy', [AuthMiddleware::class, AdminMiddleware::class]]);

// Products
$router->get('/api/products',        [ProductController::class, 'index',   [AuthMiddleware::class]]);
$router->get('/api/products/{id}',   [ProductController::class, 'show',    [AuthMiddleware::class]]);
$router->get('/api/products/{id}/price-history', [ProductController::class, 'priceHistory', [AuthMiddleware::class]]);
$router->post('/api/products',       [ProductController::class, 'store',   [AuthMiddleware::class, AdminMiddleware::class]]);
$router->put('/api/products/{id}',   [ProductController::class, 'update',  [AuthMiddleware::class, AdminMiddleware::class]]);
$router->delete('/api/products/{id}',[ProductController::class, 'destroy', [AuthMiddleware::class, AdminMiddleware::class]]);
