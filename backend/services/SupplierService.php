<?php

class SupplierService {
    
    private Supplier $supplierModel;

    public function __construct() {
        $this->supplierModel = new Supplier();
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

        $this->supplierModel->addLedgerEntry([
            'supplier_id'         => $supplierId,
            'type'                => $type,
            'amount'              => $amount,
            'description'         => $data['description'] ?? 'دفعة نقدية للمورد',
            'purchase_invoice_id' => null,
            'created_by'          => $authUser['id'],
        ]);

        return $this->supplierModel->getLedger($supplierId);
    }

    public function updateLedgerEntry(int $entryId, array $data): array {
        $entry = $this->supplierModel->getLedgerEntry($entryId);
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

        $this->supplierModel->updateLedgerEntry($entryId, [
            'type'        => $type,
            'amount'      => $amount,
            'description' => $data['description'] ?? $entry['description'],
        ]);

        return $this->supplierModel->getLedger((int)$entry['supplier_id']);
    }
}
