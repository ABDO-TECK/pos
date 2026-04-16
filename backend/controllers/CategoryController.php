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
            return Response::success($result['data'], 'success', 200, ['pagination' => $result['pagination']]);
        } else {
            return Response::success($result['data']);
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


