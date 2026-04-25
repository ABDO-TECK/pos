<?php

class CategoryController extends Controller {

    private Category $categoryModel;

    public function __construct() {
        $this->categoryModel = new Category();
    }

    public function index() {
        $filters = [];
        if ($this->getParam('page'))  $filters['page']  = $this->getParam('page');
        if ($this->getParam('limit')) $filters['limit'] = $this->getParam('limit');

        $result = $this->categoryModel->all($filters);

        if (isset($result['pagination'])) {
            $data = ['data' => $result['data'], 'pagination' => $result['pagination']];
            return Response::cacheable($data, 300);
        } else {
            return Response::cacheable($result['data'] ?? $result, 300);
        }
    }

    public function store() {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) return Response::error('Validation failed', 422, $errors);

        $id = $this->categoryModel->create(['name' => $data['name']]);
        return Response::success(['id' => $id, 'name' => $data['name']], 'Category created', 201);
    }

    public function update(string $id) {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) return Response::error('Validation failed', 422, $errors);

        $this->categoryModel->update((int)$id, ['name' => $data['name']]);
        return Response::success(['id' => (int)$id, 'name' => $data['name']], 'Category updated');
    }

    public function destroy(string $id) {
        $this->categoryModel->delete((int)$id);
        return Response::success(null, 'Category deleted');
    }
}


