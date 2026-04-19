<?php

class ProductController extends Controller {

    private ProductService $productService;
    private Product        $productModel;

    public function __construct() {
        $this->productService = new ProductService();
        $this->productModel   = $this->productService->getProductModel();
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
            return Response::success($result['data'], null, 200, ['pagination' => $result['pagination']]);
        } else {
            return Response::success($result);
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
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'name'  => 'required',
            'price' => 'required|numeric',
        ]);
        if ($errors) {
            return Response::error($this->productValidationMessage($errors), 422, $errors);
        }

        $result = $this->productService->createProduct($data);

        if (!$result['ok']) {
            return Response::error($result['error'], $result['code']);
        }
        return Response::success($result['product'], 'Product created', 201);
    }

    public function update(string $id) {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'name'  => 'required',
            'price' => 'required|numeric',
        ]);
        if ($errors) {
            return Response::error($this->productValidationMessage($errors), 422, $errors);
        }

        $result = $this->productService->updateProduct((int) $id, $data);

        if (!$result['ok']) {
            $code = $result['code'] ?? 500;
            return $code === 404
                ? Response::notFound($result['error'])
                : Response::error($result['error'], $code);
        }
        return Response::success($result['product'], 'Product updated');
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
        return Response::success(null, 'Product deleted');
    }
}
