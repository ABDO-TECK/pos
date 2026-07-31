<?php

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Requests\BulkPurchaseRequest;
use PHPUnit\Framework\TestCase;

class BulkPurchaseRequestTest extends TestCase
{
    public function testValidatesAndFiltersEveryPurchaseItem(): void
    {
        $request = new BulkPurchaseRequest([
            'supplier_id' => 1,
            'payment_type' => 'credit',
            'deposit' => 5,
            'items' => [[
                'product_id' => '2',
                'quantity' => '1.5',
                'cost' => '10.25',
                'update_cost' => true,
                'ignored' => 'not allowed through request boundary',
            ]],
        ]);

        $validated = $request->validated();

        $this->assertSame([
            'product_id' => 2,
            'quantity' => 1.5,
            'cost' => 10.25,
            'update_cost' => true,
        ], $validated['items'][0]);
    }

    public function testRejectsInvalidNestedPurchaseItem(): void
    {
        $request = new BulkPurchaseRequest([
            'supplier_id' => 1,
            'items' => [['product_id' => 1, 'quantity' => 0, 'cost' => -1]],
        ]);

        $this->expectException(ValidationException::class);
        $request->validated();
    }

    public function testRejectsMoreThanMaximumPurchaseLines(): void
    {
        $this->expectException(ValidationException::class);
        new BulkPurchaseRequest([
            'supplier_id' => 1,
            'items' => array_fill(0, 501, ['product_id' => 1, 'quantity' => 1, 'cost' => 1]),
        ]);
    }
}
