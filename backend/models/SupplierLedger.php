<?php

namespace App\Models;

use PDO;

class SupplierLedger {
    private PDO $db;
    private Supplier $supplierModel;

    public function __construct(PDO $db, Supplier $supplierModel) {
        $this->db = $db;
        $this->supplierModel = $supplierModel;
    }

    /**
     * كشف حساب المورد — مع الرصيد المتراكم لكل سطر
     * @return array{supplier: ?array, entries: list<array>, balance: float}
     */
    public function getLedger(int $supplierId): array {
        $supplier = $this->supplierModel->findById($supplierId);
        if (!$supplier) return ['supplier' => null, 'entries' => [], 'balance' => 0];

        $stmt = $this->db->prepare(
            'SELECT sl.*,
                u.name AS created_by_name,
                pi.id AS pinv_id
             FROM supplier_ledger sl
             LEFT JOIN users u ON u.id = sl.created_by
             LEFT JOIN purchase_invoices pi ON pi.id = sl.purchase_invoice_id
             WHERE sl.supplier_id = ?
             ORDER BY sl.created_at ASC, sl.id ASC'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll();

        $entries    = [];
        $runningBal = 0;

        // سطر الرصيد المبدئي إذا كان موجوداً
        $initBal = (float)($supplier['initial_balance'] ?? 0);
        if ($initBal != 0) {
            $runningBal += $initBal;
            $entries[] = [
                'id'          => null,
                'date'        => $supplier['created_at'],
                'description' => 'رصيد مبدئي',
                'debit'       => $initBal > 0 ? $initBal : 0,
                'credit'      => $initBal < 0 ? abs($initBal) : 0,
                'balance'     => round($runningBal, 2),
                'type'        => 'initial',
            ];
        }

        foreach ($rows as $row) {
            $debit  = $row['type'] === 'debit'  ? (float)$row['amount'] : 0;
            $credit = $row['type'] === 'credit' ? (float)$row['amount'] : 0;
            $runningBal += $debit - $credit;

            $entries[] = [
                'id'                  => (int)$row['id'],
                'date'                => $row['created_at'],
                'description'         => $row['description'],
                'debit'               => $debit,
                'credit'              => $credit,
                'balance'             => round($runningBal, 2),
                'type'                => $row['type'],
                'purchase_invoice_id' => $row['purchase_invoice_id'],
            ];
        }

        return [
            'supplier' => $supplier,
            'entries'  => $entries,
            'balance'  => round($runningBal, 2),
        ];
    }

    /** إضافة قيد في كشف حساب المورد */
    public function addLedgerEntry(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO supplier_ledger (supplier_id, type, amount, description, purchase_invoice_id, created_by)
             VALUES (:supplier_id, :type, :amount, :description, :purchase_invoice_id, :created_by)'
        );
        $stmt->execute([
            'supplier_id'         => $data['supplier_id'],
            'type'                => $data['type'],
            'amount'              => (float)$data['amount'],
            'description'         => $data['description'] ?? null,
            'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null,
            'created_by'          => $data['created_by'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** تعديل قيد في كشف الحساب */
    public function updateLedgerEntry(int $entryId, array $data): void {
        $stmt = $this->db->prepare(
            'UPDATE supplier_ledger SET
                type = :type,
                amount = :amount,
                description = :description
             WHERE id = :id'
        );
        $stmt->execute([
            'type'        => $data['type'],
            'amount'      => (float)$data['amount'],
            'description' => $data['description'] ?? null,
            'id'          => $entryId,
        ]);
    }

    /** الحصول على قيد واحد */
    public function getLedgerEntry(int $entryId): ?array {
        $stmt = $this->db->prepare('SELECT * FROM supplier_ledger WHERE id = ?');
        $stmt->execute([$entryId]);
        return $stmt->fetch() ?: null;
    }

    /** حذف قيد من كشف الحساب */
    public function deleteLedgerEntry(int $entryId): void {
        $stmt = $this->db->prepare('DELETE FROM supplier_ledger WHERE id = ?');
        $stmt->execute([$entryId]);
    }
}
