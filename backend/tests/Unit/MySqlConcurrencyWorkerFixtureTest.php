<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MySqlConcurrencyWorkerFixtureTest extends TestCase
{
    public function testControlledRepositoriesLoadBeforeTheWorkerDispatches(): void
    {
        $workerPath = dirname(__DIR__) . '/Fixtures/mysql_concurrency_worker.php';
        $worker = file_get_contents($workerPath);
        self::assertNotFalse($worker);

        $requirePosition = strpos($worker, "require_once __DIR__ . '/mysql_concurrency_repositories.php';");
        $dispatchPosition = strpos($worker, '$result = match ($mode)');
        self::assertIsInt($requirePosition);
        self::assertIsInt($dispatchPosition);
        self::assertLessThan($dispatchPosition, $requirePosition);

        require_once dirname($workerPath) . '/mysql_concurrency_repositories.php';
        self::assertTrue(class_exists('ControlledInvoiceRepository', false));
        self::assertTrue(class_exists('ControlledSupplierRepository', false));
    }
}
