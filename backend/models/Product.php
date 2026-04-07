<?php

class Product {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function all(array $filters = []): array {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]          = '(p.name LIKE :search OR p.barcode LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['category_id'])) {
            $where[]              = 'p.category_id = :category_id';
            $params['category_id'] = $filters['category_id'];
        }
        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $where[] = 'p.quantity <= p.low_stock_threshold';
        }

        $sql = 'SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY p.name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByBarcode(string $barcode): ?array {
        $stmt = $this->db->prepare(
            'SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.barcode = ?'
        );
        $stmt->execute([$barcode]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO products (name, barcode, price, cost, quantity, low_stock_threshold, category_id)
             VALUES (:name, :barcode, :price, :cost, :quantity, :low_stock_threshold, :category_id)'
        );
        $stmt->execute([
            'name'                => $data['name'],
            'barcode'             => $data['barcode'],
            'price'               => $data['price'],
            'cost'                => $data['cost'] ?? 0,
            'quantity'            => $data['quantity'] ?? 0,
            'low_stock_threshold' => $data['low_stock_threshold'] ?? LOW_STOCK_THRESHOLD,
            'category_id'         => $data['category_id'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $stmt = $this->db->prepare(
            'UPDATE products SET
                name = :name,
                barcode = :barcode,
                price = :price,
                cost = :cost,
                quantity = :quantity,
                low_stock_threshold = :low_stock_threshold,
                category_id = :category_id
             WHERE id = :id'
        );
        $stmt->execute([
            'name'                => $data['name'],
            'barcode'             => $data['barcode'],
            'price'               => $data['price'],
            'cost'                => $data['cost'] ?? 0,
            'quantity'            => $data['quantity'] ?? 0,
            'low_stock_threshold' => $data['low_stock_threshold'] ?? LOW_STOCK_THRESHOLD,
            'category_id'         => $data['category_id'] ?? null,
            'id'                  => $id,
        ]);
    }

    public function delete(int $id): void {
        $this->db->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    }

    public function decrementQuantity(int $id, int $qty): void {
        $this->db->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')->execute([$qty, $id]);
    }

    public function incrementQuantity(int $id, int $qty): void {
        $this->db->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')->execute([$qty, $id]);
    }

    public function getLowStock(): array {
        return $this->db->query(
            'SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.quantity <= p.low_stock_threshold
             ORDER BY p.quantity ASC'
        )->fetchAll();
    }
}
