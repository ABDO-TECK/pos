<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\ValidationException;
use App\Helpers\Response;
use App\Helpers\AuditLog;
use App\Models\Product;
use App\Requests\ProductRequest;
use App\Services\ProductService;


class ProductController extends Controller {

    private ProductService $productService;
    private Product        $productModel;

    public function __construct(ProductService $productService) {
        $this->productService = $productService;
        $this->productModel   = $productService->getProductModel();
    }

    public function index() {
        $filters = [
            'search'      => $this->getParam('search'),
            'category_id' => $this->getParam('category_id'),
            'low_stock'   => $this->getParam('low_stock'),
            'page'        => $this->getParam('page'),
            'limit'       => $this->getParam('limit'),
        ];

        $result = $this->productModel->all($filters);

        // إذا كانت النتيجة paginated (تحتوي data + pagination)
        if (isset($result['pagination'])) {
            return Response::cacheable($result['data'], 120, null, ['pagination' => $result['pagination']]);
        } else {
            return Response::cacheable($result, 120);
        }
    }

    public function show(string $id) {
        // Support lookup by barcode via ?barcode=xxx
        $barcode = $this->getParam('barcode');
        if ($id === 'barcode' && $barcode) {
            $product = $this->productModel->findByBarcode($barcode);
        } else {
            $product = $this->productModel->findById((int) $id);
        }

        if (!$product) {
            return Response::notFound('Product not found');
        }
        return Response::success($product);
    }

    public function store() {
        try {
            $request = new ProductRequest($this->getBody());
            $data = $request->validated();
            
            $result = $this->productService->createProduct($data);

            if (!$result['ok']) {
                return Response::error($result['error'], $result['code']);
            }
            return Response::success($result['product'], 'Product created', 201);
        } catch (ValidationException $e) {
            return Response::error($this->productValidationMessage($e->getErrors()), 422, $e->getErrors());
        }
    }

    public function update(string $id) {
        try {
            $request = new ProductRequest($this->getBody());
            $data = $request->validated();

            $result = $this->productService->updateProduct((int) $id, $data);

            if (!$result['ok']) {
                $code = $result['code'] ?? 500;
                return $code === 404
                    ? Response::notFound($result['error'])
                    : Response::error($result['error'], $code);
            }
            AuditLog::log('update_product', 'product', (int)$id, null, $data);
            return Response::success($result['product'], 'Product updated');
        } catch (ValidationException $e) {
            return Response::error($this->productValidationMessage($e->getErrors()), 422, $e->getErrors());
        }
    }

    /** رسالة عربية بدل "Validation failed" + أسماء حقول إنجليزية */
    private function productValidationMessage(array $errors): string {
        $parts = [];
        foreach ($errors as $field => $msgs) {
            $list = is_array($msgs) ? $msgs : [$msgs];
            foreach ($list as $m) {
                $msg = (string)$m;
                $parts[] = match ($field) {
                    'name'  => str_contains($msg, 'required') ? 'اسم المنتج مطلوب.' : ('اسم المنتج: ' . $msg),
                    'price' => str_contains($msg, 'required')
                        ? 'سعر البيع مطلوب.'
                        : (str_contains($msg, 'numeric') ? 'سعر البيع يجب أن يكون رقماً.' : ('سعر البيع: ' . $msg)),
                    default => $field . ': ' . $msg,
                };
            }
        }
        return $parts !== [] ? implode(' ', $parts) : 'تحقق من الحقول المطلوبة.';
    }

    public function destroy(string $id) {
        $result = $this->productService->deleteProduct((int) $id);

        if (!$result['ok']) {
            $code = $result['code'] ?? 500;
            return $code === 404
                ? Response::notFound($result['error'])
                : Response::error($result['error'], $code);
        }
        AuditLog::log('delete_product', 'product', (int)$id);
        return Response::success(null, 'Product deleted');
    }
}
