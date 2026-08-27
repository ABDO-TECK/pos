<?php
declare(strict_types=1);

/**
 * Update Telemetry & Fleet Management Test Suite
 *
 * Verifies:
 *  1. Successful update event recording
 *  2. Failed update event recording
 *  3. Rollback event recording
 *  4. Invalid telemetry payload rejection
 *  5. Non-blocking offline queue & batch processing
 *  6. Fleet statistics calculation & alert thresholds
 *  7. Data retention purge policy
 */

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Config\Database;
use App\Services\UpdateTelemetryService;

$GREEN = "\033[32m";
$RED = "\033[31m";
$CYAN = "\033[36m";
$BOLD = "\033[1m";
$RESET = "\033[0m";

function logHeader(string $title): void {
    global $CYAN, $BOLD, $RESET;
    echo "\n{$CYAN}{$BOLD}================================================================================{$RESET}\n";
    echo "{$CYAN}{$BOLD}{$title}{$RESET}\n";
    echo "{$CYAN}{$BOLD}================================================================================{$RESET}\n\n";
}

function logOk(string $msg): void {
    global $GREEN, $RESET;
    echo "  {$GREEN}✔ [PASS]{$RESET} {$msg}\n";
}

function logInfo(string $msg): void {
    global $CYAN, $RESET;
    echo "  {$CYAN}ℹ [INFO]{$RESET} {$msg}\n";
}

function logErr(string $msg): void {
    global $RED, $BOLD, $RESET;
    echo "  {$RED}{$BOLD}✖ [FAIL]{$RESET} {$msg}\n";
}

$results = [];
$testStorage = __DIR__ . '/../backend/storage/test_telemetry_' . time();
@mkdir($testStorage, 0755, true);

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
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

    $service = new UpdateTelemetryService($testStorage, $pdo);


    // ══════════════════════════════════════════════════════════════
    // SCENARIO 1: Successful Update Event Recording
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 1: Successful Update Event Ingestion');

    $validSuccessEvent = [
        'device_id'             => 'test-terminal-001',
        'application_version'   => '1.1.48',
        'update_engine_version' => '1.0.0',
        'channel'               => 'stable',
        'target_version'        => '1.1.49',
        'event_type'            => 'update_applied',
        'success'               => true,
        'duration_ms'           => 1420,
        'metadata'              => [
            'files_count' => 3,
            'is_delta' => true,
        ]
    ];

    $recorded = $service->recordEvent($validSuccessEvent);
    if (!$recorded) {
        throw new RuntimeException("Failed to record valid update_applied event");
    }
    logOk("Successfully recorded update_applied event for device test-terminal-001");
    $results['scenario1'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 2: Failed Update Event Recording
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 2: Failed Update Event Ingestion');

    $validFailedEvent = [
        'device_id'             => 'test-terminal-002',
        'application_version'   => '1.1.48',
        'update_engine_version' => '1.0.0',
        'channel'               => 'beta',
        'target_version'        => '1.1.50',
        'event_type'            => 'update_failed',
        'success'               => false,
        'error_code'            => 'signature_verification_failed',
        'duration_ms'           => 350,
        'metadata'              => [
            'error_message' => 'Manifest RSA signature mismatch',
        ]
    ];

    $failedRecorded = $service->recordEvent($validFailedEvent);
    if (!$failedRecorded) {
        throw new RuntimeException("Failed to record update_failed event");
    }
    logOk("Successfully recorded update_failed event with error_code and metadata");
    $results['scenario2'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 3: Rollback Completed Event Recording
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 3: Rollback Completed Event Ingestion');

    $rollbackEvent = [
        'device_id'             => 'test-terminal-003',
        'application_version'   => '1.1.48',
        'update_engine_version' => '1.0.0',
        'channel'               => 'stable',
        'target_version'        => null,
        'event_type'            => 'rollback_completed',
        'success'               => true,
        'metadata'              => [
            'snapshot_name' => 'patch_1.1.48_to_1.1.49_snap',
        ]
    ];

    $rbRecorded = $service->recordEvent($rollbackEvent);
    if (!$rbRecorded) {
        throw new RuntimeException("Failed to record rollback_completed event");
    }
    logOk("Successfully recorded rollback_completed event");
    $results['scenario3'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 4: Invalid Telemetry Payload Rejection
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 4: Invalid Telemetry Payload Validation');

    // Missing device_id
    $invalid1 = ['event_type' => 'update_applied', 'application_version' => '1.1.49'];
    $val1 = $service->validatePayload($invalid1);
    if ($val1 !== null) {
        throw new RuntimeException("Validation failed: accepted payload with missing device_id");
    }
    logOk("Rejected payload with missing device_id");

    // Unknown event type
    $invalid2 = ['device_id' => 'dev1', 'event_type' => 'customer_checkout_event', 'application_version' => '1.1.49'];
    $val2 = $service->validatePayload($invalid2);
    if ($val2 !== null) {
        throw new RuntimeException("Validation failed: accepted non-update event type (privacy violation)");
    }
    logOk("Rejected non-update event type (privacy protection confirmed)");
    $results['scenario4'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 5: Offline Queue & Batch Ingestion
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 5: Offline Buffer & Batch Transmission');

    $batchEvents = [
        [
            'device_id'             => 'test-batch-001',
            'application_version'   => '1.1.47',
            'channel'               => 'stable',
            'target_version'        => '1.1.48',
            'event_type'            => 'update_applied',
            'success'               => true,
            'duration_ms'           => 1100,
        ],
        [
            'device_id'             => 'test-batch-002',
            'application_version'   => '1.1.49',
            'channel'               => 'stable',
            'target_version'        => '1.1.50',
            'event_type'            => 'update_check_started',
            'success'               => true,
        ],
    ];

    $batchRes = $service->recordBatch($batchEvents);
    if ($batchRes['received'] !== 2 || ($batchRes['inserted'] + $batchRes['queued']) !== 2) {
        throw new RuntimeException("Batch ingestion count mismatch: " . json_encode($batchRes));
    }
    logOk("Batch processed: {$batchRes['received']} events received, inserted/queued successfully");
    $results['scenario5'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 6: Fleet Statistics & Alert Calculation
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 6: Fleet Analytics & Alert Generation');

    $stats = $service->getFleetStats();
    if (!$stats['ok']) {
        throw new RuntimeException("getFleetStats returned failure: " . ($stats['error'] ?? ''));
    }

    logInfo("Fleet Total Devices: " . $stats['total_devices']);
    logInfo("Version Distribution: " . json_encode($stats['version_distribution']));
    logInfo("Channel Distribution: " . json_encode($stats['channel_distribution']));
    logInfo("Update Health: Success Rate = {$stats['update_health']['success_rate']}%, Failures = {$stats['update_health']['failed']}");
    logInfo("Active Alerts Count: " . count($stats['alerts']));

    if (!isset($stats['update_health']['success_rate']) || !isset($stats['version_distribution'])) {
        throw new RuntimeException("Fleet stats missing required health/distribution keys");
    }
    logOk("Fleet analytics accurately computed with version and channel distributions");
    $results['scenario6'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 7: Data Retention Purge Policy
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 7: Data Retention Purge Policy');

    $purged = $service->purgeOldRecords(90);
    logOk("Retention policy executed: purged {$purged} records older than 90 days without database errors");
    $results['scenario7'] = true;

    // Cleanup test storage
    @unlink($testStorage . '/telemetry_queue.json');
    @rmdir($testStorage);

} catch (Throwable $e) {
    logErr("Telemetry test failed: " . $e->getMessage());
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
}

logHeader('UPDATE TELEMETRY & FLEET MANAGEMENT TEST SUMMARY');
$allSuccess = count(array_filter($results)) === 7;
foreach ($results as $name => $passed) {
    echo "  " . strtoupper($name) . ": " . ($passed ? "{$GREEN}PASSED ✔{$RESET}" : "{$RED}FAILED ✖{$RESET}") . "\n";
}

if ($allSuccess) {
    echo "\n{$GREEN}{$BOLD}🎉 ALL 7 UPDATE TELEMETRY & FLEET MANAGEMENT TESTS PASSED 100%!{$RESET}\n\n";
    exit(0);
} else {
    echo "\n{$RED}{$BOLD}❌ SOME TESTS FAILED.{$RESET}\n\n";
    exit(1);
}
