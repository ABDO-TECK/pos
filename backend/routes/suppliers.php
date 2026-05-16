<?php
use App\Controllers\SupplierController;
use App\Controllers\LedgerPdfController;
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

// Suppliers
$router->get('/api/suppliers',         [SupplierController::class, 'index',   [AuthMiddleware::class]]);
$router->get('/api/suppliers/{id}',    [SupplierController::class, 'show',    [AuthMiddleware::class]]);
$router->post('/api/suppliers',        [SupplierController::class, 'store',   [AuthMiddleware::class, AdminMiddleware::class]]);
$router->put('/api/suppliers/{id}',    [SupplierController::class, 'update',  [AuthMiddleware::class, AdminMiddleware::class]]);
$router->delete('/api/suppliers/{id}', [SupplierController::class, 'destroy', [AuthMiddleware::class, AdminMiddleware::class]]);
$router->post('/api/purchases',        [SupplierController::class, 'purchase',[AuthMiddleware::class, AdminMiddleware::class]]);
$router->get('/api/purchases',         [SupplierController::class, 'purchases',[AuthMiddleware::class]]);

// Purchase Invoices
$router->get('/api/purchase-invoices',         [SupplierController::class, 'purchaseInvoices',       [AuthMiddleware::class]]);
$router->get('/api/purchase-invoices/{id}',    [SupplierController::class, 'purchaseInvoiceDetail',  [AuthMiddleware::class]]);
$router->delete('/api/purchase-invoices/{id}', [SupplierController::class, 'purchaseInvoiceDelete',  [AuthMiddleware::class, AdminMiddleware::class]]);

// Bulk Purchases & Payments
$router->post('/api/purchases/bulk', [SupplierController::class, 'purchaseBulk', [AuthMiddleware::class, AdminMiddleware::class]]);
$router->post('/api/suppliers/{id}/payment', [SupplierController::class, 'addPayment', [AuthMiddleware::class]]);
$router->put('/api/suppliers/ledger/{entryId}', [SupplierController::class, 'updateLedgerEntry', [AuthMiddleware::class]]);

// Ledger PDF
$router->get('/api/suppliers/{id}/pdf', [LedgerPdfController::class, 'supplierPdf', [AuthMiddleware::class]]);
