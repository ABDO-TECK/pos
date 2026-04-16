<?php

class InventoryController extends Controller {

    private Product $productModel;

    public function __construct() {
        $this->productModel = new Product();
    }

    public function index() {
        $filters = [
            'search'      => $this->getParam('search'),
            'category_id' => $this->getParam('category_id'),
        ];
        $products = $this->productModel->all($filters);
        return Response::success($products);
    }

    public function lowStock() {
        return Response::success($this->productModel->getLowStock());
    }

    public function adjust(string $id) {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['quantity' => 'required|numeric']);
        if ($errors) return Response::error('Validation failed', 422, $errors);

        $product = $this->productModel->findById((int)$id);
        if (!$product) return Response::notFound('Product not found');

        $newQty = (int)$data['quantity'];
        if ($newQty < 0) return Response::error('Quantity cannot be negative', 400);

        $db   = Database::getInstance();
        $stmt = $db->prepare('UPDATE products SET quantity = ? WHERE id = ?');
        $stmt->execute([$newQty, $id]);

        return Response::success($this->productModel->findById((int)$id), 'Inventory adjusted');
    }
}


