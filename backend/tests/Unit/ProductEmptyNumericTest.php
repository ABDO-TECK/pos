<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Models\Product;
use App\Services\AuthService;
use PDO;
use PDOStatement;

class ProductEmptyNumericTest extends TestCase
{
    protected function setUp(): void
    {
        (new AuthService())->setBranchId(1);
    }

    public function testCreateCoercesEmptyStringNumericFieldsToValidNumbers(): void
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
        $pdo->method('lastInsertId')->willReturn('101');

        $model = new Product($pdo);

        // Exact payload sent by the UI when user creates BASELINE_FIRST_RUN with untouched quantity
        $data = [
            'name'                => 'BASELINE_FIRST_RUN',
            'barcode'             => '114700001',
            'price'               => '100',
            'cost'                => '99',
            'quantity'            => '', // Untouched / empty string from UI
            'low_stock_threshold' => '', // Cleared or empty string
            'category_id'         => null,
            'unit_type'           => 'piece',
        ];

        $model->create($data);

        $this->assertNotNull($capturedParams, 'Statement execute was not called');
        $this->assertNotSame('', $capturedParams['quantity'], 'Quantity must not be an empty string (causes MySQL 1366)');
        $this->assertNotSame('', $capturedParams['cost'], 'Cost must not be an empty string (causes MySQL 1366)');
        $this->assertNotSame('', $capturedParams['low_stock_threshold'], 'Low stock threshold must not be an empty string');
        $this->assertSame(0.0, (float)$capturedParams['quantity']);
        $this->assertSame(99.0, (float)$capturedParams['cost']);
    }

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

        $data = [
            'name'                => 'BASELINE_FIRST_RUN',
            'barcode'             => '114700001',
            'price'               => '100',
            'cost'                => '',
            'quantity'            => '',
            'low_stock_threshold' => '',
            'category_id'         => null,
            'unit_type'           => 'piece',
        ];

        $model->update(101, $data);

        $this->assertNotNull($capturedParams, 'Statement execute was not called');
        $this->assertNotSame('', $capturedParams['quantity'], 'Quantity must not be an empty string on update');
        $this->assertNotSame('', $capturedParams['cost'], 'Cost must not be an empty string on update');
        $this->assertNotSame('', $capturedParams['low_stock_threshold'], 'Low stock threshold must not be an empty string on update');
        $this->assertSame(0.0, (float)$capturedParams['quantity']);
        $this->assertSame(0.0, (float)$capturedParams['cost']);
    }
}
