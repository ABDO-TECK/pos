<?php

namespace Tests\Integration;

use App\Config\Database;
use App\Controllers\UserController;
use App\Core\ValidationException;
use App\Helpers\AuditLog;
use App\Middleware\AuthMiddleware;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Requests\UserUpdateRequest;
use App\Services\AuthService;
use PDO;
use PHPUnit\Framework\TestCase;

class UserPasswordSecurityTest extends TestCase
{
    private PDO $db;
    private User $userModel;
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->sqliteCreateFunction(
            'UTC_TIMESTAMP',
            static fn (): string => gmdate('Y-m-d H:i:s')
        );
        $this->createSchema();
        $this->setDatabaseInstance($this->db);

        $this->userModel = new User($this->db);
        $this->userRepository = new UserRepository($this->userModel);
        (new AuthService())->setBranchId(1);

        $_COOKIE = [];
        $_SERVER['REQUEST_URI'] = '/api/v1/users/1';
        $_SERVER['REQUEST_METHOD'] = 'PUT';
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        Database::resetInstance();
        parent::tearDown();
    }

    public function testMissingCurrentPasswordRejectsSelfPasswordChange(): void
    {
        $this->seedUser(1, 'cashier@pos.test', 'Temporary123', 'cashier', 1);
        $this->userModel->createToken(1);
        $refreshToken = $this->userModel->createRefreshToken(1);

        $response = $this->controllerFor(
            1,
            'cashier',
            [
                'name' => 'Cashier',
                'email' => 'cashier@pos.test',
                'password' => 'Changed456',
            ]
        )->update('1');

        $this->assertSame(422, $response['status_code']);
        $this->assertTrue(password_verify('Temporary123', $this->passwordHashFor(1)));
        $this->assertSame(1, $this->countAccessTokens(1));
        $this->assertNotNull($this->userModel->findRefreshToken($refreshToken));
    }

    public function testWrongCurrentPasswordRejectsSelfPasswordChange(): void
    {
        $this->seedUser(1, 'cashier@pos.test', 'Temporary123', 'cashier', 1);
        $this->userModel->createToken(1);
        $refreshToken = $this->userModel->createRefreshToken(1);

        $response = $this->controllerFor(
            1,
            'cashier',
            [
                'name' => 'Cashier',
                'email' => 'cashier@pos.test',
                'password' => 'Changed456',
                'current_password' => 'WrongPassword123',
            ]
        )->update('1');

        $this->assertSame(422, $response['status_code']);
        $this->assertTrue(password_verify('Temporary123', $this->passwordHashFor(1)));
        $this->assertSame(1, $this->countAccessTokens(1));
        $this->assertNotNull($this->userModel->findRefreshToken($refreshToken));
    }

    public function testCorrectTemporaryPasswordChangesHashRevokesSessionsAndRequiresLogin(): void
    {
        $this->seedUser(1, 'cashier@pos.test', 'Temporary123', 'cashier', 1);
        $oldHash = $this->passwordHashFor(1);
        $accessToken = $this->userModel->createToken(1);
        $refreshToken = $this->userModel->createRefreshToken(1);

        $response = @$this->controllerFor(
            1,
            'cashier',
            [
                'name' => 'Cashier',
                'email' => 'changed-email@pos.test',
                'password' => 'Changed456',
                'current_password' => 'Temporary123',
            ]
        )->update('1');

        $newHash = $this->passwordHashFor(1);
        $this->assertSame(200, $response['status_code']);
        $this->assertTrue($response['body']['requires_reauthentication']);
        $this->assertTrue($response['body']['sessions_revoked']);
        $this->assertNotSame($oldHash, $newHash);
        $this->assertTrue(password_verify('Changed456', $newHash));
        $this->assertFalse(password_verify('Temporary123', $newHash));
        $this->assertSame(0, (int) $this->db->query('SELECT force_password_change FROM users WHERE id = 1')->fetchColumn());
        $this->assertSame('cashier@pos.test', $this->db->query('SELECT email FROM users WHERE id = 1')->fetchColumn());
        $this->assertSame(0, $this->countAccessTokens(1));
        $this->assertNotNull($this->refreshRevokedAt($refreshToken));

        $_COOKIE['pos_token'] = $accessToken;
        $middlewareResponse = (new AuthMiddleware(new AuthService()))->handle(
            static fn (): array => ['unexpected']
        );
        $this->assertSame(401, $middlewareResponse['status_code']);
        $this->assertSame('reused', $this->userModel->rotateRefreshToken($refreshToken)['status']);

        $responseJson = json_encode($response['body']);
        $auditJson = implode(' ', $this->db->query(
            'SELECT COALESCE(old_value, "") || COALESCE(new_value, "") FROM audit_logs'
        )->fetchAll(PDO::FETCH_COLUMN));
        $this->assertStringNotContainsString('Temporary123', $responseJson);
        $this->assertStringNotContainsString('Changed456', $responseJson);
        $this->assertStringNotContainsString('"password":', strtolower($responseJson));
        $this->assertStringNotContainsString('"current_password":', strtolower($responseJson));
        $this->assertStringNotContainsString('Temporary123', $auditJson);
        $this->assertStringNotContainsString('Changed456', $auditJson);
        $this->assertStringNotContainsString('password', strtolower($auditJson));
        $this->assertSame(
            1,
            (int) $this->db->query(
                "SELECT COUNT(*) FROM audit_logs WHERE action = 'password_changed_sessions_revoked'"
            )->fetchColumn()
        );
    }

    public function testAdminResetWithoutCurrentPasswordRevokesOnlyTargetSessions(): void
    {
        $this->seedUser(1, 'admin@pos.test', 'AdminPassword123', 'admin');
        $this->seedUser(2, 'cashier@pos.test', 'Temporary123', 'cashier');
        $adminAccessToken = $this->userModel->createToken(1);
        $targetAccessToken = $this->userModel->createToken(2);
        $targetRefreshToken = $this->userModel->createRefreshToken(2);

        $response = $this->controllerFor(
            1,
            'admin',
            [
                'name' => 'Cashier',
                'email' => 'cashier@pos.test',
                'role' => 'cashier',
                'is_active' => 1,
                'password' => 'AdminReset456',
            ]
        )->update('2');

        $this->assertSame(200, $response['status_code']);
        $this->assertFalse($response['body']['requires_reauthentication']);
        $this->assertTrue(password_verify('AdminReset456', $this->passwordHashFor(2)));
        $this->assertSame(1, $this->countAccessTokens(1));
        $this->assertSame(0, $this->countAccessTokens(2));
        $this->assertNotNull($this->refreshRevokedAt($targetRefreshToken));

        $_COOKIE['pos_token'] = $adminAccessToken;
        $adminMiddlewareResponse = (new AuthMiddleware(new AuthService()))->handle(
            static fn (): array => ['status_code' => 204]
        );
        $this->assertSame(204, $adminMiddlewareResponse['status_code']);

        $_COOKIE['pos_token'] = $targetAccessToken;
        $targetMiddlewareResponse = (new AuthMiddleware(new AuthService()))->handle(
            static fn (): array => ['unexpected']
        );
        $this->assertSame(401, $targetMiddlewareResponse['status_code']);
        $this->assertSame(
            1,
            (int) $this->db->query(
                "SELECT COUNT(*) FROM audit_logs WHERE action = 'admin_password_reset_sessions_revoked'"
            )->fetchColumn()
        );
    }

    public function testAuditLogDropsPasswordFieldsRecursively(): void
    {
        AuditLog::log(
            1,
            'security_test',
            'user',
            1,
            ['password' => 'old-secret'],
            [
                'current_password' => 'current-secret',
                'nested' => [
                    'password_confirmation' => 'new-secret',
                    'sessions_revoked' => true,
                ],
            ]
        );

        $row = $this->db->query(
            "SELECT old_value, new_value FROM audit_logs WHERE action = 'security_test'"
        )->fetch();
        $auditJson = json_encode($row);

        $this->assertStringNotContainsString('password', strtolower($auditJson));
        $this->assertStringNotContainsString('secret', strtolower($auditJson));
        $this->assertStringContainsString('sessions_revoked', $auditJson);
    }

    public function testRoleAndActiveStatusAreRestrictedToSchemaValues(): void
    {
        $this->expectException(ValidationException::class);
        new UserUpdateRequest([
            'name' => 'Cashier',
            'email' => 'cashier@pos.test',
            'role' => 'superuser',
            'is_active' => 1,
        ]);
    }

    public function testActiveStatusMustBeBooleanLikeSchemaValue(): void
    {
        $this->expectException(ValidationException::class);
        new UserUpdateRequest([
            'name' => 'Cashier',
            'email' => 'cashier@pos.test',
            'role' => 'cashier',
            'is_active' => 2,
        ]);
    }

    private function controllerFor(int $actorId, string $role, array $body): UserController
    {
        $authService = new AuthService();
        $authService->setUser([
            'id' => $actorId,
            'name' => $role === 'admin' ? 'Admin' : 'Cashier',
            'email' => $role . '@pos.test',
            'role' => $role,
            'branch_id' => 1,
        ]);
        $authService->setBranchId(1);

        $controller = $this->getMockBuilder(UserController::class)
            ->setConstructorArgs([$this->userRepository, $authService])
            ->onlyMethods(['getBody'])
            ->getMock();
        $controller->method('getBody')->willReturn($body);

        return $controller;
    }

    private function seedUser(
        int $id,
        string $email,
        string $password,
        string $role,
        int $forcePasswordChange = 0
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO users
                (id, name, email, password, role, is_active, force_password_change, branch_id)
             VALUES (?, ?, ?, ?, ?, 1, ?, 1)'
        );
        $stmt->execute([
            $id,
            $role === 'admin' ? 'Admin' : 'Cashier',
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
            $forcePasswordChange,
        ]);
    }

    private function passwordHashFor(int $userId): string
    {
        $stmt = $this->db->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (string) $stmt->fetchColumn();
    }

    private function countAccessTokens(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM tokens WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    private function refreshRevokedAt(string $refreshToken): ?string
    {
        $stmt = $this->db->prepare('SELECT revoked_at FROM refresh_tokens WHERE token = ?');
        $stmt->execute([hash('sha256', $refreshToken)]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    private function setDatabaseInstance(PDO $db): void
    {
        $property = new \ReflectionProperty(Database::class, 'instance');
        $property->setValue(null, $db);
    }

    private function createSchema(): void
    {
        $this->db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                role TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                force_password_change INTEGER NOT NULL DEFAULT 0,
                branch_id INTEGER NOT NULL DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->exec(
            'CREATE TABLE tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token TEXT NOT NULL UNIQUE,
                expires_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->exec(
            'CREATE TABLE refresh_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token TEXT NOT NULL UNIQUE,
                family_id TEXT NOT NULL,
                used_at TEXT,
                revoked_at TEXT,
                replaced_by_token TEXT,
                expires_at TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
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
                ip_address TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }
}
