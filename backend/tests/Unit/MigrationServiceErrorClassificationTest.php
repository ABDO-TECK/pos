<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MigrationService;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlTestEnvironment;

require_once dirname(__DIR__) . '/Integration/Support/MySqlTestEnvironment.php';

final class MigrationServiceErrorClassificationTest extends TestCase
{
    private MigrationService $migrationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrationService = new MigrationService($this->createMock(\PDO::class));
    }

    public function testDuplicateSchemaObjectsAreSafelyIgnorable(): void
    {
        // 1060: ER_DUP_FIELDNAME (Duplicate column name)
        $dupColumn = $this->createPdoException('Duplicate column name \'branch_id\'', 1060);
        self::assertTrue(
            $this->migrationService->isIgnorableMigrationError($dupColumn, 'ALTER TABLE customers ADD COLUMN branch_id INT'),
            'Duplicate column must be safely ignorable for idempotency'
        );
        self::assertTrue(
            MySqlTestEnvironment::isIgnorableMigrationError($dupColumn, 'ALTER TABLE customers ADD COLUMN branch_id INT')
        );

        // 1061: ER_DUP_KEYNAME (Duplicate key name)
        $dupKey = $this->createPdoException('Duplicate key name \'idx_status\'', 1061);
        self::assertTrue(
            $this->migrationService->isIgnorableMigrationError($dupKey, 'ALTER TABLE job_queue ADD INDEX idx_status (status)'),
            'Duplicate index must be safely ignorable for idempotency'
        );
        self::assertTrue(
            MySqlTestEnvironment::isIgnorableMigrationError($dupKey, 'ALTER TABLE job_queue ADD INDEX idx_status (status)')
        );

        // 1050: ER_TABLE_EXISTS_ERROR (Table already exists)
        $tableExists = $this->createPdoException('Table \'branches\' already exists', 1050);
        self::assertTrue(
            $this->migrationService->isIgnorableMigrationError($tableExists, 'CREATE TABLE branches (id INT PRIMARY KEY)'),
            'Table already exists must be safely ignorable'
        );

        // 1826: ER_FK_DUP_NAME (Duplicate foreign key constraint name)
        $dupFk = $this->createPdoException('Duplicate foreign key constraint name \'fk_ledger_customer\'', 1826);
        self::assertTrue(
            $this->migrationService->isIgnorableMigrationError($dupFk, 'ALTER TABLE customer_ledger ADD CONSTRAINT fk_ledger_customer FOREIGN KEY (customer_id) REFERENCES customers(id)'),
            'Duplicate foreign key constraint must be safely ignorable'
        );

        // 1091: ER_CANT_DROP_FIELD_OR_KEY (when dropping legacy foreign key)
        $cantDropFk = $this->createPdoException('Can\'t DROP \'fk_legacy\'; check that column/key exists', 1091);
        self::assertTrue(
            $this->migrationService->isIgnorableMigrationError($cantDropFk, 'ALTER TABLE customer_ledger DROP FOREIGN KEY fk_legacy'),
            'Dropping non-existent legacy FK must be safely ignorable during canonical replacement'
        );

        // 1227 on DROP EVENT: legacy cleanup of cleanup_expired_tokens by non-SYSTEM_USER
        $dropEventAccessDenied = $this->createPdoException('Access denied; you need (at least one of) the SYSTEM_USER privilege(s) for this operation', 1227);
        self::assertTrue(
            $this->migrationService->isIgnorableMigrationError($dropEventAccessDenied, 'DROP EVENT IF EXISTS cleanup_expired_tokens'),
            'Dropping legacy cleanup_expired_tokens event when lacking SYSTEM_USER must be safely ignorable'
        );
        self::assertTrue(
            MySqlTestEnvironment::isIgnorableMigrationError($dropEventAccessDenied, 'DROP EVENT IF EXISTS cleanup_expired_tokens')
        );
        self::assertFalse(
            $this->migrationService->isIgnorableMigrationError($dropEventAccessDenied, 'DROP EVENT IF EXISTS other_custom_event'),
            'Arbitrary DROP EVENT 1227 errors must NOT be ignored'
        );
        self::assertFalse(
            MySqlTestEnvironment::isIgnorableMigrationError($dropEventAccessDenied, 'DROP EVENT IF EXISTS other_custom_event')
        );
    }

    public function testPermissionAndAccessDeniedErrorsAreNeverIgnored(): void
    {
        // 1419: ER_BINLOG_CREATE_ROUTINE_NEED_SUPER must be FATAL (triggers must not be silently skipped)
        $binlogTrigger = $this->createPdoException('You do not have the SUPER privilege and binary logging is enabled', 1419);
        self::assertFalse(
            $this->migrationService->isIgnorableMigrationError($binlogTrigger, 'CREATE TRIGGER trg_catalog AFTER INSERT ON products FOR EACH ROW INSERT INTO pcc (id) VALUES (1)'),
            'Trigger creation error 1419 MUST be fatal'
        );
        self::assertFalse(
            MySqlTestEnvironment::isIgnorableMigrationError($binlogTrigger, 'CREATE TRIGGER trg_catalog AFTER INSERT ON products FOR EACH ROW INSERT INTO pcc (id) VALUES (1)')
        );
        self::assertFalse(
            $this->migrationService->isIgnorableMigrationError($binlogTrigger, 'ALTER TABLE products ADD COLUMN brand VARCHAR(50)'),
            '1419 error on non-trigger statements must NOT be ignored'
        );
        // 1142: ER_TABLEACCESS_DENIED_ERROR (ALTER/SELECT/CREATE command denied to user)
        $tableAccessDenied = $this->createPdoException('ALTER command denied to user \'pos_user\'@\'localhost\' for table \'customers\'', 1142);
        self::assertFalse(
            $this->migrationService->isIgnorableMigrationError($tableAccessDenied, 'ALTER TABLE customers ADD COLUMN branch_id INT'),
            'Table access denied (1142) MUST NOT be ignored'
        );
        self::assertFalse(
            MySqlTestEnvironment::isIgnorableMigrationError($tableAccessDenied, 'ALTER TABLE customers ADD COLUMN branch_id INT')
        );

        // 1142 on CREATE EVENT
        $eventAccessDenied = $this->createPdoException('EVENT command denied to user \'pos_user\'@\'localhost\' for table \'pos\'', 1142);
        self::assertFalse(
            $this->migrationService->isIgnorableMigrationError($eventAccessDenied, 'CREATE EVENT cleanup_inventory_events ON SCHEDULE EVERY 1 HOUR DO SELECT 1'),
            'Event privilege denied (1142) MUST NOT be silently ignored'
        );

        // 1044: ER_DBACCESS_DENIED_ERROR (Access denied for user to database)
        $dbAccessDenied = $this->createPdoException('Access denied for user \'pos_user\'@\'localhost\' to database \'pos_db\'', 1044);
        self::assertFalse(
            $this->migrationService->isIgnorableMigrationError($dbAccessDenied, 'CREATE TABLE audit_logs (id INT)'),
            'Database access denied (1044) MUST NOT be ignored'
        );

        // 1227: ER_SPECIFIC_ACCESS_DENIED_ERROR (SUPER / SYSTEM_VARIABLES_ADMIN required)
        $specificAccessDenied = $this->createPdoException('Access denied; you need (at least one of) the SUPER privilege(s) for this operation', 1227);
        self::assertFalse(
            $this->migrationService->isIgnorableMigrationError($specificAccessDenied, 'SET GLOBAL event_scheduler = ON'),
            'Specific access denied (1227) MUST NOT be ignored'
        );

        // 1064: Syntax error
        $syntaxError = $this->createPdoException('You have an error in your SQL syntax', 1064);
        self::assertFalse(
            $this->migrationService->isIgnorableMigrationError($syntaxError, 'ALTER TABLE customers ADD COLUMN IF NOT EXISTS branch_id INT'),
            'Syntax errors (1064) MUST NOT be ignored'
        );
    }

    private function createPdoException(string $message, int $driverCode, string $sqlState = '42000'): PDOException
    {
        $exception = new PDOException($message, (int) $sqlState);
        $exception->errorInfo = [$sqlState, $driverCode, $message];
        return $exception;
    }
}
