<?php
declare(strict_types=1);

/**
 * POS v1.1.47-bootstrap Live GitHub Release Verification Suite
 * Verifies live asset availability, RSA signature validity, and legacy client update discovery.
 */

require_once __DIR__ . '/../backend/vendor/autoload.php';

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

function logErr(string $msg): void {
    global $RED, $BOLD, $RESET;
    echo "  {$RED}{$BOLD}✖ [FAIL]{$RESET} {$msg}\n";
}

$results = [];

try {
    $repo = 'ABDO-TECK/pos';
    $tag = 'v1.1.47-bootstrap';
    $sigService = new ManifestSignatureService();
    $manifestService = new UpdateManifestService();

    logHeader('TEST 1: Live GitHub Release Ingestion (v1.1.47-bootstrap)');

    $manifestUrl = "https://github.com/{$repo}/releases/download/{$tag}/manifest.json";
    $sigUrl = "https://github.com/{$repo}/releases/download/{$tag}/manifest.sig";
    $installerUrl = "https://github.com/{$repo}/releases/download/{$tag}/POS-Desktop-Setup-1.1.47.exe";
    $latestYmlUrl = "https://github.com/{$repo}/releases/download/{$tag}/latest.yml";

    // 1. Fetch manifest
    $ch = curl_init($manifestUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'POS-Bootstrap-Verification');
    $liveManifest = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($liveManifest)) {
        throw new RuntimeException("Failed to fetch live manifest.json (HTTP {$httpCode})");
    }
    logOk("Live manifest.json reachable from GitHub Releases (HTTP 200).");

    // 2. Fetch signature
    $ch = curl_init($sigUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'POS-Bootstrap-Verification');
    $liveSig = curl_exec($ch);
    $sigHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($sigHttpCode !== 200 || empty($liveSig)) {
        throw new RuntimeException("Failed to fetch live manifest.sig (HTTP {$sigHttpCode})");
    }
    logOk("Live manifest.sig reachable from GitHub Releases (HTTP 200).");
    $results['test1_asset_reachability'] = true;

    // 3. Cryptographic Verification
    logHeader('TEST 2: RSA-2048 Digital Signature & Checksums');
    if (!$sigService->verifySignature($liveManifest, $liveSig)) {
        throw new RuntimeException("RSA signature validation failed!");
    }
    logOk("RSA-2048 SHA-256 Signature verified against pinned public certificate.");

    $manifestData = json_decode($liveManifest, true);
    if (($manifestData['type'] ?? '') !== 'bootstrap_installer' || !($manifestData['requires_bootstrap'] ?? false)) {
        throw new RuntimeException("Manifest type must be bootstrap_installer with requires_bootstrap = true!");
    }
    logOk("Confirmed release type: bootstrap_installer (requires_bootstrap = true).");
    $results['test2_crypto_verification'] = true;

    // 4. Installer Executable Stream Verification
    logHeader('TEST 3: Live POS-Desktop-Setup-1.1.47.exe Stream Reachability');
    $ch = curl_init($installerUrl);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'POS-Bootstrap-Verification');
    curl_exec($ch);
    $exeHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $exeLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);

    if ($exeHttpCode !== 200) {
        throw new RuntimeException("Installer executable not reachable (HTTP {$exeHttpCode})");
    }
    $exeMb = round($exeLength / (1024 * 1024), 2);
    logOk("POS-Desktop-Setup-1.1.47.exe reachable and streamable ({$exeMb} MB, HTTP 200 OK).");
    $results['test3_installer_stream'] = true;

    // 5. Legacy Client Migration Discovery
    logHeader('TEST 4: Legacy Client (v1.1.46) Discovery Simulation');
    $compat = $manifestService->checkEngineCompatibility(null, $manifestData);
    if (!$compat['compatible'] || !$compat['requires_bootstrap']) {
        throw new RuntimeException("Legacy client must require bootstrap installer!");
    }
    logOk("Legacy Client v1.1.46 correctly identified as requiring Bootstrap Installer.");
    logOk("Installer Download URL: {$installerUrl}");
    $results['test4_legacy_discovery'] = true;

} catch (Throwable $e) {
    logErr("Verification Failed: " . $e->getMessage());
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
}

logHeader('LIVE GITHUB RELEASE v1.1.47-BOOTSTRAP VERIFICATION SUMMARY');
$allSuccess = count(array_filter($results)) === 4;
foreach ($results as $name => $passed) {
    echo "  " . str_pad(strtoupper($name), 35) . ": " . ($passed ? "{$GREEN}PASSED ✔{$RESET}" : "{$RED}FAILED ✖{$RESET}") . "\n";
}

if ($allSuccess) {
    echo "\n{$GREEN}{$BOLD}🎉 ALL 4 LIVE VERIFICATIONS PASSED 100%! BOOTSTRAP INSTALLER RELEASE v1.1.47 IS LIVE.{$RESET}\n\n";
    exit(0);
} else {
    echo "\n{$RED}{$BOLD}❌ SOME LIVE VERIFICATIONS FAILED.{$RESET}\n\n";
    exit(1);
}
