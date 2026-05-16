<?php
use App\Controllers\HealthController;
use App\Controllers\ClientLogController;
use App\Middleware\AuthMiddleware;

$router->get('/api/health', [HealthController::class, 'check']);
$router->get('/api/health/metrics', [HealthController::class, 'metrics', [AuthMiddleware::class, \App\Middleware\AdminMiddleware::class]]);
$router->post('/api/client-log', [ClientLogController::class, 'store', [AuthMiddleware::class]]);
