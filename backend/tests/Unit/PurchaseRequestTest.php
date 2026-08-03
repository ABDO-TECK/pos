<?php

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Requests\PurchaseRequest;
use PHPUnit\Framework\TestCase;

class PurchaseRequestTest extends TestCase
{
    public function testAcceptsPositiveFractionalQuantityAndNonNegativeCost(): void
    {
        $request = new PurchaseRequest([
            'supplier_id' => 1,
            'product_id' => 2,
            'quantity' => '1.5',
            'cost' => '10.25',
        ]);

        self::assertSame('1.5', $request->validated()['quantity']);
        self::assertSame('10.25', $request->validated()['cost']);
    }

    public function testRejectsZeroOrNegativeQuantity(): void
    {
        $this->expectException(ValidationException::class);

        new PurchaseRequest([
            'supplier_id' => 1,
            'product_id' => 2,
            'quantity' => 0,
            'cost' => 10,
        ]);
    }

    public function testRejectsNegativeCost(): void
    {
        $this->expectException(ValidationException::class);

        new PurchaseRequest([
            'supplier_id' => 1,
            'product_id' => 2,
            'quantity' => 1,
            'cost' => -0.01,
        ]);
    }

    public function testRejectsNonFiniteNumericValues(): void
    {
        $this->expectException(ValidationException::class);

        new PurchaseRequest([
            'supplier_id' => 1,
            'product_id' => 2,
            'quantity' => '1e309',
            'cost' => 10,
        ]);
    }
}
