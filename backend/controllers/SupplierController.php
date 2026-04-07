<?php

class SupplierController extends Controller {

    private Supplier $supplierModel;
    private Product  $productModel;

    public function __construct() {
        $this->supplierModel = new Supplier();
        $this->productModel  = new Product();
    }

    public function index(): void {
        Response::success($this->supplierModel->all());
    }

    public function show(string $id): void {
        $supplier = $this->supplierModel->findById((int)$id);
        if (!$supplier) Response::notFound('Supplier not found');
        Response::success($supplier);
    }

    public function store(): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $id       = $this->supplierModel->create($data);
        $supplier = $this->supplierModel->findById($id);
        Response::success($supplier, 'Supplier created', 201);
    }

    public function update(string $id): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $supplier = $this->supplierModel->findById((int)$id);
        if (!$supplier) Response::notFound('Supplier not found');

        $this->supplierModel->update((int)$id, $data);
        Response::success($this->supplierModel->findById((int)$id), 'Supplier updated');
    }

    public function destroy(string $id): void {
        $supplier = $this->supplierModel->findById((int)$id);
        if (!$supplier) Response::notFound('Supplier not found');
        $this->supplierModel->delete((int)$id);
        Response::success(null, 'Supplier deleted');
    }

    public function purchase(): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'supplier_id' => 'required',
            'product_id'  => 'required',
            'quantity'    => 'required|numeric',
            'cost'        => 'required|numeric',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $product = $this->productModel->findById((int)$data['product_id']);
        if (!$product) Response::notFound('Product not found');

        $supplier = $this->supplierModel->findById((int)$data['supplier_id']);
        if (!$supplier) Response::notFound('Supplier not found');

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $this->supplierModel->createPurchase($data);
            $this->productModel->incrementQuantity((int)$data['product_id'], (int)$data['quantity']);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            Response::serverError('Failed to record purchase');
        }

        Response::success([
            'product' => $this->productModel->findById((int)$data['product_id']),
        ], 'Purchase recorded and stock updated', 201);
    }

    public function purchases(): void {
        $filters = ['supplier_id' => $this->getParam('supplier_id')];
        Response::success($this->supplierModel->getPurchases($filters));
    }

    public function purchaseBulk(): void {
        $data = $this->getBody();

        if (empty($data['supplier_id'])) {
            Response::error('supplier_id is required', 422);
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            Response::error('items array is required', 422);
        }

        $supplier = $this->supplierModel->findById((int)$data['supplier_id']);
        if (!$supplier) Response::notFound('Supplier not found');

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            foreach ($data['items'] as $item) {
                if (empty($item['product_id']) || empty($item['quantity']) || !isset($item['cost'])) {
                    $db->rollBack();
                    Response::error('Each item needs product_id, quantity, and cost', 422);
                }

                $product = $this->productModel->findById((int)$item['product_id']);
                if (!$product) {
                    $db->rollBack();
                    Response::notFound("Product ID {$item['product_id']} not found");
                }

                $this->supplierModel->createPurchase([
                    'supplier_id' => (int)$data['supplier_id'],
                    'product_id'  => (int)$item['product_id'],
                    'quantity'    => (int)$item['quantity'],
                    'cost'        => (float)$item['cost'],
                ]);
                $this->productModel->incrementQuantity((int)$item['product_id'], (int)$item['quantity']);

                // Update product cost price if provided
                if (!empty($item['update_cost'])) {
                    $db->prepare('UPDATE products SET cost = ? WHERE id = ?')
                       ->execute([(float)$item['cost'], (int)$item['product_id']]);
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log($e->getMessage());
            Response::serverError('Failed to record bulk purchase');
        }

        Response::success(['items_processed' => count($data['items'])], 'Bulk purchase recorded', 201);
    }
}
