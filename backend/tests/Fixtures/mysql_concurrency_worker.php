<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\InventoryEvent;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Repositories\CustomerRepository;
use App\Repositories\InventoryEventRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\ProductRepository;
use App\Repositories\SupplierRepository;
use App\Services\InventoryService;
use App\Services\SaleService;
require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc !== 2) {
    fwrite(STDERR, "Expected one worker mode.\n");
    exit(2);
}

/** @var resource|null $control */
$control = null;

try {
    $controlAddress = requiredEnvironment('MYSQL_TEST_CONTROL');
    $control = stream_socket_client('tcp://' . $controlAddress, $errorCode, $errorMessage, 5);
    if ($control === false) {
        throw new RuntimeException("Unable to connect to barrier: {$errorMessage} ({$errorCode}).");
    }
    stream_set_timeout($control, 15);

    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            requiredEnvironment('MYSQL_TEST_HOST'),
            requiredEnvironment('MYSQL_TEST_PORT'),
            requiredEnvironment('MYSQL_TEST_DATABASE')
        ),
        requiredEnvironment('MYSQL_TEST_USER'),
        getenv('MYSQL_TEST_PASSWORD') === false ? '' : (string) getenv('MYSQL_TEST_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]
    );
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
    $connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();

    sendEvent($control, ['event' => 'ready', 'connection_id' => $connectionId]);
    expectCommand($control, 'go');

    $payload = json_decode(
        base64_decode(requiredEnvironment('MYSQL_TEST_PAYLOAD'), true) ?: '',
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $mode = $argv[1];
    $result = match ($mode) {
        'sale_create' => runSale($pdo, $payload),
        'sale_replace' => runSale($pdo, $payload, new ControlledInvoiceRepository(
            new Invoice($pdo),
            static function () use ($control, $connectionId): void {
                sendEvent($control, ['event' => 'locked', 'connection_id' => $connectionId]);
                expectCommand($control, 'release');
            },
            false
        )),
        'sale_delete' => runSaleDelete($pdo, $payload, new ControlledInvoiceRepository(
            new Invoice($pdo),
            static function () use ($control, $connectionId): void {
                sendEvent($control, ['event' => 'attempting', 'connection_id' => $connectionId]);
            },
            true,
            static function () use ($control, $connectionId): void {
                sendEvent($control, ['event' => 'locked', 'connection_id' => $connectionId]);
            }
        )),
        'purchase_replace' => runPurchase($pdo, $payload, new ControlledSupplierRepository(
            new Supplier($pdo),
            new PurchaseInvoice($pdo),
            new SupplierLedger($pdo, new Supplier($pdo)),
            static function () use ($control, $connectionId): void {
                sendEvent($control, ['event' => 'locked', 'connection_id' => $connectionId]);
                expectCommand($control, 'release');
            },
            false
        )),
        'purchase_delete' => runPurchaseDelete($pdo, $payload, new ControlledSupplierRepository(
            new Supplier($pdo),
            new PurchaseInvoice($pdo),
            new SupplierLedger($pdo, new Supplier($pdo)),
            static function () use ($control, $connectionId): void {
                sendEvent($control, ['event' => 'attempting', 'connection_id' => $connectionId]);
            },
            true,
            static function () use ($control, $connectionId): void {
                sendEvent($control, ['event' => 'locked', 'connection_id' => $connectionId]);
            }
        )),
        default => throw new RuntimeException("Unknown worker mode '{$mode}'."),
    };

    sendEvent($control, ['event' => 'result', 'result' => $result]);
    fclose($control);
} catch (Throwable $exception) {
    if (is_resource($control)) {
        sendEvent($control, ['event' => 'error', 'message' => $exception->getMessage()]);
    }
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
}

/** @param array<string,mixed> $payload */
function runSale(PDO $pdo, array $payload, ?InvoiceRepository $invoiceRepository = null): array
{
    $service = new SaleService(
        $invoiceRepository ?? new InvoiceRepository(new Invoice($pdo)),
        new ProductRepository(new Product($pdo)),
        new CustomerRepository(new Customer($pdo)),
        new InventoryEventRepository(new InventoryEvent($pdo)),
        $pdo
    );

    return $service->processSale(
        $payload['items'],
        $payload['totals'],
        $payload['data'],
        $payload['auth']
    );
}

/** @param array<string,mixed> $payload */
function runSaleDelete(PDO $pdo, array $payload, InvoiceRepository $invoiceRepository): array
{
    $service = new SaleService(
        $invoiceRepository,
        new ProductRepository(new Product($pdo)),
        new CustomerRepository(new Customer($pdo)),
        new InventoryEventRepository(new InventoryEvent($pdo)),
        $pdo
    );

    return $service->deleteInvoice((int) $payload['invoice_id']);
}

/** @param array<string,mixed> $payload */
function runPurchase(PDO $pdo, array $payload, SupplierRepository $supplierRepository): array
{
    $service = new InventoryService(new Product($pdo), $supplierRepository, $pdo);
    return $service->processBulkPurchase($payload['data'], $payload['auth']);
}

/** @param array<string,mixed> $payload */
function runPurchaseDelete(PDO $pdo, array $payload, SupplierRepository $supplierRepository): array
{
    $service = new InventoryService(new Product($pdo), $supplierRepository, $pdo);
    return $service->deletePurchaseInvoice((int) $payload['invoice_id']);
}

function requiredEnvironment(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        throw new RuntimeException("{$name} is required.");
    }
    return $value;
}

/** @param resource $control @param array<string,mixed> $event */
function sendEvent($control, array $event): void
{
    $line = json_encode($event, JSON_THROW_ON_ERROR) . "\n";
    if (fwrite($control, $line) !== strlen($line)) {
        throw new RuntimeException('Unable to send worker event.');
    }
    fflush($control);
}

/** @param resource $control */
function expectCommand($control, string $expected): void
{
    $line = fgets($control);
    if ($line === false) {
        throw new RuntimeException("Timed out waiting for '{$expected}' barrier command.");
    }
    $command = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
    if (($command['command'] ?? null) !== $expected) {
        throw new RuntimeException("Expected '{$expected}' barrier command.");
    }
}

final class ControlledInvoiceRepository extends InvoiceRepository
{
    public function __construct(
        Invoice $model,
        private readonly Closure $beforeOrAfterLock,
        private readonly bool $notifyBeforeLock,
        private readonly ?Closure $afterLock = null
    ) {
        parent::__construct($model);
    }

    public function findByIdForUpdate(int $id): ?array
    {
        if ($this->notifyBeforeLock) {
            ($this->beforeOrAfterLock)();
        }
        $invoice = parent::findByIdForUpdate($id);
        if (!$this->notifyBeforeLock) {
            ($this->beforeOrAfterLock)();
        }
        if ($this->afterLock !== null) {
            ($this->afterLock)();
        }

        return $invoice;
    }
}

final class ControlledSupplierRepository extends SupplierRepository
{
    public function __construct(
        Supplier $model,
        PurchaseInvoice $purchaseInvoiceModel,
        SupplierLedger $ledgerModel,
        private readonly Closure $beforeOrAfterLock,
        private readonly bool $notifyBeforeLock,
        private readonly ?Closure $afterLock = null
    ) {
        parent::__construct($model, $purchaseInvoiceModel, $ledgerModel);
    }

    public function getPurchaseInvoiceHeaderForUpdate(int $id): ?array
    {
        if ($this->notifyBeforeLock) {
            ($this->beforeOrAfterLock)();
        }
        $invoice = parent::getPurchaseInvoiceHeaderForUpdate($id);
        if (!$this->notifyBeforeLock) {
            ($this->beforeOrAfterLock)();
        }
        if ($this->afterLock !== null) {
            ($this->afterLock)();
        }

        return $invoice;
    }
}
