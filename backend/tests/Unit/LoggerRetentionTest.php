<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Helpers\Logger;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionProperty;

final class LoggerRetentionTest extends TestCase
{
    private string $logDir;
    private string|false $previousLogsPath;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos-logger-' . bin2hex(random_bytes(6));
        mkdir($this->logDir, 0755, true);
        $this->previousLogsPath = getenv('LOGS_PATH');
        putenv('LOGS_PATH=' . $this->logDir);
        $_ENV['LOGS_PATH'] = $this->logDir;
        $this->resetLogger();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->logDir);
        if ($this->previousLogsPath === false) {
            putenv('LOGS_PATH');
            unset($_ENV['LOGS_PATH']);
        } else {
            putenv('LOGS_PATH=' . $this->previousLogsPath);
            $_ENV['LOGS_PATH'] = $this->previousLogsPath;
        }
        $this->resetLogger();
    }

    public function testCleanupDeduplicatesOverlappingPatternsAndCountsSuccessfulDeletes(): void
    {
        $oldBase = $this->writeLogFile('pos-2026-01-01.log', "old\n");
        $oldRotation = $this->writeLogFile('pos-2026-01-01.1.log', "old rotation\n");
        $recent = $this->writeLogFile('pos-2026-07-28.log', "recent\n");
        touch($oldBase, time() - 40 * 86400);
        touch($oldRotation, time() - 40 * 86400);
        touch($recent, time());

        $discovered = Logger::getLogFiles($this->logDir);

        self::assertCount(3, $discovered);
        self::assertSame(2, Logger::cleanup($this->logDir, 30));
        self::assertFileDoesNotExist($oldBase);
        self::assertFileDoesNotExist($oldRotation);
        self::assertFileExists($recent);
    }

    public function testCliCleanupInvokesRetentionPath(): void
    {
        $expired = $this->writeLogFile('pos-2026-01-02.log', "expired\n");
        touch($expired, time() - 40 * 86400);

        ob_start();
        include __DIR__ . '/../../cli/cleanup-logs.php';
        $output = (string) ob_get_clean();

        self::assertFileDoesNotExist($expired);
        self::assertStringContainsString('Removed 1 expired log file', $output);
    }

    public function testWritesContinueIntoCachedRotations(): void
    {
        $maxFileSize = new ReflectionProperty(Logger::class, 'maxFileSize');
        $maxFileSize->setValue(null, 220);

        try {
            Logger::info(str_repeat('a', 140));
            Logger::info(str_repeat('b', 140));
            Logger::info(str_repeat('c', 140));

            $files = Logger::getLogFiles($this->logDir, true);
            self::assertGreaterThanOrEqual(2, count($files));

            $messages = [];
            foreach ($files as $file) {
                foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $entry = json_decode($line, true);
                    self::assertIsArray($entry);
                    $messages[] = $entry['message'];
                }
            }

            self::assertContains(str_repeat('a', 140), $messages);
            self::assertContains(str_repeat('b', 140), $messages);
            self::assertContains(str_repeat('c', 140), $messages);
        } finally {
            $maxFileSize->setValue(null, 5 * 1024 * 1024);
        }
    }

    public function testWritesTimestampUsingApplicationTimezone(): void
    {
        Logger::info('timezone check');

        $files = Logger::getLogFiles($this->logDir, true);
        self::assertNotEmpty($files);
        $entry = json_decode((string) (file($files[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0] ?? ''), true);

        self::assertIsArray($entry);
        self::assertSame(\APP_TIMEZONE, $entry['timezone']);
        self::assertSame(
            (new \DateTimeImmutable('now', new \DateTimeZone(\APP_TIMEZONE)))->format('Y-m-d'),
            substr((string) $entry['timestamp'], 0, 10)
        );
    }

    private function writeLogFile(string $name, string $contents): string
    {
        $path = $this->logDir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    private function resetLogger(): void
    {
        $logDir = new ReflectionProperty(Logger::class, 'logDir');
        $logDir->setValue(null, '');
        $activePaths = new ReflectionProperty(Logger::class, 'activeLogPaths');
        $activePaths->setValue(null, []);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
