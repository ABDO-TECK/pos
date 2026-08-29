<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UpdateTelemetryService;
use PDO;
use PHPUnit\Framework\TestCase;

class UpdateTelemetryServiceTest extends TestCase
{
    private string $tempDir;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/pos_telemetry_test_' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir, 0755, true);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("
            CREATE TABLE update_telemetry (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id TEXT NOT NULL,
                current_version TEXT NOT NULL,
                target_version TEXT,
                channel TEXT DEFAULT 'stable',
                event_type TEXT NOT NULL,
                success INTEGER DEFAULT 1,
                error_code TEXT,
                duration_ms INTEGER,
                metadata TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        if ($files !== false) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $path = $dir . '/' . $file;
                if (is_dir($path)) {
                    $this->removeDirectory($path);
                } else {
                    @unlink($path);
                }
            }
        }
        @rmdir($dir);
    }

    public function testGetTargetVersionResolvesFromVersionJson(): void
    {
        file_put_contents($this->tempDir . '/version.json', json_encode(['version' => '1.1.47']));
        $service = new UpdateTelemetryService($this->tempDir, $this->pdo, $this->tempDir);

        $this->assertSame('1.1.47', $service->getTargetVersion());
    }

    public function testGetTargetVersionFallsBackToNullWhenMissing(): void
    {
        $service = new UpdateTelemetryService($this->tempDir, $this->pdo, $this->tempDir);

        $this->assertNull($service->getTargetVersion());
    }

    public function testFleetAlertGeneratedWhenTargetVersionIsUnavailable(): void
    {
        // No version.json in tempDir
        $service = new UpdateTelemetryService($this->tempDir, $this->pdo, $this->tempDir);

        $service->recordEvent([
            'device_id'           => 'term-001',
            'application_version' => '1.1.46',
            'channel'             => 'stable',
            'event_type'          => 'update_applied',
            'success'             => true,
        ]);

        $stats = $service->getFleetStats();
        $this->assertTrue($stats['ok']);
        $this->assertSame(1, $stats['total_devices']);

        // Must generate target_version_unavailable alert instead of silently comparing against 0.0.0
        $unavailableAlerts = array_values(array_filter($stats['alerts'], fn($a) => $a['code'] === 'target_version_unavailable'));
        $this->assertCount(1, $unavailableAlerts);
        $this->assertSame('الإصدار المستهدف غير متاح', $unavailableAlerts[0]['title']);

        // Outdated alert must NOT be falsely generated
        $outdatedAlerts = array_filter($stats['alerts'], fn($a) => $a['code'] === 'outdated_devices');
        $this->assertEmpty($outdatedAlerts);
    }

    public function testFleetDeviceClassificationCurrentVersionNotOutdated(): void
    {
        file_put_contents($this->tempDir . '/version.json', json_encode(['version' => '1.1.47']));
        $service = new UpdateTelemetryService($this->tempDir, $this->pdo, $this->tempDir);

        // Record a device on current version 1.1.47
        $service->recordEvent([
            'device_id'           => 'term-001',
            'application_version' => '1.1.47',
            'channel'             => 'stable',
            'event_type'          => 'update_applied',
            'success'             => true,
        ]);

        $stats = $service->getFleetStats();
        $this->assertTrue($stats['ok']);
        $this->assertSame(1, $stats['total_devices']);
        $this->assertSame(['1.1.47' => 1], $stats['version_distribution']);

        // Outdated alert must NOT be present
        $outdatedAlerts = array_filter($stats['alerts'], fn($a) => $a['code'] === 'outdated_devices');
        $this->assertEmpty($outdatedAlerts, 'Device on current version 1.1.47 must not be flagged as outdated');
    }

    public function testFleetDeviceClassificationOutdatedVersionGeneratesAlertWithTargetVersion(): void
    {
        file_put_contents($this->tempDir . '/version.json', json_encode(['version' => '1.1.47']));
        $service = new UpdateTelemetryService($this->tempDir, $this->pdo, $this->tempDir);

        // Record a device on older version 1.1.46
        $service->recordEvent([
            'device_id'           => 'term-legacy',
            'application_version' => '1.1.46',
            'channel'             => 'stable',
            'event_type'          => 'update_check_started',
            'success'             => true,
        ]);

        $stats = $service->getFleetStats();
        $this->assertTrue($stats['ok']);
        $this->assertSame(1, $stats['total_devices']);

        $outdatedAlerts = array_values(array_filter($stats['alerts'], fn($a) => $a['code'] === 'outdated_devices'));
        $this->assertCount(1, $outdatedAlerts);
        $this->assertSame('أجهزة تعمل بإصدارات قديمة', $outdatedAlerts[0]['title']);
        $this->assertSame('يوجد 1 جهاز يعمل بإصدار أقدم من v1.1.47 ويتطلب التحديث.', $outdatedAlerts[0]['message']);
    }

    public function testFleetDeviceClassificationAheadOrRehearsalVersionNotOutdated(): void
    {
        file_put_contents($this->tempDir . '/version.json', json_encode(['version' => '1.1.47']));
        $service = new UpdateTelemetryService($this->tempDir, $this->pdo, $this->tempDir);

        // Record a device on ahead/test version 1.1.48
        $service->recordEvent([
            'device_id'           => 'term-ahead',
            'application_version' => '1.1.48',
            'channel'             => 'beta',
            'event_type'          => 'update_applied',
            'success'             => true,
        ]);

        $stats = $service->getFleetStats();
        $this->assertTrue($stats['ok']);

        $outdatedAlerts = array_filter($stats['alerts'], fn($a) => $a['code'] === 'outdated_devices');
        $this->assertEmpty($outdatedAlerts, 'Ahead/test version 1.1.48 must not be flagged as outdated relative to 1.1.47');
    }

    public function testMixedFleetVersionComparisonSemantics(): void
    {
        file_put_contents($this->tempDir . '/version.json', json_encode(['version' => '1.1.47']));
        $service = new UpdateTelemetryService($this->tempDir, $this->pdo, $this->tempDir);

        // 2 devices on 1.1.47 (current)
        $service->recordEvent(['device_id' => 'dev-1', 'application_version' => '1.1.47', 'channel' => 'stable', 'event_type' => 'update_applied', 'success' => true]);
        $service->recordEvent(['device_id' => 'dev-2', 'application_version' => '1.1.47', 'channel' => 'stable', 'event_type' => 'update_applied', 'success' => true]);

        // 1 device on 1.1.48 (ahead)
        $service->recordEvent(['device_id' => 'dev-3', 'application_version' => '1.1.48', 'channel' => 'beta', 'event_type' => 'update_applied', 'success' => true]);

        // 2 devices on 1.1.46 (outdated)
        $service->recordEvent(['device_id' => 'dev-4', 'application_version' => '1.1.46', 'channel' => 'stable', 'event_type' => 'update_applied', 'success' => true]);
        $service->recordEvent(['device_id' => 'dev-5', 'application_version' => '1.1.45', 'channel' => 'stable', 'event_type' => 'update_applied', 'success' => true]);

        $stats = $service->getFleetStats();
        $this->assertSame(5, $stats['total_devices']);

        $outdatedAlerts = array_values(array_filter($stats['alerts'], fn($a) => $a['code'] === 'outdated_devices'));
        $this->assertCount(1, $outdatedAlerts);
        $this->assertSame('يوجد 2 جهاز يعمل بإصدار أقدم من v1.1.47 ويتطلب التحديث.', $outdatedAlerts[0]['message']);
    }

    public function testAlertGenerationForRollbacksAndFailures(): void
    {
        file_put_contents($this->tempDir . '/version.json', json_encode(['version' => '1.1.47']));
        $service = new UpdateTelemetryService($this->tempDir, $this->pdo, $this->tempDir);

        // Record rollback and recovery failure events
        $service->recordEvent(['device_id' => 'dev-1', 'application_version' => '1.1.47', 'channel' => 'stable', 'event_type' => 'update_auto_rollback', 'success' => true]);
        $service->recordEvent(['device_id' => 'dev-2', 'application_version' => '1.1.47', 'channel' => 'stable', 'event_type' => 'update_recovery_failed', 'success' => false, 'error_code' => 'corrupted_snapshot']);

        $stats = $service->getFleetStats();
        $alertCodes = array_column($stats['alerts'], 'code');

        $this->assertContains('recent_rollbacks', $alertCodes);
        $this->assertContains('recovery_failures_detected', $alertCodes);
    }
}
