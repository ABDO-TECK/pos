<?php
declare(strict_types=1);

/**
 * POS v1.2.0 Live GitHub Release Verification Suite
 * Phase 15 Production Verification
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
    $token = trim((string) @shell_exec('gh auth token') ?: '');
    $provider = new GitHubReleaseProvider('ABDO-TECK', 'pos', $token ?: null);
    $sigService = new ManifestSignatureService();
    $manifestService = new UpdateManifestService();


    // ══════════════════════════════════════════════════════════════
    // 1. LIVE GITHUB RELEASE ASSET AVAILABILITY
    // ══════════════════════════════════════════════════════════════
    logHeader('TEST 1: Live GitHub Release Ingestion & Asset Discovery');

    $manifestUrl = "https://github.com/{$repo}/releases/download/v1.2.0/manifest.json";
    $sigUrl = "https://github.com/{$repo}/releases/download/v1.2.0/manifest.sig";
    $packageUrl = "https://github.com/{$repo}/releases/download/v1.2.0/full-package.zip";

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
    // 2. LIVE CRYPTOGRAPHIC SIGNATURE VERIFICATION
    // ══════════════════════════════════════════════════════════════
    logHeader('TEST 2: Live RSA-2048 Cryptographic Signature Verification');

    $sigValid = $sigService->verifySignature($liveManifest, $liveSig);
    if (!$sigValid) {
        throw new RuntimeException("Live GitHub manifest failed RSA-2048 signature verification!");
    }
    logOk("RSA-2048 SHA-256 Digital Signature on GitHub release asset validated 100% against pinned public key.");
    $results['test2_signature_verification'] = true;

    // ══════════════════════════════════════════════════════════════
    // 3. CLIENT UPDATE DISCOVERY SIMULATION (v1.1.46 Legacy Client)
    // ══════════════════════════════════════════════════════════════
    logHeader('TEST 3: Client Update Discovery Simulation (Legacy v1.1.46)');

    $checkResult = $provider->getLatestRelease('stable');
    if (!$checkResult['ok']) {
        throw new RuntimeException("GitHubReleaseProvider failed to fetch latest release: " . ($checkResult['error'] ?? 'unknown'));
    }

    $targetVersion = $checkResult['latest_version'] ?? 'unknown';
    if ($targetVersion !== '1.2.0') {
        throw new RuntimeException("Expected latest version 1.2.0, got {$targetVersion}");
    }
    logOk("Legacy Client v1.1.46 successfully discovered target release v1.2.0 from GitHub API.");


    $manifestData = json_decode($liveManifest, true);
    $engineCompat = $manifestService->checkEngineCompatibility(null, $manifestData);
    if (!$engineCompat['compatible'] || !$engineCompat['requires_bootstrap']) {
        throw new RuntimeException("Engine compatibility check failed: " . json_encode($engineCompat));
    }
    logOk("Migration engine recognized full bootstrap requirement for legacy client.");
    $results['test3_client_discovery'] = true;

    // ══════════════════════════════════════════════════════════════
    // 4. HTTP PACKAGE HEAD AVAILABILITY
    // ══════════════════════════════════════════════════════════════
    logHeader('TEST 4: Live Package Download Stream Availability');

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
    $results['test4_package_stream'] = true;

} catch (Throwable $e) {
    logErr("Live GitHub Release Verification Failed: " . $e->getMessage());
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
}

logHeader('LIVE GITHUB RELEASE VERIFICATION SUMMARY');
$allSuccess = count(array_filter($results)) === 4;
foreach ($results as $name => $passed) {
    echo "  " . str_pad(strtoupper($name), 35) . ": " . ($passed ? "{$GREEN}PASSED ✔{$RESET}" : "{$RED}FAILED ✖{$RESET}") . "\n";
}

if ($allSuccess) {
    echo "\n{$GREEN}{$BOLD}🎉 ALL 4 LIVE GITHUB RELEASE VERIFICATIONS PASSED 100%! RELEASE v1.2.0 IS LIVE & OPERATIONAL.{$RESET}\n\n";
    exit(0);
} else {
    echo "\n{$RED}{$BOLD}❌ SOME LIVE VERIFICATION TESTS FAILED.{$RESET}\n\n";
    exit(1);
}
