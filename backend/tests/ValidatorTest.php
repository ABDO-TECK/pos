<?php

use PHPUnit\Framework\TestCase;
use App\Core\Validator;

class ValidatorTest extends TestCase
{
    public function testRequiredFieldReturnsErrorWhenEmpty()
    {
        $data = ['name' => ''];
        $rules = ['name' => 'required'];
        $errors = Validator::validate($data, $rules);
        
        $this->assertArrayHasKey('name', $errors);
        $this->assertStringContainsString('مطلوب', $errors['name'][0]);
    }

    public function testValidDataPassesWithoutErrors()
    {
        $data = [
            'name' => 'John',
            'age' => '30'
        ];
        $rules = [
            'name' => 'required|string',
            'age' => 'required|integer'
        ];
        $errors = Validator::validate($data, $rules);
        
        $this->assertEmpty($errors, 'Validation should pass when valid data is provided');
    }

    public function testNumericRuleValidatesNumbersOnly()
    {
        $data = ['price' => 'abc'];
        $rules = ['price' => 'numeric'];
        $errors = Validator::validate($data, $rules);

        $this->assertArrayHasKey('price', $errors);
        $this->assertStringContainsString('رقماً', $errors['price'][0]);

        $validData = ['price' => '100.50'];
        $validErrors = Validator::validate($validData, $rules);
        $this->assertEmpty($validErrors);
    }
}
