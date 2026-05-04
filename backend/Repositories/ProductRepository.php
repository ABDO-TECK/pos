<?php

namespace App\Repositories;

use App\Models\Product;

/**
 * ProductRepository — طبقة وسيطة بين ProductService و Product Model.
 *
 * ⚠️  ملاحظة معمارية:
 * هذا هو الـ Repository الوحيد في المشروع حالياً. بقية الـ Models
 * (Invoice, Supplier, Customer, ...) تُستخدم مباشرة من الـ Controllers/Services
 * بدون Repository وسيط.
 *
 * تم الإبقاء على هذا الكلاس لأن ProductService يعتمد عليه
 * ولأنه يُسهّل عمل Mock أثناء الاختبارات.
 *
 * عند التوسع مستقبلاً: إما أنشئ Repository لكل Model أو أزل هذا النمط كلياً.
 */
class ProductRepository
{
    private Product $model;

    public function __construct(Product $model)
    {
        $this->model = $model;
    }

    public function all(array $filters = []): array
    {
        return $this->model->all($filters);
    }

    public function findById(int $id): ?array
    {
        return $this->model->findById($id) ?: null;
    }

    public function findByBarcode(string $barcode): ?array
    {
        return $this->model->findByBarcode($barcode) ?: null;
    }

    public function create(array $data): int
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): void
    {
        $this->model->update($id, $data);
    }

    public function delete(int $id): void
    {
        $this->model->delete($id);
    }

    public function assertBarcodesAvailable(?int $excludeId, string $main, array $extras): void
    {
        $this->model->assertBarcodesAvailable($excludeId, $main, $extras);
    }

    public function updateMainBarcode(int $id, string $barcode): void
    {
        $this->model->updateMainBarcode($id, $barcode);
    }

    public function syncAdditionalBarcodes(int $id, array $barcodes): void
    {
        $this->model->syncAdditionalBarcodes($id, $barcodes);
    }



    public function referenceCounts(int $id): array
    {
        return $this->model->referenceCounts($id);
    }

    public function getLowStock(): array
    {
        return $this->model->getLowStock();
    }

    /** يُتيح الوصول للـ Model الأصلي عند الحاجة (backward compatibility) */
    public function getModel(): Product
    {
        return $this->model;
    }
}
