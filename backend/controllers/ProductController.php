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
            'page'        => $this->getParam('page'),
            'limit'       => $this->getParam('limit'),
        ];

        $result = $this->productModel->all($filters);

        // إذا كانت النتيجة paginated (تحتوي data + pagination)
        if (isset($result['pagination'])) {
            Response::success($result['data'], null, 200, ['pagination' => $result['pagination']]);
        } else {
            Response::success($result);
        }
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
            'name'  => 'required',
            'price' => 'required|numeric',
        ]);
        if ($errors) {
            Response::error($this->productValidationMessage($errors), 422, $errors);
        }

        // توليد باركود تلقائي إذا كان فارغاً
        $main = trim($data['barcode'] ?? '');
        $isAutoBarcode = ($main === '');
        if ($isAutoBarcode) {
            // باركود مؤقت فريد ريثما نحصل على الـ ID
            $main = 'TEMP-' . uniqid('', true);
        }

        $extras = Product::normalizeAdditionalBarcodes($main, $data['additional_barcodes'] ?? []);
        $this->productModel->assertBarcodesAvailable(null, $main, $extras);

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $data['barcode'] = $main;
            $id              = $this->productModel->create($data);

            // استبدال الباركود المؤقت برقم ID (1، 2، 3 ...)
            if ($isAutoBarcode) {
                $this->productModel->updateMainBarcode($id, (string)$id);
            }

            $this->productModel->syncAdditionalBarcodes($id, $extras);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log($e->getMessage());
            if ($e instanceof PDOException && ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate'))) {
                Response::error('هذا الباركود مستخدم لمنتج آخر في قاعدة البيانات. اختر باركوداً غير مكرر.', 422);
            }
            Response::serverError('Failed to create product');
        }

        Response::success($this->productModel->findById($id), 'Product created', 201);
    }

    public function update(string $id): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'name'  => 'required',
            'price' => 'required|numeric',
        ]);
        if ($errors) {
            Response::error($this->productValidationMessage($errors), 422, $errors);
        }

        $pid = (int) $id;
        $product = $this->productModel->findById($pid);
        if (!$product) {
            Response::notFound('Product not found');
        }

        // إذا كان الباركود فارغاً احتفظ بالباركود القديم
        $main = trim($data['barcode'] ?? '');
        if ($main === '') {
            $main = $product['barcode'];
        }

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
            if ($e instanceof PDOException && ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate'))) {
                Response::error('هذا الباركود مستخدم لمنتج آخر في قاعدة البيانات. اختر باركوداً غير مكرر.', 422);
            }
            Response::serverError('Failed to update product');
        }

        Response::success($this->productModel->findById($pid), 'Product updated');
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

    public function destroy(string $id): void {
        $pid     = (int) $id;
        $product = $this->productModel->findById($pid);
        if (!$product) {
            Response::notFound('Product not found');
        }

        $refs = $this->productModel->referenceCounts($pid);
        if ($refs['invoice_items'] > 0 || $refs['purchases'] > 0) {
            $parts = [];
            if ($refs['invoice_items'] > 0) {
                $parts[] = sprintf('موجود في %d سطر من فواتير البيع', $refs['invoice_items']);
            }
            if ($refs['purchases'] > 0) {
                $parts[] = sprintf('موجود في %d سجل مشتريات', $refs['purchases']);
            }
            $detail = implode('، ', $parts);
            Response::error(
                'لا يمكن حذف المنتج: ' . $detail
                . '. احذف الفواتير المرتبطة من صفحة المبيعات أو عدّل سجلات المشتريات، أو أبقِ المنتج للحفاظ على السجل المحاسبي.',
                409
            );
        }

        try {
            $this->productModel->delete($pid);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), '1451')) {
                Response::error(
                    'لا يمكن حذف المنتج لأنه مرتبط بسجلات أخرى في النظام.',
                    409
                );
            }
            error_log($e->getMessage());
            Response::serverError('Failed to delete product');
        }

        Response::success(null, 'Product deleted');
    }
}
