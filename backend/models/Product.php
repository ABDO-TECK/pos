<?php

namespace App\Models;

use App\Config\Database;
use App\Helpers\Response;
use App\Models\Traits\ProductBarcodeTrait;
use Exception;
use PDO;

class Product {
    use ProductBarcodeTrait;
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * جلب المنتجات مع دعم pagination اختياري.
     * إذا لم يُرسل page، تُرجع كل النتائج (backward-compatible).
     * إذا أُرسل page، تُرجع مصفوفة { data, pagination }.
     */
    public function all(array $filters = []): array {
        $branchId = \App\Services\AuthService::getGlobalBranchId();
        $where  = ['p.deleted_at IS NULL', 'p.branch_id = :branch_id'];
        $params = ['branch_id' => $branchId];

        if (!empty($filters['search'])) {
            $searchTerm = trim($filters['search']);
            // Barcode lookups: use exact match + prefix (index-friendly)
            // Name lookups: use LIKE with leading wildcard (unavoidable for substring)
            $where[] = '(
                p.name LIKE :search_wild
                OR p.barcode = :search_exact
                OR p.barcode LIKE :search_prefix
                OR p.box_barcode = :search_exact2
                OR p.box_barcode LIKE :search_prefix2
                OR EXISTS (SELECT 1 FROM product_barcodes pb WHERE pb.product_id = p.id AND (pb.barcode = :search_exact3 OR pb.barcode LIKE :search_prefix3))
            )';
            $params['search_wild']    = '%' . $searchTerm . '%';
            $params['search_exact']   = $searchTerm;
            $params['search_prefix']  = $searchTerm . '%';
            $params['search_exact2']  = $searchTerm;
            $params['search_prefix2'] = $searchTerm . '%';
            $params['search_exact3']  = $searchTerm;
            $params['search_prefix3'] = $searchTerm . '%';
        }
        if (!empty($filters['category_id'])) {
            $where[]              = 'p.category_id = :category_id';
            $params['category_id'] = $filters['category_id'];
        }
        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $where[] = 'p.quantity <= p.low_stock_threshold';
        }
        
        // تصفية المنتجات الأبناء (المقاسات) إلا إذا تم تحديد البحث عنها
        if (isset($filters['parent_product_id'])) {
            if ($filters['parent_product_id'] !== 'all') {
                $where[] = 'p.parent_product_id = :parent_product_id';
                $params['parent_product_id'] = $filters['parent_product_id'];
            }
        } else {
            $where[] = 'p.parent_product_id IS NULL';
        }

        $whereClause = implode(' AND ', $where);

        // ── Pagination (اختياري) ──
        $page  = isset($filters['page'])  ? max(1, (int) $filters['page'])  : null;
        $limit = isset($filters['limit']) ? max(1, min(500, (int) $filters['limit'])) : null;

        if ($page !== null && $limit !== null) {
            // عدّ النتائج أولاً
            $countSql = "SELECT COUNT(*) FROM products p WHERE $whereClause";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $offset = ($page - 1) * $limit;
            $sql = "SELECT p.*, c.name AS category_name
                    FROM products p
                    LEFT JOIN categories c ON c.id = p.category_id
                    WHERE $whereClause
                    ORDER BY p.name ASC
                    LIMIT :pag_limit OFFSET :pag_offset";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':pag_limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':pag_offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            $this->attachAdditionalBarcodes($rows);

            // جلب المقاسات لكل منتج رئيسي (batch query — no N+1)
            $this->attachSizes($rows);

            return [
                'data' => $rows,
                'pagination' => [
                    'page'  => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int) ceil($total / $limit),
                ],
            ];
        }

        // ── بدون pagination — إرجاع الكل (backward-compatible) ──
        $sql = "SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                WHERE $whereClause
                ORDER BY p.name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $this->attachAdditionalBarcodes($rows);

        // جلب المقاسات لكل منتج رئيسي (batch query — no N+1)
        $this->attachSizes($rows);

        return $rows;
    }

    public function findById(int $id): ?array {
        $qb = new \App\Core\QueryBuilder($this->db);
        $product = $qb->table('products p')
            ->select('p.*', 'c.name AS category_name')
            ->leftJoin('categories c', 'c.id', '=', 'p.category_id')
            ->where('p.id', '=', $id)
            ->first();

        if (!$product) {
            return null;
        }
        $product['additional_barcodes'] = $this->getAdditionalBarcodesList($id);

        // جلب المقاسات المرتبطة
        $stmt = $this->db->prepare('SELECT * FROM products WHERE parent_product_id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        $sizes = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $this->attachAdditionalBarcodes($sizes);
        $product['sizes'] = $sizes;

        return $product;
    }

    /**
     * Batch-fetch multiple products by their IDs in a single query.
     * Eliminates N+1 when enriching cart items or restoring invoice quantities.
     *
     * @param  int[]  $ids
     * @return array<int, array>  Keyed by product ID for O(1) lookup
     */
    public function findByIds(array $ids): array {
        if (empty($ids)) return [];
        $ids = array_map('intval', $ids);
        $ids = array_unique($ids);
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $this->db->prepare(
            "SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id IN ($placeholders)"
        );
        $stmt->execute(array_values($ids));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Key by product ID for O(1) lookup
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = $row;
        }
        return $result;
    }

    /**
     * Fetch only the current quantities for the given product IDs.
     * Lightweight alternative to findByIds() when only quantity is needed.
     *
     * @param  int[]  $ids
     * @return array<int, int>  product_id => quantity
     */
    public function getQuantitiesByIds(array $ids): array {
        if (empty($ids)) return [];
        $ids = array_map('intval', $ids);
        $ids = array_unique($ids);
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $this->db->prepare(
            "SELECT id, quantity FROM products WHERE id IN ($placeholders)"
        );
        $stmt->execute(array_values($ids));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = (int) $row['quantity'];
        }
        return $result;
    }

    /**
     * Batch-load sizes for multiple parent products in a single query.
     * Eliminates the N+1 problem where each product triggers a separate SELECT.
     */
    private function attachSizes(array &$rows): void
    {
        if (empty($rows)) return;

        $parentIds = array_column($rows, 'id');
        if (empty($parentIds)) return;

        $placeholders = str_repeat('?,', count($parentIds) - 1) . '?';
        $stmt = $this->db->prepare(
            "SELECT * FROM products WHERE parent_product_id IN ($placeholders) AND deleted_at IS NULL"
        );
        $stmt->execute($parentIds);
        $allSizes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Attach additional barcodes to all size rows in one batch
        $this->attachAdditionalBarcodes($allSizes);

        // Group sizes by parent_product_id
        $grouped = [];
        foreach ($allSizes as $size) {
            $grouped[$size['parent_product_id']][] = $size;
        }

        // Assign grouped sizes to each parent row
        foreach ($rows as &$row) {
            $row['sizes'] = $grouped[$row['id']] ?? [];
        }
        unset($row);
    }

    public function findByBarcode(string $barcode): ?array {
        $stmt = $this->db->prepare(
            'SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.barcode = ?
                OR p.box_barcode = ?
                OR p.id IN (SELECT product_id FROM product_barcodes WHERE barcode = ?)
             LIMIT 1'
        );
        $stmt->execute([$barcode, $barcode, $barcode]);
        $product = $stmt->fetch();
        if (!$product) {
            return null;
        }
        $product['additional_barcodes'] = $this->getAdditionalBarcodesList((int) $product['id']);
        return $product;
    }



    public function create(array $data): int {
        $unitType = $data['unit_type'] ?? 'piece';
        $sellByWeight = ($unitType === 'weight') ? 1 : 0;

        $stmt = $this->db->prepare(
            'INSERT INTO products (name, barcode, box_barcode, price, cost, quantity, low_stock_threshold, category_id, parent_product_id, size_name, units_per_box, sell_by_weight, unit_type)
             VALUES (:name, :barcode, :box_barcode, :price, :cost, :quantity, :low_stock_threshold, :category_id, :parent_product_id, :size_name, :units_per_box, :sell_by_weight, :unit_type)'
        );
        $stmt->execute([
            'name'                => $data['name'],
            'barcode'             => $data['barcode'],
            'box_barcode'         => !empty($data['box_barcode']) ? $data['box_barcode'] : null,
            'price'               => $data['price'],
            'cost'                => $data['cost'] ?? 0,
            'quantity'            => $data['quantity'] ?? 0,
            'low_stock_threshold' => $data['low_stock_threshold'] ?? LOW_STOCK_THRESHOLD,
            'category_id'         => $data['category_id'] ?? null,
            'parent_product_id'   => !empty($data['parent_product_id']) ? (int)$data['parent_product_id'] : null,
            'size_name'           => !empty($data['size_name']) ? $data['size_name'] : null,
            'units_per_box'       => max(1, (int)($data['units_per_box'] ?? 1)),
            'sell_by_weight'      => $sellByWeight,
            'unit_type'           => $unitType,
        ]);
        return (int) $this->db->lastInsertId();
    }



    public function update(int $id, array $data): void {
        $unitType = $data['unit_type'] ?? 'piece';
        $sellByWeight = ($unitType === 'weight') ? 1 : 0;

        $stmt = $this->db->prepare(
            'UPDATE products SET
                name = :name,
                barcode = :barcode,
                box_barcode = :box_barcode,
                price = :price,
                cost = :cost,
                quantity = :quantity,
                low_stock_threshold = :low_stock_threshold,
                category_id = :category_id,
                parent_product_id = :parent_product_id,
                size_name = :size_name,
                units_per_box = :units_per_box,
                sell_by_weight = :sell_by_weight,
                unit_type = :unit_type
             WHERE id = :id'
        );
        $stmt->execute([
            'name'                => $data['name'],
            'barcode'             => $data['barcode'],
            'box_barcode'         => !empty($data['box_barcode']) ? $data['box_barcode'] : null,
            'price'               => $data['price'],
            'cost'                => $data['cost'] ?? 0,
            'quantity'            => $data['quantity'] ?? 0,
            'low_stock_threshold' => $data['low_stock_threshold'] ?? LOW_STOCK_THRESHOLD,
            'category_id'         => $data['category_id'] ?? null,
            'parent_product_id'   => !empty($data['parent_product_id']) ? (int)$data['parent_product_id'] : null,
            'size_name'           => !empty($data['size_name']) ? $data['size_name'] : null,
            'units_per_box'       => max(1, (int)($data['units_per_box'] ?? 1)),
            'sell_by_weight'      => $sellByWeight,
            'unit_type'           => $unitType,
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
        // تفريغ الباركودات عند الحذف الناعم حتى يمكن إعادة استخدامها لمنتجات جديدة.
        // نحفظ الباركود القديم في الاسم كمرجع.
        $stmt = $this->db->prepare('SELECT barcode FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $oldBarcode = $stmt->fetchColumn();

        $deletedBarcode = '__deleted_' . $id . '_' . time();
        $this->db->prepare(
            'UPDATE products SET deleted_at = NOW(), barcode = ?, box_barcode = NULL WHERE id = ?'
        )->execute([$deletedBarcode, $id]);

        // حذف الباركودات الإضافية من جدول product_barcodes
        $this->db->prepare('DELETE FROM product_barcodes WHERE product_id = ?')->execute([$id]);
    }

    public function decrementQuantity(int $id, float $qty): void {
        $this->db->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')->execute([$qty, $id]);
    }

    public function incrementQuantity(int $id, float $qty): void {
        $this->db->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')->execute([$qty, $id]);
    }

    /**
     * Batch-decrement quantities for multiple products in a single query.
     * Uses CASE WHEN to update all rows atomically.
     *
     * @param array $decrements Array of ['product_id' => int, 'quantity' => float]
     */
    public function batchDecrementQuantity(array $decrements): void
    {
        if (empty($decrements)) return;

        $ids = [];
        $cases = [];
        $params = [];
        $i = 0;

        foreach ($decrements as $item) {
            $pidParam = ":pid_{$i}";
            $qtyParam = ":qty_{$i}";
            $wherePidParam = ":where_pid_{$i}";

            $cases[] = "WHEN id = {$pidParam} THEN quantity - {$qtyParam}";
            
            $params[$pidParam] = (int) $item['product_id'];
            $params[$qtyParam] = (float) $item['quantity'];
            $params[$wherePidParam] = (int) $item['product_id'];

            $ids[] = $wherePidParam;
            $i++;
        }

        $sql = "UPDATE products SET quantity = CASE "
             . implode(' ', $cases)
             . " ELSE quantity END"
             . " WHERE id IN (" . implode(',', $ids) . ")";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function getLowStock(array $filters = []): array {
        $hasPagination = false;
        if (isset($filters['page']) && isset($filters['limit'])) {
            $page  = max(1, (int)$filters['page']);
            $limit = max(1, (int)$filters['limit']);
            $offset = ($page - 1) * $limit;
            $hasPagination = true;

            $countStmt = $this->db->prepare('SELECT COUNT(*) FROM products p WHERE p.quantity <= p.low_stock_threshold');
            $countStmt->execute();
            $total = (int) $countStmt->fetchColumn();

            $limitStr = "LIMIT :pag_limit OFFSET :pag_offset";
        } else {
            $limitStr = "";
        }

        $stmt = $this->db->prepare(
            'SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.quantity <= p.low_stock_threshold
             ORDER BY p.quantity ASC
             ' . $limitStr
        );
        if ($hasPagination) {
            $stmt->bindValue(':pag_limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':pag_offset', $offset, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $this->attachAdditionalBarcodes($rows);

        if ($hasPagination) {
            return [
                'data' => $rows,
                'pagination' => [
                    'page'  => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int) ceil($total / $limit)
                ]
            ];
        }

        return $rows;
    }

    public function getLowStockByProductIds(array $ids): array {
        if (empty($ids)) return [];
        // Ensure all IDs are integers to prevent any issues
        $ids = array_map('intval', $ids);
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $this->db->prepare(
            "SELECT * FROM products 
             WHERE id IN ($placeholders) AND quantity <= low_stock_threshold"
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTotalProductsCount(): int {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM products');
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    public function getLowStockProductsCount(): int {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM products WHERE quantity <= low_stock_threshold');
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}
