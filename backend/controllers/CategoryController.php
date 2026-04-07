<?php

class CategoryController extends Controller {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function index(): void {
        $rows = $this->db->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();
        Response::success($rows);
    }

    public function store(): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $stmt = $this->db->prepare('INSERT INTO categories (name) VALUES (?)');
        $stmt->execute([$data['name']]);
        $id = (int) $this->db->lastInsertId();
        Response::success(['id' => $id, 'name' => $data['name']], 'Category created', 201);
    }

    public function update(string $id): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $this->db->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$data['name'], $id]);
        Response::success(['id' => (int)$id, 'name' => $data['name']], 'Category updated');
    }

    public function destroy(string $id): void {
        $this->db->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        Response::success(null, 'Category deleted');
    }
}
