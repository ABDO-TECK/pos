<?php

// ── Auth ───────────────────────────────────────────────────────
$router->get('/api/csrf-cookie', [AuthController::class, 'csrfCookie']);
$router->post('/api/login',  [AuthController::class, 'login']);
$router->post('/api/logout', [AuthController::class, 'logout', [AuthMiddleware::class]]);
$router->get('/api/user',    [AuthController::class, 'me',     [AuthMiddleware::class]]);

// ── Categories ─────────────────────────────────────────────────
$router->get('/api/categories',      [CategoryController::class, 'index',   [AuthMiddleware::class]]);
$router->post('/api/categories',     [CategoryController::class, 'store',   [AuthMiddleware::class, AdminMiddleware::class]]);
$router->put('/api/categories/{id}', [CategoryController::class, 'update',  [AuthMiddleware::class, AdminMiddleware::class]]);
$router->delete('/api/categories/{id}', [CategoryController::class, 'destroy', [AuthMiddleware::class, AdminMiddleware::class]]);

// ── Products ───────────────────────────────────────────────────
$router->get('/api/products',        [ProductController::class, 'index',   [AuthMiddleware::class]]);
$router->get('/api/products/{id}',   [ProductController::class, 'show',    [AuthMiddleware::class]]);
$router->post('/api/products',       [ProductController::class, 'store',   [AuthMiddleware::class, AdminMiddleware::class]]);
$router->put('/api/products/{id}',   [ProductController::class, 'update',  [AuthMiddleware::class, AdminMiddleware::class]]);
$router->delete('/api/products/{id}',[ProductController::class, 'destroy', [AuthMiddleware::class, AdminMiddleware::class]]);

// ── Sales ──────────────────────────────────────────────────────
$router->post('/api/sales',          [SaleController::class, 'store',   [AuthMiddleware::class]]);
$router->get('/api/sales',           [SaleController::class, 'index',   [AuthMiddleware::class]]);
$router->get('/api/sales/{id}',      [SaleController::class, 'show',    [AuthMiddleware::class]]);
$router->delete('/api/sales/{id}',  [SaleController::class, 'destroy', [AuthMiddleware::class, AdminMiddleware::class]]);

// ── Inventory ──────────────────────────────────────────────────
$router->get('/api/inventory',          [InventoryController::class, 'index',   [AuthMiddleware::class]]);
$router->get('/api/inventory/low-stock',[InventoryController::class, 'lowStock',[AuthMiddleware::class]]);
$router->put('/api/inventory/{id}',     [InventoryController::class, 'adjust',  [AuthMiddleware::class, AdminMiddleware::class]]);

// ── Suppliers ──────────────────────────────────────────────────
$router->get('/api/suppliers',         [SupplierController::class, 'index',   [AuthMiddleware::class]]);
$router->get('/api/suppliers/{id}',    [SupplierController::class, 'show',    [AuthMiddleware::class]]);
$router->post('/api/suppliers',        [SupplierController::class, 'store',   [AuthMiddleware::class, AdminMiddleware::class]]);
$router->put('/api/suppliers/{id}',    [SupplierController::class, 'update',  [AuthMiddleware::class, AdminMiddleware::class]]);
$router->delete('/api/suppliers/{id}', [SupplierController::class, 'destroy', [AuthMiddleware::class, AdminMiddleware::class]]);
$router->post('/api/purchases',        [SupplierController::class, 'purchase',[AuthMiddleware::class, AdminMiddleware::class]]);
$router->get('/api/purchases',         [SupplierController::class, 'purchases',[AuthMiddleware::class]]);

// ── Purchase Invoices (فواتير المشتريات) ───────────────────────
$router->get('/api/purchase-invoices',         [SupplierController::class, 'purchaseInvoices',       [AuthMiddleware::class]]);
$router->get('/api/purchase-invoices/{id}',    [SupplierController::class, 'purchaseInvoiceDetail',  [AuthMiddleware::class]]);
$router->delete('/api/purchase-invoices/{id}', [SupplierController::class, 'purchaseInvoiceDelete',  [AuthMiddleware::class, AdminMiddleware::class]]);

// ── Bulk Purchases ─────────────────────────────────────────────
$router->post('/api/purchases/bulk', [SupplierController::class, 'purchaseBulk', [AuthMiddleware::class, AdminMiddleware::class]]);
$router->post('/api/suppliers/{id}/payment', [SupplierController::class, 'addPayment', [AuthMiddleware::class]]);
$router->put('/api/suppliers/ledger/{entryId}', [SupplierController::class, 'updateLedgerEntry', [AuthMiddleware::class]]);

// ── Ledger PDF Export (Server-side mPDF) ───────────────────────
$router->get('/api/customers/{id}/pdf', [LedgerPdfController::class, 'customerPdf', [AuthMiddleware::class]]);
$router->get('/api/suppliers/{id}/pdf', [LedgerPdfController::class, 'supplierPdf', [AuthMiddleware::class]]);

// ── Reports ────────────────────────────────────────────────────
$router->get('/api/reports/daily',    [ReportController::class, 'daily',        [AuthMiddleware::class]]);
$router->get('/api/reports/monthly',  [ReportController::class, 'monthly',      [AuthMiddleware::class]]);
$router->get('/api/reports/products', [ReportController::class, 'topProducts',  [AuthMiddleware::class]]);
$router->get('/api/reports/summary',  [ReportController::class, 'summary',      [AuthMiddleware::class]]);
$router->get('/api/reports/profit',   [ReportController::class, 'profitReport', [AuthMiddleware::class]]);

// ── Users ──────────────────────────────────────────────────────
$router->get('/api/users',         [UserController::class, 'index',   [AuthMiddleware::class, AdminMiddleware::class]]);
$router->post('/api/users',        [UserController::class, 'store',   [AuthMiddleware::class, AdminMiddleware::class]]);
$router->put('/api/users/{id}',    [UserController::class, 'update',  [AuthMiddleware::class, AdminMiddleware::class]]);
$router->delete('/api/users/{id}', [UserController::class, 'destroy', [AuthMiddleware::class, AdminMiddleware::class]]);

// ── Settings ───────────────────────────────────────────────────
$router->get('/api/settings',  [SettingsController::class, 'index',  [AuthMiddleware::class]]);
$router->post('/api/settings', [SettingsController::class, 'update', [AuthMiddleware::class, AdminMiddleware::class]]);

// ── Updates ────────────────────────────────────────────────────
$router->get('/api/update/check',     [UpdateController::class, 'check',     [AuthMiddleware::class, AdminMiddleware::class]]);
$router->post('/api/update/apply',    [UpdateController::class, 'apply',     [AuthMiddleware::class, AdminMiddleware::class]]);
$router->get('/api/update/changelog', [UpdateController::class, 'changelog', [AuthMiddleware::class, AdminMiddleware::class]]);

// ── Backup ─────────────────────────────────────────────────────
$router->get('/api/backup', [BackupController::class, 'download', [AuthMiddleware::class, AdminMiddleware::class]]);
$router->post('/api/backup/restore', [BackupController::class, 'restore', [AuthMiddleware::class, AdminMiddleware::class]]);

// ── Customers ──────────────────────────────────────────────────
$router->get('/api/customers',                 [CustomerController::class, 'index',      [AuthMiddleware::class]]);
$router->post('/api/customers',                [CustomerController::class, 'store',      [AuthMiddleware::class]]);
$router->get('/api/customers/{id}',            [CustomerController::class, 'show',       [AuthMiddleware::class]]);
$router->put('/api/customers/{id}',            [CustomerController::class, 'update',     [AuthMiddleware::class]]);
$router->delete('/api/customers/{id}',         [CustomerController::class, 'destroy',    [AuthMiddleware::class, AdminMiddleware::class]]);
$router->post('/api/customers/{id}/payment',   [CustomerController::class, 'addPayment',       [AuthMiddleware::class]]);
$router->put('/api/customers/ledger/{entryId}', [CustomerController::class, 'updateLedgerEntry', [AuthMiddleware::class]]);

// ── Expenses ─────────────────────────────────────────────────────
$router->get('/api/expense-categories',         [ExpenseController::class, 'getCategories', [AuthMiddleware::class]]);
$router->post('/api/expense-categories',        [ExpenseController::class, 'createCategory', [AuthMiddleware::class]]);
$router->put('/api/expense-categories/{id}',    [ExpenseController::class, 'updateCategory', [AuthMiddleware::class]]);
$router->delete('/api/expense-categories/{id}', [ExpenseController::class, 'deleteCategory', [AuthMiddleware::class, AdminMiddleware::class]]);

$router->get('/api/expenses',         [ExpenseController::class, 'getExpenses', [AuthMiddleware::class]]);
$router->post('/api/expenses',        [ExpenseController::class, 'createExpense', [AuthMiddleware::class]]);
$router->put('/api/expenses/{id}',    [ExpenseController::class, 'updateExpense', [AuthMiddleware::class]]);
$router->delete('/api/expenses/{id}', [ExpenseController::class, 'deleteExpense', [AuthMiddleware::class, AdminMiddleware::class]]);

// ── Health Check ───────────────────────────────────────────────
$router->get('/api/health', [HealthController::class, 'check']);
