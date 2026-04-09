<?php

class Customer {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /** جميع العملاء مع رصيدهم الحالي */
    public function all(): array {
        $rows = $this->db->query(
            'SELECT c.*,
                COALESCE(SUM(CASE WHEN cl.type = "debit"  THEN cl.amount ELSE 0 END), 0) AS total_debit,
                COALESCE(SUM(CASE WHEN cl.type = "credit" THEN cl.amount ELSE 0 END), 0) AS total_credit
             FROM customers c
             LEFT JOIN customer_ledger cl ON cl.customer_id = c.id
             GROUP BY c.id
             ORDER BY c.name ASC'
        )->fetchAll();

        foreach ($rows as &$r) {
            $r['balance'] = round(
                (float)$r['initial_balance'] + (float)$r['total_debit'] - (float)$r['total_credit'],
                2
            );
        }
        unset($r);
        return $rows;
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT c.*,
                COALESCE(SUM(CASE WHEN cl.type = "debit"  THEN cl.amount ELSE 0 END), 0) AS total_debit,
                COALESCE(SUM(CASE WHEN cl.type = "credit" THEN cl.amount ELSE 0 END), 0) AS total_credit
             FROM customers c
             LEFT JOIN customer_ledger cl ON cl.customer_id = c.id
             WHERE c.id = ?
             GROUP BY c.id'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['balance'] = round(
            (float)$row['initial_balance'] + (float)$row['total_debit'] - (float)$row['total_credit'],
            2
        );
        return $row;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO customers (name, phone, address, initial_balance)
             VALUES (:name, :phone, :address, :initial_balance)'
        );
        $stmt->execute([
            'name'            => $data['name'],
            'phone'           => $data['phone'] ?? null,
            'address'         => $data['address'] ?? null,
            'initial_balance' => (float)($data['initial_balance'] ?? 0),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $stmt = $this->db->prepare(
            'UPDATE customers SET
                name = :name,
                phone = :phone,
                address = :address,
                initial_balance = :initial_balance
             WHERE id = :id'
        );
        $stmt->execute([
            'name'            => $data['name'],
            'phone'           => $data['phone'] ?? null,
            'address'         => $data['address'] ?? null,
            'initial_balance' => (float)($data['initial_balance'] ?? 0),
            'id'              => $id,
        ]);
    }

    public function delete(int $id): void {
        $this->db->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    }

    /**
     * كشف حساب العميل — مع الرصيد المتراكم لكل سطر
     * @return array{entries: list<array>, balance: float}
     */
    public function getLedger(int $customerId): array {
        $customer = $this->findById($customerId);
        if (!$customer) return ['entries' => [], 'balance' => 0];

        // جلب القيود من customer_ledger مع بيانات الفاتورة إن وجدت
        $stmt = $this->db->prepare(
            'SELECT cl.*,
                u.name AS created_by_name,
                i.id AS inv_id
             FROM customer_ledger cl
             LEFT JOIN users u ON u.id = cl.created_by
             LEFT JOIN invoices i ON i.id = cl.invoice_id
             WHERE cl.customer_id = ?
             ORDER BY cl.created_at ASC, cl.id ASC'
        );
        $stmt->execute([$customerId]);
        $rows = $stmt->fetchAll();

        $entries    = [];
        $runningBal = 0;

        // سطر الرصيد المبدئي إذا كان موجوداً
        if ((float)$customer['initial_balance'] > 0) {
            $runningBal += (float)$customer['initial_balance'];
            $entries[] = [
                'id'          => null,
                'date'        => $customer['created_at'],
                'description' => 'رصيد مبدئي',
                'debit'       => (float)$customer['initial_balance'],
                'credit'      => 0,
                'balance'     => round($runningBal, 2),
                'type'        => 'initial',
            ];
        }

        foreach ($rows as $row) {
            $debit  = $row['type'] === 'debit'  ? (float)$row['amount'] : 0;
            $credit = $row['type'] === 'credit' ? (float)$row['amount'] : 0;
            $runningBal += $debit - $credit;

            $entries[] = [
                'id'          => (int)$row['id'],
                'date'        => $row['created_at'],
                'description' => $row['description'],
                'debit'       => $debit,
                'credit'      => $credit,
                'balance'     => round($runningBal, 2),
                'type'        => $row['type'],
                'invoice_id'  => $row['invoice_id'],
            ];
        }

        return [
            'customer' => $customer,
            'entries'  => $entries,
            'balance'  => round($runningBal, 2),
        ];
    }

    /** إضافة قيد في كشف الحساب */
    public function addLedgerEntry(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO customer_ledger (customer_id, type, amount, description, invoice_id, created_by)
             VALUES (:customer_id, :type, :amount, :description, :invoice_id, :created_by)'
        );
        $stmt->execute([
            'customer_id' => $data['customer_id'],
            'type'        => $data['type'],
            'amount'      => (float)$data['amount'],
            'description' => $data['description'] ?? null,
            'invoice_id'  => $data['invoice_id'] ?? null,
            'created_by'  => $data['created_by'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }
}
