<?php
namespace App\Services;

use App\Config\Database;
use PDO;

class LoyaltyService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /** هل نظام الولاء مفعل؟ */
    public function isEnabled(): bool {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = 'loyalty_enabled'");
        $stmt->execute();
        return (bool)($stmt->fetchColumn() ?: false);
    }

    /** احسب النقاط المكتسبة لمبلغ معين */
    public function calculatePoints(float $total): int {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = 'loyalty_points_per_rial'");
        $stmt->execute();
        $rate = (int)($stmt->fetchColumn() ?: 1);
        return (int)floor($total * $rate);
    }

    /** أضف نقاط لعميل */
    public function earnPoints(int $customerId, int $invoiceId, float $total): int {
        if (!$this->isEnabled()) return 0;
        $points = $this->calculatePoints($total);
        if ($points <= 0) return 0;

        $this->db->prepare(
            "INSERT INTO loyalty_transactions (customer_id, invoice_id, points, type, description)
             VALUES (?, ?, ?, 'earn', ?)"
        )->execute([$customerId, $invoiceId, $points, "اكتساب نقاط من فاتورة #{$invoiceId}"]);

        $this->db->prepare("UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?")
            ->execute([$points, $customerId]);

        return $points;
    }

    /** استرداد نقاط */
    public function redeemPoints(int $customerId, int $points, ?int $invoiceId = null): float {
        $stmt = $this->db->prepare("SELECT loyalty_points FROM customers WHERE id = ?");
        $stmt->execute([$customerId]);
        $current = (int)$stmt->fetchColumn();
        if ($current < $points) throw new \Exception('رصيد النقاط غير كافي');

        $stmt2 = $this->db->prepare("SELECT value FROM settings WHERE `key` = 'loyalty_rial_per_point'");
        $stmt2->execute();
        $rate = (float)($stmt2->fetchColumn() ?: 0.01);
        $discount = round($points * $rate, 2);

        $this->db->prepare(
            "INSERT INTO loyalty_transactions (customer_id, invoice_id, points, type, description)
             VALUES (?, ?, ?, 'redeem', ?)"
        )->execute([$customerId, $invoiceId, -$points, "استرداد {$points} نقطة"]);

        $this->db->prepare("UPDATE customers SET loyalty_points = loyalty_points - ? WHERE id = ?")
            ->execute([$points, $customerId]);

        return $discount;
    }

    /** جلب سجل نقاط عميل */
    public function getHistory(int $customerId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM loyalty_transactions WHERE customer_id = ? ORDER BY created_at DESC LIMIT 100"
        );
        $stmt->execute([$customerId]);
        return $stmt->fetchAll();
    }
}
