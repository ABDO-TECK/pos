<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlTestEnvironment;

require_once dirname(__DIR__) . '/Integration/Support/MySqlTestEnvironment.php';

final class MySqlGrantParserTest extends TestCase
{
    public function testMariaDbAllPrivilegesGrantFormatPasses(): void
    {
        $grants = [
            "GRANT USAGE ON *.* TO 'pos_test_1234'@'%'",
            "GRANT ALL PRIVILEGES ON `pos_test_db`.* TO 'pos_test_1234'@'%'",
        ];

        MySqlTestEnvironment::validateDatabaseScopedGrants($grants, 'pos_test_db');
        self::assertTrue(true);
    }

    public function testMySql8ExpandedDatabasePrivilegesGrantFormatPasses(): void
    {
        $grants = [
            "GRANT USAGE ON *.* TO `pos_test_1234`@`%`",
            "GRANT ALTER, ALTER ROUTINE, CREATE, CREATE ROUTINE, CREATE TEMPORARY TABLES, CREATE VIEW, DELETE, DROP, EVENT, EXECUTE, INDEX, INSERT, LOCK TABLES, REFERENCES, SELECT, SHOW VIEW, TRIGGER, UPDATE ON `pos_test_db`.* TO `pos_test_1234`@`%`",
        ];

        MySqlTestEnvironment::validateDatabaseScopedGrants($grants, 'pos_test_db');
        self::assertTrue(true);
    }

    public function testGlobalAllPrivilegesGrantFails(): void
    {
        $grants = [
            "GRANT ALL PRIVILEGES ON *.* TO `pos_test_1234`@`%`",
            "GRANT ALL PRIVILEGES ON `pos_test_db`.* TO `pos_test_1234`@`%`",
        ];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Any global grant on *.* must strictly be USAGE only');
        MySqlTestEnvironment::validateDatabaseScopedGrants($grants, 'pos_test_db');
    }

    public function testGlobalSuperPrivilegeFails(): void
    {
        $grants = [
            "GRANT USAGE ON *.* TO `pos_test_1234`@`%`",
            "GRANT SUPER ON *.* TO `pos_test_1234`@`%`",
            "GRANT ALL PRIVILEGES ON `pos_test_db`.* TO `pos_test_1234`@`%`",
        ];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Restricted user must not have global privilege: SUPER');
        MySqlTestEnvironment::validateDatabaseScopedGrants($grants, 'pos_test_db');
    }

    public function testGlobalSystemVariablesAdminPrivilegeFails(): void
    {
        $grants = [
            "GRANT USAGE ON *.* TO `pos_test_1234`@`%`",
            "GRANT SYSTEM_VARIABLES_ADMIN ON *.* TO `pos_test_1234`@`%`",
            "GRANT ALL PRIVILEGES ON `pos_test_db`.* TO `pos_test_1234`@`%`",
        ];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Restricted user must not have global privilege: SYSTEM_VARIABLES_ADMIN');
        MySqlTestEnvironment::validateDatabaseScopedGrants($grants, 'pos_test_db');
    }

    public function testMissingTargetDatabaseGrantFails(): void
    {
        $grants = [
            "GRANT USAGE ON *.* TO `pos_test_1234`@`%`",
            "GRANT ALL PRIVILEGES ON `other_db`.* TO `pos_test_1234`@`%`",
        ];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Grants must contain database-scoped privileges on `pos_test_db`.*');
        MySqlTestEnvironment::validateDatabaseScopedGrants($grants, 'pos_test_db');
    }
}
