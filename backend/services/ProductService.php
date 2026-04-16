<?php

class ProductService {

    private Product $productModel;

    public function __construct() {
        $this->productModel = new Product();
    }

    public function getLowStockProducts(): array {
        return $this->productModel->getLowStock();
    }
}
