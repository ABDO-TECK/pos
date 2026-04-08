<?php

class Product {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function all(array $filters = []): array {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]          = '(p.name LIKE :search OR p.barcode LIKE :search
                OR EXISTS (SELECT 1 FROM product_barcodes pb WHERE pb.product_id = p.id AND pb.barcode LIKE :search))';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['category_id'])) {
            $where[]              = 'p.category_id = :category_id';
            $params['category_id'] = $filters['category_id'];
        }
        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $where[] = 'p.quantity <= p.low_stock_threshold';
        }

        $sql = 'SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY p.name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['additional_barcodes'] = $this->getAdditionalBarcodesList((int) $row['id']);
        }
        unset($row);
        return $rows;
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare(
            'SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product) {
            return null;
        }
        $product['additional_barcodes'] = $this->getAdditionalBarcodesList($id);
        return $product;
    }

    public function findByBarcode(string $barcode): ?array {
        $stmt = $this->db->prepare(
            'SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.barcode = ?
                OR p.id IN (SELECT product_id FROM product_barcodes WHERE barcode = ?)
             LIMIT 1'
        );
        $stmt->execute([$barcode, $barcode]);
        $product = $stmt->fetch();
        if (!$product) {
            return null;
        }
        $product['additional_barcodes'] = $this->getAdditionalBarcodesList((int) $product['id']);
        return $product;
    }

    /** @return list<string> */
    public function getAdditionalBarcodesList(int $productId): array {
        $stmt = $this->db->prepare('SELECT barcode FROM product_barcodes WHERE product_id = ? ORDER BY id ASC');
        $stmt->execute([$productId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'barcode');
    }

    public function findOwnerProductIdByBarcode(string $barcode): ?int {
        $stmt = $this->db->prepare('SELECT id FROM products WHERE barcode = ? LIMIT 1');
        $stmt->execute([$barcode]);
        $id = $stmt->fetchColumn();
        if ($id !== false && $id !== null) {
            return (int) $id;
        }
        $stmt = $this->db->prepare('SELECT product_id FROM product_barcodes WHERE barcode = ? LIMIT 1');
        $stmt->execute([$barcode]);
        $pid = $stmt->fetchColumn();
        return ($pid !== false && $pid !== null) ? (int) $pid : null;
    }

    /**
     * @param list<string> $extras
     * @throws void calls Response::error on conflict
     */
    public function assertBarcodesAvailable(?int $excludeProductId, string $main, array $extras): void {
        $main = trim($main);
        $all  = array_merge([$main], $extras);
        foreach ($all as $code) {
            if ($code === '') {
                continue;
            }
            $owner = $this->findOwnerProductIdByBarcode($code);
            if ($owner !== null && ($excludeProductId === null || (int) $owner !== (int) $excludeProductId)) {
                Response::error('الباركود "' . $code . '" مستخدم لمنتج آخر', 422);
            }
        }
    }

    /** Replace additional barcodes (not the main `products.barcode`). */
    public function syncAdditionalBarcodes(int $productId, array $extras): void {
        $this->db->prepare('DELETE FROM product_barcodes WHERE product_id = ?')->execute([$productId]);
        $ins = $this->db->prepare('INSERT INTO product_barcodes (product_id, barcode) VALUES (?, ?)');
        foreach ($extras as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }
            $ins->execute([$productId, $code]);
        }
    }

    public static function normalizeAdditionalBarcodes(string $main, $raw): array {
        $main = trim($main);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [$main => true];
        foreach ($raw as $e) {
            $t = trim((string) $e);
            if ($t === '' || isset($seen[$t])) {
                continue;
            }
            $seen[$t] = true;
            $out[]    = $t;
        }
        return $out;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare(
            'INSERT INTO products (name, barcode, price, cost, quantity, low_stock_threshold, category_id, units_per_box)
             VALUES (:name, :barcode, :price, :cost, :quantity, :low_stock_threshold, :category_id, :units_per_box)'
        );
        $stmt->execute([
            'name'                => $data['name'],
            'barcode'             => $data['barcode'],
            'price'               => $data['price'],
            'cost'                => $data['cost'] ?? 0,
            'quantity'            => $data['quantity'] ?? 0,
            'low_stock_threshold' => $data['low_stock_threshold'] ?? LOW_STOCK_THRESHOLD,
            'category_id'         => $data['category_id'] ?? null,
            'units_per_box'       => max(1, (int)($data['units_per_box'] ?? 1)),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** تحديث الباركود الأساسي فقط (يُستخدم لتعيين رقم ID كباركود تلقائي) */
    public function updateMainBarcode(int $id, string $barcode): void {
        $this->db->prepare('UPDATE products SET barcode = ? WHERE id = ?')->execute([$barcode, $id]);
    }

    public function update(int $id, array $data): void {
        $stmt = $this->db->prepare(
            'UPDATE products SET
                name = :name,
                barcode = :barcode,
                price = :price,
                cost = :cost,
                quantity = :quantity,
                low_stock_threshold = :low_stock_threshold,
                category_id = :category_id,
                units_per_box = :units_per_box
             WHERE id = :id'
        );
        $stmt->execute([
            'name'                => $data['name'],
            'barcode'             => $data['barcode'],
            'price'               => $data['price'],
            'cost'                => $data['cost'] ?? 0,
            'quantity'            => $data['quantity'] ?? 0,
            'low_stock_threshold' => $data['low_stock_threshold'] ?? LOW_STOCK_THRESHOLD,
            'category_id'         => $data['category_id'] ?? null,
            'units_per_box'       => max(1, (int)($data['units_per_box'] ?? 1)),
            'id'                  => $id,
        ]);
    }

    /**
     * أسطر تمنع حذف المنتج بسبب مفاتيح أجنبية (فواتير، مشتريات).
     *
     * @return array{invoice_items: int, purchases: int}
     */
    public function referenceCounts(int $id): array {
        $stmt = $this->db->prepare(
            'SELECT
                (SELECT COUNT(*) FROM invoice_items WHERE product_id = ?) AS invoice_items,
                (SELECT COUNT(*) FROM purchases WHERE product_id = ?) AS purchases'
        );
        $stmt->execute([$id, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'invoice_items' => (int) ($row['invoice_items'] ?? 0),
            'purchases'     => (int) ($row['purchases'] ?? 0),
        ];
    }

    public function delete(int $id): void {
        $this->db->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    }

    public function decrementQuantity(int $id, int $qty): void {
        $this->db->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')->execute([$qty, $id]);
    }

    public function incrementQuantity(int $id, int $qty): void {
        $this->db->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')->execute([$qty, $id]);
    }

    public function getLowStock(): array {
        $rows = $this->db->query(
            'SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.quantity <= p.low_stock_threshold
             ORDER BY p.quantity ASC'
        )->fetchAll();
        foreach ($rows as &$row) {
            $row['additional_barcodes'] = $this->getAdditionalBarcodesList((int) $row['id']);
        }
        unset($row);
        return $rows;
    }
}
