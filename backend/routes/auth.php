<?php
use App\Controllers\AuthController;
use App\Middleware\AuthMiddleware;

$router->get('/api/csrf-cookie', [AuthController::class, 'csrfCookie']);
$router->post('/api/login',  [AuthController::class, 'login',  [\App\Middleware\EndpointRateLimiter::limit('login', 5, 60)]]);
$router->post('/api/logout', [AuthController::class, 'logout', [AuthMiddleware::class]]);
$router->get('/api/user',    [AuthController::class, 'me',     [AuthMiddleware::class]]);
$router->post('/api/refresh', [AuthController::class, 'refresh']);
