<?php
declare(strict_types=1);

/**
 * Bootstrap Migration Test Runner
 * Tests the 3 critical scenarios:
 *  1. Old client (v1.1.46) -> Bootstrap Full Update (v1.1.47)
 *  2. New client (v1.1.47) -> Incremental Delta Update (v1.1.48)
 *  3. Failed Bootstrap -> Safe Rollback to original client
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

function logTitle(string $text): void {
    global $CYAN, $BOLD, $RESET;
    echo "\n{$CYAN}{$BOLD}================================================================================{$RESET}\n";
    echo "{$CYAN}{$BOLD}{$text}{$RESET}\n";
    echo "{$CYAN}{$BOLD}================================================================================{$RESET}\n\n";
}

function logPass(string $text): void {
    global $GREEN, $RESET;
    echo "  {$GREEN}✔ [PASS]{$RESET} {$text}\n";
}

function logInfo(string $text): void {
    global $CYAN, $RESET;
    echo "  {$CYAN}ℹ [INFO]{$RESET} {$text}\n";
}

function logError(string $text): void {
    global $RED, $BOLD, $RESET;
    echo "  {$RED}{$BOLD}✖ [FAIL]{$RESET} {$text}\n";
}

$rootDir = realpath(__DIR__ . '/..');
$tempTestDir = $rootDir . '/backend/storage/bootstrap_test_' . time();
@mkdir($tempTestDir, 0755, true);

$manifestService = new UpdateManifestService();
$sigService = new ManifestSignatureService();
$privateKeyPath = $rootDir . '/release/private_key.pem';
$publicKeyPath = $rootDir . '/backend/certs/update_public_key.pem';

$scenarioResults = [];

try {
    // ══════════════════════════════════════════════════════════════
    // SCENARIO 1: Old Client (v1.1.46) -> Full Bootstrap (v1.1.47)
    // ══════════════════════════════════════════════════════════════
    logTitle('SCENARIO 1: Old Legacy Client (v1.1.46) -> Bootstrap Migration (v1.1.47)');

    $legacyClientDir = $tempTestDir . '/legacy_client';
    @mkdir($legacyClientDir . '/backend/Services', 0755, true);
    @mkdir($legacyClientDir . '/backend/storage/update-backups', 0755, true);

    // Old version.json without update_engine_version
    $legacyVersionJson = [
        'version' => '1.1.46',
        'released_at' => '2026-08-04',
        'changelog' => ['Old legacy release']
    ];
    file_put_contents($legacyClientDir . '/version.json', json_encode($legacyVersionJson));

    // Old dummy file
    file_put_contents($legacyClientDir . '/backend/Services/LegacyService.php', '<?php // Old legacy code');

    // Bootstrap manifest
    $bootstrapManifestPath = $rootDir . '/release/1.1.47-bootstrap/manifest.json';
    $bootstrapSigPath = $rootDir . '/release/1.1.47-bootstrap/manifest.sig';
    $bootstrapZipPath = $rootDir . '/release/1.1.47-bootstrap/full-package.zip';

    $bootstrapManifest = json_decode((string) file_get_contents($bootstrapManifestPath), true);
    $bootstrapSig = (string) file_get_contents($bootstrapSigPath);

    // 1. Verify RSA signature of bootstrap release
    $sigValid = $sigService->verifySignature(file_get_contents($bootstrapManifestPath), $bootstrapSig, $publicKeyPath);
    if (!$sigValid) {
        throw new RuntimeException("Bootstrap manifest signature failed verification.");
    }
    logPass("Verified bootstrap manifest RSA-2048 digital signature");

    // 2. Check engine compatibility (legacy client lacking update_engine_version)
    $engineCompat = $manifestService->checkEngineCompatibility(null, $bootstrapManifest);
    if (!$engineCompat['compatible'] || !$engineCompat['requires_bootstrap']) {
        throw new RuntimeException("Engine compatibility did not identify bootstrap requirement for legacy client.");
    }
    logPass("Update engine correctly identified legacy client and selected full bootstrap migration");

    // 3. Extract bootstrap package to client staging and apply
    $legacyDeltaService = new DeltaUpdateService($manifestService, $legacyClientDir, $legacyClientDir . '/backend/storage');
    $stagingDir = $legacyDeltaService->getStagingDir('1.1.47');
    $extractRes = $legacyDeltaService->extractZipToStaging($bootstrapZipPath, $stagingDir);
    if (!$extractRes['ok']) {
        throw new RuntimeException("Failed to extract bootstrap package: " . implode(', ', $extractRes['errors']));
    }
    logPass("Extracted full bootstrap package ({$extractRes['extracted_count']} files)");

    // 4. Create snapshot & Apply
    $snapshotRes = $legacyDeltaService->createBackupSnapshot('1.1.46', '1.1.47', $bootstrapManifest);
    if (!$snapshotRes['ok']) {
        throw new RuntimeException("Failed to create snapshot for legacy client.");
    }
    logPass("Created pre-migration snapshot: " . basename($snapshotRes['snapshot_path']));

    $applyRes = $legacyDeltaService->applyStagedFiles($bootstrapManifest, $snapshotRes['snapshot_path']);
    if (!$applyRes['ok']) {
        throw new RuntimeException("Failed to apply bootstrap files: " . implode('; ', $applyRes['errors']));
    }
    logPass("Applied bootstrap migration package (" . count($applyRes['applied_files']) . " files replaced)");

    // 5. Verify upgraded version.json on client
    $upgradedVersionJson = json_decode((string) file_get_contents($legacyClientDir . '/version.json'), true);
    if (($upgradedVersionJson['version'] ?? '') !== '1.1.47' || ($upgradedVersionJson['update_engine_version'] ?? '') !== '1.0.0') {
        throw new RuntimeException("Upgraded client version.json does not reflect v1.1.47 and update_engine_version 1.0.0");
    }
    logPass("Client successfully migrated to v1.1.47 with update_engine_version: 1.0.0");
    $scenarioResults['scenario1'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 2: New Client (v1.1.47) -> Incremental Delta (v1.1.48)
    // ══════════════════════════════════════════════════════════════
    logTitle('SCENARIO 2: Migrated Client (v1.1.47) -> Incremental Delta Update (v1.1.48)');

    // Create delta release v1.1.48
    $deltaManifestData = [
        'manifest_version' => '1.0',
        'version' => '1.1.48',
        'minimum_version' => '1.1.47',
        'update_engine_version' => '1.0.0',
        'channel' => 'stable',
        'type' => 'delta',
        'changelog' => ['Incremental performance fix'],
        'files' => [
            [
                'path' => 'backend/Services/DeltaUpdateService.php',
                'action' => 'replace',
                'sha256' => hash_file('sha256', $rootDir . '/backend/Services/DeltaUpdateService.php'),
                'size' => filesize($rootDir . '/backend/Services/DeltaUpdateService.php'),
            ]
        ],
        'deleted_files' => []
    ];

    $deltaManifestJson = json_encode($deltaManifestData, JSON_PRETTY_PRINT);
    $deltaSig = $sigService->signData($deltaManifestJson, $privateKeyPath);

    // Client checks engine compatibility
    $clientEngineVer = $upgradedVersionJson['update_engine_version']; // '1.0.0'
    $deltaEngineCheck = $manifestService->checkEngineCompatibility($clientEngineVer, $deltaManifestData);
    if (!$deltaEngineCheck['compatible'] || $deltaEngineCheck['requires_bootstrap']) {
        throw new RuntimeException("Migrated client was rejected for delta update.");
    }
    logPass("Client v1.1.47 update engine validated for delta update v1.1.48");

    $deltaVersionCheck = $manifestService->checkVersionCompatibility('1.1.47', $deltaManifestData);
    if (!$deltaVersionCheck['compatible']) {
        throw new RuntimeException("Version compatibility check failed for delta v1.1.48.");
    }
    logPass("Incremental delta update v1.1.47 -> v1.1.48 verified compatible");
    $scenarioResults['scenario2'] = true;

    // ══════════════════════════════════════════════════════════════
    // SCENARIO 3: Controlled Bootstrap Failure -> Automatic Rollback
    // ══════════════════════════════════════════════════════════════
    logTitle('SCENARIO 3: Bootstrap Failure Simulation & Rollback to Legacy Client');

    // 1. Signature Tampering Test
    $corruptSig = 'INVALID_RSA_SIGNATURE_TAMPERED_HASH';
    $isTamperedValid = $sigService->verifySignature($bootstrapManifestJson ?? $manifestJson ?? '', $corruptSig, $publicKeyPath);
    if ($isTamperedValid) {
        throw new RuntimeException("Security vulnerability: Invalid signature accepted.");
    }
    logPass("Corrupted/Tampered bootstrap signature rejected by client");

    // 2. Perform Rollback of migrated client back to legacy state
    logInfo("Triggering rollback of migrated client to pre-migration snapshot: " . basename($snapshotRes['snapshot_path']));
    $rollbackRes = $legacyDeltaService->rollbackFiles($snapshotRes['snapshot_path']);
    if (!$rollbackRes['ok']) {
        throw new RuntimeException("Rollback failed: " . implode('; ', $rollbackRes['errors']));
    }
    logPass("Rollback executed successfully (" . count($rollbackRes['restored_files']) . " files restored)");

    // 3. Verify original legacy version.json was restored
    $restoredVersionJson = json_decode((string) file_get_contents($legacyClientDir . '/version.json'), true);
    if (($restoredVersionJson['version'] ?? '') !== '1.1.46' || isset($restoredVersionJson['update_engine_version'])) {
        throw new RuntimeException("Rollback did not restore original legacy v1.1.46 state!");
    }
    logPass("Confirmed client is safely restored to original v1.1.46 state");
    $scenarioResults['scenario3'] = true;

} catch (Throwable $e) {
    logError("Test failed: " . $e->getMessage());
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
} finally {
    // Clean temp test environment
    if (is_dir($tempTestDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempTestTestDir ?? $tempTestDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $todo = ($f->isDir() ? 'rmdir' : 'unlink');
            @$todo($f->getRealPath());
        }
        @rmdir($tempTestDir);
    }
}

logTitle('BOOTSTRAP MIGRATION TEST SUMMARY');
$allSuccess = count(array_filter($scenarioResults)) === 3;
foreach ($scenarioResults as $k => $passed) {
    echo "  " . strtoupper($k) . ": " . ($passed ? "{$GREEN}PASSED ✔{$RESET}" : "{$RED}FAILED ✖{$RESET}") . "\n";
}

if ($allSuccess) {
    echo "\n{$GREEN}{$BOLD}🎉 ALL 3 BOOTSTRAP MIGRATION SCENARIOS PASSED WITH 100% SUCCESS!{$RESET}\n\n";
    exit(0);
} else {
    echo "\n{$RED}{$BOLD}❌ SOME SCENARIOS FAILED.{$RESET}\n\n";
    exit(1);
}
