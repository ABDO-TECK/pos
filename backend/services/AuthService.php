<?php

namespace App\Services;


class AuthService {
    private ?array $user = null;
    private int $branchId = 1;
    private string $apiVersion = 'v1';

    // ── Static accessor (مؤقت — للتوافق مع الكود الذي لا يمكنه الوصول لـ Container) ──
    private static int $globalBranchId = 1;

    public function setUser(array $user): void {
        $this->user = $user;
    }

    public function user(): ?array {
        return $this->user;
    }

    public function id(): ?int {
        return $this->user['id'] ?? null;
    }

    public function role(): ?string {
        return $this->user['role'] ?? null;
    }

    public function check(): bool {
        return $this->user !== null;
    }

    // ── Branch ID ──────────────────────────────────────────────

    public function setBranchId(int $branchId): void {
        $this->branchId = $branchId;
        self::$globalBranchId = $branchId; // تحديث القيمة الثابتة أيضاً
    }

    public function branchId(): int {
        return $this->branchId;
    }

    public static function getGlobalBranchId(): int {
        return self::$globalBranchId;
    }

    // ── API Version ───────────────────────────────────────────

    public function setApiVersion(string $version): void {
        $this->apiVersion = $version;
    }

    public function apiVersion(): string {
        return $this->apiVersion;
    }
}
