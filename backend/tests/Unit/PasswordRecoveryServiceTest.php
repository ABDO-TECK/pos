<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\Database;
use App\Services\PasswordRecoveryService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PasswordRecoveryServiceTest extends TestCase
{
    private const LEGACY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    private PDO $db;
    private PasswordRecoveryService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                email TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                force_password_change INTEGER NOT NULL DEFAULT 0
            )'
        );
        $this->db->exec(
            'CREATE TABLE tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL
            )'
        );
        $this->db->exec(
            'CREATE TABLE refresh_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                revoked_at TEXT
            )'
        );
        $this->db->exec(
            'CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                action TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id INTEGER,
                old_value TEXT,
                new_value TEXT,
                ip_address TEXT
            )'
        );

        $property = new \ReflectionProperty(Database::class, 'instance');
        $property->setValue(null, $this->db);
        $this->service = new PasswordRecoveryService($this->db);
    }

    protected function tearDown(): void
    {
        Database::resetInstance();
    }

    public function testLegacyDisabledAccountCanBeRecoveredAndReactivated(): void
    {
        $this->insertUser(1, 'admin@pos.com', self::LEGACY_HASH, 0, 1);
        $this->db->exec('INSERT INTO tokens (user_id) VALUES (1)');
        $this->db->exec('INSERT INTO refresh_tokens (user_id) VALUES (1)');

        $result = $this->service->resetByEmail('admin@pos.com', 'Recovered123');

        self::assertTrue($result['ok']);
        self::assertTrue($result['reactivated']);
        $user = $this->db->query('SELECT password, is_active, force_password_change FROM users WHERE id = 1')->fetch();
        self::assertIsArray($user);
        self::assertTrue(password_verify('Recovered123', (string) $user['password']));
        self::assertSame(1, (int) $user['is_active']);
        self::assertSame(0, (int) $user['force_password_change']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM tokens WHERE user_id = 1')->fetchColumn());
        self::assertNotNull($this->db->query('SELECT revoked_at FROM refresh_tokens WHERE user_id = 1')->fetchColumn());
    }

    public function testIntentionallyDisabledAccountCannotBeReactivated(): void
    {
        $this->insertUser(2, 'staff@example.com', password_hash('OldPassword1', PASSWORD_DEFAULT), 0, 1);

        $result = $this->service->resetByEmail('staff@example.com', 'Recovered123');

        self::assertFalse($result['ok']);
        self::assertSame('Active account not found.', $result['error']);
        $user = $this->db->query('SELECT password, is_active FROM users WHERE id = 2')->fetch();
        self::assertIsArray($user);
        self::assertSame(0, (int) $user['is_active']);
        self::assertTrue(password_verify('OldPassword1', (string) $user['password']));
    }

    public function testActiveAccountCanResetWithoutBeingMarkedReactivated(): void
    {
        $this->insertUser(3, 'active@example.com', password_hash('OldPassword1', PASSWORD_DEFAULT), 1, 0);

        $result = $this->service->resetByEmail('active@example.com', 'Recovered123');

        self::assertTrue($result['ok']);
        self::assertFalse($result['reactivated']);
        $user = $this->db->query('SELECT password, is_active FROM users WHERE id = 3')->fetch();
        self::assertIsArray($user);
        self::assertSame(1, (int) $user['is_active']);
        self::assertTrue(password_verify('Recovered123', (string) $user['password']));
    }

    private function insertUser(
        int $id,
        string $email,
        string $password,
        int $isActive,
        int $forcePasswordChange
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO users (id, email, password, is_active, force_password_change)
             VALUES (:id, :email, :password, :is_active, :force_password_change)'
        );
        $statement->execute([
            'id' => $id,
            'email' => $email,
            'password' => $password,
            'is_active' => $isActive,
            'force_password_change' => $forcePasswordChange,
        ]);
    }
}
