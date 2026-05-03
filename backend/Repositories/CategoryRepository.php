<?php

namespace App\Repositories;

use App\Models\Category;

class CategoryRepository
{
    private Category $model;

    public function __construct(Category $model)
    {
        $this->model = $model;
    }

    public function all(array $filters = []): array
    {
        return $this->model->all($filters);
    }

    public function create(array $data): int
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): bool
    {
        return $this->model->update($id, $data);
    }

    public function delete(int $id): bool
    {
        return $this->model->delete($id);
    }

    public function getModel(): Category
    {
        return $this->model;
    }
}
