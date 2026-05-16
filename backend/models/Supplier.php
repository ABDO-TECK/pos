<?php

namespace App\Models;

use App\Config\Database;
use PDO;


class Supplier {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /** جميع الموردين مع رصيدهم — مع دعم pagination اختياري */
    public function all(array $filters = []): array {
        $where  = ['s.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]          = '(s.name LIKE :search OR s.phone LIKE :search OR s.email LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $where);

        // ── Pagination الإجباري ──
        $page  = isset($filters['page'])  ? max(1, (int) $filters['page'])  : 1;
        $limit = isset($filters['limit']) ? max(1, min(1000, (int) $filters['limit'])) : 1000;

        $countSql = "SELECT COUNT(*) FROM suppliers s WHERE $whereClause";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $limit;
        $sql = "SELECT s.*,
            COALESCE(SUM(CASE WHEN sl.type = \"debit\"  THEN sl.amount ELSE 0 END), 0) AS total_debit,
            COALESCE(SUM(CASE WHEN sl.type = \"credit\" THEN sl.amount ELSE 0 END), 0) AS total_credit
         FROM suppliers s
         LEFT JOIN supplier_ledger sl ON sl.supplier_id = s.id
         WHERE $whereClause
         GROUP BY s.id
         ORDER BY s.name ASC
         LIMIT :pag_limit OFFSET :pag_offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':pag_limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':pag_offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['balance'] = round(
                (float)($r['initial_balance'] ?? 0) + (float)$r['total_debit'] - (float)$r['total_credit'],
                2
            );
        }
        unset($r);

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

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT s.*,
                COALESCE(SUM(CASE WHEN sl.type = "debit"  THEN sl.amount ELSE 0 END), 0) AS total_debit,
                COALESCE(SUM(CASE WHEN sl.type = "credit" THEN sl.amount ELSE 0 END), 0) AS total_credit
             FROM suppliers s
             LEFT JOIN supplier_ledger sl ON sl.supplier_id = s.id
             WHERE s.id = ?
             GROUP BY s.id'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['balance'] = round(
            (float)($row['initial_balance'] ?? 0) + (float)$row['total_debit'] - (float)$row['total_credit'],
            2
        );
        return $row;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO suppliers (name, phone, email, address, initial_balance) VALUES (:name, :phone, :email, :address, :initial_balance)'
        );
        $stmt->execute([
            'name'            => $data['name'],
            'phone'           => $data['phone'] ?? null,
            'email'           => $data['email'] ?? null,
            'address'         => $data['address'] ?? null,
            'initial_balance' => (float)($data['initial_balance'] ?? 0),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $stmt = $this->db->prepare(
            'UPDATE suppliers SET name = :name, phone = :phone, email = :email, address = :address, initial_balance = :initial_balance WHERE id = :id'
        );
        $stmt->execute([
            'name'            => $data['name'],
            'phone'           => $data['phone'] ?? null,
            'email'           => $data['email'] ?? null,
            'address'         => $data['address'] ?? null,
            'initial_balance' => (float)($data['initial_balance'] ?? 0),
            'id'              => $id,
        ]);
    }

    public function delete(int $id): void {
        $this->db->prepare('UPDATE suppliers SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
    }



    public function getTotalSuppliersCount(): int {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM suppliers');
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}
