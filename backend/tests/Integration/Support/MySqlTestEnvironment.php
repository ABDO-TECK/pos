<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use PDO;
use PDOException;
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
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');

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
                branch_id INT NOT NULL DEFAULT 1,
                name VARCHAR(150) NOT NULL,
                phone VARCHAR(30) NULL,
                address VARCHAR(255) NULL,
                initial_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
                loyalty_points INT NOT NULL DEFAULT 0,
                deleted_at TIMESTAMP NULL,
                UNIQUE KEY uq_customers_branch_id (branch_id, id),
                CONSTRAINT fk_test_customer_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
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
                branch_id INT NOT NULL DEFAULT 1,
                name VARCHAR(150) NOT NULL,
                phone VARCHAR(30) NULL,
                email VARCHAR(150) NULL,
                address VARCHAR(255) NULL,
                initial_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
                deleted_at TIMESTAMP NULL,
                UNIQUE KEY uq_suppliers_branch_id (branch_id, id),
                CONSTRAINT fk_test_supplier_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
            ) ENGINE=InnoDB',
            'CREATE TABLE purchase_invoices (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                branch_id INT NOT NULL,
                supplier_id INT NOT NULL,
                total DECIMAL(12,2) NOT NULL DEFAULT 0,
                discount DECIMAL(12,2) NOT NULL DEFAULT 0,
                shipping_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
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
                branch_id INT NOT NULL DEFAULT 1,
                customer_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                description TEXT NULL,
                invoice_id INT NULL,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_test_customer_ledger_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
                CONSTRAINT fk_test_customer_ledger_customer FOREIGN KEY (branch_id, customer_id) REFERENCES customers(branch_id, id),
                CONSTRAINT fk_test_customer_ledger_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
            ) ENGINE=InnoDB',
            'CREATE TABLE supplier_ledger (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                branch_id INT NOT NULL DEFAULT 1,
                supplier_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                description TEXT NULL,
                purchase_invoice_id INT NULL,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_test_supplier_ledger_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
                CONSTRAINT fk_test_supplier_ledger_supplier FOREIGN KEY (branch_id, supplier_id) REFERENCES suppliers(branch_id, id),
                CONSTRAINT fk_test_supplier_ledger_invoice FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL
            ) ENGINE=InnoDB',
            'CREATE TABLE loyalty_transactions (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                customer_id INT NOT NULL,
                invoice_id INT NULL,
                points INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                description VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_test_loyalty_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                CONSTRAINT fk_test_loyalty_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
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
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                if (!self::isIgnorableMigrationError($e, $statement)) {
                    throw $e;
                }
            }
        }
    }

    public static function isIgnorableMigrationError(PDOException $exception, string $sql): bool
    {
        $errorInfo = $exception->errorInfo ?? [];
        $driverCode = (int) ($errorInfo[1] ?? $exception->getCode());
        $details = strtolower(trim(
            $exception->getMessage() . ' ' . (string) ($errorInfo[2] ?? '')
        ));

        $duplicateObjectCodes = [1060, 1061, 1050, 1068, 1826];
        if (in_array($driverCode, $duplicateObjectCodes, true)) {
            return true;
        }

        if (
            $driverCode === 1005
            && str_contains($details, 'duplicate key')
            && preg_match('/\\bADD\\s+CONSTRAINT\\b/i', $sql) === 1
        ) {
            return true;
        }

        if (
            $driverCode === 1091
            && preg_match('/\\bDROP\\s+FOREIGN\\s+KEY\\b/i', $sql) === 1
        ) {
            return true;
        }

        return false;
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
        $probes = [];
        $unavailableProbes = [];
        foreach (self::lockWaitProbeQueries() as $name => $query) {
            try {
                $probes[$name] = $pdo->prepare($query);
            } catch (\Throwable $exception) {
                $unavailableProbes[] = sprintf('%s: %s', $name, $exception->getMessage());
            }
        }
        if ($probes === []) {
            throw new RuntimeException(
                'No MySQL lock-wait probe is available. ' . implode(' | ', $unavailableProbes)
            );
        }

        do {
            foreach ($probes as $name => $statement) {
                try {
                    $statement->execute([$connectionId]);
                    if ((bool) $statement->fetchColumn()) {
                        return;
                    }
                } catch (\Throwable $exception) {
                    unset($probes[$name]);
                    $unavailableProbes[] = sprintf('%s: %s', $name, $exception->getMessage());
                }
            }
            if ($probes === []) {
                throw new RuntimeException(
                    'MySQL lock-wait probes became unavailable. ' . implode(' | ', $unavailableProbes)
                );
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(
            sprintf(
                'Connection %d did not enter a MySQL row-lock wait within %.1f seconds. Probes: %s',
                $connectionId,
                $timeoutSeconds,
                implode(', ', array_keys($probes))
            )
        );
    }

    /** @return array<string,string> */
    public static function lockWaitProbeQueries(): array
    {
        return [
            'performance_schema.data_lock_waits' =>
                'SELECT EXISTS(
                    SELECT 1
                    FROM performance_schema.data_lock_waits AS lock_wait
                    INNER JOIN performance_schema.threads AS waiting_thread
                        ON waiting_thread.THREAD_ID = lock_wait.REQUESTING_THREAD_ID
                    WHERE waiting_thread.PROCESSLIST_ID = ?
                )',
            'information_schema.innodb_trx' =>
                "SELECT EXISTS(
                    SELECT 1
                    FROM information_schema.innodb_trx
                    WHERE trx_mysql_thread_id = ? AND trx_state = 'LOCK WAIT'
                )",
            'information_schema.innodb_lock_waits' =>
                'SELECT EXISTS(
                    SELECT 1
                    FROM information_schema.innodb_lock_waits AS lock_wait
                    INNER JOIN information_schema.innodb_trx AS waiting_trx
                        ON waiting_trx.trx_id = lock_wait.requesting_trx_id
                    WHERE waiting_trx.trx_mysql_thread_id = ?
                )',
            'information_schema.processlist' =>
                "SELECT EXISTS(
                    SELECT 1
                    FROM information_schema.processlist
                    WHERE id = ?
                      AND command = 'Query'
                      AND state LIKE '%lock%'
                )",
        ];
    }

    public static function captureLockDiagnostics(PDO $pdo): string
    {
        $diagnostics = [];
        try {
            $stmt = $pdo->query(
                'SELECT
                    waiting_thread.PROCESSLIST_ID AS waiting_pid,
                    blocking_thread.PROCESSLIST_ID AS blocking_pid,
                    lock_wait.REQUESTING_ENGINE_LOCK_ID AS requested_lock,
                    lock_wait.BLOCKING_ENGINE_LOCK_ID AS blocking_lock,
                    locks.OBJECT_SCHEMA AS db,
                    locks.OBJECT_NAME AS table_name,
                    locks.LOCK_TYPE AS lock_type,
                    locks.LOCK_MODE AS lock_mode,
                    locks.LOCK_STATUS AS lock_status
                 FROM performance_schema.data_lock_waits lock_wait
                 LEFT JOIN performance_schema.threads waiting_thread
                     ON waiting_thread.THREAD_ID = lock_wait.REQUESTING_THREAD_ID
                 LEFT JOIN performance_schema.threads blocking_thread
                     ON blocking_thread.THREAD_ID = lock_wait.BLOCKING_THREAD_ID
                 LEFT JOIN performance_schema.data_locks locks
                     ON locks.ENGINE_LOCK_ID = lock_wait.REQUESTING_ENGINE_LOCK_ID'
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $diagnostics[] = 'data_lock_waits: ' . json_encode($rows, JSON_UNESCAPED_SLASHES);
            }
        } catch (\Throwable) {
        }

        try {
            $stmt = $pdo->query(
                'SELECT trx_id, trx_state, trx_started, trx_mysql_thread_id, trx_query, trx_wait_started
                 FROM information_schema.innodb_trx'
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $diagnostics[] = 'innodb_trx: ' . json_encode($rows, JSON_UNESCAPED_SLASHES);
            }
        } catch (\Throwable) {
        }

        try {
            $stmt = $pdo->query(
                "SELECT id, user, host, db, command, time, state, info
                 FROM information_schema.processlist
                 WHERE command != 'Sleep' OR info IS NOT NULL"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $diagnostics[] = 'processlist: ' . json_encode($rows, JSON_UNESCAPED_SLASHES);
            }
        } catch (\Throwable) {
        }

        return empty($diagnostics) ? '' : ' [DB Lock State: ' . implode(' | ', $diagnostics) . ']';
    }

    /**
     * Creates a temporary database-scoped restricted user with NO global privileges (NO SUPER).
     * Uses host '%' for portability across loopback and CI Docker bridge networking.
     *
     * @return array{username: string, password: string, host: string}
     */
    public static function createRestrictedUser(PDO $rootPdo, string $database, string $prefix = 'pos_test'): array
    {
        self::assertSafeDatabaseName($database);
        $username = sprintf('%s_%s', $prefix, bin2hex(random_bytes(4)));
        $password = sprintf('pass_%s', bin2hex(random_bytes(8)));
        $host = '%';

        $rootPdo->exec(sprintf("DROP USER IF EXISTS '%s'@'%s', '%s'@'127.0.0.1'", $username, $host, $username));
        $rootPdo->exec(sprintf("CREATE USER '%s'@'%s' IDENTIFIED BY '%s'", $username, $host, $password));
        $rootPdo->exec(sprintf("GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'%s'", $database, $username, $host));
        $rootPdo->exec('FLUSH PRIVILEGES');

        return [
            'username' => $username,
            'password' => $password,
            'host' => $host,
        ];
    }

    /**
     * Connects as a restricted user to a specific database.
     */
    public static function connectAs(string $username, string $password, string $database): PDO
    {
        $configuration = self::configuration($database);
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $configuration['host'],
                $configuration['port'],
                $database
            ),
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 3,
            ]
        );
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');

        return $pdo;
    }

    /**
     * Deterministically drops a test user.
     */
    public static function dropUser(PDO $rootPdo, string $username, string $host = '%'): void
    {
        $rootPdo->exec(sprintf("DROP USER IF EXISTS '%s'@'%s', '%s'@'127.0.0.1'", $username, $host, $username));
        $rootPdo->exec('FLUSH PRIVILEGES');
    }

    /**
     * Asserts that the currently connected user has only database-scoped privileges,
     * with no global administrative privileges (no SUPER, no SYSTEM_VARIABLES_ADMIN, no ALL on *.*).
     */
    public static function assertDatabaseScopedPrivilegesOnly(PDO $restrictedPdo, string $database): void
    {
        $grants = $restrictedPdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
        $grantString = implode("\n", $grants);

        // Ensure no global administrative privileges
        \PHPUnit\Framework\Assert::assertStringNotContainsString('ALL PRIVILEGES ON *.*', $grantString);
        \PHPUnit\Framework\Assert::assertStringNotContainsString('SUPER', $grantString);
        \PHPUnit\Framework\Assert::assertStringNotContainsString('SYSTEM_VARIABLES_ADMIN', $grantString);
        \PHPUnit\Framework\Assert::assertStringNotContainsString('RELOAD', $grantString);
        \PHPUnit\Framework\Assert::assertStringNotContainsString('SHUTDOWN', $grantString);
        \PHPUnit\Framework\Assert::assertStringNotContainsString('PROCESS', $grantString);

        // Verify DB-level grant exists
        $hasDbScope = false;
        foreach ($grants as $grant) {
            if (stripos($grant, 'ALL PRIVILEGES ON `' . $database . '`.*') !== false
                || stripos($grant, 'ALL PRIVILEGES ON ' . $database . '.*') !== false) {
                $hasDbScope = true;
                break;
            }
        }
        \PHPUnit\Framework\Assert::assertTrue($hasDbScope, "Grants must contain ALL PRIVILEGES on `{$database}`.*. Found grants:\n" . $grantString);
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
    private string $mode;

    /** @param array<string,string> $environment */
    public function __construct(string $mode, array $environment)
    {
        $this->mode = $mode;
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
        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
            unset($this->pipes[0]);
        }
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        $this->control = $control;
        stream_set_blocking($this->control, false);
        $this->waitForEvent('ready', 5.0);
    }

    /** @return array<string,mixed> */
    public function waitForEvent(string $eventName, float $timeoutSeconds = 10.0, ?PDO $pdo = null): array
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
            if ($remaining <= 0.0) {
                break;
            }
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

        $lockState = $pdo !== null ? MySqlTestEnvironment::captureLockDiagnostics($pdo) : '';
        throw new RuntimeException(
            sprintf(
                "Timed out waiting for MySQL worker event '%s' (mode: %s) after %.1fs.%s%s",
                $eventName,
                $this->mode,
                $timeoutSeconds,
                $this->diagnostics(),
                $lockState
            )
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
        if (is_resource($this->control)) {
            fclose($this->control);
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process, 15);
                for ($i = 0; $i < 10; $i++) {
                    usleep(20_000);
                    $status = proc_get_status($this->process);
                    if (!$status['running']) {
                        break;
                    }
                }
                if ($status['running']) {
                    proc_terminate($this->process, 9);
                }
            }
            proc_close($this->process);
        }
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
