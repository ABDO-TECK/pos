<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlTestEnvironment;
use Tests\Integration\Support\MySqlWorkerProcess;

require_once __DIR__ . '/Support/MySqlTestEnvironment.php';

#[Group('mysql')]
final class MySqlConcurrencyTest extends TestCase
{
    private static string $database;
    private static ?PDO $pdo = null;

    /** @var list<MySqlWorkerProcess> */
    private array $workers = [];

    public static function setUpBeforeClass(): void
    {
        self::$database = MySqlTestEnvironment::createDatabase('pos_concurrency_test');
        try {
            self::$pdo = MySqlTestEnvironment::connect(self::$database);
            MySqlTestEnvironment::createConcurrencySchema(self::$pdo);
            MySqlTestEnvironment::applyMigration(self::$pdo, 'database/migrations/042_add_sale_idempotency.sql');
            MySqlTestEnvironment::applyMigration(self::$pdo, 'database/migrations/043_add_product_catalog_changes.sql');
        } catch (\Throwable $exception) {
            MySqlTestEnvironment::dropDatabase(self::$database);
            throw $exception;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo = null;
        if (isset(self::$database)) {
            MySqlTestEnvironment::dropDatabase(self::$database);
        }
    }

    protected function setUp(): void
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'sale_idempotency_keys',
            'inventory_events',
            'customer_ledger',
            'supplier_ledger',
            'invoice_items',
            'purchases',
            'invoices',
            'purchase_invoices',
            'customers',
            'suppliers',
            'product_barcodes',
            'product_catalog_changes',
            'products',
            'categories',
            'users',
            'branches',
        ] as $table) {
            self::$pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        self::$pdo->exec("INSERT INTO branches (id, name) VALUES (1, 'Concurrency branch')");
        self::$pdo->exec("INSERT INTO users (id, branch_id, name) VALUES (1, 1, 'Concurrency cashier')");
        self::$pdo->exec(
            "INSERT INTO products
                (id, branch_id, name, barcode, price, cost, quantity, low_stock_threshold)
             VALUES (1, 1, 'Concurrency product', 'concurrency-product', 10, 5, 100, 5)"
        );
        self::$pdo->exec(
            "INSERT INTO suppliers (id, name, initial_balance) VALUES (1, 'Concurrency supplier', 0)"
        );
        self::$pdo->exec('TRUNCATE TABLE product_catalog_changes');
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->close();
        }
        $this->workers = [];
    }

    public function testSimultaneousIdenticalSalesAreAppliedOnceAndChangedPayloadConflicts(): void
    {
        $key = 'a6d27ba4-74bb-47ce-b6ea-4c576573e6cf';
        $payload = $this->salePayload($key, 2.0);
        $first = $this->worker('sale_create', $payload);
        $second = $this->worker('sale_create', $payload);

        $first->send('go');
        $second->send('go');
        $firstResult = $first->waitForEvent('result')['result'];
        $secondResult = $second->waitForEvent('result')['result'];

        self::assertTrue($firstResult['ok']);
        self::assertTrue($secondResult['ok']);
        self::assertSame($firstResult['invoice_id'], $secondResult['invoice_id']);
        self::assertSame([false, true], $this->sortedBooleans([
            $firstResult['replayed'],
            $secondResult['replayed'],
        ]));
        $this->assertSingleSaleEffect(98.0);

        $changed = $this->worker('sale_create', $this->salePayload($key, 3.0));
        $changed->send('go');
        $conflict = $changed->waitForEvent('result')['result'];

        self::assertFalse($conflict['ok']);
        self::assertSame(409, $conflict['code']);
        self::assertTrue($conflict['idempotency_conflict']);
        $this->assertSingleSaleEffect(98.0);
    }

    public function testSaleReplacementSerializesBeforeDeletionAndDeletionUsesOneAffectedHeader(): void
    {
        $invoiceId = $this->seedSaleInvoice(10.0);
        $replacement = $this->worker(
            'sale_replace',
            $this->salePayload('a6fc3fb4-efac-4e42-b204-f91072d90f5b', 4.0, $invoiceId)
        );
        $replacement->send('go');
        $replacement->waitForEvent('locked');

        $deletion = $this->worker('sale_delete', ['invoice_id' => $invoiceId]);
        $deletion->send('go');
        $attempting = $deletion->waitForEvent('attempting');
        MySqlTestEnvironment::waitForLockWait(self::$pdo, (int) $attempting['connection_id']);

        $replacement->send('release');
        $replacementResult = $replacement->waitForEvent('result')['result'];
        $deletion->waitForEvent('locked');
        $deletionResult = $deletion->waitForEvent('result')['result'];

        self::assertTrue($replacementResult['ok']);
        self::assertTrue($replacementResult['is_update']);
        self::assertTrue($deletionResult['ok']);
        self::assertSame(0, $this->countRows('invoices'));
        self::assertSame(0, $this->countRows('invoice_items'));
        self::assertSame(100.0, $this->productQuantity());
        self::assertSame(3, $this->countRows('inventory_events'));
    }

    public function testPurchaseReplacementSerializesBeforeDeletionAndDeletionUsesOneAffectedHeader(): void
    {
        $invoiceId = $this->seedPurchaseInvoice(10.0);
        $replacement = $this->worker('purchase_replace', [
            'data' => [
                'supplier_id' => 1,
                'replace_invoice_id' => $invoiceId,
                'payment_type' => 'cash',
                'items' => [['product_id' => 1, 'quantity' => 4.0, 'cost' => 5.0]],
            ],
            'auth' => ['id' => 1],
        ]);
        $replacement->send('go');
        $replacement->waitForEvent('locked');

        $deletion = $this->worker('purchase_delete', ['invoice_id' => $invoiceId]);
        $deletion->send('go');
        $attempting = $deletion->waitForEvent('attempting');
        MySqlTestEnvironment::waitForLockWait(self::$pdo, (int) $attempting['connection_id']);

        $replacement->send('release');
        $replacementResult = $replacement->waitForEvent('result')['result'];
        $deletion->waitForEvent('locked');
        $deletionResult = $deletion->waitForEvent('result')['result'];

        self::assertTrue($replacementResult['ok']);
        self::assertTrue($replacementResult['is_update']);
        self::assertTrue($deletionResult['ok']);
        self::assertSame(0, $this->countRows('purchase_invoices'));
        self::assertSame(0, $this->countRows('purchases'));
        self::assertSame(0, $this->countRows('supplier_ledger'));
        self::assertSame(100.0, $this->productQuantity());
    }

    /** @return array<string,mixed> */
    private function salePayload(string $key, float $quantity, ?int $invoiceId = null): array
    {
        $data = [
            'idempotency_key' => $key,
            'items' => [['product_id' => 1, 'quantity' => $quantity]],
            'payment_method' => 'cash',
            'amount_paid' => $quantity * 10,
            'status' => 'completed',
        ];
        if ($invoiceId !== null) {
            $data['invoice_id'] = $invoiceId;
        }

        return [
            'data' => $data,
            'items' => [[
                'product_id' => 1,
                'quantity' => $quantity,
                'price' => 10.0,
                'unit_cost' => 5.0,
                'product' => ['id' => 1, 'quantity' => $this->productQuantity()],
            ]],
            'totals' => [
                'subtotal' => $quantity * 10,
                'discount' => 0.0,
                'tax' => 0.0,
                'shipping_cost' => 0.0,
                'total' => $quantity * 10,
                'amount_paid' => $quantity * 10,
                'change_due' => 0.0,
                'amount_due' => 0.0,
                'customer_id' => null,
                'is_credit_sale' => false,
                'deposit' => 0.0,
            ],
            'auth' => ['id' => 1],
        ];
    }

    private function seedSaleInvoice(float $quantity): int
    {
        self::$pdo->exec('UPDATE products SET quantity = quantity - 10 WHERE id = 1');
        $statement = self::$pdo->prepare(
            "INSERT INTO invoices
                (branch_id, user_id, subtotal, total, payment_method, amount_paid, status)
             VALUES (1, 1, ?, ?, 'cash', ?, 'completed')"
        );
        $total = $quantity * 10;
        $statement->execute([$total, $total, $total]);
        $invoiceId = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare(
            'INSERT INTO invoice_items (invoice_id, product_id, quantity, price, unit_cost, subtotal)
             VALUES (?, 1, ?, 10, 5, ?)'
        )->execute([$invoiceId, $quantity, $total]);

        return $invoiceId;
    }

    private function seedPurchaseInvoice(float $quantity): int
    {
        self::$pdo->exec('UPDATE products SET quantity = quantity + 10 WHERE id = 1');
        $total = $quantity * 5;
        self::$pdo->prepare(
            'INSERT INTO purchase_invoices (branch_id, supplier_id, total, items_count)
             VALUES (1, 1, ?, 1)'
        )->execute([$total]);
        $invoiceId = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare(
            'INSERT INTO purchases (purchase_invoice_id, supplier_id, product_id, quantity, cost, total)
             VALUES (?, 1, 1, ?, 5, ?)'
        )->execute([$invoiceId, $quantity, $total]);

        return $invoiceId;
    }

    /** @param array<string,mixed> $payload */
    private function worker(string $mode, array $payload): MySqlWorkerProcess
    {
        $worker = new MySqlWorkerProcess(
            $mode,
            MySqlTestEnvironment::workerEnvironment(self::$database, [
                'MYSQL_TEST_PAYLOAD' => base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ])
        );
        $this->workers[] = $worker;

        return $worker;
    }

    private function assertSingleSaleEffect(float $expectedQuantity): void
    {
        self::assertSame(1, $this->countRows('invoices'));
        self::assertSame(1, $this->countRows('invoice_items'));
        self::assertSame(1, $this->countRows('inventory_events'));
        self::assertSame(1, $this->countRows('sale_idempotency_keys'));
        self::assertSame($expectedQuantity, $this->productQuantity());
        self::assertSame(-2.0, (float) self::$pdo->query('SELECT delta FROM inventory_events')->fetchColumn());
    }

    private function countRows(string $table): int
    {
        return (int) self::$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }

    private function productQuantity(): float
    {
        return (float) self::$pdo->query('SELECT quantity FROM products WHERE id = 1')->fetchColumn();
    }

    /** @param list<bool> $values @return list<bool> */
    private function sortedBooleans(array $values): array
    {
        sort($values);
        return $values;
    }
}
