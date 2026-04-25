<?php

class Expense
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getAll(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['date'])) {
            $where[] = "DATE(e.expense_date) = :date";
            $params['date'] = $filters['date'];
        }
        if (!empty($filters['month']) && !empty($filters['year'])) {
            $where[] = "MONTH(e.expense_date) = :month AND YEAR(e.expense_date) = :year";
            $params['month'] = $filters['month'];
            $params['year'] = $filters['year'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = "e.category_id = :category_id";
            $params['category_id'] = $filters['category_id'];
        }

        $whereClause = implode(' AND ', $where);

        $page  = isset($filters['page'])  ? max(1, (int) $filters['page'])  : 1;
        $limit = isset($filters['limit']) ? max(1, min(1000, (int) $filters['limit'])) : 1000;

        $countSql = "SELECT COUNT(*) FROM expenses e WHERE $whereClause";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;

        $sql = "SELECT e.*, c.name as category_name, u.name as user_name 
                FROM expenses e 
                JOIN expense_categories c ON e.category_id = c.id 
                JOIN users u ON e.user_id = u.id 
                WHERE $whereClause
                ORDER BY e.expense_date DESC, e.id DESC
                LIMIT $limit OFFSET $offset";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return [
            'data' => $rows,
            'pagination' => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT e.*, c.name as category_name, u.name as user_name 
                                    FROM expenses e 
                                    JOIN expense_categories c ON e.category_id = c.id 
                                    JOIN users u ON e.user_id = u.id 
                                    WHERE e.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('
            INSERT INTO expenses (category_id, user_id, amount, notes, expense_date) 
            VALUES (:category_id, :user_id, :amount, :notes, :expense_date)
        ');
        $stmt->execute([
            'category_id'  => $data['category_id'],
            'user_id'      => $data['user_id'],
            'amount'       => $data['amount'],
            'notes'        => $data['notes'] ?? null,
            'expense_date' => $data['expense_date']
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare('
            UPDATE expenses SET 
                category_id = :category_id,
                amount = :amount,
                notes = :notes,
                expense_date = :expense_date
            WHERE id = :id
        ');
        $stmt->execute([
            'category_id'  => $data['category_id'],
            'amount'       => $data['amount'],
            'notes'        => $data['notes'] ?? null,
            'expense_date' => $data['expense_date'],
            'id'           => $id
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM expenses WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function getTotalExpensesForDate(string $date): float
    {
        $stmt = $this->db->prepare('SELECT SUM(amount) FROM expenses WHERE DATE(expense_date) = ?');
        $stmt->execute([$date]);
        return (float) $stmt->fetchColumn();
    }

    public function getTotalExpensesForMonth(int $month, int $year): float
    {
        $stmt = $this->db->prepare('SELECT SUM(amount) FROM expenses WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ?');
        $stmt->execute([$month, $year]);
        return (float) $stmt->fetchColumn();
    }
}
