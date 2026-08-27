<?php
declare(strict_types=1);

/**
 * Release Automation Workflow Simulation & Validation Test Suite
 * 
 * Verifies:
 *  1. Bootstrap Release Packaging & Signing
 *  2. Delta Release Packaging & Signing
 *  3. Version Mismatch Rejection
 *  4. Missing Private Key Error Handling
 *  5. Build Integrity
 */

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\ManifestSignatureService;
use App\Services\UpdateManifestService;

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

function logPass(string $msg): void {
    global $GREEN, $RESET;
    echo "  {$GREEN}✔ [PASS]{$RESET} {$msg}\n";
}

function logInfo(string $msg): void {
    global $CYAN, $RESET;
    echo "  {$CYAN}ℹ [INFO]{$RESET} {$msg}\n";
}

function logFail(string $msg): void {
    global $RED, $BOLD, $RESET;
    echo "  {$RED}{$BOLD}✖ [FAIL]{$RESET} {$msg}\n";
}

$rootDir = realpath(__DIR__ . '/..');
$tempTestDir = $rootDir . '/backend/storage/release_sim_test_' . time();
@mkdir($tempTestDir, 0755, true);

$phpBinary = PHP_BINARY;
$sigService = new ManifestSignatureService();
$manifestService = new UpdateManifestService();
$pubKeyPath = $rootDir . '/backend/certs/update_public_key.pem';
$privateKeyPath = $rootDir . '/release/private_key.pem';

$testResults = [];

try {
    // ══════════════════════════════════════════════════════════════
    // TEST 1: Bootstrap Release Packaging & Signing
    // ══════════════════════════════════════════════════════════════
    logStep('TEST 1: Bootstrap Tag Release Packaging & Signing');

    $bootstrapOut = $tempTestDir . '/1.1.47-bootstrap';
    $cmd1 = "\"{$phpBinary}\" scripts/build-release-package.php --tag=v1.1.47-bootstrap --private-key=\"{$privateKeyPath}\" --output-dir=\"{$bootstrapOut}\" 2>&1";
    
    // Temporarily mock version.json to 1.1.47 for this check
    $origVersionJson = file_get_contents($rootDir . '/version.json');
    $mock147 = json_decode($origVersionJson, true);
    $mock147['version'] = '1.1.47';
    file_put_contents($rootDir . '/version.json', json_encode($mock147));

    exec($cmd1, $out1, $code1);
    file_put_contents($rootDir . '/version.json', $origVersionJson);

    if ($code1 !== 0) {
        throw new RuntimeException("Bootstrap build script failed with code {$code1}: " . implode("\n", $out1));
    }

    if (!file_exists($bootstrapOut . '/full-package.zip') || !file_exists($bootstrapOut . '/manifest.json') || !file_exists($bootstrapOut . '/manifest.sig')) {
        throw new RuntimeException("Missing required bootstrap artifacts in {$bootstrapOut}");
    }

    // Verify RSA signature
    $manifestJson = (string) file_get_contents($bootstrapOut . '/manifest.json');
    $sig = (string) file_get_contents($bootstrapOut . '/manifest.sig');
    if (!$sigService->verifySignature($manifestJson, $sig, $pubKeyPath)) {
        throw new RuntimeException("Bootstrap manifest RSA signature failed verification!");
    }
    logPass("Bootstrap release generated full-package.zip with valid RSA signature");
    $testResults['bootstrap_packaging'] = true;

    // ══════════════════════════════════════════════════════════════
    // TEST 2: Delta Tag Release Packaging & Signing
    // ══════════════════════════════════════════════════════════════
    logStep('TEST 2: Delta Tag Release Packaging & Signing');

    $deltaOut = $tempTestDir . '/1.1.48';
    $cmd2 = "\"{$phpBinary}\" scripts/build-release-package.php --tag=v1.1.48 --from-tag=v1.1.47 --private-key=\"{$privateKeyPath}\" --output-dir=\"{$deltaOut}\" 2>&1";
    exec($cmd2, $out2, $code2);

    if ($code2 !== 0) {
        throw new RuntimeException("Delta build script failed with code {$code2}: " . implode("\n", $out2));
    }

    if (!file_exists($deltaOut . '/manifest.json') || !file_exists($deltaOut . '/manifest.sig') || !file_exists($deltaOut . '/delta.zip')) {
        throw new RuntimeException("Missing delta artifacts in {$deltaOut}");
    }

    // Verify RSA signature
    $deltaMf = (string) file_get_contents($deltaOut . '/manifest.json');
    $deltaSig = (string) file_get_contents($deltaOut . '/manifest.sig');
    if (!$sigService->verifySignature($deltaMf, $deltaSig, $pubKeyPath)) {
        throw new RuntimeException("Delta manifest RSA signature failed verification!");
    }
    logPass("Delta release generated delta.zip and signed manifest with valid RSA signature");
    $testResults['delta_packaging'] = true;

    // ══════════════════════════════════════════════════════════════
    // TEST 3: Version Mismatch Rejection
    // ══════════════════════════════════════════════════════════════
    logStep('TEST 3: Version Mismatch Rejection');

    $mismatchOut = $tempTestDir . '/mismatch';
    $cmd3 = "\"{$phpBinary}\" scripts/build-release-package.php --tag=v9.9.99 --private-key=\"{$privateKeyPath}\" --output-dir=\"{$mismatchOut}\" 2>&1";
    exec($cmd3, $out3, $code3);

    if ($code3 === 0) {
        throw new RuntimeException("Security defect: Build script succeeded on mismatched version tag!");
    }
    logPass("Workflow correctly rejected mismatched version tag v9.9.99 (Exit code: {$code3})");
    $testResults['version_mismatch_rejected'] = true;

    // ══════════════════════════════════════════════════════════════
    // TEST 4: Missing Private Key Rejection
    // ══════════════════════════════════════════════════════════════
    logStep('TEST 4: Missing Private Key Error Handling');

    $noKeyOut = $tempTestDir . '/no_key';
    putenv('UPDATE_PRIVATE_KEY=');
    $cmd4 = "\"{$phpBinary}\" scripts/build-release-package.php --tag=v1.1.48 --private-key=nonexistent.pem --output-dir=\"{$noKeyOut}\" 2>&1";
    exec($cmd4, $out4, $code4);

    if ($code4 === 0) {
        throw new RuntimeException("Security defect: Build script succeeded without private key!");
    }
    logPass("Workflow rejected build when private key is absent (Exit code: {$code4})");
    $testResults['missing_key_rejected'] = true;

    // ══════════════════════════════════════════════════════════════
    // TEST 5: Ephemeral Key Security
    // ══════════════════════════════════════════════════════════════
    logStep('TEST 5: Ephemeral Signing Key Auto-Cleanup');

    $leftoverTempKeys = glob($rootDir . '/backend/storage/.temp_signing_key_*.pem');
    if (!empty($leftoverTempKeys)) {
        throw new RuntimeException("Found leftover temporary signing key files on disk: " . implode(', ', $leftoverTempKeys));
    }
    logPass("Confirmed zero private key remnants left on filesystem");
    $testResults['key_cleanup'] = true;

} catch (Throwable $e) {
    logFail("Test execution failed: " . $e->getMessage());
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
} finally {
    if (is_dir($tempTestDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempTestDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $todo = ($f->isDir() ? 'rmdir' : 'unlink');
            @$todo($f->getRealPath());
        }
        @rmdir($tempTestDir);
    }
}

logStep('RELEASE AUTOMATION TEST SUITE SUMMARY');
$allSuccess = count(array_filter($testResults)) === 5;
foreach ($testResults as $name => $passed) {
    echo "  " . strtoupper($name) . ": " . ($passed ? "{$GREEN}PASSED ✔{$RESET}" : "{$RED}FAILED ✖{$RESET}") . "\n";
}

if ($allSuccess) {
    echo "\n{$GREEN}{$BOLD}🎉 ALL 5 RELEASE AUTOMATION WORKFLOW TESTS PASSED 100%!{$RESET}\n\n";
    exit(0);
} else {
    echo "\n{$RED}{$BOLD}❌ SOME TESTS FAILED.{$RESET}\n\n";
    exit(1);
}
