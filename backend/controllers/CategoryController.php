<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Services\CategoryService;

class CategoryController extends Controller {

    private CategoryService $categoryService;

    public function __construct(CategoryService $categoryService) {
        $this->categoryService = $categoryService;
    }

    public function index() {
        $filters = $this->getPaginationParams();
        $result = $this->categoryService->getAll($filters);

        if (isset($result['pagination'])) {
            return Response::cacheable($result['data'], 300, null, ['pagination' => $result['pagination']]);
        } else {
            $data = $result['data'] ?? $result;
            return Response::cacheable($data, 300);
        }
    }

    public function store() {
        $request = new \App\Requests\CategoryRequest($this->getBody());
        $data = $request->validated();

        try {
            $result = $this->categoryService->createCategory($data);
            return Response::success($result, 'Category created', 201);
        } catch (\Throwable $e) {
            return Response::error('Failed to create category: ' . $e->getMessage(), 500);
        }
    }

    public function update(string $id) {
        $request = new \App\Requests\CategoryRequest($this->getBody());
        $data = $request->validated();

        try {
            $result = $this->categoryService->updateCategory((int)$id, $data);
            return Response::success($result, 'Category updated');
        } catch (\Throwable $e) {
            return Response::error('Failed to update category: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(string $id) {
        try {
            $this->categoryService->deleteCategory((int)$id);
            return Response::success(null, 'Category deleted');
        } catch (\Throwable $e) {
            return Response::error('Failed to delete category: ' . $e->getMessage(), 500);
        }
    }
}


