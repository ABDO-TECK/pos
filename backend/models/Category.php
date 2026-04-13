<?php

class Category {

    protected string $table = 'categories';
    protected PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function all(array $filters = []): array {
        $sql = "SELECT * FROM {$this->table} ORDER BY name ASC";
        
        // التحقق مما إذا كان هناك ترقيم (Pagination) مطلوب
        if (!empty($filters['page']) && !empty($filters['limit'])) {
            $page  = max(1, (int)$filters['page']);
            $limit = max(1, (int)$filters['limit']);
            $offset = ($page - 1) * $limit;
            
            $total = $this->db->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn();
            
            $sql .= " LIMIT $limit OFFSET $offset";
            $data = $this->db->query($sql)->fetchAll();
            
            return [
                'data'       => $data,
                'pagination' => [
                    'total'        => (int) $total,
                    'per_page'     => $limit,
                    'current_page' => $page,
                    'last_page'    => ceil($total / $limit)
                ]
            ];
        }

        // إرجاع كل البيانات بدون صفحات
        $data = $this->db->query($sql)->fetchAll();
        return ['data' => $data];
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("INSERT INTO {$this->table} (name) VALUES (?)");
        $stmt->execute([$data['name']]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool {
        return $this->db->prepare("UPDATE {$this->table} SET name = ? WHERE id = ?")->execute([$data['name'], $id]);
    }

    public function delete(int $id): bool {
        return $this->db->prepare("DELETE FROM {$this->table} WHERE id = ?")->execute([$id]);
    }
}
