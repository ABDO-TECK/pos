<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Integration\Support\MySqlTestEnvironment;

require_once __DIR__ . '/../Integration/Support/MySqlTestEnvironment.php';

final class MySqlLockWaitProbeTest extends TestCase
{
    public function testPerformanceSchemaProbeIsPreferredAndTargetsTheWorkerConnection(): void
    {
        $probes = MySqlTestEnvironment::lockWaitProbeQueries();

        self::assertSame(
            [
                'performance_schema.data_lock_waits',
                'information_schema.innodb_trx',
                'information_schema.innodb_lock_waits',
                'information_schema.processlist',
            ],
            array_keys($probes)
        );
        self::assertStringContainsString('performance_schema.data_lock_waits', $probes['performance_schema.data_lock_waits']);
        self::assertStringContainsString('REQUESTING_THREAD_ID', $probes['performance_schema.data_lock_waits']);
        self::assertStringContainsString('PROCESSLIST_ID = ?', $probes['performance_schema.data_lock_waits']);
        self::assertStringContainsString("trx_state = 'LOCK WAIT'", $probes['information_schema.innodb_trx']);
        self::assertStringContainsString('requesting_trx_id', $probes['information_schema.innodb_lock_waits']);
        self::assertStringContainsString("state LIKE '%lock%'", $probes['information_schema.processlist']);
    }
}
