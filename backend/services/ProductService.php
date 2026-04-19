<?php

/**
 * ProductService — منطق الأعمال لإدارة المنتجات.
 *
 * يستخرج Business Logic من ProductController ليسهل إعادة استخدامه
 * من controllers أخرى أو من خطوط أوامر (CLI).
 */
class ProductService
{
    private Product $productModel;

    public function __construct()
    {
        $this->productModel = new Product();
    }

    // ── Create product ──────────────────────────────────────

    /**
     * إنشاء منتج جديد مع معالجة الباركود التلقائي والباركودات الإضافية.
     *
     * @return array ['ok' => true, 'product' => [...]] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function createProduct(array $data): array
    {
        $main = trim($data['barcode'] ?? '');
        $isAutoBarcode = ($main === '');
        if ($isAutoBarcode) {
            $main = 'TEMP-' . uniqid('', true);
        }

        $extras = Product::normalizeAdditionalBarcodes($main, $data['additional_barcodes'] ?? []);
        $extrasToCheck = $extras;
        if (!empty($data['box_barcode'])) {
            $extrasToCheck[] = $data['box_barcode'];
        }
        $this->productModel->assertBarcodesAvailable(null, $main, $extrasToCheck);

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $data['barcode'] = $main;
            $id = $this->productModel->create($data);

            if ($isAutoBarcode) {
                $this->productModel->updateMainBarcode($id, (string) $id);
            }

            $this->productModel->syncAdditionalBarcodes($id, $extras);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            Logger::error('فشل إضافة المنتج', ['error' => $e->getMessage()]);
            if ($e instanceof PDOException && ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate'))) {
                return ['ok' => false, 'error' => 'هذا الباركود مستخدم لمنتج آخر في قاعدة البيانات. اختر باركوداً غير مكرر.', 'code' => 422];
            }
            return ['ok' => false, 'error' => 'Failed to create product', 'code' => 500];
        }

        return ['ok' => true, 'product' => $this->productModel->findById($id)];
    }

    // ── Update product ──────────────────────────────────────

    /**
     * تحديث منتج موجود مع معالجة الباركودات.
     *
     * @return array ['ok' => true, 'product' => [...]] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function updateProduct(int $id, array $data): array
    {
        $product = $this->productModel->findById($id);
        if (!$product) {
            return ['ok' => false, 'error' => 'Product not found', 'code' => 404];
        }

        $main = trim($data['barcode'] ?? '');
        if ($main === '') {
            $main = $product['barcode'];
        }

        $extras = Product::normalizeAdditionalBarcodes($main, $data['additional_barcodes'] ?? []);
        $extrasToCheck = $extras;
        if (!empty($data['box_barcode'])) {
            $extrasToCheck[] = $data['box_barcode'];
        }
        $this->productModel->assertBarcodesAvailable($id, $main, $extrasToCheck);

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $data['barcode'] = $main;
            $this->productModel->update($id, $data);
            $this->productModel->syncAdditionalBarcodes($id, $extras);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            Logger::error('فشل تحديث المنتج', ['error' => $e->getMessage()]);
            if ($e instanceof PDOException && ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate'))) {
                return ['ok' => false, 'error' => 'هذا الباركود مستخدم لمنتج آخر في قاعدة البيانات. اختر باركوداً غير مكرر.', 'code' => 422];
            }
            return ['ok' => false, 'error' => 'Failed to update product', 'code' => 500];
        }

        return ['ok' => true, 'product' => $this->productModel->findById($id)];
    }

    // ── Delete product ──────────────────────────────────────

    /**
     * حذف منتج مع فحص المراجع (فواتير ومشتريات).
     *
     * @return array ['ok' => true] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function deleteProduct(int $id): array
    {
        $product = $this->productModel->findById($id);
        if (!$product) {
            return ['ok' => false, 'error' => 'Product not found', 'code' => 404];
        }

        $refs = $this->productModel->referenceCounts($id);
        if ($refs['invoice_items'] > 0 || $refs['purchases'] > 0) {
            $parts = [];
            if ($refs['invoice_items'] > 0) {
                $parts[] = sprintf('موجود في %d سطر من فواتير البيع', $refs['invoice_items']);
            }
            if ($refs['purchases'] > 0) {
                $parts[] = sprintf('موجود في %d سجل مشتريات', $refs['purchases']);
            }
            $detail = implode('، ', $parts);
            return [
                'ok'    => false,
                'error' => 'لا يمكن حذف المنتج: ' . $detail
                    . '. احذف الفواتير المرتبطة من صفحة المبيعات أو عدّل سجلات المشتريات، أو أبقِ المنتج للحفاظ على السجل المحاسبي.',
                'code'  => 409,
            ];
        }

        try {
            $this->productModel->delete($id);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), '1451')) {
                return ['ok' => false, 'error' => 'لا يمكن حذف المنتج لأنه مرتبط بسجلات أخرى في النظام.', 'code' => 409];
            }
            Logger::error('فشل حذف المنتج', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Failed to delete product', 'code' => 500];
        }

        return ['ok' => true];
    }

    // ── Low stock products ──────────────────────────────────

    /**
     * جلب المنتجات ذات المخزون المنخفض.
     */
    public function getLowStockProducts(): array
    {
        return $this->productModel->getLowStock();
    }

    // ── Accessor ────────────────────────────────────────────

    public function getProductModel(): Product
    {
        return $this->productModel;
    }
}
