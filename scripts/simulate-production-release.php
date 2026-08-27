<?php
declare(strict_types=1);

/**
 * End-to-End Production Release Simulation Script
 * POS Desktop Update Infrastructure
 */

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\DeltaUpdateService;
use App\Services\GitHubReleaseProvider;
use App\Services\ManifestSignatureService;
use App\Services\UpdateManifestService;
use App\Services\UpdateService;
use App\Helpers\Logger;

// Set console styling
$GREEN = "\033[32m";
$RED = "\033[31m";
$CYAN = "\033[36m";
$YELLOW = "\033[33m";
$BOLD = "\033[1m";
$RESET = "\033[0m";

function logStep(string $phase, string $title): void {
    global $CYAN, $BOLD, $RESET;
    echo "\n{$CYAN}{$BOLD}================================================================================{$RESET}\n";
    echo "{$CYAN}{$BOLD}[{$phase}] {$title}{$RESET}\n";
    echo "{$CYAN}{$BOLD}================================================================================{$RESET}\n\n";
}

function logSuccess(string $msg): void {
    global $GREEN, $RESET;
    echo "  {$GREEN}✔ [PASS]{$RESET} {$msg}\n";
}

function logInfo(string $msg): void {
    global $CYAN, $RESET;
    echo "  {$CYAN}ℹ [INFO]{$RESET} {$msg}\n";
}

function logWarn(string $msg): void {
    global $YELLOW, $RESET;
    echo "  {$YELLOW}⚠ [WARN]{$RESET} {$msg}\n";
}

function logFail(string $msg): void {
    global $RED, $BOLD, $RESET;
    echo "  {$RED}{$BOLD}✖ [FAIL]{$RESET} {$msg}\n";
}

$rootDir = realpath(__DIR__ . '/..');
$tempSimDir = $rootDir . '/backend/storage/simulation_env_' . time();
@mkdir($tempSimDir, 0755, true);

// Initialize Services
$manifestService = new UpdateManifestService();
$sigService = new ManifestSignatureService();
$deltaService = new DeltaUpdateService($manifestService, $rootDir);
$privateKeyPath = $rootDir . '/release/private_key.pem';
$publicKeyPath = $rootDir . '/backend/certs/update_public_key.pem';


echo "{$BOLD}🚀 STARTING END-TO-END PRODUCTION UPDATE SIMULATION{$RESET}\n";
echo "Root Directory: {$rootDir}\n";
echo "Simulation Temp: {$tempSimDir}\n\n";

$results = [];

try {
    // ══════════════════════════════════════════════════════════════
    // PHASE 1 - PREPARE TEST RELEASE
    // ══════════════════════════════════════════════════════════════
    logStep('PHASE 1', 'Prepare Test Release Assets (v1.1.46 -> v1.1.47)');

    $baseVersion = '1.1.46';
    $targetVersion = '1.1.47';

    // 1. Create a safe test file to simulate changed/added file
    $testRelativePath = 'docs/update-engine-simulation-marker.txt';
    $testFullPath = $rootDir . '/' . $testRelativePath;
    $testInitialContent = "POS Simulation Marker - Initial Version {$baseVersion} - " . date('Y-m-d H:i:s');
    file_put_contents($testFullPath, $testInitialContent);

    logSuccess("Created initial test marker file: {$testRelativePath}");

    // Target content for release v1.1.47
    $testUpdatedContent = "POS Simulation Marker - Updated Release {$targetVersion} - PROD_READY_" . bin2hex(random_bytes(6));
    $stagingSourceDir = $tempSimDir . '/source_files';
    @mkdir(dirname($stagingSourceDir . '/' . $testRelativePath), 0755, true);
    file_put_contents($stagingSourceDir . '/' . $testRelativePath, $testUpdatedContent);

    $targetFileSha256 = hash('sha256', $testUpdatedContent);
    $targetFileSize = strlen($testUpdatedContent);

    logSuccess("Prepared updated target payload (SHA256: " . substr($targetFileSha256, 0, 16) . "...)");
    $results['phase1'] = true;

    // ══════════════════════════════════════════════════════════════
    // PHASE 2 - GENERATE RELEASE PACKAGE (Manifest, RSA Signature, Delta ZIP)
    // ══════════════════════════════════════════════════════════════
    logStep('PHASE 2', 'Generate Release Package (Manifest, RSA Signature, Delta ZIP)');

    $releaseDir = $rootDir . "/release/{$targetVersion}";
    @mkdir($releaseDir . '/files/' . dirname($testRelativePath), 0755, true);
    file_put_contents($releaseDir . '/files/' . $testRelativePath, $testUpdatedContent);

    // 1. Build Manifest JSON
    $manifestData = [
        'manifest_version' => '1.0',
        'version' => $targetVersion,
        'minimum_version' => $baseVersion,
        'released_at' => date('Y-m-d'),
        'channel' => 'stable',
        'type' => 'delta',
        'changelog' => [
            'Simulated end-to-end update test marker',
            'Verification of atomic file updates and rollback'
        ],
        'files' => [
            [
                'path' => $testRelativePath,
                'action' => 'replace',
                'sha256' => $targetFileSha256,
                'size' => $targetFileSize,
            ]
        ],
        'deleted_files' => []
    ];

    $manifestJson = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    $manifestPath = $releaseDir . '/manifest.json';
    file_put_contents($manifestPath, $manifestJson);
    logSuccess("Generated manifest.json at: {$manifestPath}");

    // Validate Manifest schema
    $manifestValidation = $manifestService->validateManifest($manifestData);
    if (!$manifestValidation['valid']) {
        throw new RuntimeException("Manifest validation failed: " . implode(', ', $manifestValidation['errors']));
    }
    logSuccess("Validated manifest schema and security constraints");

    // 2. Sign Manifest with Developer Private Key
    $signature = $sigService->signData($manifestJson, $privateKeyPath);
    if (!$signature) {
        throw new RuntimeException("RSA signature generation failed.");
    }
    $sigPath = $releaseDir . '/manifest.sig';
    file_put_contents($sigPath, $signature);
    logSuccess("Generated RSA-2048 Digital Signature at: {$sigPath}");

    // Verify signature immediately with client public key
    $sigVerified = $sigService->verifySignature($manifestJson, $signature, $publicKeyPath);
    if (!$sigVerified) {
        throw new RuntimeException("Immediate signature verification with client public key failed.");
    }
    logSuccess("Verified RSA-SHA256 signature against embedded public key (backend/certs/update_public_key.pem)");

    // 3. Package Delta ZIP
    $zipPath = $releaseDir . "/delta-{$baseVersion}-to-{$targetVersion}.zip";
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Failed to create ZIP package: {$zipPath}");
    }
    $zip->addFromString($testRelativePath, $testUpdatedContent);
    $zip->close();
    copy($zipPath, $releaseDir . '/delta.zip');
    logSuccess("Packaged delta archive: {$zipPath} (" . filesize($zipPath) . " bytes)");
    $results['phase2'] = true;

    // ══════════════════════════════════════════════════════════════
    // PHASE 3 - SIMULATE GITHUB RELEASE DISCOVERY
    // ══════════════════════════════════════════════════════════════
    logStep('PHASE 3', 'Simulate GitHub Release Asset Discovery');

    // Simulate GitHub Release metadata payload returned by api.github.com
    $mockGitHubRelease = [
        'tag_name' => "v{$targetVersion}",
        'name' => "Release v{$targetVersion}",
        'html_url' => 'https://github.com/ABDO-TECK/pos/releases/tag/v' . $targetVersion,
        'body' => "### Changelog\n- Simulated end-to-end update test marker\n- Verification of atomic file updates and rollback",
        'published_at' => date('c'),
        'assets' => [
            [
                'name' => 'manifest.json',
                'browser_download_url' => 'https://github.com/ABDO-TECK/pos/releases/download/v' . $targetVersion . '/manifest.json',
                'size' => strlen($manifestJson),
            ],
            [
                'name' => 'manifest.sig',
                'browser_download_url' => 'https://github.com/ABDO-TECK/pos/releases/download/v' . $targetVersion . '/manifest.sig',
                'size' => strlen($signature),
            ],
            [
                'name' => "delta-{$baseVersion}-to-{$targetVersion}.zip",
                'browser_download_url' => 'https://github.com/ABDO-TECK/pos/releases/download/v' . $targetVersion . "/delta-{$baseVersion}-to-{$targetVersion}.zip",
                'size' => filesize($zipPath),
            ],
        ]
    ];

    $provider = new GitHubReleaseProvider();

    // Map assets using same mapping rules as GitHubReleaseProvider
    $manifestUrl = null;
    $signatureUrl = null;
    $deltaUrl = null;
    foreach ($mockGitHubRelease['assets'] as $asset) {
        $name = strtolower($asset['name']);
        if ($name === 'manifest.json') {
            $manifestUrl = $asset['browser_download_url'];
        } elseif (str_ends_with($name, '.sig')) {
            $signatureUrl = $asset['browser_download_url'];
        } elseif (str_starts_with($name, 'delta-') && str_ends_with($name, '.zip')) {
            $deltaUrl = $asset['browser_download_url'];
        }
    }

    logInfo("Discovered Manifest URL: " . ($manifestUrl ?? 'NONE'));
    logInfo("Discovered Signature URL: " . ($signatureUrl ?? 'NONE'));
    logInfo("Discovered Delta Package URL: " . ($deltaUrl ?? 'NONE'));

    if (empty($manifestUrl) || empty($signatureUrl) || empty($deltaUrl)) {
        throw new RuntimeException("GitHub Release asset discovery did not match all required delta assets.");
    }
    logSuccess("GitHub Release asset discovery matched manifest.json, manifest.sig, and delta ZIP");
    $results['phase3'] = true;


    // ══════════════════════════════════════════════════════════════
    // PHASE 4 - CLIENT UPDATE SIMULATION (Backup -> Atomic Apply -> Complete)
    // ══════════════════════════════════════════════════════════════
    logStep('PHASE 4', 'Client Update Execution (Backup, Staging, Verification, Atomic Apply)');

    // 1. Stage files from release package into staging directory
    $stagingDir = $deltaService->getStagingDir($targetVersion);
    $stageResult = $deltaService->extractZipToStaging($zipPath, $stagingDir);
    if (!$stageResult['ok']) {
        throw new RuntimeException("Zip extraction failed: " . implode('; ', $stageResult['errors']));
    }
    logSuccess("Extracted staged files with ZipSlip traversal guards ({$stageResult['extracted_count']} files)");

    // 2. Verify staged hashes against manifest
    $verifyStaged = $manifestService->verifyStagedFiles($stagingDir, $manifestData['files']);
    if (!$verifyStaged['ok']) {
        throw new RuntimeException("Staged file verification failed.");
    }
    logSuccess("Verified all staged files against manifest SHA-256 hashes");

    // 3. Create Pre-Update Backup Snapshot
    $backupResult = $deltaService->createBackupSnapshot($baseVersion, $targetVersion, $manifestData);
    if (!$backupResult['ok']) {
        throw new RuntimeException("Backup snapshot failed: " . ($backupResult['error'] ?? 'unknown'));
    }
    $snapshotPath = $backupResult['snapshot_path'];
    logSuccess("Created pre-update atomic backup snapshot at: {$snapshotPath}");
    logInfo("Backed up files count: " . count($backupResult['backed_up_files']));

    // 4. Apply Delta Atomically
    $applyResult = $deltaService->applyStagedFiles($manifestData, $snapshotPath);
    if (!$applyResult['ok']) {
        throw new RuntimeException("Delta apply failed: " . implode('; ', $applyResult['errors']));
    }
    logSuccess("Atomically applied delta update (" . count($applyResult['applied_files']) . " files replaced)");



    // 5. Verify file on disk is now the updated version
    $diskContent = file_get_contents($testFullPath);
    if ($diskContent !== $testUpdatedContent) {
        throw new RuntimeException("Updated file content on disk did not match expected target content!");
    }
    logSuccess("Verified live file content on disk matches target version payload");

    // 6. Verify update state is completed
    $finalState = $deltaService->getUpdateState();
    if (($finalState['state'] ?? '') !== 'completed') {
        throw new RuntimeException("Update state expected 'completed', got: " . ($finalState['state'] ?? 'null'));
    }
    logSuccess("Update transaction state recorded as 'completed'");
    $results['phase4'] = true;

    // ══════════════════════════════════════════════════════════════
    // PHASE 5 - CONTROLLED SECURITY & INTEGRITY FAILURE TEST
    // ══════════════════════════════════════════════════════════════
    logStep('PHASE 5', 'Controlled Failure Test (Tampered Manifest Signature)');

    $tamperedManifest = $manifestData;
    $tamperedManifest['files'][0]['sha256'] = '0000000000000000000000000000000000000000000000000000000000000000';
    $tamperedManifestJson = json_encode($tamperedManifest);

    // Attempt verification with the genuine signature against tampered manifest
    $isTamperedValid = $sigService->verifySignature($tamperedManifestJson, $signature, $publicKeyPath);
    if ($isTamperedValid) {
        throw new RuntimeException("SECURITY FAILURE: Tampered manifest was accepted by RSA verification!");
    }
    logSuccess("Tampered manifest signature REJECTED by RSA verification (MITM / Tampering Blocked)");

    // Test Corrupted Staged File Hash
    $corruptStagingDir = $tempSimDir . '/corrupt_staging';
    @mkdir($corruptStagingDir . '/' . dirname($testRelativePath), 0755, true);
    file_put_contents($corruptStagingDir . '/' . $testRelativePath, 'CORRUPTED_TAMPERED_CONTENT');

    $corruptVerify = $manifestService->verifyStagedFiles($corruptStagingDir, $manifestData['files']);
    if ($corruptVerify['ok']) {
        throw new RuntimeException("SECURITY FAILURE: Corrupted file was accepted by SHA-256 verification!");
    }
    logSuccess("Corrupted staged file REJECTED by SHA-256 verification (Hash Mismatch Caught)");
    $results['phase5'] = true;

    // ══════════════════════════════════════════════════════════════
    // PHASE 6 - ATOMIC ROLLBACK TEST
    // ══════════════════════════════════════════════════════════════
    logStep('PHASE 6', 'Atomic Rollback Test (Restore to Snapshot)');

    logInfo("Triggering rollback using snapshot: {$snapshotPath}");
    $rollbackResult = $deltaService->rollbackFiles($snapshotPath);
    if (!$rollbackResult['ok']) {
        throw new RuntimeException("Rollback failed: " . implode('; ', $rollbackResult['errors']));
    }
    $deltaService->setUpdateState('rolled_back', ['snapshot' => $snapshotPath]);
    logSuccess("Rollback completed (" . count($rollbackResult['restored_files']) . " files restored)");

    // Verify disk content is restored to original
    $restoredDiskContent = file_get_contents($testFullPath);
    if ($restoredDiskContent !== $testInitialContent) {
        throw new RuntimeException("Rollback failed to restore original file content!");
    }
    logSuccess("Verified live file content on disk is 100% restored to original pre-update state");

    $rollbackState = $deltaService->getUpdateState();
    if (($rollbackState['state'] ?? '') !== 'rolled_back') {
        throw new RuntimeException("Expected state 'rolled_back', got: " . ($rollbackState['state'] ?? 'null'));
    }
    logSuccess("Update transaction state recorded as 'rolled_back'");
    $results['phase6'] = true;


    // Clean up test marker
    @unlink($testFullPath);

} catch (Throwable $e) {
    logFail("Simulation aborted with error: " . $e->getMessage());
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
} finally {
    // Clean up temporary simulation directory
    if (is_dir($tempSimDir)) {
        // recursive clean
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempSimDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            @$todo($fileinfo->getRealPath());
        }
        @rmdir($tempSimDir);
    }
    // Clean delta update state
    $deltaService->clearUpdateState();
}

echo "\n{$BOLD}================================================================================{$RESET}\n";
echo "{$BOLD}SIMULATION SUMMARY & VERDICT{$RESET}\n";
echo "{$BOLD}================================================================================{$RESET}\n";
$allPassed = count(array_filter($results)) === 6;
foreach ($results as $phase => $passed) {
    echo "  " . strtoupper($phase) . ": " . ($passed ? "{$GREEN}PASSED ✔{$RESET}" : "{$RED}FAILED ✖{$RESET}") . "\n";
}

if ($allPassed) {
    echo "\n{$GREEN}{$BOLD}🎉 ALL 6 SIMULATION PHASES COMPLETED WITH 100% SUCCESS!{$RESET}\n";
    echo "The update engine is verified for live production release cycles.\n\n";
    exit(0);
} else {
    echo "\n{$RED}{$BOLD}❌ SOME SIMULATION PHASES FAILED. REVIEW LOGS ABOVE.{$RESET}\n\n";
    exit(1);
}
