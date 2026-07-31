<?php
use App\Controllers\AuthController;
use App\Middleware\AuthMiddleware;
use App\Middleware\LoginRateLimiter;

$router->get('/api/csrf-cookie', [AuthController::class, 'csrfCookie']);
$router->post('/api/login',  [AuthController::class, 'login',  [LoginRateLimiter::class]]);
$router->post('/api/logout', [AuthController::class, 'logout', [AuthMiddleware::class]]);
$router->get('/api/user',    [AuthController::class, 'me',     [AuthMiddleware::class]]);
$router->post('/api/refresh', [AuthController::class, 'refresh', [\App\Middleware\EndpointRateLimiter::limit('refresh', 10, 60)]]);
