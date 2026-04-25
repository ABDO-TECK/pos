<?php

class ExpenseController
{
    private Expense $expenseModel;
    private ExpenseCategory $categoryModel;
    private ExpenseService $expenseService;

    public function __construct()
    {
        $this->expenseModel = new Expense();
        $this->categoryModel = new ExpenseCategory();
        $this->expenseService = new ExpenseService();
    }

    // ── Categories ───────────────────────────────────────────────────

    public function getCategories(): array
    {
        return Response::cacheable($this->categoryModel->getAll(), 300); // Cache for 5 minutes
    }

    public function createCategory(): array
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($data['name'])) {
            return Response::error('اسم التصنيف مطلوب', 400);
        }
        try {
            $id = $this->categoryModel->create($data);
            return Response::success($this->categoryModel->findById($id));
        } catch (Throwable $e) {
            Logger::error('Failed to create expense category', ['error' => $e->getMessage()]);
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                return Response::error('هذا التصنيف موجود مسبقاً', 422);
            }
            return Response::error('حدث خطأ أثناء إضافة التصنيف', 500);
        }
    }

    public function updateCategory(array $params): array
    {
        $id = (int)$params['id'];
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($data['name'])) {
            return Response::error('اسم التصنيف مطلوب', 400);
        }
        if (!$this->categoryModel->findById($id)) {
            return Response::error('التصنيف غير موجود', 404);
        }
        try {
            $this->categoryModel->update($id, $data);
            return Response::success($this->categoryModel->findById($id));
        } catch (Throwable $e) {
            Logger::error('Failed to update expense category', ['error' => $e->getMessage()]);
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                return Response::error('هذا التصنيف موجود مسبقاً', 422);
            }
            return Response::error('حدث خطأ أثناء تعديل التصنيف', 500);
        }
    }

    public function deleteCategory(array $params): array
    {
        $id = (int)$params['id'];
        if (!$this->categoryModel->findById($id)) {
            return Response::error('التصنيف غير موجود', 404);
        }
        try {
            $this->categoryModel->delete($id);
            return Response::success(['message' => 'تم الحذف بنجاح']);
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
        if (isset($_GET['date'])) $filters['date'] = $_GET['date'];
        if (isset($_GET['month']) && isset($_GET['year'])) {
            $filters['month'] = $_GET['month'];
            $filters['year'] = $_GET['year'];
        }
        if (isset($_GET['category_id'])) $filters['category_id'] = $_GET['category_id'];

        if (isset($_GET['page'])) $filters['page'] = $_GET['page'];
        if (isset($_GET['limit'])) $filters['limit'] = $_GET['limit'];

        $result = $this->expenseModel->getAll($filters);

        if (isset($result['pagination'])) {
            return Response::success($result['data'], 'success', 200, ['pagination' => $result['pagination']]);
        }
        return Response::success($result);
    }

    public function createExpense(): array
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $user = $_SERVER['AUTH_USER'] ?? null;
        if (!$user) {
            return Response::error('غير مصرح', 401);
        }

        try {
            $id = $this->expenseService->createExpense($data, $user);
            return Response::success($this->expenseModel->findById($id));
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            if ($code === 400) return Response::error($e->getMessage(), 400);
            Logger::error('Failed to create expense', ['error' => $e->getMessage()]);
            return Response::error('حدث خطأ أثناء تسجيل المصروف', 500);
        }
    }

    public function updateExpense(array $params): array
    {
        $id = (int)$params['id'];
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        try {
            $this->expenseService->updateExpense($id, $data);
            return Response::success($this->expenseModel->findById($id));
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            if ($code === 400) return Response::error($e->getMessage(), 400);
            if ($code === 404) return Response::error($e->getMessage(), 404);
            Logger::error('Failed to update expense', ['error' => $e->getMessage()]);
            return Response::error('حدث خطأ أثناء تعديل المصروف', 500);
        }
    }

    public function deleteExpense(array $params): array
    {
        $id = (int)$params['id'];
        if (!$this->expenseModel->findById($id)) {
            return Response::error('المصروف غير موجود', 404);
        }
        try {
            $this->expenseModel->delete($id);
            return Response::success(['message' => 'تم حذف المصروف بنجاح']);
        } catch (Throwable $e) {
            Logger::error('Failed to delete expense', ['error' => $e->getMessage()]);
            return Response::error('حدث خطأ أثناء الحذف', 500);
        }
    }
}
