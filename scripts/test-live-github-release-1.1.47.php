<?php
declare(strict_types=1);

/**
 * POS v1.1.47 Live GitHub Release Verification Suite
 * Verifies live asset availability, signature validity, and legacy client update discovery.
 */

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\GitHubReleaseProvider;
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
    $tag = 'v1.1.47';
    $token = trim((string) @shell_exec('gh auth token') ?: '');
    $provider = new GitHubReleaseProvider('ABDO-TECK', 'pos', $token ?: null);
    $sigService = new ManifestSignatureService();
    $manifestService = new UpdateManifestService();

    // ══════════════════════════════════════════════════════════════
    // 1. LIVE ASSET DISCOVERY & AVAILABILITY
    // ══════════════════════════════════════════════════════════════
    logHeader('TEST 1: Live GitHub Release Ingestion & Asset Reachability (v1.1.47)');

    $manifestUrl = "https://github.com/{$repo}/releases/download/{$tag}/manifest.json";
    $sigUrl = "https://github.com/{$repo}/releases/download/{$tag}/manifest.sig";
    $packageUrl = "https://github.com/{$repo}/releases/download/{$tag}/full-package.zip";

    echo "  Downloading live manifest from: {$manifestUrl}...\n";
    $ch = curl_init($manifestUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'POS-Client-Verification');
    $liveManifest = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($liveManifest)) {
        throw new RuntimeException("Failed to fetch live manifest.json (HTTP {$httpCode})");
    }
    logOk("Live manifest.json successfully fetched from GitHub Releases (HTTP 200).");

    echo "  Downloading live signature from: {$sigUrl}...\n";
    $ch = curl_init($sigUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'POS-Client-Verification');
    $liveSig = curl_exec($ch);
    $sigHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($sigHttpCode !== 200 || empty($liveSig)) {
        throw new RuntimeException("Failed to fetch live manifest.sig (HTTP {$sigHttpCode})");
    }
    logOk("Live manifest.sig successfully fetched from GitHub Releases (HTTP 200).");
    $results['test1_asset_availability'] = true;

    // ══════════════════════════════════════════════════════════════
    // 2. LIVE CRYPTOGRAPHIC SIGNATURE & SHA-256 VERIFICATION
    // ══════════════════════════════════════════════════════════════
    logHeader('TEST 2: RSA-2048 Cryptographic Signature Validation');

    $sigValid = $sigService->verifySignature($liveManifest, $liveSig);
    if (!$sigValid) {
        throw new RuntimeException("Live GitHub manifest failed RSA-2048 signature verification!");
    }
    logOk("RSA-2048 SHA-256 Signature verified against pinned public key.");

    $manifestData = json_decode($liveManifest, true);
    if (($manifestData['version'] ?? '') !== '1.1.47') {
        throw new RuntimeException("Manifest version mismatch: Expected 1.1.47, got " . ($manifestData['version'] ?? 'null'));
    }
    logOk("Manifest version verified: v1.1.47 (Engine: " . ($manifestData['update_engine_version'] ?? '1.0.0') . ").");
    $results['test2_signature_verification'] = true;

    // ══════════════════════════════════════════════════════════════
    // 3. LIVE FULL PACKAGE DOWNLOAD STREAM AVAILABILITY
    // ══════════════════════════════════════════════════════════════
    logHeader('TEST 3: Live full-package.zip Stream Reachability');

    echo "  Checking package download availability at: {$packageUrl}...\n";
    $ch = curl_init($packageUrl);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'POS-Client-Verification');
    curl_exec($ch);
    $pkgHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);

    if ($pkgHttpCode !== 200) {
        throw new RuntimeException("Live full-package.zip not reachable (HTTP {$pkgHttpCode})");
    }
    $sizeMb = round($contentLength / (1024 * 1024), 2);
    logOk("Live full-package.zip reachable and streamable ({$sizeMb} MB, HTTP 200 OK).");
    $results['test3_package_stream'] = true;

    // ══════════════════════════════════════════════════════════════
    // 4. LEGACY CLIENT DISCOVERY SIMULATION (v1.1.46)
    // ══════════════════════════════════════════════════════════════
    logHeader('TEST 4: Legacy Client Discovery Simulation (v1.1.46 -> v1.1.47)');

    $compat = $manifestService->checkEngineCompatibility(null, $manifestData);
    if (!$compat['compatible'] || !$compat['requires_bootstrap']) {
        throw new RuntimeException("Legacy client compatibility check failed: " . json_encode($compat));
    }
    logOk("Legacy Client (v1.1.46) recognized as requiring Bootstrap Migration package.");
    logOk("Bootstrap Package URL: {$packageUrl}");
    $results['test4_legacy_discovery'] = true;

} catch (Throwable $e) {
    logErr("Live Verification Failed: " . $e->getMessage());
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
}

logHeader('LIVE GITHUB RELEASE v1.1.47 VERIFICATION SUMMARY');
$allSuccess = count(array_filter($results)) === 4;
foreach ($results as $name => $passed) {
    echo "  " . str_pad(strtoupper($name), 35) . ": " . ($passed ? "{$GREEN}PASSED ✔{$RESET}" : "{$RED}FAILED ✖{$RESET}") . "\n";
}

if ($allSuccess) {
    echo "\n{$GREEN}{$BOLD}🎉 ALL 4 LIVE GITHUB RELEASE VERIFICATIONS PASSED 100%! RELEASE v1.1.47 IS LIVE & OPERATIONAL.{$RESET}\n\n";
    exit(0);
} else {
    echo "\n{$RED}{$BOLD}❌ SOME LIVE VERIFICATION TESTS FAILED.{$RESET}\n\n";
    exit(1);
}
