<?php
declare(strict_types=1);

/**
 * Release Channels & Gradual Rollout Test Runner
 * 
 * Verifies:
 *  - Scenario 1: Stable client ignores beta release
 *  - Scenario 2: Beta client accepts beta release
 *  - Scenario 3: 25% gradual rollout across 100 simulated devices (~25% distribution)
 *  - Scenario 4: Multiple checks for same device ID are 100% deterministic
 *  - Scenario 5: Atomic rollback functions normally across channels
 */

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\DeltaUpdateService;
use App\Services\GitHubReleaseProvider;
use App\Services\ManifestSignatureService;
use App\Services\UpdateManifestService;
use App\Services\UpdateService;

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

$rootDir = realpath(__DIR__ . '/..');
$manifestService = new UpdateManifestService();
$sigService = new ManifestSignatureService();
$results = [];

try {
    // ══════════════════════════════════════════════════════════════
    // SCENARIO 1: Stable client vs Beta Release (v1.1.50-beta)
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 1: Stable Client vs Beta Release (Expected: Ignored)');

    $betaManifest = [
        'manifest_version' => '1.0',
        'version' => '1.1.50',
        'type' => 'delta',
        'minimum_version' => '1.1.49',
        'update_engine_version' => '1.0.0',
        'channel' => 'beta',
        'rollout_percentage' => 100,
        'changelog' => ['Beta experimental feature'],
        'files' => [
            ['path' => 'version.json', 'action' => 'replace', 'sha256' => str_repeat('a', 64), 'size' => 100]
        ]
    ];

    $stableClientCheck = $manifestService->checkChannelCompatibility('stable', $betaManifest['channel']);
    if ($stableClientCheck['compatible']) {
        throw new RuntimeException("Security defect: Stable client accepted beta release!");
    }
    logOk("Stable client successfully rejected beta release: " . $stableClientCheck['reason']);
    $results['scenario1'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 2: Beta client vs Beta Release (v1.1.50-beta)
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 2: Beta Client vs Beta Release (Expected: Accepted)');

    $betaClientCheck = $manifestService->checkChannelCompatibility('beta', $betaManifest['channel']);
    if (!$betaClientCheck['compatible']) {
        throw new RuntimeException("Beta client was unexpectedly rejected for beta release.");
    }
    logOk("Beta client accepted beta release successfully");

    $rcClientBetaCheck = $manifestService->checkChannelCompatibility('rc', $betaManifest['channel']);
    if ($rcClientBetaCheck['compatible']) {
        throw new RuntimeException("RC client should not receive beta releases!");
    }
    logOk("RC client correctly rejected beta release");
    $results['scenario2'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 3: Gradual Rollout Distribution (25% on 100 devices)
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 3: 25% Gradual Rollout across 100 Devices');

    $targetVersion = '1.1.50';
    $rolloutPercentage = 25;
    $eligibleCount = 0;
    $totalDevices = 100;

    for ($i = 1; $i <= $totalDevices; $i++) {
        $deviceId = "simulated-pos-terminal-{$i}-" . md5("device-salt-{$i}");
        $eligibility = $manifestService->checkRolloutEligibility($deviceId, $targetVersion, $rolloutPercentage);
        if ($eligibility['eligible']) {
            $eligibleCount++;
        }
    }

    logInfo("Eligible devices for 25% rollout: {$eligibleCount} / {$totalDevices} devices (" . ($eligibleCount) . "%)");
    
    // Distribution check: 25% rollout should be between 15% and 35% with standard hash distribution on 100 items
    if ($eligibleCount < 15 || $eligibleCount > 35) {
        throw new RuntimeException("Rollout distribution was non-uniform: {$eligibleCount}% received update.");
    }
    logOk("25% gradual rollout produced realistic uniform distribution ({$eligibleCount}%)");
    $results['scenario3'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 4: Rollout Determinism Test (Same device multiple times)
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 4: Rollout Determinism Check (Same Device Multiple Times)');

    $testDevice = 'terminal-pos-xyz-987654';
    $firstResult = $manifestService->checkRolloutEligibility($testDevice, $targetVersion, $rolloutPercentage);

    for ($attempt = 1; $attempt <= 20; $attempt++) {
        $repeatResult = $manifestService->checkRolloutEligibility($testDevice, $targetVersion, $rolloutPercentage);
        if ($repeatResult['eligible'] !== $firstResult['eligible'] || $repeatResult['bucket'] !== $firstResult['bucket']) {
            throw new RuntimeException("Rollout decision was non-deterministic on attempt #{$attempt}!");
        }
    }
    logOk("Device bucket #{$firstResult['bucket']} gave 100% deterministic decision across all 20 consecutive checks");
    $results['scenario4'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 5: Atomic Rollback across Channels
    // ══════════════════════════════════════════════════════════════
    logHeader('SCENARIO 5: Rollback Verification under Channel Architecture');

    $tempRollbackDir = $rootDir . '/backend/storage/chan_rollback_test_' . time();
    @mkdir($tempRollbackDir . '/backend/storage/update-backups', 0755, true);

    $dummyVersion = [
        'version' => '1.1.49',
        'application_version' => '1.1.49',
        'update_channel' => 'beta',
        'update_engine_version' => '1.0.0',
    ];
    file_put_contents($tempRollbackDir . '/version.json', json_encode($dummyVersion));

    $dummyService = new DeltaUpdateService($manifestService, $tempRollbackDir, $tempRollbackDir . '/backend/storage');
    $snapRes = $dummyService->createBackupSnapshot('1.1.49', '1.1.50', $betaManifest);
    if (!$snapRes['ok']) {
        throw new RuntimeException("Failed to create pre-update snapshot for channel test.");
    }
    logOk("Created snapshot under channel test: " . basename($snapRes['snapshot_path']));

    // Modify file
    $dummyVersion['version'] = '1.1.50';
    file_put_contents($tempRollbackDir . '/version.json', json_encode($dummyVersion));

    // Rollback
    $rbRes = $dummyService->rollbackFiles($snapRes['snapshot_path']);
    if (!$rbRes['ok']) {
        throw new RuntimeException("Rollback failed: " . implode('; ', $rbRes['errors']));
    }
    logOk("Rollback executed successfully (" . count($rbRes['restored_files']) . " files restored)");

    $restored = json_decode((string) file_get_contents($tempRollbackDir . '/version.json'), true);
    if (($restored['version'] ?? '') !== '1.1.49' || ($restored['update_channel'] ?? '') !== 'beta') {
        throw new RuntimeException("Rollback did not restore original version and channel state!");
    }
    logOk("Confirmed state, version 1.1.49, and channel 'beta' restored 100%");
    $results['scenario5'] = true;

    // Clean temp dir
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempRollbackDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $todo = ($f->isDir() ? 'rmdir' : 'unlink');
        @$todo($f->getRealPath());
    }
    @rmdir($tempRollbackDir);

} catch (Throwable $e) {
    logErr("Test failed: " . $e->getMessage());
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
}

logHeader('RELEASE CHANNELS & GRADUAL ROLLOUT TEST SUMMARY');
$allSuccess = count(array_filter($results)) === 5;
foreach ($results as $name => $passed) {
    echo "  " . strtoupper($name) . ": " . ($passed ? "{$GREEN}PASSED ✔{$RESET}" : "{$RED}FAILED ✖{$RESET}") . "\n";
}

if ($allSuccess) {
    echo "\n{$GREEN}{$BOLD}🎉 ALL 5 RELEASE CHANNEL & GRADUAL ROLLOUT TESTS PASSED 100%!{$RESET}\n\n";
    exit(0);
} else {
    echo "\n{$RED}{$BOLD}❌ SOME TESTS FAILED.{$RESET}\n\n";
    exit(1);
}
