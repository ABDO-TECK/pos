<?php
namespace App\Models;

use PDO;

class InventoryEvent
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * تسجيل حدث تغيير مخزون.
     * يُستدعى من SaleService / InventoryService / ProductService عند أي تغيير.
     */
    public function record(int $productId, string $action, int $newQuantity, int $delta = 0): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO inventory_events (product_id, action, quantity, delta)
             VALUES (:pid, :action, :qty, :delta)'
        );
        $stmt->execute([
            'pid'    => $productId,
            'action' => $action,
            'qty'    => $newQuantity,
            'delta'  => $delta,
        ]);
    }

    /**
     * جلب الأحداث الجديدة بعد ID معين.
     * يُستدعى من SSE endpoint.
     */
    public function getAfter(int $lastId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_id, action, quantity, delta, created_at
             FROM inventory_events
             WHERE id > :last_id
             ORDER BY id ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':last_id', $lastId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * تنظيف الأحداث القديمة (أكثر من ساعة).
     * يُستدعى دورياً من SSE endpoint.
     */
    public function cleanup(): void
    {
        $this->db->exec("DELETE FROM inventory_events WHERE created_at < NOW() - INTERVAL 1 HOUR");
    }
}
