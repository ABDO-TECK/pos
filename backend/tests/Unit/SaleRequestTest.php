<?php

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Requests\SaleRequest;
use PHPUnit\Framework\TestCase;

class SaleRequestTest extends TestCase
{
    public function testPreservesAndNormalizesNewCustomer(): void
    {
        $request = new SaleRequest([
            'idempotency_key' => '01932f9e-bb6f-4ce0-935d-6f179b69aa08',
            'items' => [['product_id' => 1, 'quantity' => 1]],
            'payment_method' => 'cash',
            'new_customer' => [
                'name' => '  New Customer  ',
                'phone' => ' 01000000000 ',
                'address' => '  Cairo ',
                'ignored' => 'must not pass validation boundary',
            ],
        ]);

        $validated = $request->validated();

        $this->assertSame([
            'name' => 'New Customer',
            'phone' => '01000000000',
            'address' => 'Cairo',
        ], $validated['new_customer']);
    }

    public function testRejectsBlankNewCustomerName(): void
    {
        $request = new SaleRequest([
            'idempotency_key' => '2e0e671e-5c3f-4b52-a34d-35dc672bb8d5',
            'items' => [['product_id' => 1, 'quantity' => 1]],
            'payment_method' => 'cash',
            'new_customer' => ['name' => '   '],
        ]);

        $this->expectException(ValidationException::class);
        $request->validated();
    }

    public function testRejectsInvalidNestedSaleItem(): void
    {
        $request = new SaleRequest([
            'idempotency_key' => 'bc31c67b-aa66-4894-b5f0-02883ea3e338',
            'items' => [['product_id' => 1, 'quantity' => 0]],
            'payment_method' => 'cash',
        ]);

        $this->expectException(ValidationException::class);
        $request->validated();
    }

    public function testRejectsMoreThanMaximumSaleLines(): void
    {
        $this->expectException(ValidationException::class);
        new SaleRequest([
            'idempotency_key' => 'd60a0e1c-a779-4f62-b6cb-7582fb3d30b1',
            'items' => array_fill(0, 501, ['product_id' => 1, 'quantity' => 1]),
            'payment_method' => 'cash',
        ]);
    }

    public function testRejectsMissingIdempotencyKey(): void
    {
        $this->expectException(ValidationException::class);
        new SaleRequest([
            'items' => [['product_id' => 1, 'quantity' => 1]],
            'payment_method' => 'cash',
        ]);
    }

    public function testRejectsNonV4IdempotencyKey(): void
    {
        $this->expectException(ValidationException::class);
        new SaleRequest([
            'idempotency_key' => '01932f9e-bb6f-1ce0-935d-6f179b69aa08',
            'items' => [['product_id' => 1, 'quantity' => 1]],
            'payment_method' => 'cash',
        ]);
    }

    public function testRejectsNegativeDiscountAndAmountPaid(): void
    {
        $this->expectException(ValidationException::class);

        new SaleRequest([
            'idempotency_key' => 'bc31c67b-aa66-4894-b5f0-02883ea3e338',
            'items' => [['product_id' => 1, 'quantity' => 1]],
            'payment_method' => 'cash',
            'discount' => -1,
            'amount_paid' => -1,
        ]);
    }
}
