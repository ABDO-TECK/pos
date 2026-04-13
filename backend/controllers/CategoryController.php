<?php

class CategoryController extends Controller {

    private Category $categoryModel;

    public function __construct() {
        $this->categoryModel = new Category();
    }

    public function index(): void {
        $filters = [];
        if ($this->getParam('page'))  $filters['page']  = $this->getParam('page');
        if ($this->getParam('limit')) $filters['limit'] = $this->getParam('limit');

        $result = $this->categoryModel->all($filters);

        if (isset($result['pagination'])) {
            Response::success($result['data'], 'success', 200, ['pagination' => $result['pagination']]);
        } else {
            Response::success($result['data']);
        }
    }

    public function store(): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $id = $this->categoryModel->create(['name' => $data['name']]);
        Response::success(['id' => $id, 'name' => $data['name']], 'Category created', 201);
    }

    public function update(string $id): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $this->categoryModel->update((int)$id, ['name' => $data['name']]);
        Response::success(['id' => (int)$id, 'name' => $data['name']], 'Category updated');
    }

    public function destroy(string $id): void {
        $this->categoryModel->delete((int)$id);
        Response::success(null, 'Category deleted');
    }
}
