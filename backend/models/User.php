<?php

class User {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function findByEmail(string $email): ?array {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT id, name, email, role, is_active, created_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function createToken(int $userId): string {
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + TOKEN_LIFETIME);
        $stmt = $this->db->prepare('INSERT INTO tokens (user_id, token, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $token, $expiresAt]);
        return $token;
    }

    public function deleteToken(string $token): void {
        $stmt = $this->db->prepare('DELETE FROM tokens WHERE token = ?');
        $stmt->execute([$token]);
    }

    public function all(array $filters = []): array {
        $sql = 'SELECT id, name, email, role, is_active, created_at FROM users ORDER BY id DESC';
        
        if (!empty($filters['page']) && !empty($filters['limit'])) {
            $page  = max(1, (int)$filters['page']);
            $limit = max(1, (int)$filters['limit']);
            $offset = ($page - 1) * $limit;
            
            $total = $this->db->query("SELECT COUNT(*) FROM users")->fetchColumn();
            
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

        return ['data' => $this->db->query($sql)->fetchAll()];
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO users (name, email, password, role) VALUES (:name, :email, :password, :role)'
        );
        $stmt->execute([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'role'     => $data['role'] ?? 'cashier',
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $fields = ['name = :name', 'email = :email', 'role = :role', 'is_active = :is_active'];
        $params = [
            'name'      => $data['name'],
            'email'     => $data['email'],
            'role'      => $data['role'],
            'is_active' => $data['is_active'] ?? 1,
            'id'        => $id,
        ];
        if (!empty($data['password'])) {
            $fields[] = 'password = :password';
            $params['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $this->db->prepare($sql)->execute($params);
    }

    public function delete(int $id): void {
        $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
}
