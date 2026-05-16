<?php
/** @var \App\Core\Router $router */

use App\Controllers\SseController;
use App\Middleware\AuthMiddleware;

// SSE — لا يحتاج CSRF لكنه يحتاج Auth
$router->get('/api/sse/inventory', [SseController::class, 'inventory', [AuthMiddleware::class]]);
