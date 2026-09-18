<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Models\Product;
use App\Requests\ProductRequest;
use App\Services\AuthService;
use PDO;
use PDOStatement;

class ProductEmptyNumericTest extends TestCase
{
    protected function setUp(): void
    {
        (new AuthService())->setBranchId(1);
    }

    private function createMockPdo(&$capturedParams): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);

        $stmt->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                $capturedParams = $params;
                return true;
            });

        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('lastInsertId')->willReturn('101');

        return $pdo;
    }

    /**
     * 1. Empty quantity normalizes to numeric zero.
     */
    public function testEmptyQuantityNormalizesToNumericZero(): void
    {
        $captured = null;
        $pdo = $this->createMockPdo($captured);
        $model = new Product($pdo);

        $model->create([
            'name'     => 'PROD_EMPTY_QTY',
            'barcode'  => '114700010',
            'price'    => '100',
            'cost'     => '50',
            'quantity' => '', // Empty quantity string
        ]);

        $this->assertNotNull($captured);
        $this->assertNotSame('', $captured['quantity']);
        $this->assertSame(0.0, (float)$captured['quantity']);
        $this->assertSame(50.0, (float)$captured['cost']);
    }

    /**
     * 2. Empty cost normalizes to numeric zero.
     */
    public function testEmptyCostNormalizesToNumericZero(): void
    {
        $captured = null;
        $pdo = $this->createMockPdo($captured);
        $model = new Product($pdo);

        $model->create([
            'name'     => 'PROD_EMPTY_COST',
            'barcode'  => '114700020',
            'price'    => '100',
            'cost'     => '', // Empty cost string
            'quantity' => '10',
        ]);

        $this->assertNotNull($captured);
        $this->assertNotSame('', $captured['cost']);
        $this->assertSame(0.0, (float)$captured['cost']);
        $this->assertSame(10.0, (float)$captured['quantity']);
    }

    /**
     * 3. Both quantity and cost empty normalize to numeric zero.
     */
    public function testBothEmptyQuantityAndCostNormalizeToNumericZero(): void
    {
        $captured = null;
        $pdo = $this->createMockPdo($captured);
        $model = new Product($pdo);

        $model->create([
            'name'                => 'BASELINE_FIRST_RUN',
            'barcode'             => '114700001',
            'price'               => '100',
            'cost'                => '', // Both empty
            'quantity'            => '', // Both empty
            'low_stock_threshold' => '', // Cleared
        ]);

        $this->assertNotNull($captured);
        $this->assertNotSame('', $captured['quantity'], 'Quantity must not be empty string');
        $this->assertNotSame('', $captured['cost'], 'Cost must not be empty string');
        $this->assertNotSame('', $captured['low_stock_threshold'], 'Low stock must not be empty string');
        $this->assertSame(0.0, (float)$captured['quantity']);
        $this->assertSame(0.0, (float)$captured['cost']);
        $this->assertSame(5, (int)$captured['low_stock_threshold']);
    }

    /**
     * 4. Explicit numeric zero preserved for quantity, cost, and threshold.
     */
    public function testExplicitNumericZeroPreserved(): void
    {
        $captured = null;
        $pdo = $this->createMockPdo($captured);
        $model = new Product($pdo);

        $model->create([
            'name'                => 'PROD_EXPLICIT_ZERO',
            'barcode'             => '114700030',
            'price'               => '100',
            'cost'                => 0,
            'quantity'            => '0',
            'low_stock_threshold' => 0,
        ]);

        $this->assertNotNull($captured);
        $this->assertSame(0.0, (float)$captured['quantity']);
        $this->assertSame(0.0, (float)$captured['cost']);
        $this->assertSame(0, (int)$captured['low_stock_threshold']);
    }

    /**
     * 5. Explicit nonzero values remain unchanged.
     */
    public function testExplicitNonzeroValuesPreserved(): void
    {
        $captured = null;
        $pdo = $this->createMockPdo($captured);
        $model = new Product($pdo);

        $model->create([
            'name'                => 'PROD_NONZERO',
            'barcode'             => '114700040',
            'price'               => '150.75',
            'cost'                => '99.50',
            'quantity'            => '15.25',
            'low_stock_threshold' => '10',
        ]);

        $this->assertNotNull($captured);
        $this->assertSame(150.75, (float)$captured['price']);
        $this->assertSame(99.50, (float)$captured['cost']);
        $this->assertSame(15.25, (float)$captured['quantity']);
        $this->assertSame(10, (int)$captured['low_stock_threshold']);
    }

    /**
     * Test ProductRequest validation normalizes empty string numeric inputs.
     */
    public function testProductRequestNormalizesEmptyNumericInputs(): void
    {
        $request = new ProductRequest([
            'name'                => 'VALID_PROD',
            'barcode'             => '9990001',
            'price'               => '50',
            'cost'                => '',
            'quantity'            => '',
            'low_stock_threshold' => '',
        ]);
        $validated = $request->validated();

        $this->assertSame(0.0, $validated['cost']);
        $this->assertSame(0.0, $validated['quantity']);
        $this->assertSame(5, $validated['low_stock_threshold']);
    }

    /**
     * Test Product::update also coerces empty strings to numeric defaults.
     */
    public function testUpdateCoercesEmptyStringNumericFieldsToValidNumbers(): void
    {
        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(PDOStatement::class);

        $capturedParams = null;
        $stmt->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function ($params) use (&$capturedParams) {
                $capturedParams = $params;
                return true;
            });

        $pdo->method('prepare')->willReturn($stmt);
        $model = new Product($pdo);

        $model->update(101, [
            'name'                => 'BASELINE_UPDATE',
            'barcode'             => '114700001',
            'price'               => '100',
            'cost'                => '',
            'quantity'            => '',
            'low_stock_threshold' => '',
        ]);

        $this->assertNotNull($capturedParams);
        $this->assertSame(0.0, (float)$capturedParams['quantity']);
        $this->assertSame(0.0, (float)$capturedParams['cost']);
        $this->assertSame(5, (int)$capturedParams['low_stock_threshold']);
    }
}
