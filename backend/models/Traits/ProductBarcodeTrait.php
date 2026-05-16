<?php
namespace App\Models\Traits;

use PDO;
use Exception;

trait ProductBarcodeTrait {
    /** @return list<string> */
    public function getAdditionalBarcodesList(int $productId): array {
        $stmt = $this->db->prepare('SELECT barcode FROM product_barcodes WHERE product_id = ? ORDER BY id ASC');
        $stmt->execute([$productId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'barcode');
    }

    /** Helper to load barcodes for multiple products in ONE query */
    private function attachAdditionalBarcodes(array &$rows): void {
        if (empty($rows)) return;
        $ids = array_column($rows, 'id');
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $this->db->prepare("SELECT product_id, barcode FROM product_barcodes WHERE product_id IN ($placeholders)");
        $stmt->execute($ids);
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grouped[$row['product_id']][] = $row['barcode'];
        }
        foreach ($rows as &$r) {
            $r['additional_barcodes'] = $grouped[$r['id']] ?? [];
        }
    }

    public function findOwnerProductIdByBarcode(string $barcode): ?int {
        $stmt = $this->db->prepare('SELECT id FROM products WHERE (barcode = ? OR box_barcode = ?) AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$barcode, $barcode]);
        $id = $stmt->fetchColumn();
        if ($id !== false && $id !== null) {
            return (int) $id;
        }
        // فحص الباركودات الإضافية — مع استثناء المنتجات المحذوفة
        $stmt = $this->db->prepare(
            'SELECT pb.product_id FROM product_barcodes pb
             INNER JOIN products p ON p.id = pb.product_id AND p.deleted_at IS NULL
             WHERE pb.barcode = ? LIMIT 1'
        );
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
                $nameStmt = $this->db->prepare('SELECT name FROM products WHERE id = ? LIMIT 1');
                $nameStmt->execute([$owner]);
                $ownerName = $nameStmt->fetchColumn();
                $ownerName = $ownerName !== false && $ownerName !== null && $ownerName !== ''
                    ? (string)$ownerName
                    : ('#' . $owner);
                throw new Exception(
                    'الباركود «' . $code . '» مسجّل مسبقاً للمنتج: «' . $ownerName . '». استخدم باركوداً مختلفاً أو عدّل المنتج الحالي.'
                );
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

    /** تحديث الباركود الأساسي فقط (يُستخدم لتعيين رقم ID كباركود تلقائي) */
    public function updateMainBarcode(int $id, string $barcode): void {
        $this->db->prepare('UPDATE products SET barcode = ? WHERE id = ?')->execute([$barcode, $id]);
    }
}
