<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Contracts\SupplierServiceInterface;
use Exception;

class SupplierService implements SupplierServiceInterface {
    
    private Supplier $supplierModel;
    private SupplierLedger $ledgerModel;

    public function __construct(Supplier $supplierModel, SupplierLedger $ledgerModel) {
        $this->supplierModel = $supplierModel;
        $this->ledgerModel = $ledgerModel;
    }

    public function addPayment(int $supplierId, array $data, array $authUser): array {
        $supplier = $this->supplierModel->findById($supplierId);
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
            $this->ledgerModel->addLedgerEntry([
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

        return $this->ledgerModel->getLedger($supplierId);
    }

    public function updateLedgerEntry(int $entryId, array $data): array {
        $entry = $this->ledgerModel->getLedgerEntry($entryId);
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
            $this->ledgerModel->updateLedgerEntry($entryId, [
                'type'        => $type,
                'amount'      => $amount,
                'description' => $data['description'] ?? $entry['description'],
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw new Exception('فشل تحديث القيد', 500);
        }

        return $this->ledgerModel->getLedger((int)$entry['supplier_id']);
    }
}
