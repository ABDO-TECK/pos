<?php

namespace App\Repositories;

use App\Contracts\RepositoryInterface;
use PDO;

class LoyaltyRepository implements RepositoryInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function all(array $filters = []): array
    {
        throw new \BadMethodCallException('Method not supported.');
    }

    public function findById(int $id): ?array
    {
        throw new \BadMethodCallException('Method not supported.');
    }

    public function create(array $data): int
    {
        throw new \BadMethodCallException('Method not supported.');
    }

    public function update(int $id, array $data): void
    {
        throw new \BadMethodCallException('Method not supported.');
    }

    public function delete(int $id): void
    {
        throw new \BadMethodCallException('Method not supported.');
    }

    public function isEnabled(): bool
    {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = 'loyalty_enabled'");
        $stmt->execute();
        return (bool)($stmt->fetchColumn() ?: false);
    }

    public function getPointsPerRial(): int
    {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = 'loyalty_points_per_rial'");
        $stmt->execute();
        return (int)($stmt->fetchColumn() ?: 1);
    }

    public function getRialPerPoint(): float
    {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = 'loyalty_rial_per_point'");
        $stmt->execute();
        return (float)($stmt->fetchColumn() ?: 0.01);
    }

    public function getCustomerPoints(int $customerId): int
    {
        $stmt = $this->db->prepare("SELECT loyalty_points FROM customers WHERE id = ?");
        $stmt->execute([$customerId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    public function recordTransaction(int $customerId, ?int $invoiceId, int $points, string $type, string $description): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO loyalty_transactions (customer_id, invoice_id, points, type, description)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$customerId, $invoiceId, $points, $type, $description]);
    }

    public function updateCustomerPoints(int $customerId, int $points): void
    {
        $stmt = $this->db->prepare("UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?");
        $stmt->execute([$points, $customerId]);
    }

    public function getHistory(int $customerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM loyalty_transactions WHERE customer_id = ? ORDER BY created_at DESC LIMIT 100"
        );
        $stmt->execute([$customerId]);
        return $stmt->fetchAll();
    }
}
