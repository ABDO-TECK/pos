<?php

class InventoryController extends Controller {

    private Product $productModel;

    public function __construct() {
        $this->productModel = new Product();
    }

    public function index(): void {
        $filters = [
            'search'      => $this->getParam('search'),
            'category_id' => $this->getParam('category_id'),
        ];
        $products = $this->productModel->all($filters);
        Response::success($products);
    }

    public function lowStock(): void {
        Response::success($this->productModel->getLowStock());
    }

    public function adjust(string $id): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['quantity' => 'required|numeric']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $product = $this->productModel->findById((int)$id);
        if (!$product) Response::notFound('Product not found');

        $newQty = (int)$data['quantity'];
        if ($newQty < 0) Response::error('Quantity cannot be negative', 400);

        $db   = Database::getInstance();
        $stmt = $db->prepare('UPDATE products SET quantity = ? WHERE id = ?');
        $stmt->execute([$newQty, $id]);

        Response::success($this->productModel->findById((int)$id), 'Inventory adjusted');
    }
}
