<?php
use App\Controllers\CustomerController;
use App\Controllers\LedgerPdfController;
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

$router->get('/api/customers',                 [CustomerController::class, 'index',      [AuthMiddleware::class]]);
$router->post('/api/customers',                [CustomerController::class, 'store',      [AuthMiddleware::class]]);
$router->get('/api/customers/{id}',            [CustomerController::class, 'show',       [AuthMiddleware::class]]);
$router->put('/api/customers/{id}',            [CustomerController::class, 'update',     [AuthMiddleware::class]]);
$router->delete('/api/customers/{id}',         [CustomerController::class, 'destroy',    [AuthMiddleware::class, AdminMiddleware::class]]);
$router->post('/api/customers/{id}/payment',   [CustomerController::class, 'addPayment',       [AuthMiddleware::class]]);
$router->put('/api/customers/ledger/{entryId}', [CustomerController::class, 'updateLedgerEntry', [AuthMiddleware::class]]);
$router->get('/api/customers/{id}/pdf', [LedgerPdfController::class, 'customerPdf', [AuthMiddleware::class]]);

use App\Controllers\LoyaltyController;
$router->get('/api/loyalty/status',            [LoyaltyController::class, 'status',  [AuthMiddleware::class]]);
$router->get('/api/loyalty/{customerId}/history', [LoyaltyController::class, 'history', [AuthMiddleware::class]]);
$router->post('/api/loyalty/{customerId}/redeem', [LoyaltyController::class, 'redeem',  [AuthMiddleware::class]]);
