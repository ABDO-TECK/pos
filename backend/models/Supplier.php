<?php

class Supplier {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function all(): array {
        return $this->db->query('SELECT * FROM suppliers ORDER BY name ASC')->fetchAll();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO suppliers (name, phone, email, address) VALUES (:name, :phone, :email, :address)'
        );
        $stmt->execute([
            'name'    => $data['name'],
            'phone'   => $data['phone'] ?? null,
            'email'   => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $stmt = $this->db->prepare(
            'UPDATE suppliers SET name = :name, phone = :phone, email = :email, address = :address WHERE id = :id'
        );
        $stmt->execute([
            'name'    => $data['name'],
            'phone'   => $data['phone'] ?? null,
            'email'   => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'id'      => $id,
        ]);
    }

    public function delete(int $id): void {
        $this->db->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
    }

    public function createPurchase(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO purchases (supplier_id, product_id, quantity, cost, total, notes)
             VALUES (:supplier_id, :product_id, :quantity, :cost, :total, :notes)'
        );
        $stmt->execute([
            'supplier_id' => $data['supplier_id'],
            'product_id'  => $data['product_id'],
            'quantity'    => $data['quantity'],
            'cost'        => $data['cost'],
            'total'       => $data['quantity'] * $data['cost'],
            'notes'       => $data['notes'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function getPurchases(array $filters = []): array {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['supplier_id'])) {
            $where[]                 = 'pu.supplier_id = :supplier_id';
            $params['supplier_id']   = $filters['supplier_id'];
        }

        $stmt = $this->db->prepare(
            'SELECT pu.*, s.name AS supplier_name, p.name AS product_name
             FROM purchases pu
             JOIN suppliers s ON s.id = pu.supplier_id
             JOIN products p ON p.id = pu.product_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY pu.created_at DESC
             LIMIT 200'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
