<?php

class Supplier {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function all(): array {
        return $this->db->query('SELECT * FROM suppliers ORDER BY name ASC')->fetchAll();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO suppliers (name, phone, email, address) VALUES (:name, :phone, :email, :address)'
        );
        $stmt->execute([
            'name'    => $data['name'],
            'phone'   => $data['phone'] ?? null,
            'email'   => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $stmt = $this->db->prepare(
            'UPDATE suppliers SET name = :name, phone = :phone, email = :email, address = :address WHERE id = :id'
        );
        $stmt->execute([
            'name'    => $data['name'],
            'phone'   => $data['phone'] ?? null,
            'email'   => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'id'      => $id,
        ]);
    }

    public function delete(int $id): void {
        $this->db->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
    }

    // ── Purchase Invoices ───────────────────────────────────────

    /**
     * Create a purchase invoice header and return its ID.
     */
    public function createPurchaseInvoice(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO purchase_invoices (supplier_id, total, items_count, notes)
             VALUES (:supplier_id, :total, :items_count, :notes)'
        );
        $stmt->execute([
            'supplier_id' => $data['supplier_id'],
            'total'       => $data['total'] ?? 0,
            'items_count' => $data['items_count'] ?? 0,
            'notes'       => $data['notes'] ?? null,
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

        $stmt = $this->db->prepare(
            'SELECT pi.*, s.name AS supplier_name
             FROM purchase_invoices pi
             JOIN suppliers s ON s.id = pi.supplier_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY pi.created_at DESC
             LIMIT 200'
        );
        $stmt->execute($params);
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
        $stmt = $this->db->prepare('UPDATE purchase_invoices SET total = :total, items_count = :items_count, notes = :notes WHERE id = :id');
        $stmt->execute(['id' => $id, 'total' => $data['total'], 'items_count' => $data['items_count'], 'notes' => $data['notes']]);
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

        $stmt = $this->db->prepare(
            'SELECT pu.*, s.name AS supplier_name, p.name AS product_name, p.barcode AS product_barcode
             FROM purchases pu
             JOIN suppliers s ON s.id = pu.supplier_id
             JOIN products p ON p.id = pu.product_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY pu.created_at DESC
             LIMIT 500'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
