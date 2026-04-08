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
            $product = $this->productModel->findById((int) $id);
        }

        if (!$product) {
            Response::notFound('Product not found');
        }
        Response::success($product);
    }

    public function store(): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'name'    => 'required',
            'barcode' => 'required',
            'price'   => 'required|numeric',
        ]);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $main   = trim($data['barcode']);
        $extras = Product::normalizeAdditionalBarcodes($main, $data['additional_barcodes'] ?? []);
        $this->productModel->assertBarcodesAvailable(null, $main, $extras);

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $data['barcode'] = $main;
            $id              = $this->productModel->create($data);
            $this->productModel->syncAdditionalBarcodes($id, $extras);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log($e->getMessage());
            Response::serverError('Failed to create product');
        }

        Response::success($this->productModel->findById($id), 'Product created', 201);
    }

    public function update(string $id): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'name'    => 'required',
            'barcode' => 'required',
            'price'   => 'required|numeric',
        ]);
        if ($errors) {
            Response::error('Validation failed', 422, $errors);
        }

        $pid = (int) $id;
        $product = $this->productModel->findById($pid);
        if (!$product) {
            Response::notFound('Product not found');
        }

        $main   = trim($data['barcode']);
        $extras = Product::normalizeAdditionalBarcodes($main, $data['additional_barcodes'] ?? []);
        $this->productModel->assertBarcodesAvailable($pid, $main, $extras);

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $data['barcode'] = $main;
            $this->productModel->update($pid, $data);
            $this->productModel->syncAdditionalBarcodes($pid, $extras);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log($e->getMessage());
            Response::serverError('Failed to update product');
        }

        Response::success($this->productModel->findById($pid), 'Product updated');
    }

    public function destroy(string $id): void {
        $product = $this->productModel->findById((int) $id);
        if (!$product) {
            Response::notFound('Product not found');
        }

        $this->productModel->delete((int) $id);
        Response::success(null, 'Product deleted');
    }
}
