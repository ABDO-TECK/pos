<?php

namespace App\Services;

use App\Config\Database;
use App\Helpers\Logger;
use App\Models\Product;
use App\Models\PriceHistory;
use App\Repositories\ProductRepository;
use App\Contracts\ProductServiceInterface;
use Exception;
use PDOException;
use Throwable;

/**
 * ProductService — منطق الأعمال لإدارة المنتجات.
 *
 * يستخرج Business Logic من ProductController ليسهل إعادة استخدامه
 * من controllers أخرى أو من خطوط أوامر (CLI).
 */
class ProductService implements ProductServiceInterface
{
    private ProductRepository $productRepo;
    private PriceHistory $priceHistory;

    public function __construct(ProductRepository $productRepo, PriceHistory $priceHistory)
    {
        $this->productRepo = $productRepo;
        $this->priceHistory = $priceHistory;
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
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $this->productRepo->assertBarcodesAvailable(null, $main, $extrasToCheck);
            $data['barcode'] = $main;
            $id = $this->productRepo->create($data);

            if ($isAutoBarcode) {
                $this->productRepo->updateMainBarcode($id, (string) $id);
            }

            $this->productRepo->syncAdditionalBarcodes($id, $extras);

            // حفظ المقاسات المرفقة
            if (!empty($data['sizes']) && is_array($data['sizes'])) {
                foreach ($data['sizes'] as $size) {
                    $sizeBarcode = trim($size['barcode'] ?? '');
                    $isSizeAutoBarcode = ($sizeBarcode === '');
                    if ($isSizeAutoBarcode) {
                        $sizeBarcode = 'TEMP-SZ-' . uniqid('', true);
                    }

                    $this->productRepo->assertBarcodesAvailable(null, $sizeBarcode, []);

                    $sizeId = $this->productRepo->create([
                        'name'                => $data['name'] . ' - ' . $size['size_name'],
                        'barcode'             => $sizeBarcode,
                        'box_barcode'         => null,
                        'price'               => $size['price'],
                        'cost'                => $size['cost'] ?? 0,
                        'quantity'            => $size['quantity'] ?? 0,
                        'low_stock_threshold' => $size['low_stock_threshold'] ?? ($data['low_stock_threshold'] ?? LOW_STOCK_THRESHOLD),
                        'category_id'         => $data['category_id'] ?? null,
                        'parent_product_id'   => $id,
                        'size_name'           => $size['size_name'],
                        'units_per_box'       => 1,
                        'sell_by_weight'      => 0,
                        'unit_type'           => $data['unit_type'] ?? 'piece',
                    ]);

                    if ($isSizeAutoBarcode) {
                        $this->productRepo->updateMainBarcode($sizeId, (string) $sizeId);
                    }
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            Logger::error('فشل إضافة المنتج', ['error' => $e->getMessage()]);
            if ($e instanceof PDOException && ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate'))) {
                return ['ok' => false, 'error' => 'هذا الباركود مستخدم لمنتج آخر في قاعدة البيانات. اختر باركوداً غير مكرر.', 'code' => 422];
            }
            if ($e instanceof Exception && str_starts_with($e->getMessage(), 'الباركود')) {
                return ['ok' => false, 'error' => $e->getMessage(), 'code' => 422];
            }
            return ['ok' => false, 'error' => 'فشل إنشاء المنتج: ' . $e->getMessage(), 'code' => 500];
        }

        return ['ok' => true, 'product' => $this->productRepo->findById($id)];
    }

    // ── Update product ──────────────────────────────────────

    /**
     * تحديث منتج موجود مع معالجة الباركودات.
     *
     * @return array ['ok' => true, 'product' => [...]] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function updateProduct(int $id, array $data): array
    {
        $product = $this->productRepo->findById($id);
        if (!$product) {
            return ['ok' => false, 'error' => 'المنتج غير موجود', 'code' => 404];
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
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $this->productRepo->assertBarcodesAvailable($id, $main, $extrasToCheck);
            $data['barcode'] = $main;
            $this->productRepo->update($id, $data);

            // تسجيل تغيير السعر (إن وُجد)
            $this->priceHistory->record($id, $product, $data, null);

            $this->productRepo->syncAdditionalBarcodes($id, $extras);

            // مزامنة المقاسات
            $existingSizeIds = [];
            $stmt = $db->prepare('SELECT id FROM products WHERE parent_product_id = ? AND deleted_at IS NULL');
            $stmt->execute([$id]);
            $existingSizeIds = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

            $keepSizeIds = [];

            if (isset($data['sizes']) && is_array($data['sizes'])) {
                foreach ($data['sizes'] as $size) {
                    $sizeBarcode = trim($size['barcode'] ?? '');
                    $isSizeAutoBarcode = ($sizeBarcode === '');

                    $sizeId = !empty($size['id']) ? (int)$size['id'] : null;

                    if ($sizeId) {
                        if ($isSizeAutoBarcode) {
                            $sizeBarcode = (string)$sizeId;
                        }
                        $this->productRepo->assertBarcodesAvailable($sizeId, $sizeBarcode, []);

                        $this->productRepo->update($sizeId, [
                            'name'                => $data['name'] . ' - ' . $size['size_name'],
                            'barcode'             => $sizeBarcode,
                            'box_barcode'         => null,
                            'price'               => $size['price'],
                            'cost'                => $size['cost'] ?? 0,
                            'quantity'            => $size['quantity'] ?? 0,
                            'low_stock_threshold' => $size['low_stock_threshold'] ?? ($data['low_stock_threshold'] ?? LOW_STOCK_THRESHOLD),
                            'category_id'         => $data['category_id'] ?? null,
                            'parent_product_id'   => $id,
                            'size_name'           => $size['size_name'],
                            'units_per_box'       => 1,
                            'sell_by_weight'      => 0,
                            'unit_type'           => $data['unit_type'] ?? 'piece',
                        ]);
                        $keepSizeIds[] = $sizeId;
                    } else {
                        if ($isSizeAutoBarcode) {
                            $sizeBarcode = 'TEMP-SZ-' . uniqid('', true);
                        }
                        $this->productRepo->assertBarcodesAvailable(null, $sizeBarcode, []);

                        $newSizeId = $this->productRepo->create([
                            'name'                => $data['name'] . ' - ' . $size['size_name'],
                            'barcode'             => $sizeBarcode,
                            'box_barcode'         => null,
                            'price'               => $size['price'],
                            'cost'                => $size['cost'] ?? 0,
                            'quantity'            => $size['quantity'] ?? 0,
                            'low_stock_threshold' => $size['low_stock_threshold'] ?? ($data['low_stock_threshold'] ?? LOW_STOCK_THRESHOLD),
                            'category_id'         => $data['category_id'] ?? null,
                            'parent_product_id'   => $id,
                            'size_name'           => $size['size_name'],
                            'units_per_box'       => 1,
                            'sell_by_weight'      => 0,
                            'unit_type'           => $data['unit_type'] ?? 'piece',
                        ]);

                        if ($isSizeAutoBarcode) {
                            $this->productRepo->updateMainBarcode($newSizeId, (string)$newSizeId);
                        }
                        $keepSizeIds[] = $newSizeId;
                    }
                }
            }

            // حذف المقاسات الملغاة
            $toDeleteIds = array_diff($existingSizeIds, $keepSizeIds);
            foreach ($toDeleteIds as $delId) {
                $this->productRepo->delete($delId);
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            Logger::error('فشل تحديث المنتج', ['error' => $e->getMessage()]);
            if ($e instanceof PDOException && ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate'))) {
                return ['ok' => false, 'error' => 'هذا الباركود مستخدم لمنتج آخر في قاعدة البيانات. اختر باركوداً غير مكرر.', 'code' => 422];
            }
            if ($e instanceof Exception && str_starts_with($e->getMessage(), 'الباركود')) {
                return ['ok' => false, 'error' => $e->getMessage(), 'code' => 422];
            }
            return ['ok' => false, 'error' => 'فشل تحديث المنتج: ' . $e->getMessage(), 'code' => 500];
        }

        return ['ok' => true, 'product' => $this->productRepo->findById($id)];
    }

    // ── Delete product ──────────────────────────────────────

    /**
     * حذف منتج مع فحص المراجع (فواتير ومشتريات).
     *
     * @return array ['ok' => true] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function deleteProduct(int $id): array
    {
        $product = $this->productRepo->findById($id);
        if (!$product) {
            return ['ok' => false, 'error' => 'المنتج غير موجود', 'code' => 404];
        }

        $refs = $this->productRepo->referenceCounts($id);
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

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $this->productRepo->delete($id);
            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), '1451')) {
                return ['ok' => false, 'error' => 'لا يمكن حذف المنتج لأنه مرتبط بسجلات أخرى في النظام.', 'code' => 409];
            }
            Logger::error('فشل حذف المنتج', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'فشل حذف المنتج', 'code' => 500];
        } catch (Throwable $e) {
            $db->rollBack();
            Logger::error('فشل حذف المنتج', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'فشل حذف المنتج', 'code' => 500];
        }

        return ['ok' => true];
    }

    // ── Low stock products ──────────────────────────────────

    /**
     * جلب المنتجات ذات المخزون المنخفض.
     */
    public function getLowStockProducts(): array
    {
        return $this->productRepo->getLowStock();
    }

    // ── Accessor ────────────────────────────────────────────

    public function getProductModel(): Product
    {
        return $this->productRepo->getModel();
    }
}
