<?php
use App\Controllers\BranchController;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;

$router->get('/api/branches',          [BranchController::class, 'index',  [AuthMiddleware::class]]);
$router->post('/api/branches',         [BranchController::class, 'store',  [AuthMiddleware::class, PermissionMiddleware::require('branches.create')]]);
$router->put('/api/branches/{id}',     [BranchController::class, 'update', [AuthMiddleware::class, PermissionMiddleware::require('branches.update')]]);
