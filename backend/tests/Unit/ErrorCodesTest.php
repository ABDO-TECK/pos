<?php
namespace Tests\Unit;

use App\Helpers\ErrorCodes;
use PHPUnit\Framework\TestCase;

class ErrorCodesTest extends TestCase
{
    public function testAllCodesStartWithERR(): void {
        $ref = new \ReflectionClass(ErrorCodes::class);
        foreach ($ref->getConstants() as $name => $value) {
            $this->assertStringStartsWith('ERR_', $value, "Constant {$name} should start with ERR_");
        }
    }

    public function testNoDuplicateCodes(): void {
        $ref = new \ReflectionClass(ErrorCodes::class);
        $values = array_values($ref->getConstants());
        $this->assertCount(count($values), array_unique($values), 'Duplicate error codes found');
    }
}
