<?php

class Invoice {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * جلب الفواتير مع دعم pagination اختياري.
     */
    public function all(array $filters = []): array {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['date'])) {
            $where[]        = 'DATE(i.created_at) = :date';
            $params['date'] = $filters['date'];
        }
        if (!empty($filters['month']) && !empty($filters['year'])) {
            $where[]         = 'MONTH(i.created_at) = :month AND YEAR(i.created_at) = :year';
            $params['month'] = $filters['month'];
            $params['year']  = $filters['year'];
        }

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $where[]          = 'i.status = :status';
            $params['status'] = $filters['status'];
        } elseif (!isset($filters['status'])) {
            // Default backward compatibility: only fetch completed
            $where[]          = 'i.status = :status';
            $params['status'] = 'completed';
        }

        $whereClause = implode(' AND ', $where);

        // ── Pagination (اختياري) ──
        $page  = isset($filters['page'])  ? max(1, (int) $filters['page'])  : null;
        $limit = isset($filters['limit']) ? max(1, min(500, (int) $filters['limit'])) : null;

        if ($page !== null && $limit !== null) {
            $countSql = "SELECT COUNT(*) FROM invoices i WHERE $whereClause";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $offset = ($page - 1) * $limit;
            $sql = "SELECT i.*, u.name AS cashier_name, c.name AS customer_name
                    FROM invoices i
                    JOIN users u ON u.id = i.user_id
                    LEFT JOIN customers c ON c.id = i.customer_id
                    WHERE $whereClause
                    ORDER BY i.created_at DESC
                    LIMIT $limit OFFSET $offset";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return [
                'data' => $stmt->fetchAll(),
                'pagination' => [
                    'page'  => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int) ceil($total / $limit),
                ],
            ];
        }

        // ── بدون pagination ──
        $sql = "SELECT i.*, u.name AS cashier_name, c.name AS customer_name
                FROM invoices i
                JOIN users u ON u.id = i.user_id
                LEFT JOIN customers c ON c.id = i.customer_id
                WHERE $whereClause
                ORDER BY i.created_at DESC
                LIMIT 200";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT i.*, u.name AS cashier_name
             FROM invoices i
             JOIN users u ON u.id = i.user_id
             WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        $invoice = $stmt->fetch();
        if (!$invoice) return null;

        $invoice['items'] = $this->getItems($id);
        return $invoice;
    }

    public function getItems(int $invoiceId): array {
        $stmt = $this->db->prepare(
            'SELECT ii.*, p.name AS product_name, p.barcode
             FROM invoice_items ii
             JOIN products p ON p.id = ii.product_id
             WHERE ii.invoice_id = ?'
        );
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll();
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO invoices (user_id, customer_id, subtotal, discount, tax, total, payment_method, amount_paid, change_due, amount_due, status)
             VALUES (:user_id, :customer_id, :subtotal, :discount, :tax, :total, :payment_method, :amount_paid, :change_due, :amount_due, :status)'
        );
        $stmt->execute([
            'user_id'        => $data['user_id'],
            'customer_id'    => $data['customer_id'] ?? null,
            'subtotal'       => $data['subtotal'],
            'discount'       => $data['discount'] ?? 0,
            'tax'            => $data['tax'] ?? 0,
            'total'          => $data['total'],
            'payment_method' => $data['payment_method'] ?? 'cash',
            'amount_paid'    => $data['amount_paid'] ?? $data['total'],
            'change_due'     => $data['change_due'] ?? 0,
            'amount_due'     => $data['amount_due'] ?? 0,
            'status'         => $data['status'] ?? 'completed',
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function addItem(int $invoiceId, array $item): void {
        $unitCost = isset($item['unit_cost']) ? (float)$item['unit_cost'] : 0.0;
        $stmt     = $this->db->prepare(
            'INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal)
             VALUES (:invoice_id, :product_id, :quantity, :price, :unit_cost, :subtotal)'
        );
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'product_id' => $item['product_id'],
            'quantity'   => $item['quantity'],
            'price'      => $item['price'],
            'unit_cost'  => $unitCost,
            'subtotal'   => $item['quantity'] * $item['price'],
        ]);
    }

    public function deleteItemsByInvoiceId(int $invoiceId): void {
        $this->db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);
    }

    public function updateTotals(int $id, array $data): void {
        $stmt = $this->db->prepare(
            'UPDATE invoices SET
                customer_id = :customer_id,
                subtotal = :subtotal,
                discount = :discount,
                tax = :tax,
                total = :total,
                payment_method = :payment_method,
                amount_paid = :amount_paid,
                change_due = :change_due,
                amount_due = :amount_due,
                status = :status
             WHERE id = :id'
        );
        $stmt->execute([
            'id'               => $id,
            'customer_id'      => $data['customer_id'] ?? null,
            'subtotal'         => $data['subtotal'],
            'discount'         => $data['discount'] ?? 0,
            'tax'              => $data['tax'] ?? 0,
            'total'            => $data['total'],
            'payment_method'   => $data['payment_method'],
            'amount_paid'      => $data['amount_paid'],
            'change_due'       => $data['change_due'] ?? 0,
            'amount_due'       => $data['amount_due'] ?? 0,
            'status'           => $data['status'] ?? 'completed',
        ]);
    }

    public function updateStatus(int $id, string $status): void {
        $stmt = $this->db->prepare('UPDATE invoices SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);

        if ($status === 'completed') {
            $this->db->prepare("UPDATE customer_ledger SET description = REPLACE(description, ' 🕒 (محجوزة - لم تُسلم)', '') WHERE invoice_id = ?")->execute([$id]);
        }
    }

    public function delete(int $id): void {
        $this->db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
    }

    public function getDailySummary(string $date): array {
        $stmt = $this->db->prepare(
            'SELECT
                COUNT(*) AS total_invoices,
                SUM(total) AS total_revenue,
                SUM(discount) AS total_discount,
                SUM(tax) AS total_tax,
                SUM(total - tax) AS net_revenue
             FROM invoices
             WHERE DATE(created_at) = ? AND status = "completed"'
        );
        $stmt->execute([$date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $row['total_profit'] = $this->getTotalProfitForDate($date);
        $row['total_cost']   = $this->getTotalCostForDate($date);
        return $row;
    }

    /** إجمالي تكلفة البضاعة المباعة: unit_cost × الكمية — فواتير مكتملة */
    public function getTotalCostForDate(string $date): float {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(ii.unit_cost * ii.quantity), 0)
             FROM invoice_items ii
             INNER JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"
             WHERE DATE(inv.created_at) = ?'
        );
        $stmt->execute([$date]);
        return (float)$stmt->fetchColumn();
    }

    public function getTotalCostForMonth(int $month, int $year): float {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(ii.unit_cost * ii.quantity), 0)
             FROM invoice_items ii
             INNER JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"
             WHERE MONTH(inv.created_at) = ? AND YEAR(inv.created_at) = ?'
        );
        $stmt->execute([$month, $year]);
        return (float)$stmt->fetchColumn();
    }

    /** صافي الربح: (سعر البيع − تكلفة لحظة البيع المخزنة في البند) × الكمية */
    public function getTotalProfitForDate(string $date): float {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM((ii.price - ii.unit_cost) * ii.quantity), 0)
             FROM invoice_items ii
             INNER JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"
             WHERE DATE(inv.created_at) = ?'
        );
        $stmt->execute([$date]);
        return (float)$stmt->fetchColumn();
    }

    public function getTotalProfitForMonth(int $month, int $year): float {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM((ii.price - ii.unit_cost) * ii.quantity), 0)
             FROM invoice_items ii
             INNER JOIN invoices inv ON inv.id = ii.invoice_id AND inv.status = "completed"
             WHERE MONTH(inv.created_at) = ? AND YEAR(inv.created_at) = ?'
        );
        $stmt->execute([$month, $year]);
        return (float)$stmt->fetchColumn();
    }

    public function getMonthlySummary(int $month, int $year): array {
        $stmt = $this->db->prepare(
            'SELECT
                DATE(created_at) AS date,
                COUNT(*) AS total_invoices,
                SUM(total) AS total_revenue
             FROM invoices
             WHERE MONTH(created_at) = ? AND YEAR(created_at) = ? AND status = "completed"
             GROUP BY DATE(created_at)
             ORDER BY date ASC'
        );
        $stmt->execute([$month, $year]);
        return $stmt->fetchAll();
    }

    public function getTopProducts(int $limit = 10, ?string $fromDate = null, ?string $toDate = null): array {
        $where  = ['1=1'];
        $params = [];
        if ($fromDate) {
            $where[]           = 'i.created_at >= :from';
            $params['from']    = $fromDate . ' 00:00:00';
        }
        if ($toDate) {
            $where[]         = 'i.created_at <= :to';
            $params['to']    = $toDate . ' 23:59:59';
        }

        $stmt = $this->db->prepare(
            'SELECT p.id, p.name, p.barcode,
                    SUM(ii.quantity) AS total_sold,
                    SUM(ii.subtotal) AS total_revenue,
                    SUM((ii.price - ii.unit_cost) * ii.quantity) AS total_profit
             FROM invoice_items ii
             JOIN invoices i ON i.id = ii.invoice_id AND i.status = "completed"
             JOIN products p ON p.id = ii.product_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY p.id, p.name, p.barcode
             ORDER BY total_sold DESC
             LIMIT ' . (int)$limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
