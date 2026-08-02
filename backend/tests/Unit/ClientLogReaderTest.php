<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Requests\ClientLogIndexRequest;
use App\Services\ClientLogReader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ClientLogReaderTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos-log-reader-' . bin2hex(random_bytes(6));
        mkdir($this->logDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->logDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->logDir);
    }

    public function testCursorPagesRotationsNewestFirstAndVisitsEachFileOnce(): void
    {
        $this->writeEntries('pos-2026-07-27.log', [
            $this->entry('2026-07-27 10:00:00', 'ERROR', '[CLIENT] oldest'),
        ]);
        $this->writeEntries('pos-2026-07-28.log', [
            $this->entry('2026-07-28 09:00:00', 'INFO', '[CLIENT] base'),
        ]);
        $this->writeEntries('pos-2026-07-28.1.log', [
            $this->entry('2026-07-28 11:00:00', 'WARNING', '[CLIENT] newer'),
            $this->entry('2026-07-28 12:00:00', 'ERROR', '[CLIENT] newest', [
                'password' => 'secret',
                'nested' => ['access_token' => 'token-value'],
            ]),
        ]);

        $visited = [];
        $reader = new ClientLogReader($this->logDir, static function (string $file) use (&$visited): void {
            $visited[] = realpath($file);
        });

        $firstPage = $reader->paginate('all', 2);

        self::assertSame(['[CLIENT] newest', '[CLIENT] newer'], array_column($firstPage['data'], 'message'));
        self::assertSame('[REDACTED]', $firstPage['data'][0]['context']['password']);
        self::assertSame('[REDACTED]', $firstPage['data'][0]['context']['nested']['access_token']);
        self::assertTrue($firstPage['pagination']['has_more']);
        self::assertNotNull($firstPage['pagination']['next_cursor']);
        self::assertSame(1, count($visited));
        self::assertSame(count($visited), count(array_unique($visited)));

        $visited = [];
        $secondPage = $reader->paginate('all', 2, $firstPage['pagination']['next_cursor']);

        self::assertSame(['[CLIENT] base', '[CLIENT] oldest'], array_column($secondPage['data'], 'message'));
        self::assertSame(2, $secondPage['pagination']['page']);
        self::assertFalse($secondPage['pagination']['has_more']);
        self::assertSame(count($visited), count(array_unique($visited)));
    }

    public function testFilteringSkipsMalformedLinesAndTruncatedFinalLine(): void
    {
        $path = $this->logDir . DIRECTORY_SEPARATOR . 'pos-2026-07-28.log';
        file_put_contents(
            $path,
            json_encode($this->entry('2026-07-28 10:00:00', 'ERROR', '[CLIENT] matching')) . "\n"
            . json_encode($this->entry('2026-07-28 11:00:00', 'INFO', '[CLIENT] filtered')) . "\n"
            . "{truncated"
        );

        $result = (new ClientLogReader($this->logDir))->paginate('error', 10);

        self::assertSame(['[CLIENT] matching'], array_column($result['data'], 'message'));
        self::assertFalse($result['pagination']['has_more']);
    }

    public function testIncludesServerRuntimeEntriesForMaintenanceView(): void
    {
        $this->writeEntries('pos-2026-07-28.log', [
            $this->entry('2026-07-28 10:00:00', 'ERROR', '[CLIENT] browser error'),
            $this->entry('2026-07-28 10:01:00', 'CRITICAL', 'Fatal PHP error: call to undefined function'),
        ]);

        $result = (new ClientLogReader($this->logDir))->paginate('all', 10, null, true);

        self::assertSame(['Fatal PHP error: call to undefined function', '[CLIENT] browser error'], array_column($result['data'], 'message'));
        self::assertSame(['server', 'client'], array_column($result['data'], 'source'));
    }

    public function testNormalizesLegacyBerlinTimestampsToApplicationTimezone(): void
    {
        $this->writeEntries('pos-2026-08-02.log', [
            $this->entry('2026-08-02 04:00:00', 'ERROR', 'legacy timestamp'),
        ]);

        $result = (new ClientLogReader($this->logDir))->paginate('all', 10, null, true);

        self::assertSame('2026-08-02 05:00:00', $result['data'][0]['created_at']);
    }

    public function testSkipsStructuredEntriesWithUnsafeFieldTypes(): void
    {
        $this->writeEntries('pos-2026-07-28.log', [
            $this->entry('2026-07-28 10:00:00', 'ERROR', '[CLIENT] safe'),
            [
                'timestamp' => '2026-07-28 11:00:00',
                'level' => ['ERROR'],
                'message' => '[CLIENT] malformed level',
                'context' => [],
            ],
        ]);

        $result = (new ClientLogReader($this->logDir))->paginate('all', 10);

        self::assertSame(['[CLIENT] safe'], array_column($result['data'], 'message'));
    }

    public function testOversizedFileUsesBoundedMemoryAndSkipsOversizedFinalLine(): void
    {
        $path = $this->logDir . DIRECTORY_SEPARATOR . 'pos-2026-07-28.log';
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        fwrite($handle, json_encode($this->entry('2026-07-28 10:00:00', 'ERROR', '[CLIENT] retained')) . "\n");
        for ($index = 0; $index < 1536; $index++) {
            fwrite($handle, str_repeat('x', 8192));
        }
        fclose($handle);

        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $before = memory_get_usage(true);
        $result = (new ClientLogReader($this->logDir))->paginate('all', 1);
        $peakGrowth = memory_get_peak_usage(true) - $before;

        self::assertSame(['[CLIENT] retained'], array_column($result['data'], 'message'));
        self::assertLessThanOrEqual(4 * 1024 * 1024, $peakGrowth);
    }

    public function testRejectsCursorWhenFilterChanges(): void
    {
        $this->writeEntries('pos-2026-07-28.log', [
            $this->entry('2026-07-28 10:00:00', 'ERROR', '[CLIENT] one'),
            $this->entry('2026-07-28 11:00:00', 'ERROR', '[CLIENT] two'),
        ]);
        $reader = new ClientLogReader($this->logDir);
        $firstPage = $reader->paginate('all', 1);

        $this->expectException(ValidationException::class);
        $reader->paginate('error', 1, $firstPage['pagination']['next_cursor']);
    }

    public function testRequestRejectsLimitsAboveTheStrictMaximum(): void
    {
        $this->expectException(ValidationException::class);
        new ClientLogIndexRequest(['limit' => '101']);
    }

    public function testRequestDefaultsToTenRowsPerPage(): void
    {
        self::assertSame(10, (new ClientLogIndexRequest([]))->normalized()['limit']);
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function writeEntries(string $file, array $entries): void
    {
        $lines = array_map(
            static fn (array $entry): string => json_encode($entry, JSON_UNESCAPED_SLASHES),
            $entries
        );
        file_put_contents($this->logDir . DIRECTORY_SEPARATOR . $file, implode("\n", $lines) . "\n");
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function entry(string $timestamp, string $level, string $message, array $context = []): array
    {
        return compact('timestamp', 'level', 'message', 'context');
    }
}
