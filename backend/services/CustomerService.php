<?php

class CustomerService {
    
    private Customer $customerModel;

    public function __construct() {
        $this->customerModel = new Customer();
    }

    public function createCustomer(array $data): int {
        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $id = $this->customerModel->create($data);
            $db->commit();
            return $id;
        } catch (Throwable $e) {
            $db->rollBack();
            Logger::error('فشل إضافة العميل', ['error' => $e->getMessage()]);
            throw new Exception('فشل في إضافة العميل');
        }
    }

    public function addPayment(int $customerId, array $data, array $authUser): array {
        $customer = $this->customerModel->findById($customerId);
        if (!$customer) {
            throw new Exception('العميل غير موجود', 404);
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            throw new Exception('يجب أن يكون المبلغ أكبر من صفر', 422);
        }

        $type = $data['type'] === 'debit' ? 'debit' : 'credit';

        $this->customerModel->addLedgerEntry([
            'customer_id' => $customerId,
            'type'        => $type,
            'amount'      => $amount,
            'description' => $data['description'] ?? 'دفعة نقدية',
            'invoice_id'  => null,
            'created_by'  => $authUser['id'],
        ]);

        return $this->customerModel->getLedger($customerId);
    }

    public function updateLedgerEntry(int $entryId, array $data): array {
        $entry = $this->customerModel->getLedgerEntry($entryId);
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

        $this->customerModel->updateLedgerEntry($entryId, [
            'type'        => $type,
            'amount'      => $amount,
            'description' => $data['description'] ?? $entry['description'],
        ]);

        return $this->customerModel->getLedger((int)$entry['customer_id']);
    }
}
