<?php

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Requests\ProductRequest;
use PHPUnit\Framework\TestCase;

class ProductRequestTest extends TestCase
{
    public function testAcceptsEmptyAndMaximumNestedCollections(): void
    {
        $request = new ProductRequest($this->validProduct([
            'additional_barcodes' => array_fill(0, 20, 'barcode'),
            'sizes' => array_fill(0, 100, [
                'size_name' => 'Medium',
                'price' => 12.5,
                'cost' => 6.25,
                'quantity' => 1.5,
                'low_stock_threshold' => 0.5,
                'barcode' => 'size-barcode',
            ]),
        ]));

        $validated = $request->validated();

        $this->assertCount(20, $validated['additional_barcodes']);
        $this->assertCount(100, $validated['sizes']);
        $this->assertSame(12.5, $validated['sizes'][0]['price']);
    }

    public function testAcceptsEmptyNestedCollections(): void
    {
        $request = new ProductRequest($this->validProduct([
            'additional_barcodes' => [],
            'sizes' => [],
        ]));

        $this->assertSame([], $request->validated()['additional_barcodes']);
        $this->assertSame([], $request->validated()['sizes']);
    }

    public function testRejectsOneMoreThanMaximumAdditionalBarcode(): void
    {
        $this->expectException(ValidationException::class);
        new ProductRequest($this->validProduct([
            'additional_barcodes' => array_fill(0, 21, 'barcode'),
        ]));
    }

    public function testRejectsOneMoreThanMaximumSize(): void
    {
        $this->expectException(ValidationException::class);
        new ProductRequest($this->validProduct([
            'sizes' => array_fill(0, 101, [
                'size_name' => 'Medium',
                'price' => 12.5,
            ]),
        ]));
    }

    public function testRejectsMalformedSizeEntry(): void
    {
        try {
            (new ProductRequest($this->validProduct(['sizes' => ['not-an-object']])))->validated();
            $this->fail('Expected invalid size entry to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sizes.0', $exception->getErrors());
        }
    }

    public function testRejectsOversizedNestedBarcode(): void
    {
        try {
            (new ProductRequest($this->validProduct(['additional_barcodes' => [str_repeat('x', 101)]])))->validated();
            $this->fail('Expected oversized barcode to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('additional_barcodes.0', $exception->getErrors());
        }
    }

    public function testRejectsInvalidNestedNumericRange(): void
    {
        try {
            (new ProductRequest($this->validProduct(['sizes' => [[
                'size_name' => 'Small',
                'price' => -0.5,
            ]]])))->validated();
            $this->fail('Expected negative size price to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sizes.0.price', $exception->getErrors());
        }
    }

    private function validProduct(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Product',
            'price' => 10.5,
            'barcode' => 'main-barcode',
        ], $overrides);
    }
}
