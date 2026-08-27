<?php
declare(strict_types=1);

/**
 * Real Production Delta Release v1.1.47 -> v1.1.48 Simulation & Validation Script
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

function logStep(string $title): void {
    global $CYAN, $BOLD, $RESET;
    echo "\n{$CYAN}{$BOLD}================================================================================{$RESET}\n";
    echo "{$CYAN}{$BOLD}{$title}{$RESET}\n";
    echo "{$CYAN}{$BOLD}================================================================================{$RESET}\n\n";
}

function logOk(string $msg): void {
    global $GREEN, $RESET;
    echo "  {$GREEN}✔ [PASS]{$RESET} {$msg}\n";
}

function logDetails(string $msg): void {
    global $CYAN, $RESET;
    echo "  {$CYAN}ℹ [INFO]{$RESET} {$msg}\n";
}

function logErr(string $msg): void {
    global $RED, $BOLD, $RESET;
    echo "  {$RED}{$BOLD}✖ [FAIL]{$RESET} {$msg}\n";
}

$rootDir = realpath(__DIR__ . '/..');
$tempEnvDir = $rootDir . '/backend/storage/delta_prod_sim_' . time();
@mkdir($tempEnvDir, 0755, true);

$manifestService = new UpdateManifestService();
$sigService = new ManifestSignatureService();
$publicKeyPath = $rootDir . '/backend/certs/update_public_key.pem';
$releaseDir = $rootDir . '/release/1.1.48';

$results = [];

try {
    // ══════════════════════════════════════════════════════════════
    // 1. Validate Release Assets & Signatures
    // ══════════════════════════════════════════════════════════════
    logStep('1. Validate Delta Release Package Assets & Cryptographic Signature');

    $manifestPath = $releaseDir . '/manifest.json';
    $sigPath = $releaseDir . '/manifest.sig';
    $zipPath = $releaseDir . '/delta-1.1.47-to-1.1.48.zip';

    if (!is_file($manifestPath) || !is_file($sigPath) || !is_file($zipPath)) {
        throw new RuntimeException("Missing one or more required release artifacts in {$releaseDir}");
    }

    $manifestJson = (string) file_get_contents($manifestPath);
    $signature = (string) file_get_contents($sigPath);

    // Validate RSA-2048 Signature
    $sigValid = $sigService->verifySignature($manifestJson, $signature, $publicKeyPath);
    if (!$sigValid) {
        throw new RuntimeException("RSA-2048 digital signature verification failed for manifest.json!");
    }
    logOk("Verified RSA-2048 / SHA-256 digital signature against embedded public key");

    // Validate Manifest Structure
    $manifestData = json_decode($manifestJson, true);
    $validation = $manifestService->validateManifest($manifestData);
    if (!$validation['valid']) {
        throw new RuntimeException("Manifest schema invalid: " . implode('; ', $validation['errors']));
    }
    logOk("Validated manifest structure: version={$manifestData['version']}, type={$manifestData['type']}");

    // Validate ZIP contents
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException("Could not open delta ZIP package: {$zipPath}");
    }
    $zipFilesCount = $zip->numFiles;
    $zip->close();
    logOk("Delta archive contains {$zipFilesCount} files (" . round(filesize($zipPath) / 1024, 2) . " KB)");
    $results['package_validation'] = true;

    // ══════════════════════════════════════════════════════════════
    // 2. Real Client Update Simulation (v1.1.47 -> v1.1.48)
    // ══════════════════════════════════════════════════════════════
    logStep('2. Real Client Upgrade Simulation (Client on v1.1.47 with Engine 1.0.0)');

    $clientDir = $tempEnvDir . '/client';
    @mkdir($clientDir . '/backend/Services', 0755, true);
    @mkdir($clientDir . '/backend/storage/update-backups', 0755, true);

    // Set up client version.json as v1.1.47 with update engine 1.0.0
    $v147Json = [
        'version' => '1.1.47',
        'application_version' => '1.1.47',
        'update_engine_version' => '1.0.0',
        'released_at' => '2026-08-27',
        'changelog' => ['Bootstrap migration release']
    ];
    file_put_contents($clientDir . '/version.json', json_encode($v147Json, JSON_PRETTY_PRINT));

    // Place initial v1.1.47 version of HealthService
    $initialHealthServiceContent = "<?php\nnamespace App\Services;\nclass HealthService { public const V = '1.1.47'; }\n";
    file_put_contents($clientDir . '/backend/Services/HealthService.php', $initialHealthServiceContent);

    $clientDeltaService = new DeltaUpdateService($manifestService, $clientDir, $clientDir . '/backend/storage');

    // A. Engine & Version Compatibility Check
    $engineCheck = $manifestService->checkEngineCompatibility('1.0.0', $manifestData);
    if (!$engineCheck['compatible'] || $engineCheck['requires_bootstrap']) {
        throw new RuntimeException("Client engine 1.0.0 was rejected for delta update.");
    }
    logOk("Client update engine 1.0.0 confirmed compatible for incremental delta update");

    $versionCheck = $manifestService->checkVersionCompatibility('1.1.47', $manifestData);
    if (!$versionCheck['compatible']) {
        throw new RuntimeException("Version compatibility check failed: " . ($versionCheck['reason'] ?? ''));
    }
    logOk("Version compatibility confirmed: v1.1.47 -> v1.1.48");

    // B. Extract Delta ZIP to Staging
    $stagingDir = $clientDeltaService->getStagingDir('1.1.48');
    $extractRes = $clientDeltaService->extractZipToStaging($zipPath, $stagingDir);
    if (!$extractRes['ok']) {
        throw new RuntimeException("Extraction failed: " . implode('; ', $extractRes['errors']));
    }
    logOk("Extracted {$extractRes['extracted_count']} staged delta files with ZipSlip guards");

    // C. Verify SHA-256 Hashes of Staged Files
    $verifyStaged = $manifestService->verifyStagedFiles($stagingDir, $manifestData['files']);
    if (!$verifyStaged['ok']) {
        throw new RuntimeException("Staged file SHA-256 verification failed!");
    }
    logOk("Verified all staged files against manifest SHA-256 checksums");

    // D. Pre-Update Backup Snapshot
    $snapshotRes = $clientDeltaService->createBackupSnapshot('1.1.47', '1.1.48', $manifestData);
    if (!$snapshotRes['ok']) {
        throw new RuntimeException("Backup snapshot failed: " . ($snapshotRes['error'] ?? ''));
    }
    $snapshotPath = $snapshotRes['snapshot_path'];
    logOk("Created atomic backup snapshot: " . basename($snapshotPath));

    // E. Apply Staged Delta Files
    $applyRes = $clientDeltaService->applyStagedFiles($manifestData, $snapshotPath);
    if (!$applyRes['ok']) {
        throw new RuntimeException("Failed to apply staged delta files: " . implode('; ', $applyRes['errors']));
    }
    logOk("Atomically applied delta update (" . count($applyRes['applied_files']) . " files replaced)");

    // F. Verify Upgraded Client State
    $upgradedVersionJson = json_decode((string) file_get_contents($clientDir . '/version.json'), true);
    if (($upgradedVersionJson['version'] ?? '') !== '1.1.48') {
        throw new RuntimeException("Client version expected 1.1.48, got: " . ($upgradedVersionJson['version'] ?? 'null'));
    }
    logOk("Verified client version.json updated to v1.1.48 (Update Engine: 1.0.0)");

    $healthContentOnDisk = (string) file_get_contents($clientDir . '/backend/Services/HealthService.php');
    if (!str_contains($healthContentOnDisk, 'update_infrastructure')) {
        throw new RuntimeException("HealthService on disk does not contain the v1.1.48 update infrastructure check!");
    }
    logOk("Verified production HealthService on disk updated with new diagnostic code");

    $results['client_update'] = true;

    // ══════════════════════════════════════════════════════════════
    // 3. Security Failure & Rollback Test
    // ══════════════════════════════════════════════════════════════
    logStep('3. Security Failure Resistance & Atomic Rollback');

    // A. Signature Tampering Test
    $tamperedManifest = $manifestData;
    $tamperedManifest['files'][0]['sha256'] = str_repeat('a', 64);
    $tamperedJson = json_encode($tamperedManifest);
    $tamperedSigValid = $sigService->verifySignature($tamperedJson, $signature, $publicKeyPath);
    if ($tamperedSigValid) {
        throw new RuntimeException("Security defect: Tampered manifest accepted!");
    }
    logOk("Tampered manifest signature REJECTED by RSA verification");

    // B. Rollback Verification
    logDetails("Executing atomic rollback using snapshot: " . basename($snapshotPath));
    $rollbackRes = $clientDeltaService->rollbackFiles($snapshotPath);
    if (!$rollbackRes['ok']) {
        throw new RuntimeException("Rollback failed: " . implode('; ', $rollbackRes['errors']));
    }
    logOk("Rollback completed successfully (" . count($rollbackRes['restored_files']) . " files restored)");

    // Verify disk content restored to v1.1.47
    $restoredVersionJson = json_decode((string) file_get_contents($clientDir . '/version.json'), true);
    if (($restoredVersionJson['version'] ?? '') !== '1.1.47') {
        throw new RuntimeException("Version was not restored to v1.1.47 after rollback!");
    }
    logOk("Confirmed client version restored to v1.1.47");

    $restoredHealthContent = (string) file_get_contents($clientDir . '/backend/Services/HealthService.php');
    if ($restoredHealthContent !== $initialHealthServiceContent) {
        throw new RuntimeException("HealthService file content was not restored to original v1.1.47 state!");
    }
    logOk("Confirmed file contents restored 100% to original pre-update state");
    $results['rollback_verification'] = true;

} catch (Throwable $e) {
    logErr("Test execution failed: " . $e->getMessage());
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
} finally {
    if (is_dir($tempEnvDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempEnvDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $todo = ($f->isDir() ? 'rmdir' : 'unlink');
            @$todo($f->getRealPath());
        }
        @rmdir($tempEnvDir);
    }
}

logStep('PRODUCTION DELTA RELEASE v1.1.48 TEST SUMMARY');
$allPassed = count(array_filter($results)) === 3;
foreach ($results as $k => $p) {
    echo "  " . strtoupper($k) . ": " . ($p ? "{$GREEN}PASSED ✔{$RESET}" : "{$RED}FAILED ✖{$RESET}") . "\n";
}

if ($allPassed) {
    echo "\n{$GREEN}{$BOLD}🎉 PRODUCTION DELTA RELEASE v1.1.48 VALIDATED WITH 100% SUCCESS!{$RESET}\n\n";
    exit(0);
} else {
    echo "\n{$RED}{$BOLD}❌ SOME TESTS FAILED.{$RESET}\n\n";
    exit(1);
}
