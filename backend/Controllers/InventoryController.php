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
        $filters += $this->getPaginationParams();

        $products = $this->productModel->all($filters);
        // المخزون يتغير بعد كل عملية شراء — لا نضع كاش هنا
        return Response::success($products);
    }

    public function lowStock() {
        $filters = [];
        $filters += $this->getPaginationParams();

        // المخزون المنخفض يتغير باستمرار — استجابة طازجة دائماً
        return Response::success($this->productModel->getLowStock($filters));
    }

    public function adjust(string $id) {
        $id = $this->resolveId($id);
        $request = new \App\Requests\InventoryAdjustRequest($this->getBody());
        $data = $request->validated();

        $product = $this->productModel->findById($id);
        if (!$product) return Response::notFound('Product not found');

        $newQty = (int)$data['quantity'];
        if ($newQty < 0) return Response::error('الكمية لا يمكن أن تكون سالبة', 400);

        $db   = Database::getInstance();
        $stmt = $db->prepare('UPDATE products SET quantity = ? WHERE id = ? AND branch_id = ?');
        $stmt->execute([$newQty, $id, $this->authService->branchId()]);

        AuditLog::log($this->authService->id(), 'adjust_inventory', 'product', $id, null, $data);

        return Response::success($this->productModel->findById($id), 'Inventory adjusted');
    }
}


