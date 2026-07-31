<?php

namespace Tests\Integration;

use App\Controllers\AuthController;
use App\Models\User;
use App\Services\AuthService;
use PDO;
use PHPUnit\Framework\TestCase;

class AuthApiTest extends TestCase
{
    /**
     * @runInSeparateProcess
     */
    public function testLoginSuccessReturnsToken()
    {
        $userModelMock = $this->createMock(User::class);
        $authServiceMock = $this->createMock(AuthService::class);

        $hashedPassword = password_hash('secret', PASSWORD_DEFAULT);
        $userModelMock->expects($this->once())
            ->method('findForAuthentication')
            ->with('admin@pos.com')
            ->willReturn([
                'id' => 1,
                'name' => 'Admin',
                'email' => 'admin@pos.com',
                'password' => $hashedPassword,
                'role' => 'admin',
                'branch_id' => 7,
                'force_password_change' => 1,
            ]);

        $userModelMock->expects($this->once())
            ->method('createToken')
            ->with(1)
            ->willReturn('fake_token_123');

        $controller = $this->getMockBuilder(AuthController::class)
            ->setConstructorArgs([$userModelMock, $authServiceMock])
            ->onlyMethods(['getBody'])
            ->getMock();

        $controller->expects($this->once())
            ->method('getBody')
            ->willReturn([
                'email' => 'admin@pos.com',
                'password' => 'secret'
            ]);

        // Suppress setcookie warning in CLI
        @$response = $controller->login();

        $this->assertEquals(200, $response['status_code']);
        $this->assertEquals('success', $response['body']['status']);
        $this->assertSame([
            'id' => 1,
            'name' => 'Admin',
            'email' => 'admin@pos.com',
            'role' => 'admin',
            'branch_id' => 7,
            'force_password_change' => 1,
        ], $response['body']['data']['user']);
    }

    /**
     * @runInSeparateProcess
     */
    public function testLoginFailsWithInvalidPassword()
    {
        $userModelMock = $this->createMock(User::class);
        $authServiceMock = $this->createMock(AuthService::class);

        $hashedPassword = password_hash('secret', PASSWORD_DEFAULT);
        $userModelMock->expects($this->once())
            ->method('findForAuthentication')
            ->with('admin@pos.com')
            ->willReturn([
                'id' => 1,
                'name' => 'Admin',
                'email' => 'admin@pos.com',
                'password' => $hashedPassword,
                'role' => 'admin',
                'branch_id' => 7,
            ]);

        $controller = $this->getMockBuilder(AuthController::class)
            ->setConstructorArgs([$userModelMock, $authServiceMock])
            ->onlyMethods(['getBody'])
            ->getMock();

        $controller->expects($this->once())
            ->method('getBody')
            ->willReturn([
                'email' => 'admin@pos.com',
                'password' => 'wrongpassword'
            ]);

        $response = $controller->login();

        $this->assertEquals(401, $response['status_code']);
        $this->assertEquals('error', $response['body']['status']);
    }

    public function testMeReturnsBranchId()
    {
        $userModelMock = $this->createMock(User::class);
        $authServiceMock = $this->createMock(AuthService::class);
        $authServiceMock->method('user')->willReturn(['id' => 3, 'branch_id' => 9]);
        $userModelMock->expects($this->once())
            ->method('findById')
            ->with(3)
            ->willReturn([
                'id' => 3,
                'name' => 'Cashier',
                'email' => 'cashier@pos.com',
                'role' => 'cashier',
                'branch_id' => 9,
                'force_password_change' => 0,
            ]);

        $controller = new AuthController($userModelMock, $authServiceMock);
        $response = $controller->me();

        $this->assertSame([
            'id' => 3,
            'name' => 'Cashier',
            'email' => 'cashier@pos.com',
            'role' => 'cashier',
            'branch_id' => 9,
            'force_password_change' => 0,
        ], $response['body']['data']);
    }

    /**
     * @runInSeparateProcess
     */
    public function testRefreshReturnsBranchId()
    {
        $_COOKIE['pos_refresh_token'] = 'refresh-token';
        $userModelMock = $this->createMock(User::class);
        $authServiceMock = $this->createMock(AuthService::class);
        $userModelMock->expects($this->once())
            ->method('rotateRefreshToken')
            ->with('refresh-token')
            ->willReturn([
                'status' => 'ok',
                'refresh_token' => 'next-refresh-token',
                'access_token' => 'next-access-token',
                'user_id' => 4,
            ]);
        $userModelMock->expects($this->once())
            ->method('findById')
            ->with(4)
            ->willReturn([
                'id' => 4,
                'name' => 'Admin',
                'email' => 'admin@pos.com',
                'role' => 'admin',
                'branch_id' => 11,
                'force_password_change' => 0,
            ]);

        $controller = new AuthController($userModelMock, $authServiceMock);
        @$response = $controller->refresh();

        $this->assertSame([
            'id' => 4,
            'name' => 'Admin',
            'email' => 'admin@pos.com',
            'role' => 'admin',
            'branch_id' => 11,
            'force_password_change' => 0,
        ], $response['body']['data']['user']);
    }

    public function testFindByIdIncludesBranchId(): void
    {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $database->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                role TEXT NOT NULL,
                is_active INTEGER NOT NULL,
                force_password_change INTEGER NOT NULL,
                branch_id INTEGER NOT NULL,
                created_at TEXT
            )'
        );
        $database->exec(
            "INSERT INTO users
                (id, name, email, role, is_active, force_password_change, branch_id, created_at)
             VALUES (8, 'Cashier', 'cashier@example.test', 'cashier', 1, 0, 23, '2026-07-31')"
        );

        $user = (new User($database))->findById(8);

        $this->assertIsArray($user);
        $this->assertSame(23, (int) $user['branch_id']);
    }
}
