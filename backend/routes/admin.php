<?php
use App\Controllers\ReportController;
use App\Controllers\UserController;
use App\Controllers\SettingsController;
use App\Controllers\UpdateController;
use App\Controllers\BackupController;
use App\Controllers\ExpenseController;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;

// Reports
$router->get('/api/reports/daily',    [ReportController::class, 'daily',        [AuthMiddleware::class, PermissionMiddleware::require('reports.view')]]);
$router->get('/api/reports/monthly',  [ReportController::class, 'monthly',      [AuthMiddleware::class, PermissionMiddleware::require('reports.view')]]);
$router->get('/api/reports/products', [ReportController::class, 'topProducts',  [AuthMiddleware::class, PermissionMiddleware::require('reports.view')]]);
$router->get('/api/reports/summary',  [ReportController::class, 'summary',      [AuthMiddleware::class, PermissionMiddleware::require('reports.view')]]);
$router->get('/api/reports/profit',   [ReportController::class, 'profitReport', [AuthMiddleware::class, PermissionMiddleware::require('reports.view')]]);

// Users
$router->get('/api/users',         [UserController::class, 'index',   [AuthMiddleware::class, PermissionMiddleware::require('users.manage')]]);
$router->post('/api/users',        [UserController::class, 'store',   [AuthMiddleware::class, PermissionMiddleware::require('users.manage')]]);
$router->put('/api/users/{id}',    [UserController::class, 'update',  [AuthMiddleware::class]]);
$router->delete('/api/users/{id}', [UserController::class, 'destroy', [AuthMiddleware::class, PermissionMiddleware::require('users.manage')]]);

// Settings
$router->get('/api/settings',  [SettingsController::class, 'index',  [AuthMiddleware::class]]);
$router->post('/api/settings', [SettingsController::class, 'update', [AuthMiddleware::class, PermissionMiddleware::require('settings.update')]]);

// Updates
$router->get('/api/update/check',     [UpdateController::class, 'check',     [AuthMiddleware::class, PermissionMiddleware::require('settings.update')]]);
$router->post('/api/update/apply',    [UpdateController::class, 'apply',     [AuthMiddleware::class, PermissionMiddleware::require('settings.update')]]);
$router->get('/api/update/changelog', [UpdateController::class, 'changelog', [AuthMiddleware::class, PermissionMiddleware::require('settings.update')]]);

// Backup
$router->get('/api/backup', [BackupController::class, 'download', [AuthMiddleware::class, PermissionMiddleware::require('backup.manage')]]);
$router->post('/api/backup/restore', [BackupController::class, 'restore', [AuthMiddleware::class, PermissionMiddleware::require('backup.manage')]]);
$router->post('/api/admin/backup/schedule', [BackupController::class, 'schedule', [AuthMiddleware::class, PermissionMiddleware::require('backup.manage')]]);

// Expenses
$router->get('/api/expense-categories',         [ExpenseController::class, 'getCategories', [AuthMiddleware::class]]);
$router->post('/api/expense-categories',        [ExpenseController::class, 'createCategory', [AuthMiddleware::class, PermissionMiddleware::require('expenses.manage')]]);
$router->put('/api/expense-categories/{id}',    [ExpenseController::class, 'updateCategory', [AuthMiddleware::class, PermissionMiddleware::require('expenses.manage')]]);
$router->delete('/api/expense-categories/{id}', [ExpenseController::class, 'deleteCategory', [AuthMiddleware::class, PermissionMiddleware::require('expenses.delete')]]);
$router->get('/api/expenses',         [ExpenseController::class, 'getExpenses', [AuthMiddleware::class]]);
$router->post('/api/expenses',        [ExpenseController::class, 'createExpense', [AuthMiddleware::class, PermissionMiddleware::require('expenses.create')]]);
$router->put('/api/expenses/{id}',    [ExpenseController::class, 'updateExpense', [AuthMiddleware::class, PermissionMiddleware::require('expenses.update')]]);
$router->delete('/api/expenses/{id}', [ExpenseController::class, 'deleteExpense', [AuthMiddleware::class, PermissionMiddleware::require('expenses.delete')]]);

// Health
$router->get('/api/admin/health/slow-queries', [\App\Controllers\HealthController::class, 'slowQueries', [AuthMiddleware::class, PermissionMiddleware::require('settings.update')]]);
$router->get('/api/admin/client-logs', [\App\Controllers\ClientLogController::class, 'index', [AuthMiddleware::class, PermissionMiddleware::require('audit.view')]]);

// Audit Logs
$router->get('/api/admin/audit-logs', [\App\Controllers\AuditLogController::class, 'index', [AuthMiddleware::class, PermissionMiddleware::require('audit.view')]]);
