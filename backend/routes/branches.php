<?php
use App\Controllers\BranchController;
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

$router->get('/api/branches',          [BranchController::class, 'index',  [AuthMiddleware::class]]);
$router->post('/api/branches',         [BranchController::class, 'store',  [AuthMiddleware::class, AdminMiddleware::class]]);
$router->put('/api/branches/{id}',     [BranchController::class, 'update', [AuthMiddleware::class, AdminMiddleware::class]]);
