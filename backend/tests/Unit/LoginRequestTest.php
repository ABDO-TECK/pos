<?php

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Requests\LoginRequest;
use PHPUnit\Framework\TestCase;

class LoginRequestTest extends TestCase
{
    public function testAcceptsBoundedCredentials(): void
    {
        $request = new LoginRequest([
            'email' => 'cashier@example.test',
            'password' => str_repeat('a', 256),
        ]);

        $this->assertSame(256, strlen($request->validated()['password']));
    }

    public function testRejectsOversizedPassword(): void
    {
        $this->expectException(ValidationException::class);

        new LoginRequest([
            'email' => 'cashier@example.test',
            'password' => str_repeat('a', 257),
        ]);
    }
}
