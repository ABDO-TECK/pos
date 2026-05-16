<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ValidationException;
use App\Helpers\Cache;
use App\Helpers\Logger;
use App\Helpers\Response;
use App\Repositories\ExpenseRepository;
use App\Repositories\ExpenseCategoryRepository;
use App\Requests\ExpenseRequest;
use App\Services\AuthService;
use App\Services\ExpenseService;
use PDOException;
use Throwable;


class ExpenseController extends Controller
{
    private ExpenseRepository $expenseRepo;
    private ExpenseCategoryRepository $categoryRepo;
    private ExpenseService $expenseService;
    private AuthService $authService;

    public function __construct(ExpenseRepository $expenseRepo, ExpenseCategoryRepository $categoryRepo, ExpenseService $expenseService, AuthService $authService)
    {
        $this->expenseRepo = $expenseRepo;
        $this->categoryRepo = $categoryRepo;
        $this->expenseService = $expenseService;
        $this->authService = $authService;
    }

    // ── Categories ───────────────────────────────────────────────────

    public function getCategories(): array
    {
        return Response::cacheable($this->categoryRepo->getAll(), 300); // Cache for 5 minutes
    }

    public function createCategory(): array
    {
        $data = $this->getBody();
        if (empty($data['name'])) {
            return Response::error('اسم التصنيف مطلوب', 400);
        }
        try {
            return $this->withTransaction(function () use ($data) {
                $id = $this->categoryRepo->create($data);
                return Response::success($this->categoryRepo->findById($id));
            });
        } catch (Throwable $e) {
            Logger::error('Failed to create expense category', ['error' => $e->getMessage()]);
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                return Response::error('هذا التصنيف موجود مسبقاً', 422);
            }
            return Response::error('حدث خطأ أثناء إضافة التصنيف', 500);
        }
    }

    public function updateCategory(string $id): array
    {
        $id = (int)$id;
        $data = $this->getBody();
        if (empty($data['name'])) {
            return Response::error('اسم التصنيف مطلوب', 400);
        }
        if (!$this->categoryRepo->findById($id)) {
            return Response::notFound('التصنيف غير موجود');
        }
        try {
            return $this->withTransaction(function () use ($id, $data) {
                $this->categoryRepo->update($id, $data);
                return Response::success($this->categoryRepo->findById($id));
            });
        } catch (Throwable $e) {
            Logger::error('Failed to update expense category', ['error' => $e->getMessage()]);
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                return Response::error('هذا التصنيف موجود مسبقاً', 422);
            }
            return Response::error('حدث خطأ أثناء تعديل التصنيف', 500);
        }
    }

    public function deleteCategory(string $id): array
    {
        $id = (int)$id;
        if (!$this->categoryRepo->findById($id)) {
            return Response::notFound('التصنيف غير موجود');
        }
        try {
            return $this->withTransaction(function () use ($id) {
                $this->categoryRepo->delete($id);
                return Response::success(['message' => 'تم الحذف بنجاح']);
            });
        } catch (Throwable $e) {
            Logger::error('Failed to delete expense category', ['error' => $e->getMessage()]);
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                return Response::error('لا يمكن حذف هذا التصنيف لوجود مصروفات مرتبطة به', 422);
            }
            return Response::error('حدث خطأ أثناء الحذف', 500);
        }
    }

    // ── Expenses ─────────────────────────────────────────────────────

    public function getExpenses(): array
    {
        $filters = [];
        if ($this->getParam('date')) $filters['date'] = $this->getParam('date');
        if ($this->getParam('month') && $this->getParam('year')) {
            $filters['month'] = $this->getParam('month');
            $filters['year'] = $this->getParam('year');
        }
        if ($this->getParam('category_id')) $filters['category_id'] = $this->getParam('category_id');

        $filters += $this->getPaginationParams();

        $result = $this->expenseRepo->getAll($filters);

        if (isset($result['pagination'])) {
            return Response::success($result['data'], 'success', 200, ['pagination' => $result['pagination']]);
        }
        return Response::success($result);
    }

    public function createExpense(): array
    {
        try {
            $request = new ExpenseRequest($this->getBody());
            $data = $request->validated();
        } catch (ValidationException $e) {
            return Response::error('فشل التحقق من صحة البيانات', 422, $e->getErrors());
        }

        $user = $this->authService->user() ?? null;
        if (!$user) {
            return Response::error('غير مصرح', 401);
        }

        try {
            return $this->withTransaction(function () use ($data, $user) {
                $id = $this->expenseService->createExpense($data, $user);
                return Response::success($this->expenseRepo->findById($id));
            });
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            if ($code === 400) return Response::error($e->getMessage(), 400);
            Logger::error('Failed to create expense', ['error' => $e->getMessage()]);
            return Response::error('حدث خطأ أثناء تسجيل المصروف', 500);
        }
    }

    public function updateExpense(string $id): array
    {
        $id = (int)$id;
        try {
            $request = new ExpenseRequest($this->getBody());
            $data = $request->validated();
        } catch (ValidationException $e) {
            return Response::error('فشل التحقق من صحة البيانات', 422, $e->getErrors());
        }

        try {
            return $this->withTransaction(function () use ($id, $data) {
                $this->expenseService->updateExpense($id, $data);
                return Response::success($this->expenseRepo->findById($id));
            });
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            if ($code === 400) return Response::error($e->getMessage(), 400);
            if ($code === 404) return Response::error($e->getMessage(), 404);
            Logger::error('Failed to update expense', ['error' => $e->getMessage()]);
            return Response::error('حدث خطأ أثناء تعديل المصروف', 500);
        }
    }

    public function deleteExpense(string $id): array
    {
        $id = (int)$id;
        if (!$this->expenseRepo->findById($id)) {
            return Response::notFound('المصروف غير موجود');
        }
        try {
            return $this->withTransaction(function () use ($id) {
                $this->expenseRepo->delete($id);
                return Response::success(['message' => 'تم حذف المصروف بنجاح']);
            });
        } catch (Throwable $e) {
            Logger::error('Failed to delete expense', ['error' => $e->getMessage()]);
            return Response::error('حدث خطأ أثناء الحذف', 500);
        }
    }
}
