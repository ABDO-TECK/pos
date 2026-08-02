<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InventoryEventPollingTest extends TestCase
{
    public function testPollingIsBranchAwareAndDoesNotRunRandomMaintenance(): void
    {
        $backend = dirname(__DIR__, 2);
        $controller = file_get_contents($backend . '/Controllers/SseController.php');
        $model = file_get_contents($backend . '/Models/InventoryEvent.php');

        self::assertIsString($controller);
        self::assertStringNotContainsString('rand(1, 20)', $controller);
        self::assertStringNotContainsString('->cleanup()', $controller);
        self::assertStringContainsString('retry: 15000', $controller);
        self::assertStringContainsString('$model->getLatestId()', $controller);

        self::assertIsString($model);
        self::assertStringContainsString('WHERE branch_id = ?', $model);
        self::assertStringContainsString('INTERVAL 24 HOUR', $model);
    }
}
