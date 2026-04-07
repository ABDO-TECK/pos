<?php

class ProductController extends Controller {

    private Product $productModel;

    public function __construct() {
        $this->productModel = new Product();
    }

    public function index(): void {
        $filters = [
            'search'      => $this->getParam('search'),
            'category_id' => $this->getParam('category_id'),
            'low_stock'   => $this->getParam('low_stock'),
        ];
        Response::success($this->productModel->all($filters));
    }

    public function show(string $id): void {
        // Support lookup by barcode via ?barcode=xxx
        $barcode = $this->getParam('barcode');
        if ($id === 'barcode' && $barcode) {
            $product = $this->productModel->findByBarcode($barcode);
        } else {
            $product = $this->productModel->findById((int)$id);
        }

        if (!$product) Response::notFound('Product not found');
        Response::success($product);
    }

    public function store(): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'name'    => 'required',
            'barcode' => 'required',
            'price'   => 'required|numeric',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $id      = $this->productModel->create($data);
        $product = $this->productModel->findById($id);
        Response::success($product, 'Product created', 201);
    }

    public function update(string $id): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'name'    => 'required',
            'barcode' => 'required',
            'price'   => 'required|numeric',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $product = $this->productModel->findById((int)$id);
        if (!$product) Response::notFound('Product not found');

        $this->productModel->update((int)$id, $data);
        Response::success($this->productModel->findById((int)$id), 'Product updated');
    }

    public function destroy(string $id): void {
        $product = $this->productModel->findById((int)$id);
        if (!$product) Response::notFound('Product not found');

        $this->productModel->delete((int)$id);
        Response::success(null, 'Product deleted');
    }
}
