<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Core\Validator;

class ValidatorTest extends TestCase
{
    public function testRequiredFieldPasses(): void
    {
        $errors = Validator::validate(['name' => 'أحمد'], ['name' => 'required']);
        $this->assertEmpty($errors);
    }

    public function testRequiredFieldFails(): void
    {
        $errors = Validator::validate([], ['name' => 'required']);
        $this->assertArrayHasKey('name', $errors);
    }

    public function testMinLengthPasses(): void
    {
        $errors = Validator::validate(['pw' => 'abcdef'], ['pw' => 'min:6']);
        $this->assertEmpty($errors);
    }

    public function testMinLengthFails(): void
    {
        $errors = Validator::validate(['pw' => 'abc'], ['pw' => 'min:6']);
        $this->assertArrayHasKey('pw', $errors);
    }

    public function testEmailPasses(): void
    {
        $errors = Validator::validate(['e' => 'test@example.com'], ['e' => 'email']);
        $this->assertEmpty($errors);
    }

    public function testEmailFails(): void
    {
        $errors = Validator::validate(['e' => 'not-email'], ['e' => 'email']);
        $this->assertArrayHasKey('e', $errors);
    }

    public function testNumericPasses(): void
    {
        $errors = Validator::validate(['price' => '19.99'], ['price' => 'numeric']);
        $this->assertEmpty($errors);
    }

    public function testNumericFails(): void
    {
        $errors = Validator::validate(['price' => 'abc'], ['price' => 'numeric']);
        $this->assertArrayHasKey('price', $errors);
    }

    public function testStrongPasswordRejectsWeak(): void
    {
        $errors = Validator::validate(['pw' => 'password'], ['pw' => 'strong_password']);
        $this->assertArrayHasKey('pw', $errors);
    }

    public function testStrongPasswordAcceptsStrong(): void
    {
        $errors = Validator::validate(['pw' => 'MyStr0ng!Pass'], ['pw' => 'strong_password']);
        $this->assertEmpty($errors);
    }

    public function testMultipleRulesAllPass(): void
    {
        $errors = Validator::validate(
            ['email' => 'a@b.com', 'name' => 'Test'],
            ['email' => 'required|email', 'name' => 'required|min:2']
        );
        $this->assertEmpty($errors);
    }

    public function testMultipleRulesPartialFail(): void
    {
        $errors = Validator::validate(
            ['email' => 'bad', 'name' => 'T'],
            ['email' => 'required|email', 'name' => 'required|min:2']
        );
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('name', $errors);
    }
}
