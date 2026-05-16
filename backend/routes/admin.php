<?php
use App\Controllers\ReportController;
use App\Controllers\UserController;
use App\Controllers\SettingsController;
use App\Controllers\UpdateController;
use App\Controllers\BackupController;
use App\Controllers\ExpenseController;
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

// Reports
$router->get('/api/reports/daily',    [ReportController::class, 'daily',        [AuthMiddleware::class]]);
$router->get('/api/reports/monthly',  [ReportController::class, 'monthly',      [AuthMiddleware::class]]);
$router->get('/api/reports/products', [ReportController::class, 'topProducts',  [AuthMiddleware::class]]);
$router->get('/api/reports/summary',  [ReportController::class, 'summary',      [AuthMiddleware::class]]);
$router->get('/api/reports/profit',   [ReportController::class, 'profitReport', [AuthMiddleware::class]]);

// Users
$router->get('/api/users',         [UserController::class, 'index',   [AuthMiddleware::class, AdminMiddleware::class]]);
$router->post('/api/users',        [UserController::class, 'store',   [AuthMiddleware::class, AdminMiddleware::class]]);
$router->put('/api/users/{id}',    [UserController::class, 'update',  [AuthMiddleware::class]]);
$router->delete('/api/users/{id}', [UserController::class, 'destroy', [AuthMiddleware::class, AdminMiddleware::class]]);

// Settings
$router->get('/api/settings',  [SettingsController::class, 'index',  [AuthMiddleware::class]]);
$router->post('/api/settings', [SettingsController::class, 'update', [AuthMiddleware::class, AdminMiddleware::class]]);

// Updates
$router->get('/api/update/check',     [UpdateController::class, 'check',     [AuthMiddleware::class, AdminMiddleware::class]]);
$router->post('/api/update/apply',    [UpdateController::class, 'apply',     [AuthMiddleware::class, AdminMiddleware::class]]);
$router->get('/api/update/changelog', [UpdateController::class, 'changelog', [AuthMiddleware::class, AdminMiddleware::class]]);

// Backup
$router->get('/api/backup', [BackupController::class, 'download', [AuthMiddleware::class, AdminMiddleware::class]]);
$router->post('/api/backup/restore', [BackupController::class, 'restore', [AuthMiddleware::class, AdminMiddleware::class]]);
$router->post('/api/admin/backup/schedule', [BackupController::class, 'schedule', [AuthMiddleware::class, AdminMiddleware::class]]);

// Expenses
$router->get('/api/expense-categories',         [ExpenseController::class, 'getCategories', [AuthMiddleware::class]]);
$router->post('/api/expense-categories',        [ExpenseController::class, 'createCategory', [AuthMiddleware::class]]);
$router->put('/api/expense-categories/{id}',    [ExpenseController::class, 'updateCategory', [AuthMiddleware::class]]);
$router->delete('/api/expense-categories/{id}', [ExpenseController::class, 'deleteCategory', [AuthMiddleware::class, AdminMiddleware::class]]);
$router->get('/api/expenses',         [ExpenseController::class, 'getExpenses', [AuthMiddleware::class]]);
$router->post('/api/expenses',        [ExpenseController::class, 'createExpense', [AuthMiddleware::class]]);
$router->put('/api/expenses/{id}',    [ExpenseController::class, 'updateExpense', [AuthMiddleware::class]]);
$router->delete('/api/expenses/{id}', [ExpenseController::class, 'deleteExpense', [AuthMiddleware::class, AdminMiddleware::class]]);

// Health
$router->get('/api/admin/health/slow-queries', [\App\Controllers\HealthController::class, 'slowQueries', [AuthMiddleware::class, AdminMiddleware::class]]);
$router->get('/api/admin/client-logs', [\App\Controllers\ClientLogController::class, 'index', [AuthMiddleware::class, AdminMiddleware::class]]);

// Audit Logs
$router->get('/api/admin/audit-logs', [\App\Controllers\AuditLogController::class, 'index', [AuthMiddleware::class, AdminMiddleware::class]]);
