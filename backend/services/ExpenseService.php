<?php

class ExpenseService {
    
    private Expense $expenseModel;

    public function __construct() {
        $this->expenseModel = new Expense();
    }

    public function createExpense(array $data, array $authUser): int {
        if (empty($data['category_id']) || empty($data['amount']) || empty($data['expense_date'])) {
            throw new Exception('البيانات المطلوبة غير مكتملة', 400);
        }

        $data['user_id'] = $authUser['id'];

        return $this->expenseModel->create($data);
    }

    public function updateExpense(int $id, array $data): void {
        if (empty($data['category_id']) || empty($data['amount']) || empty($data['expense_date'])) {
            throw new Exception('البيانات المطلوبة غير مكتملة', 400);
        }

        if (!$this->expenseModel->findById($id)) {
            throw new Exception('المصروف غير موجود', 404);
        }

        $this->expenseModel->update($id, $data);
    }
}
