<?php

namespace App\Models;

use PDO;

class PurchaseInvoice {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Create a purchase invoice header and return its ID.
     */
    public function createPurchaseInvoice(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO purchase_invoices (supplier_id, total, items_count, notes, driver_name, vehicle_number, delivery_date, delivery_notes)
             VALUES (:supplier_id, :total, :items_count, :notes, :driver_name, :vehicle_number, :delivery_date, :delivery_notes)'
        );
        $stmt->execute([
            'supplier_id'    => $data['supplier_id'],
            'total'          => $data['total'] ?? 0,
            'items_count'    => $data['items_count'] ?? 0,
            'notes'          => $data['notes'] ?? null,
            'driver_name'    => $data['driver_name'] ?? null,
            'vehicle_number' => $data['vehicle_number'] ?? null,
            'delivery_date'  => $data['delivery_date'] ?? null,
            'delivery_notes' => $data['delivery_notes'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Create a purchase line item linked to a purchase invoice.
     */
    public function createPurchase(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO purchases (purchase_invoice_id, supplier_id, product_id, quantity, cost, total, notes)
             VALUES (:purchase_invoice_id, :supplier_id, :product_id, :quantity, :cost, :total, :notes)'
        );
        $stmt->execute([
            'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null,
            'supplier_id'         => $data['supplier_id'],
            'product_id'          => $data['product_id'],
            'quantity'            => $data['quantity'],
            'cost'                => $data['cost'],
            'total'               => $data['quantity'] * $data['cost'],
            'notes'               => $data['notes'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * List purchase invoices (for the purchase log — like sales list).
     */
    public function getPurchaseInvoices(array $filters = []): array {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['supplier_id'])) {
            $where[]               = 'pi.supplier_id = :supplier_id';
            $params['supplier_id'] = $filters['supplier_id'];
        }
        if (!empty($filters['date'])) {
            $where[]          = 'DATE(pi.created_at) = :date';
            $params['date']   = $filters['date'];
        }
        if (!empty($filters['month']) && !empty($filters['year'])) {
            $where[]          = 'MONTH(pi.created_at) = :month AND YEAR(pi.created_at) = :year';
            $params['month']  = $filters['month'];
            $params['year']   = $filters['year'];
        }

        // ── Search (بحث برقم الفاتورة أو اسم منتج) ──
        if (!empty($filters['search'])) {
            $searchTerm = trim($filters['search']);
            if (ctype_digit($searchTerm)) {
                $where[] = 'pi.id = :search_id';
                $params['search_id'] = (int)$searchTerm;
            } else {
                $where[] = 'pi.id IN (SELECT p.purchase_invoice_id FROM purchases p JOIN products pr ON pr.id = p.product_id WHERE pr.name LIKE :search_name)';
                $params['search_name'] = '%' . $searchTerm . '%';
            }
        }

        $hasPagination = false;
        if (isset($filters['page']) && isset($filters['limit'])) {
            $page  = max(1, (int)$filters['page']);
            $limit = max(1, (int)$filters['limit']);
            $offset = ($page - 1) * $limit;
            $hasPagination = true;
            
            $countStmt = $this->db->prepare('SELECT COUNT(*) FROM purchase_invoices pi WHERE ' . implode(' AND ', $where));
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();
            
            $limitStr = "LIMIT :pag_limit OFFSET :pag_offset";
        } else {
            $limitStr = "LIMIT 200";
        }

        $stmt = $this->db->prepare(
            'SELECT pi.*, s.name AS supplier_name
             FROM purchase_invoices pi
             JOIN suppliers s ON s.id = pi.supplier_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY pi.created_at DESC
             ' . $limitStr
        );
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        if ($hasPagination) {
            $stmt->bindValue(':pag_limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':pag_offset', $offset, \PDO::PARAM_INT);
        }
        $stmt->execute();

        if (isset($page, $limit)) {
            return [
                'data' => $stmt->fetchAll(),
                'pagination' => [
                    'total'        => $total,
                    'per_page'     => $limit,
                    'current_page' => $page,
                    'last_page'    => ceil($total / $limit)
                ]
            ];
        }

        return $stmt->fetchAll();
    }

    /**
     * Get a single purchase invoice with its items (like invoice detail).
     */
    public function getPurchaseInvoice(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT pi.*, s.name AS supplier_name
             FROM purchase_invoices pi
             JOIN suppliers s ON s.id = pi.supplier_id
             WHERE pi.id = ?'
        );
        $stmt->execute([$id]);
        $invoice = $stmt->fetch();
        if (!$invoice) return null;

        $itemStmt = $this->db->prepare(
            'SELECT pu.*, p.name AS product_name, p.barcode AS product_barcode
             FROM purchases pu
             JOIN products p ON p.id = pu.product_id
             WHERE pu.purchase_invoice_id = ?
             ORDER BY pu.id ASC'
        );
        $itemStmt->execute([$id]);
        $invoice['items'] = $itemStmt->fetchAll();

        return $invoice;
    }

    /**
     * Delete a purchase invoice and restore stock quantities.
     */
    public function deletePurchaseInvoiceItems(int $id): void {
        $this->db->prepare('DELETE FROM purchases WHERE purchase_invoice_id = ?')->execute([$id]);
    }

    public function updatePurchaseInvoiceTotals(int $id, array $data): void {
        $stmt = $this->db->prepare(
            'UPDATE purchase_invoices 
             SET total = :total, 
                 items_count = :items_count, 
                 notes = :notes, 
                 driver_name = :driver_name, 
                 vehicle_number = :vehicle_number, 
                 delivery_date = :delivery_date, 
                 delivery_notes = :delivery_notes 
             WHERE id = :id'
        );
        $stmt->execute([
            'id'             => $id, 
            'total'          => $data['total'], 
            'items_count'    => $data['items_count'], 
            'notes'          => $data['notes'] ?? null,
            'driver_name'    => $data['driver_name'] ?? null,
            'vehicle_number' => $data['vehicle_number'] ?? null,
            'delivery_date'  => $data['delivery_date'] ?? null,
            'delivery_notes' => $data['delivery_notes'] ?? null,
        ]);
    }

    public function deletePurchaseInvoice(int $id): array {
        // Get items before deleting
        $stmt = $this->db->prepare(
            'SELECT product_id, quantity FROM purchases WHERE purchase_invoice_id = ?'
        );
        $stmt->execute([$id]);
        $items = $stmt->fetchAll();

        // Delete items then header
        $this->db->prepare('DELETE FROM purchases WHERE purchase_invoice_id = ?')->execute([$id]);
        $this->db->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);

        return $items;
    }

    /**
     * Legacy: get flat purchase list (kept for backward compatibility).
     */
    public function getPurchases(array $filters = []): array {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['supplier_id'])) {
            $where[]                 = 'pu.supplier_id = :supplier_id';
            $params['supplier_id']   = $filters['supplier_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[]              = 'DATE(pu.created_at) >= :date_from';
            $params['date_from']  = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[]            = 'DATE(pu.created_at) <= :date_to';
            $params['date_to']  = $filters['date_to'];
        }

        $hasPagination = false;
        if (isset($filters['page']) && isset($filters['limit'])) {
            $page  = max(1, (int)$filters['page']);
            $limit = max(1, (int)$filters['limit']);
            $offset = ($page - 1) * $limit;
            $hasPagination = true;

            $countStmt = $this->db->prepare('SELECT COUNT(*) FROM purchases pu WHERE ' . implode(' AND ', $where));
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $limitStr = "LIMIT :pag_limit OFFSET :pag_offset";
        } else {
            $limitStr = "LIMIT 500";
        }

        $stmt = $this->db->prepare(
            'SELECT pu.*, s.name AS supplier_name, p.name AS product_name, p.barcode AS product_barcode
             FROM purchases pu
             JOIN suppliers s ON s.id = pu.supplier_id
             JOIN products p ON p.id = pu.product_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY pu.created_at DESC
             ' . $limitStr
        );
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        if ($hasPagination) {
            $stmt->bindValue(':pag_limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':pag_offset', $offset, \PDO::PARAM_INT);
        }
        $stmt->execute();

        if ($hasPagination) {
            return [
                'data' => $stmt->fetchAll(),
                'pagination' => [
                    'page'  => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int) ceil($total / $limit)
                ]
            ];
        }

        return $stmt->fetchAll();
    }
}
