<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Repositories\LoyaltyRepository;
use App\Services\AuthService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlTestEnvironment;

require_once __DIR__ . '/Support/MySqlTestEnvironment.php';

#[Group('mysql')]
final class BusinessPartnerBranchIsolationTest extends TestCase
{
    private static string $database;
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        self::$database = MySqlTestEnvironment::createDatabase('pos_partner_scope_test');
        try {
            self::$pdo = MySqlTestEnvironment::connect(self::$database);
            MySqlTestEnvironment::createConcurrencySchema(self::$pdo);
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
        self::$pdo->exec("INSERT INTO branches (id, name) VALUES (1, 'Branch one'), (2, 'Branch two')");
        self::$pdo->exec("INSERT INTO users (id, branch_id, name) VALUES (1, 1, 'Cashier one')");
        (new AuthService())->setBranchId(1);
    }

    protected function tearDown(): void
    {
        (new AuthService())->setBranchId(1);
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'loyalty_transactions', 'customer_ledger', 'supplier_ledger',
            'invoice_items', 'purchases', 'invoices', 'purchase_invoices',
            'customers', 'suppliers', 'inventory_events', 'product_barcodes',
            'products', 'categories', 'users', 'branches',
        ] as $table) {
            self::$pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testPartnerModelsAndLedgersRejectForeignBranchIds(): void
    {
        $customerModel = new Customer(self::$pdo);
        $supplierModel = new Supplier(self::$pdo);
        $supplierLedger = new SupplierLedger(self::$pdo, $supplierModel);

        $branchOneCustomer = $customerModel->create(['name' => 'Customer one']);
        $branchOneSupplier = $supplierModel->create(['name' => 'Supplier one']);

        (new AuthService())->setBranchId(2);
        $branchTwoCustomer = $customerModel->create(['name' => 'Customer two']);
        $branchTwoSupplier = $supplierModel->create(['name' => 'Supplier two']);
        $customerEntry = $customerModel->addLedgerEntry([
            'customer_id' => $branchTwoCustomer,
            'type' => 'debit',
            'amount' => 10,
        ]);
        $supplierEntry = $supplierLedger->addLedgerEntry([
            'supplier_id' => $branchTwoSupplier,
            'type' => 'debit',
            'amount' => 10,
        ]);

        (new AuthService())->setBranchId(1);
        self::assertNotNull($customerModel->findById($branchOneCustomer));
        self::assertNotNull($supplierModel->findById($branchOneSupplier));
        self::assertNull($customerModel->findById($branchTwoCustomer));
        self::assertNull($supplierModel->findById($branchTwoSupplier));
        self::assertNull($customerModel->getLedgerEntry($customerEntry));
        self::assertNull($supplierLedger->getLedgerEntry($supplierEntry));
    }

    public function testPartnerSearchWorksWithNativePreparedStatementsAndRemainsBranchScoped(): void
    {
        $customerModel = new Customer(self::$pdo);
        $supplierModel = new Supplier(self::$pdo);

        $customerModel->create(['name' => 'Ahmed Search', 'phone' => '01012345678']);
        $supplierModel->create(['name' => 'Cairo Supplies', 'phone' => '01112345678', 'email' => 'sales@example.test']);

        (new AuthService())->setBranchId(2);
        $customerModel->create(['name' => 'Ahmed Other Branch', 'phone' => '01099999999']);
        $supplierModel->create(['name' => 'Other Supplies', 'email' => 'sales@example.test']);

        (new AuthService())->setBranchId(1);

        $customers = $customerModel->all(['search' => 'Ahmed', 'page' => 1, 'limit' => 20]);
        self::assertCount(1, $customers['data']);
        self::assertSame('Ahmed Search', $customers['data'][0]['name']);

        $suppliers = $supplierModel->all(['search' => 'sales@example.test', 'page' => 1, 'limit' => 20]);
        self::assertCount(1, $suppliers['data']);
        self::assertSame('Cairo Supplies', $suppliers['data'][0]['name']);
    }

    public function testSalesPurchasesAndLoyaltyRejectForeignBranchPartners(): void
    {
        (new AuthService())->setBranchId(2);
        $customerId = (new Customer(self::$pdo))->create(['name' => 'Foreign customer']);
        $supplierId = (new Supplier(self::$pdo))->create(['name' => 'Foreign supplier']);

        (new AuthService())->setBranchId(1);

        try {
            (new Invoice(self::$pdo))->create([
                'user_id' => 1,
                'customer_id' => $customerId,
                'subtotal' => 10,
                'total' => 10,
            ]);
            self::fail('Cross-branch customer was accepted by a sale.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('Customer', $exception->getMessage());
        }

        try {
            (new PurchaseInvoice(self::$pdo))->createPurchaseInvoice([
                'supplier_id' => $supplierId,
                'total' => 10,
                'items_count' => 1,
            ]);
            self::fail('Cross-branch supplier was accepted by a purchase.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('Supplier', $exception->getMessage());
        }

        $loyalty = new LoyaltyRepository(self::$pdo);
        $this->expectException(\DomainException::class);
        $loyalty->updateCustomerPoints($customerId, 10);
    }
}
