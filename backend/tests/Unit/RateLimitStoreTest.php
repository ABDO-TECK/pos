<?php

namespace Tests\Unit;

use App\Helpers\Logger;
use App\Middleware\RateLimitStore;
use PDO;
use PHPUnit\Framework\TestCase;

class RateLimitStoreTest extends TestCase
{
    protected function setUp(): void
    {
        RateLimitStore::reset();
    }

    public function testIncrementReturnsAtomicallyUpdatedCount(): void
    {
        $expiresAt = time() + 60;

        $this->assertSame(1, RateLimitStore::increment('atomic_unit', $expiresAt));
        $this->assertSame(2, RateLimitStore::increment('atomic_unit', $expiresAt));
        $this->assertSame(3, RateLimitStore::increment('atomic_unit', $expiresAt));
    }

    public function testApcuUnavailableFallsBackToAtomicSqliteCounter(): void
    {
        RateLimitStore::configureFailuresForTesting(true, false);
        $expiresAt = time() + 60;

        $this->assertNull(RateLimitStore::incrementApcu('apcu_down', 60));
        $this->assertSame(1, RateLimitStore::increment('apcu_down', $expiresAt));
        $this->assertSame(2, RateLimitStore::increment('apcu_down', $expiresAt));
    }

    public function testSqliteUnavailableReturnsNullForEmergencyPolicy(): void
    {
        RateLimitStore::configureFailuresForTesting(true, true);

        $this->assertNull(
            RateLimitStore::increment('sqlite_down', time() + 60)
        );
    }

    public function testLockedSqliteReturnsNullWithoutLeakingException(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'pos-rate-limit-lock-');
        $this->assertIsString($databasePath);
        $locker = new PDO('sqlite:' . $databasePath);
        $counter = null;

        try {
            $locker->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $locker->exec(
                'CREATE TABLE rate_limits (
                    key_name TEXT PRIMARY KEY,
                    request_count INTEGER NOT NULL DEFAULT 1,
                    expires_at INTEGER NOT NULL
                )'
            );

            $counter = new PDO('sqlite:' . $databasePath);
            $counter->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $counter->exec('PRAGMA busy_timeout=1');
            RateLimitStore::useDatabaseForTesting($counter);

            $locker->exec('BEGIN EXCLUSIVE');
            $this->assertNull(
                RateLimitStore::increment('locked', time() + 60)
            );
            $locker->exec('ROLLBACK');
        } finally {
            if ($locker->inTransaction()) {
                $locker->exec('ROLLBACK');
            }
            RateLimitStore::reset();
            $counter = null;
            $locker = null;
            if (is_file($databasePath)) {
                unlink($databasePath);
            }
        }
    }

    public function testEmergencyFallbackIsBoundedAndReclaimsExpiredKeys(): void
    {
        RateLimitStore::configureFailuresForTesting(true, true, 2);

        $this->assertSame(1, RateLimitStore::incrementEmergency('one', 110, 100));
        $this->assertSame(2, RateLimitStore::incrementEmergency('one', 110, 100));
        $this->assertSame(1, RateLimitStore::incrementEmergency('two', 110, 100));
        $this->assertNull(RateLimitStore::incrementEmergency('three', 110, 100));

        $this->assertSame(1, RateLimitStore::incrementEmergency('three', 120, 110));
    }

    public function testStorageDiagnosticsAreSanitizedAndThrottled(): void
    {
        $directory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'pos-rate-limit-log-'
            . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700, true));

        $logger = new \ReflectionClass(Logger::class);
        $logDirectory = $logger->getProperty('logDir');
        $activeLogPaths = $logger->getProperty('activeLogPaths');
        $originalDirectory = $logDirectory->getValue();
        $originalActivePaths = $activeLogPaths->getValue();

        try {
            $logDirectory->setValue(null, $directory);
            $activeLogPaths->setValue(null, []);
            RateLimitStore::configureFailuresForTesting(null, null, null, $directory);

            RateLimitStore::logStorageFailure('password=do-not-log-this');
            RateLimitStore::logStorageFailure('password=do-not-log-this');

            $files = glob($directory . DIRECTORY_SEPARATOR . '*.log') ?: [];
            $this->assertCount(1, $files);
            $contents = file_get_contents($files[0]);
            $this->assertIsString($contents);
            $lines = array_values(array_filter(explode(PHP_EOL, $contents)));

            $this->assertCount(1, $lines);
            $this->assertStringNotContainsString('do-not-log-this', $contents);
            $this->assertStringContainsString('"operation":"unknown"', $contents);
        } finally {
            $logDirectory->setValue(null, $originalDirectory);
            $activeLogPaths->setValue(null, $originalActivePaths);
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testParallelIncrementsDoNotUndercount(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for the concurrency test');
        }

        $directory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'pos-rate-limit-'
            . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700, true));

        $workers = 6;
        $incrementsPerWorker = 25;
        $processes = [];
        $database = null;
        $statement = null;
        $workerScript = dirname(__DIR__) . '/Fixtures/rate_limit_worker.php';

        try {
            for ($worker = 0; $worker < $workers; $worker++) {
                $pipes = [];
                $process = proc_open(
                    [
                        PHP_BINARY,
                        $workerScript,
                        $directory,
                        'parallel_counter',
                        (string) $incrementsPerWorker,
                    ],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes
                );
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes[1], $pipes[2]];
            }

            foreach ($processes as $index => [$process, $stdout, $stderr]) {
                $output = stream_get_contents($stdout);
                $error = stream_get_contents($stderr);
                fclose($stdout);
                fclose($stderr);
                $exitCode = proc_close($process);
                unset($processes[$index]);
                $this->assertSame(
                    0,
                    $exitCode,
                    "Rate-limit worker failed: {$output}{$error}"
                );
            }
            $processes = [];

            $database = new PDO('sqlite:' . $directory . '/rate_limit.sqlite');
            $statement = $database->prepare(
                'SELECT request_count FROM rate_limits WHERE key_name = :key'
            );
            $statement->execute([':key' => 'parallel_counter']);

            $this->assertSame(
                $workers * $incrementsPerWorker,
                (int) $statement->fetchColumn()
            );
        } finally {
            foreach ($processes as [$process, $stdout, $stderr]) {
                fclose($stdout);
                fclose($stderr);
                proc_terminate($process);
                proc_close($process);
            }

            $statement = null;
            $database = null;
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
