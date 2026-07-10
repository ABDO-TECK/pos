<?php

namespace App\Services;

use App\Contracts\SupplierServiceInterface;
use Exception;

class SupplierService implements SupplierServiceInterface {
    
    private \App\Repositories\SupplierLedgerRepository $ledgerRepo;
    private \App\Repositories\SupplierRepository $supplierRepo;
    private \App\Repositories\ProductRepository $productRepo;

    public function __construct(
        \App\Repositories\SupplierLedgerRepository $ledgerRepo,
        \App\Repositories\SupplierRepository $supplierRepo,
        \App\Repositories\ProductRepository $productRepo
    ) {
        $this->ledgerRepo = $ledgerRepo;
        $this->supplierRepo = $supplierRepo;
        $this->productRepo = $productRepo;
    }

    public function addPayment(int $supplierId, array $data, array $authUser): array {
        $supplier = $this->supplierRepo->findById($supplierId);
        if (!$supplier) {
            throw new Exception('المورد غير موجود', 404);
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new Exception('يجب أن يكون المبلغ أكبر من صفر', 422);
        }

        $type = $data['type'] === 'debit' ? 'debit' : 'credit';

        $db = \App\Config\Database::getInstance();
        $db->beginTransaction();
        try {
            $this->ledgerRepo->create([
                'supplier_id'         => $supplierId,
                'type'                => $type,
                'amount'              => $amount,
                'description'         => $data['description'] ?? 'دفعة نقدية للمورد',
                'purchase_invoice_id' => null,
                'created_by'          => $authUser['id'],
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw new Exception('فشل تسجيل الدفعة', 500);
        }

        return $this->ledgerRepo->getLedger($supplierId);
    }

    public function updateLedgerEntry(int $entryId, array $data): array {
        $entry = $this->ledgerRepo->findById($entryId);
        if (!$entry) {
            throw new Exception('القيد غير موجود', 404);
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new Exception('يجب أن يكون المبلغ أكبر من صفر', 422);
        }

        $type = $data['type'] ?? $entry['type'];
        if (!in_array($type, ['debit', 'credit'])) {
            throw new Exception('نوع القيد غير صحيح', 422);
        }

        $db = \App\Config\Database::getInstance();
        $db->beginTransaction();
        try {
            $this->ledgerRepo->update($entryId, [
                'type'        => $type,
                'amount'      => $amount,
                'description' => $data['description'] ?? $entry['description'],
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw new Exception('فشل تحديث القيد', 500);
        }

        return $this->ledgerRepo->getLedger((int)$entry['supplier_id']);
    }

    public function recordSinglePurchase(array $data): array {
        $product = $this->productRepo->findById((int)$data['product_id']);
        if (!$product) {
            throw new Exception('Product not found', 404);
        }

        $supplier = $this->supplierRepo->findById((int)$data['supplier_id']);
        if (!$supplier) {
            throw new Exception('Supplier not found', 404);
        }

        $db = \App\Config\Database::getInstance();
        $db->beginTransaction();
        try {
            $this->supplierRepo->createPurchase($data);
            $this->productRepo->getModel()->incrementQuantity((int)$data['product_id'], (int)$data['quantity']);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw new Exception('Failed to record purchase', 500);
        }

        return ['product' => $this->productRepo->findById((int)$data['product_id'])];
    }

    public function deleteLedgerEntry(int $entryId): array {
        $entry = $this->ledgerRepo->findById($entryId);
        if (!$entry) {
            throw new Exception('القيد غير موجود', 404);
        }

        $db = \App\Config\Database::getInstance();
        $db->beginTransaction();
        try {
            $this->ledgerRepo->delete($entryId);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw new Exception('فشل حذف القيد', 500);
        }

        return $this->ledgerRepo->getLedger((int)$entry['supplier_id']);
    }
}
