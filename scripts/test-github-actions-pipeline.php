<?php
declare(strict_types=1);

/**
 * End-to-End Simulation of GitHub Actions Release Pipeline (v1.1.49)
 * 
 * Verifies:
 *  - Automated Delta Detection & Packaging
 *  - RSA-2048 Cryptographic Signing via Environment Secret
 *  - Ephemeral Secret Cleanup
 *  - Clean Client Upgrade from v1.1.48 to v1.1.49
 *  - Pipeline Failure Guards
 */

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\DeltaUpdateService;
use App\Services\ManifestSignatureService;
use App\Services\UpdateManifestService;

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
$sandboxDir = $rootDir . '/backend/storage/gh_actions_sim_' . time();
@mkdir($sandboxDir, 0755, true);

$phpBinary = PHP_BINARY;
$sigService = new ManifestSignatureService();
$manifestService = new UpdateManifestService();
$pubKeyPath = $rootDir . '/backend/certs/update_public_key.pem';
$privateKeyPath = $rootDir . '/release/private_key.pem';
$privateKeyContent = (string) file_get_contents($privateKeyPath);

$pipelineResults = [];

try {
    // ══════════════════════════════════════════════════════════════
    // PHASE 1 & 2: GitHub Actions Automated Execution Simulation
    // ══════════════════════════════════════════════════════════════
    logHeader('PHASE 1: GitHub Actions CI/CD Pipeline Execution (Tag: v1.1.49)');

    $releaseOutputDir = $sandboxDir . '/artifacts/1.1.49';
    @mkdir($releaseOutputDir, 0755, true);

    // Simulate GitHub Actions runner executing build-release-package with UPDATE_PRIVATE_KEY secret env
    putenv("UPDATE_PRIVATE_KEY={$privateKeyContent}");
    putenv("RELEASE_TAG=v1.1.49");

    $buildCmd = "\"{$phpBinary}\" scripts/build-release-package.php --tag=v1.1.49 --output-dir=\"{$releaseOutputDir}\" 2>&1";
    exec($buildCmd, $buildOutput, $buildCode);
    
    // Clear secret from environment immediately
    putenv("UPDATE_PRIVATE_KEY=");

    if ($buildCode !== 0) {
        throw new RuntimeException("Pipeline build failed with code {$buildCode}: " . implode("\n", $buildOutput));
    }
    logOk("GitHub Actions builder executed cleanly with exit code 0");

    // Check generated assets
    $deltaZip = $releaseOutputDir . '/delta-1.1.48-to-1.1.49.zip';
    $genericZip = $releaseOutputDir . '/delta.zip';
    $manifestFile = $releaseOutputDir . '/manifest.json';
    $sigFile = $releaseOutputDir . '/manifest.sig';
    $notesFile = $releaseOutputDir . '/release-notes.md';

    if (!file_exists($manifestFile) || !file_exists($sigFile) || !file_exists($notesFile)) {
        throw new RuntimeException("Required release assets were not generated in {$releaseOutputDir}");
    }

    $actualZip = file_exists($deltaZip) ? $deltaZip : $genericZip;
    if (!file_exists($actualZip)) {
        throw new RuntimeException("Delta ZIP package was not found in {$releaseOutputDir}");
    }

    $zipSizeKb = round(filesize($actualZip) / 1024, 2);
    logOk("Generated Delta Package: " . basename($actualZip) . " ({$zipSizeKb} KB)");
    logOk("Generated Manifest: manifest.json (" . round(filesize($manifestFile) / 1024, 2) . " KB)");
    logOk("Generated Digital Signature: manifest.sig");
    logOk("Generated Release Notes: release-notes.md");

    // ══════════════════════════════════════════════════════════════
    // PHASE 3: Security & Cryptographic Validation
    // ══════════════════════════════════════════════════════════════
    logHeader('PHASE 2: Security & Cryptographic Validation');

    // 1. Verify RSA-2048 Digital Signature
    $manifestJson = (string) file_get_contents($manifestFile);
    $signature = (string) file_get_contents($sigFile);
    $sigValid = $sigService->verifySignature($manifestJson, $signature, $pubKeyPath);
    if (!$sigValid) {
        throw new RuntimeException("RSA-2048 signature verification failed!");
    }
    logOk("Manifest digital signature verified 100% against update_public_key.pem");

    // 2. Verify Private Key Redaction in Logs
    $fullLogs = implode("\n", $buildOutput);
    if (str_contains($fullLogs, 'PRIVATE KEY') || str_contains($fullLogs, substr($privateKeyContent, 30, 40))) {
        throw new RuntimeException("Security defect: Private key leaked in build logs!");
    }
    logOk("Verified UPDATE_PRIVATE_KEY secret was never echoed or printed in logs");

    // 3. Verify Ephemeral File Cleanup
    $tempKeyFiles = glob($rootDir . '/backend/storage/.temp_signing_key_*.pem');
    if (!empty($tempKeyFiles)) {
        throw new RuntimeException("Temporary signing keys were not wiped from disk!");
    }
    logOk("Verified ephemeral signing key files were securely deleted");

    // 4. Verify Delta Package Contains Zero Secrets
    $zip = new ZipArchive();
    $zip->open($actualZip);
    $packedFiles = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $packedFiles[] = $zip->getNameIndex($i);
    }
    $zip->close();

    foreach ($packedFiles as $pf) {
        if (str_ends_with($pf, '.pem') || str_ends_with($pf, '.env') || str_starts_with($pf, 'storage/')) {
            throw new RuntimeException("Security defect: Secret/internal file found in delta package: {$pf}");
        }
    }
    logOk("Verified delta package contains only public production files: " . implode(', ', $packedFiles));
    $pipelineResults['security_validation'] = true;

    // ══════════════════════════════════════════════════════════════
    // PHASE 4: Client Upgrade Simulation (v1.1.48 -> v1.1.49)
    // ══════════════════════════════════════════════════════════════
    logHeader('PHASE 3: Client Update Simulation (Client on v1.1.48)');

    $clientDir = $sandboxDir . '/client_pos';
    @mkdir($clientDir . '/backend/Helpers', 0755, true);
    @mkdir($clientDir . '/backend/storage/update-backups', 0755, true);

    // Initial v1.1.48 client state
    $v148Version = [
        'version' => '1.1.48',
        'application_version' => '1.1.48',
        'update_engine_version' => '1.0.0',
        'released_at' => '2026-08-27',
        'changelog' => ['Incremental health check diagnostics update']
    ];
    file_put_contents($clientDir . '/version.json', json_encode($v148Version, JSON_PRETTY_PRINT));
    file_put_contents($clientDir . '/backend/Helpers/Logger.php', "<?php\nnamespace App\Helpers;\nclass Logger { public const V = '1.1.48'; }\n");

    $manifestData = json_decode($manifestJson, true);
    $clientDeltaService = new DeltaUpdateService($manifestService, $clientDir, $clientDir . '/backend/storage');

    // Extract to staging with ZipSlip protection
    $stagingDir = $clientDeltaService->getStagingDir('1.1.49');
    $extractRes = $clientDeltaService->extractZipToStaging($actualZip, $stagingDir);
    if (!$extractRes['ok']) {
        throw new RuntimeException("Extraction failed: " . implode('; ', $extractRes['errors']));
    }
    logOk("Extracted delta files to staging area with path validation");

    // Verify SHA-256 Checksums
    $verifyRes = $manifestService->verifyStagedFiles($stagingDir, $manifestData['files']);
    if (!$verifyRes['ok']) {
        throw new RuntimeException("SHA-256 verification failed on staged files!");
    }
    logOk("Verified SHA-256 checksums on all staged files");

    // Pre-Update Backup Snapshot
    $snapRes = $clientDeltaService->createBackupSnapshot('1.1.48', '1.1.49', $manifestData);
    if (!$snapRes['ok']) {
        throw new RuntimeException("Backup snapshot failed!");
    }
    $snapPath = $snapRes['snapshot_path'];
    logOk("Created pre-update snapshot: " . basename($snapPath));

    // Apply Staged Files
    $applyRes = $clientDeltaService->applyStagedFiles($manifestData, $snapPath);
    if (!$applyRes['ok']) {
        throw new RuntimeException("Apply staged files failed: " . implode('; ', $applyRes['errors']));
    }
    logOk("Applied delta update (" . count($applyRes['applied_files']) . " files replaced)");

    // Confirm updated state
    $upgradedJson = json_decode((string) file_get_contents($clientDir . '/version.json'), true);
    if (($upgradedJson['version'] ?? '') !== '1.1.49' || ($upgradedJson['update_engine_version'] ?? '') !== '1.0.0') {
        throw new RuntimeException("Client version.json was not updated to v1.1.49!");
    }
    logOk("Client successfully upgraded to version: " . $upgradedJson['version']);

    $upgradedLoggerContent = (string) file_get_contents($clientDir . '/backend/Helpers/Logger.php');
    if (!str_contains($upgradedLoggerContent, 'private_key')) {
        throw new RuntimeException("Logger.php on client does not contain updated redaction logic!");
    }
    logOk("Confirmed updated Logger.php logic active on client");
    $pipelineResults['client_upgrade'] = true;

    // ══════════════════════════════════════════════════════════════
    // PHASE 5: Pipeline Failure Guard Checks
    // ══════════════════════════════════════════════════════════════
    logHeader('PHASE 4: Pipeline Failure Guard Checks');

    // Scenario A: Version Mismatch
    $mismatchCmd = "\"{$phpBinary}\" scripts/build-release-package.php --tag=v9.9.99 --output-dir=\"{$sandboxDir}/fail1\" 2>&1";
    exec($mismatchCmd, $misOut, $misCode);
    if ($misCode === 0) {
        throw new RuntimeException("Pipeline failed to reject version mismatch!");
    }
    logOk("Pipeline rejected version mismatch correctly (Exit code {$misCode})");

    // Scenario B: Missing UPDATE_PRIVATE_KEY
    putenv("UPDATE_PRIVATE_KEY=");
    $noKeyCmd = "\"{$phpBinary}\" scripts/build-release-package.php --tag=v1.1.49 --private-key=missing.pem --output-dir=\"{$sandboxDir}/fail2\" 2>&1";
    exec($noKeyCmd, $noKeyOut, $noKeyCode);
    if ($noKeyCode === 0) {
        throw new RuntimeException("Pipeline failed to reject missing private key!");
    }
    logOk("Pipeline rejected missing private key correctly (Exit code {$noKeyCode})");
    $pipelineResults['failure_guards'] = true;

} catch (Throwable $e) {
    logErr("Test execution failed: " . $e->getMessage());
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
} finally {
    if (is_dir($sandboxDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sandboxDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $todo = ($f->isDir() ? 'rmdir' : 'unlink');
            @$todo($f->getRealPath());
        }
        @rmdir($sandboxDir);
    }
}

logHeader('GITHUB ACTIONS RELEASE AUTOMATION TEST SUMMARY');
$allPassed = count(array_filter($pipelineResults)) === 3;
foreach ($pipelineResults as $name => $passed) {
    echo "  " . strtoupper($name) . ": " . ($passed ? "{$GREEN}PASSED ✔{$RESET}" : "{$RED}FAILED ✖{$RESET}") . "\n";
}

if ($allPassed) {
    echo "\n{$GREEN}{$BOLD}🎉 GITHUB ACTIONS PRODUCTION RELEASE v1.1.49 VALIDATED WITH 100% SUCCESS!{$RESET}\n\n";
    exit(0);
} else {
    echo "\n{$RED}{$BOLD}❌ SOME PIPELINE CHECKS FAILED.{$RESET}\n\n";
    exit(1);
}
