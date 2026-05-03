<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Controller;
use App\Helpers\Response;
use App\Helpers\AuditLog;
use App\Models\Product;


class InventoryController extends Controller {

    private Product $productModel;

    public function __construct(Product $productModel) {
        $this->productModel = $productModel;
    }

    public function index() {
        $filters = [
            'search'      => $this->getParam('search'),
            'category_id' => $this->getParam('category_id'),
        ];
        if ($this->getParam('page'))  $filters['page']  = $this->getParam('page');
        if ($this->getParam('limit')) $filters['limit'] = $this->getParam('limit');

        $products = $this->productModel->all($filters);
        return Response::cacheable($products, 60);
    }

    public function lowStock() {
        $filters = [];
        if ($this->getParam('page'))  $filters['page']  = $this->getParam('page');
        if ($this->getParam('limit')) $filters['limit'] = $this->getParam('limit');

        return Response::cacheable($this->productModel->getLowStock($filters), 60);
    }

    public function adjust(string $id) {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['quantity' => 'required|numeric']);
        if ($errors) return Response::error('فشل التحقق من صحة البيانات', 422, $errors);

        $product = $this->productModel->findById((int)$id);
        if (!$product) return Response::notFound('Product not found');

        $newQty = (int)$data['quantity'];
        if ($newQty < 0) return Response::error('الكمية لا يمكن أن تكون سالبة', 400);

        $db   = Database::getInstance();
        $stmt = $db->prepare('UPDATE products SET quantity = ? WHERE id = ?');
        $stmt->execute([$newQty, $id]);

        AuditLog::log('adjust_inventory', 'product', (int)$id, null, $data);

        return Response::success($this->productModel->findById((int)$id), 'Inventory adjusted');
    }
}


