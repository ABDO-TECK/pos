<?php

namespace Tests\Unit;

use App\Repositories\CustomerRepository;
use App\Repositories\InventoryEventRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\ProductRepository;
use App\Services\SaleService;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class SaleIdempotencyTest extends TestCase
{
    private const KEY = 'f60dc5d8-3f64-4a67-9bd5-76e88e706db4';

    public function testCanonicalHashIgnoresObjectKeyOrderAndTheIdempotencyKey(): void
    {
        $service = $this->makeService();

        $first = [
            'idempotency_key' => self::KEY,
            'payment_method' => 'cash',
            'items' => [['quantity' => 1, 'product_id' => 7]],
        ];
        $second = [
            'items' => [['product_id' => 7, 'quantity' => 1]],
            'payment_method' => 'cash',
            'idempotency_key' => '502c434e-bf20-4cd3-807d-f33093816db6',
        ];

        $this->assertSame(
            $service->hashSaleRequest($first),
            $service->hashSaleRequest($second)
        );
    }

    public function testSameKeyAndPayloadTwiceMutatesInvoiceAndStockOnlyOnce(): void
    {
        $invoiceRepo = $this->createMock(InvoiceRepository::class);
        $productRepo = $this->createMock(ProductRepository::class);
        $customerRepo = $this->createMock(CustomerRepository::class);
        $inventoryRepo = $this->createMock(InventoryEventRepository::class);
        $pdo = $this->createMock(PDO::class);
        $service = new SaleService($invoiceRepo, $productRepo, $customerRepo, $inventoryRepo, $pdo);

        $data = [
            'idempotency_key' => self::KEY,
            'items' => [['product_id' => 1, 'quantity' => 2, 'price' => 10]],
            'payment_method' => 'cash',
            'amount_paid' => 20,
            'status' => 'completed',
        ];
        $requestHash = $service->hashSaleRequest($data);
        $storedResponseJson = null;
        $claimCount = 0;
        $duplicate = new PDOException('Duplicate entry for branch idempotency key');
        $duplicate->errorInfo = ['23000', 1062, 'Duplicate entry'];

        $pdo->expects($this->exactly(2))->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('commit')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $invoiceRepo->expects($this->exactly(2))
            ->method('claimIdempotency')
            ->with(self::KEY, $requestHash)
            ->willReturnCallback(function () use (&$claimCount, $duplicate): void {
                $claimCount++;
                if ($claimCount === 2) {
                    throw $duplicate;
                }
            });
        $invoiceRepo->expects($this->once())->method('create')->willReturn(77);
        $invoiceRepo->expects($this->once())->method('addItem');
        $invoiceRepo->expects($this->once())
            ->method('findById')
            ->with(77)
            ->willReturn(['id' => 77, 'status' => 'completed', 'items' => []]);
        $invoiceRepo->expects($this->once())
            ->method('completeIdempotency')
            ->willReturnCallback(
                function (
                    string $key,
                    string $hash,
                    int $invoiceId,
                    int $code,
                    string $message,
                    string $responseJson
                ) use (&$storedResponseJson, $requestHash): void {
                    $this->assertSame(self::KEY, $key);
                    $this->assertSame($requestHash, $hash);
                    $this->assertSame(77, $invoiceId);
                    $this->assertSame(201, $code);
                    $this->assertSame('Sale completed', $message);
                    $storedResponseJson = $responseJson;
                }
            );
        $invoiceRepo->expects($this->once())
            ->method('findIdempotency')
            ->with(self::KEY)
            ->willReturnCallback(function () use (&$storedResponseJson, $requestHash): array {
                return [
                    'request_hash' => $requestHash,
                    'invoice_id' => 77,
                    'response_code' => 201,
                    'response_message' => 'Sale completed',
                    'response_json' => $storedResponseJson,
                    'completed_at' => '2026-07-28 12:00:00',
                ];
            });

        $productRepo->expects($this->once())
            ->method('batchDecrementQuantity')
            ->with([['product_id' => 1, 'quantity' => 2.0]]);
        $inventoryRepo->expects($this->once())->method('record');
        $customerRepo->expects($this->never())->method('addLedgerEntry');

        $items = [[
            'product_id' => 1,
            'quantity' => 2.0,
            'price' => 10.0,
            'unit_cost' => 5.0,
            'product' => ['id' => 1, 'quantity' => 10.0],
        ]];
        $totals = [
            'subtotal' => 20.0,
            'discount' => 0.0,
            'tax' => 0.0,
            'shipping_cost' => 0.0,
            'total' => 20.0,
            'amount_paid' => 20.0,
            'change_due' => 0.0,
            'amount_due' => 0.0,
            'customer_id' => null,
            'is_credit_sale' => false,
            'deposit' => 0.0,
        ];

        $first = $service->processSale($items, $totals, $data, ['id' => 9]);
        $second = $service->processSale($items, $totals, $data, ['id' => 9]);

        $this->assertTrue($first['ok']);
        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['replayed']);
        $this->assertSame(77, $second['invoice_id']);
        $this->assertSame($first['response_data'], $second['response_data']);
    }

    public function testSameKeyWithChangedPayloadReturnsConflict(): void
    {
        $invoiceRepo = $this->createMock(InvoiceRepository::class);
        $service = $this->makeService($invoiceRepo);
        $originalHash = $service->hashSaleRequest([
            'idempotency_key' => self::KEY,
            'items' => [['product_id' => 1, 'quantity' => 1]],
            'payment_method' => 'cash',
        ]);
        $changedHash = $service->hashSaleRequest([
            'idempotency_key' => self::KEY,
            'items' => [['product_id' => 1, 'quantity' => 2]],
            'payment_method' => 'cash',
        ]);

        $invoiceRepo->expects($this->once())
            ->method('findIdempotency')
            ->with(self::KEY)
            ->willReturn([
                'request_hash' => $originalHash,
                'invoice_id' => 77,
                'response_json' => '{"invoice":{"id":77},"low_stock_alerts":[]}',
                'completed_at' => '2026-07-28 12:00:00',
            ]);

        $result = $service->resolveIdempotency(self::KEY, $changedHash);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame(409, $result['code']);
    }

    public function testReservedInvoiceReplayReturnsItsOriginalSnapshot(): void
    {
        $invoiceRepo = $this->createMock(InvoiceRepository::class);
        $service = $this->makeService($invoiceRepo);
        $data = [
            'idempotency_key' => self::KEY,
            'invoice_id' => 45,
            'items' => [['product_id' => 1, 'quantity' => 1]],
            'payment_method' => 'cash',
            'status' => 'completed',
        ];
        $hash = $service->hashSaleRequest($data);
        $originalSnapshot = [
            'invoice' => ['id' => 45, 'status' => 'completed', 'total' => '10.00'],
            'low_stock_alerts' => [],
        ];

        $invoiceRepo->method('findIdempotency')->willReturn([
            'request_hash' => $hash,
            'invoice_id' => 45,
            'response_code' => 200,
            'response_message' => 'Invoice updated',
            'response_json' => json_encode($originalSnapshot, JSON_THROW_ON_ERROR),
            'completed_at' => '2026-07-28 12:00:00',
        ]);

        $result = $service->resolveIdempotency(self::KEY, $hash);

        $this->assertSame('replay', $result['status']);
        $this->assertSame($originalSnapshot, $result['data']);
        $this->assertSame(200, $result['code']);
    }

    private function makeService(?InvoiceRepository $invoiceRepo = null): SaleService
    {
        return new SaleService(
            $invoiceRepo ?? $this->createMock(InvoiceRepository::class),
            $this->createMock(ProductRepository::class),
            $this->createMock(CustomerRepository::class),
            $this->createMock(InventoryEventRepository::class),
            $this->createMock(PDO::class)
        );
    }
}
