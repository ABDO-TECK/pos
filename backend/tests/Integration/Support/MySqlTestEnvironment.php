<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use PDO;
use RuntimeException;

final class MySqlTestEnvironment
{
    /** @return array{host:string,port:int,user:string,password:string,database:string} */
    public static function configuration(?string $database = null): array
    {
        $port = (int) (getenv('DB_PORT') ?: 3307);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('DB_PORT must be between 1 and 65535.');
        }

        return [
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => $port,
            'user' => getenv('DB_USER') ?: (getenv('DB_USERNAME') ?: 'root'),
            'password' => getenv('DB_PASS') !== false
                ? (string) getenv('DB_PASS')
                : (getenv('DB_PASSWORD') ?: ''),
            'database' => $database ?? (getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: 'pos')),
        ];
    }

    public static function connect(?string $database = null): PDO
    {
        $configuration = self::configuration($database);
        $databasePart = $database === null ? '' : ';dbname=' . $configuration['database'];
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d%s;charset=utf8mb4',
                $configuration['host'],
                $configuration['port'],
                $databasePart
            ),
            $configuration['user'],
            $configuration['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 3,
            ]
        );
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');

        return $pdo;
    }

    public static function createDatabase(string $prefix): string
    {
        $database = sprintf('%s_%s', $prefix, bin2hex(random_bytes(6)));
        self::assertSafeDatabaseName($database);
        self::connect()->exec(
            sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $database)
        );

        return $database;
    }

    public static function dropDatabase(string $database): void
    {
        self::assertSafeDatabaseName($database);
        self::connect()->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
    }

    public static function createMigrationPrerequisites(PDO $pdo): void
    {
        self::executeStatements($pdo, [
            'CREATE TABLE branches (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB',
            'CREATE TABLE invoices (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                branch_id INT NOT NULL,
                KEY idx_invoices_branch (branch_id),
                CONSTRAINT fk_test_invoice_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
            ) ENGINE=InnoDB',
            'CREATE TABLE products (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                branch_id INT NOT NULL,
                name VARCHAR(200) NOT NULL,
                KEY idx_products_branch (branch_id),
                CONSTRAINT fk_test_product_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
            ) ENGINE=InnoDB',
        ]);
    }

    public static function createConcurrencySchema(PDO $pdo): void
    {
        self::executeStatements($pdo, [
            'CREATE TABLE branches (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB',
            'CREATE TABLE users (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                branch_id INT NOT NULL,
                name VARCHAR(150) NOT NULL,
                KEY idx_users_branch (branch_id),
                CONSTRAINT fk_test_user_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
            ) ENGINE=InnoDB',
            'CREATE TABLE categories (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB',
            'CREATE TABLE products (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                branch_id INT NOT NULL,
                name VARCHAR(200) NOT NULL,
                barcode VARCHAR(100) NOT NULL UNIQUE,
                box_barcode VARCHAR(100) NULL,
                price DECIMAL(10,2) NOT NULL DEFAULT 0,
                cost DECIMAL(10,2) NOT NULL DEFAULT 0,
                quantity DECIMAL(10,3) NOT NULL DEFAULT 0,
                low_stock_threshold DECIMAL(10,3) NOT NULL DEFAULT 5,
                category_id INT NULL,
                parent_product_id INT NULL,
                size_name VARCHAR(100) NULL,
                unit_type VARCHAR(20) NOT NULL DEFAULT \'piece\',
                sell_by_weight TINYINT(1) NOT NULL DEFAULT 0,
                deleted_at TIMESTAMP NULL,
                KEY idx_products_branch (branch_id),
                CONSTRAINT fk_test_product_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
                CONSTRAINT fk_test_product_category FOREIGN KEY (category_id) REFERENCES categories(id)
            ) ENGINE=InnoDB',
            'CREATE TABLE product_barcodes (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                barcode VARCHAR(100) NOT NULL UNIQUE,
                CONSTRAINT fk_test_barcode_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB',
            'CREATE TABLE customers (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                phone VARCHAR(30) NULL,
                address VARCHAR(255) NULL,
                initial_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
                deleted_at TIMESTAMP NULL
            ) ENGINE=InnoDB',
            'CREATE TABLE invoices (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                branch_id INT NOT NULL,
                user_id INT NOT NULL,
                customer_id INT NULL,
                subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
                discount DECIMAL(12,2) NOT NULL DEFAULT 0,
                tax DECIMAL(12,2) NOT NULL DEFAULT 0,
                shipping_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
                total DECIMAL(12,2) NOT NULL DEFAULT 0,
                payment_method VARCHAR(30) NOT NULL DEFAULT \'cash\',
                amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
                change_due DECIMAL(12,2) NOT NULL DEFAULT 0,
                amount_due DECIMAL(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL DEFAULT \'completed\',
                driver_name VARCHAR(150) NULL,
                vehicle_number VARCHAR(100) NULL,
                delivery_date DATE NULL,
                delivery_notes TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_invoices_branch (branch_id),
                CONSTRAINT fk_test_invoice_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
                CONSTRAINT fk_test_invoice_user FOREIGN KEY (user_id) REFERENCES users(id),
                CONSTRAINT fk_test_invoice_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
            ) ENGINE=InnoDB',
            'CREATE TABLE invoice_items (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                invoice_id INT NOT NULL,
                product_id INT NOT NULL,
                quantity DECIMAL(10,3) NOT NULL,
                price DECIMAL(12,2) NOT NULL,
                unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
                subtotal DECIMAL(12,2) NOT NULL,
                CONSTRAINT fk_test_item_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
                CONSTRAINT fk_test_item_product FOREIGN KEY (product_id) REFERENCES products(id)
            ) ENGINE=InnoDB',
            'CREATE TABLE suppliers (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                phone VARCHAR(30) NULL,
                email VARCHAR(150) NULL,
                address VARCHAR(255) NULL,
                initial_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
                deleted_at TIMESTAMP NULL
            ) ENGINE=InnoDB',
            'CREATE TABLE purchase_invoices (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                branch_id INT NOT NULL,
                supplier_id INT NOT NULL,
                total DECIMAL(12,2) NOT NULL DEFAULT 0,
                items_count INT NOT NULL DEFAULT 0,
                notes TEXT NULL,
                driver_name VARCHAR(150) NULL,
                vehicle_number VARCHAR(100) NULL,
                delivery_date DATE NULL,
                delivery_notes TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_purchase_invoice_branch (branch_id),
                CONSTRAINT fk_test_purchase_invoice_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
                CONSTRAINT fk_test_purchase_invoice_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
            ) ENGINE=InnoDB',
            'CREATE TABLE purchases (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                purchase_invoice_id INT NULL,
                supplier_id INT NOT NULL,
                product_id INT NOT NULL,
                quantity DECIMAL(10,3) NOT NULL,
                cost DECIMAL(12,2) NOT NULL,
                total DECIMAL(12,2) NOT NULL,
                notes TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_test_purchase_invoice FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL,
                CONSTRAINT fk_test_purchase_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
                CONSTRAINT fk_test_purchase_product FOREIGN KEY (product_id) REFERENCES products(id)
            ) ENGINE=InnoDB',
            'CREATE TABLE customer_ledger (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                description TEXT NULL,
                invoice_id INT NULL,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_test_customer_ledger_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
                CONSTRAINT fk_test_customer_ledger_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
            ) ENGINE=InnoDB',
            'CREATE TABLE supplier_ledger (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                supplier_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                description TEXT NULL,
                purchase_invoice_id INT NULL,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_test_supplier_ledger_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
                CONSTRAINT fk_test_supplier_ledger_invoice FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL
            ) ENGINE=InnoDB',
            'CREATE TABLE inventory_events (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                branch_id INT NOT NULL,
                product_id INT NOT NULL,
                action VARCHAR(30) NOT NULL,
                quantity DECIMAL(10,3) NOT NULL,
                delta DECIMAL(10,3) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_test_event_product FOREIGN KEY (product_id) REFERENCES products(id)
            ) ENGINE=InnoDB',
        ]);
    }

    public static function applyMigration(PDO $pdo, string $relativePath): void
    {
        $path = dirname(__DIR__, 4) . '/' . ltrim($relativePath, '/');
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException(sprintf('Unable to read migration %s.', $relativePath));
        }

        foreach (self::splitMigrationStatements($sql) as $statement) {
            $pdo->exec($statement);
        }
    }

    /** @return list<string> */
    public static function splitMigrationStatements(string $sql): array
    {
        $withoutLineComments = preg_replace('/^\s*(?:--|#).*$(?:\R|$)/mu', '', $sql);
        if ($withoutLineComments === null) {
            throw new RuntimeException('Unable to parse migration SQL comments.');
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/;\s*(?:\r?\n|$)/u', $withoutLineComments) ?: []),
            static fn (string $statement): bool => $statement !== '',
        ));
    }

    /** @return array<string,string> */
    public static function workerEnvironment(string $database, array $extra = []): array
    {
        $configuration = self::configuration($database);

        return array_merge([
            'MYSQL_TEST_HOST' => $configuration['host'],
            'MYSQL_TEST_PORT' => (string) $configuration['port'],
            'MYSQL_TEST_USER' => $configuration['user'],
            'MYSQL_TEST_PASSWORD' => $configuration['password'],
            'MYSQL_TEST_DATABASE' => $database,
        ], $extra);
    }

    public static function waitForLockWait(PDO $pdo, int $connectionId, float $timeoutSeconds = 8.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $statement = $pdo->prepare(
            'SELECT trx_state FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ?'
        );
        do {
            $statement->execute([$connectionId]);
            if ($statement->fetchColumn() === 'LOCK WAIT') {
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(
            sprintf('Connection %d did not enter an InnoDB row-lock wait within %.1f seconds.', $connectionId, $timeoutSeconds)
        );
    }

    /** @param list<string> $statements */
    private static function executeStatements(PDO $pdo, array $statements): void
    {
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }

    private static function assertSafeDatabaseName(string $database): void
    {
        if (preg_match('/^[a-z0-9_]+$/D', $database) !== 1) {
            throw new RuntimeException('Unsafe disposable database name.');
        }
    }
}

final class MySqlWorkerProcess
{
    /** @var resource */
    private $process;
    /** @var array<int,resource> */
    private array $pipes;
    /** @var resource */
    private $control;
    /** @var list<array<string,mixed>> */
    private array $queuedEvents = [];
    private string $controlBuffer = '';
    private bool $closed = false;

    /** @param array<string,string> $environment */
    public function __construct(string $mode, array $environment)
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($server === false) {
            throw new RuntimeException("Unable to create worker barrier: {$errorMessage} ({$errorCode}).");
        }
        $address = stream_socket_get_name($server, false);
        if ($address === false) {
            fclose($server);
            throw new RuntimeException('Unable to resolve worker barrier address.');
        }

        $fixture = dirname(__DIR__, 2) . '/Fixtures/mysql_concurrency_worker.php';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $workerEnvironment = array_merge(getenv(), $environment, ['MYSQL_TEST_CONTROL' => $address]);
        $process = proc_open([PHP_BINARY, $fixture, $mode], $descriptors, $pipes, null, $workerEnvironment);
        if (!is_resource($process)) {
            fclose($server);
            throw new RuntimeException('Unable to start MySQL concurrency worker.');
        }
        $control = stream_socket_accept($server, 5);
        fclose($server);
        if ($control === false) {
            proc_terminate($process);
            throw new RuntimeException('MySQL concurrency worker did not connect to its barrier within 5 seconds.');
        }

        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        $this->control = $control;
        stream_set_blocking($this->control, false);
        $this->waitForEvent('ready', 5.0);
    }

    /** @return array<string,mixed> */
    public function waitForEvent(string $eventName, float $timeoutSeconds = 10.0): array
    {
        $queued = $this->takeQueuedEvent($eventName);
        if ($queued !== null) {
            return $queued;
        }

        $deadline = microtime(true) + $timeoutSeconds;
        do {
            while (($newline = strpos($this->controlBuffer, "\n")) !== false) {
                $line = substr($this->controlBuffer, 0, $newline);
                $this->controlBuffer = substr($this->controlBuffer, $newline + 1);
                $event = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
                if (($event['event'] ?? null) === 'error') {
                    throw new RuntimeException('MySQL worker failed: ' . ($event['message'] ?? 'unknown error'));
                }
                if (($event['event'] ?? null) === $eventName) {
                    return $event;
                }
                $this->queuedEvents[] = $event;
            }

            $remaining = max(0.0, $deadline - microtime(true));
            $seconds = (int) floor($remaining);
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $read = [$this->control];
            $write = null;
            $except = null;
            $selected = stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected === false) {
                throw new RuntimeException('Unable to wait on MySQL worker barrier.');
            }
            if ($selected === 0) {
                break;
            }

            $chunk = fread($this->control, 8192);
            if ($chunk === false || ($chunk === '' && feof($this->control))) {
                break;
            }
            $this->controlBuffer .= $chunk;
        } while (microtime(true) < $deadline);

        throw new RuntimeException(
            sprintf("Timed out waiting for MySQL worker event '%s'.%s", $eventName, $this->diagnostics())
        );
    }

    public function send(string $command): void
    {
        $encoded = json_encode(['command' => $command], JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($this->control, $encoded) !== strlen($encoded)) {
            throw new RuntimeException("Unable to send '{$command}' to MySQL worker.");
        }
        fflush($this->control);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        fclose($this->control);
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            proc_terminate($this->process);
        }
        proc_close($this->process);
    }

    public function __destruct()
    {
        $this->close();
    }

    private function diagnostics(): string
    {
        $stdout = stream_get_contents($this->pipes[1]);
        $stderr = stream_get_contents($this->pipes[2]);
        $details = trim((string) $stdout . "\n" . (string) $stderr);
        return $details === '' ? '' : " Worker output: {$details}";
    }

    /** @return array<string,mixed>|null */
    private function takeQueuedEvent(string $eventName): ?array
    {
        foreach ($this->queuedEvents as $index => $event) {
            if (($event['event'] ?? null) === $eventName) {
                array_splice($this->queuedEvents, $index, 1);
                return $event;
            }
        }

        return null;
    }
}
