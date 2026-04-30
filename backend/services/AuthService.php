<?php

namespace App\Services;


class AuthService {
    private ?array $user = null;

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
}
