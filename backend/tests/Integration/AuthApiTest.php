<?php

namespace Tests\Integration;

use App\Controllers\AuthController;
use App\Models\User;
use App\Services\AuthService;
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
            ->method('findByEmail')
            ->with('admin@pos.com')
            ->willReturn([
                'id' => 1,
                'name' => 'Admin',
                'email' => 'admin@pos.com',
                'password' => $hashedPassword,
                'role' => 'admin',
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
        $this->assertEquals('fake_token_123', $response['body']['data']['token']);
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
            ->method('findByEmail')
            ->with('admin@pos.com')
            ->willReturn([
                'id' => 1,
                'name' => 'Admin',
                'email' => 'admin@pos.com',
                'password' => $hashedPassword,
                'role' => 'admin',
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
}
