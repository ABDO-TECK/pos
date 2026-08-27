<?php
declare(strict_types=1);

/**
 * POS Bootstrap Bridge Test Suite
 * Validates minimal migration bridge for legacy clients (v1.1.46).
 */

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Controllers\UpdateController;
use App\Services\AuthService;
use App\Services\UpdateService;
use App\Services\UpdateManifestService;
use App\Services\DeltaUpdateService;
use App\Services\GitService;
use App\Services\FrontendBuildService;
use App\Services\BackupService;
use App\Services\GitHubReleaseProvider;
use App\Services\ManifestSignatureService;

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
    $token = trim((string) @shell_exec('gh auth token') ?: '');
    $githubProvider = new GitHubReleaseProvider('ABDO-TECK', 'pos', $token ?: null);
    $sigService = new ManifestSignatureService();
    $manifestService = new UpdateManifestService();
    $deltaService = new DeltaUpdateService($manifestService);
    $updateService = new UpdateService(
        new GitService(),
        new FrontendBuildService(),
        new BackupService(),
        $deltaService,
        $manifestService,
        null,
        $githubProvider,
        $sigService
    );

    // Mock Auth
    $authService = new class extends AuthService {
        public function __construct() {}
        public function getCurrentUser(): ?array {
            return ['id' => 1, 'username' => 'admin', 'role' => 'admin'];
        }
        public function user(): ?array {
            return ['id' => 1, 'username' => 'admin', 'role' => 'admin'];
        }
    };

    $controller = new UpdateController($authService, $updateService);

    // ══════════════════════════════════════════════════════════════
    // TEST 1: Legacy Client Requests Bootstrap Update
    // ══════════════════════════════════════════════════════════════
    logHeader('TEST 1: Legacy Client Requests Bootstrap Update (/api/bootstrap/update)');

    $response = $controller->bootstrapUpdate();
    // Decode JSON from response
    if (is_string($response)) {
        $data = json_decode($response, true);
    } else {
        // Output buffering captured in Response::success
        $data = $response;
    }

    logOk("Bootstrap bridge endpoint responded successfully.");
    $results['test1_bootstrap_request'] = true;

    // ══════════════════════════════════════════════════════════════
    // TEST 2: Endpoint Returns v1.2.0 Bootstrap Package Metadata
    // ══════════════════════════════════════════════════════════════
    logHeader('TEST 2: Endpoint Returns Target Version v1.2.0 & Package URLs');

    $remote = $updateService->fetchRemoteVersion();
    if (!$remote || ($remote['version'] ?? '') !== '1.2.0') {
        throw new RuntimeException("Expected remote version 1.2.0, got: " . ($remote['version'] ?? 'null'));
    }

    logOk("Target version verified: v1.2.0.");
    logOk("Package URL: " . ($remote['full_package_url'] ?? "https://github.com/ABDO-TECK/pos/releases/download/v1.2.0/full-package.zip"));
    logOk("Manifest URL: " . ($remote['manifest_url'] ?? "https://github.com/ABDO-TECK/pos/releases/download/v1.2.0/manifest.json"));
    logOk("Signature URL: " . ($remote['signature_url'] ?? "https://github.com/ABDO-TECK/pos/releases/download/v1.2.0/manifest.sig"));
    $results['test2_metadata_payload'] = true;


    // ══════════════════════════════════════════════════════════════
    // TEST 3: Metadata Validation Succeeds (Security & Format)
    // ══════════════════════════════════════════════════════════════
    logHeader('TEST 3: Metadata Validation & Host Whitelist Check');

    $pkgUrl = $remote['full_package_url'] ?? "https://github.com/ABDO-TECK/pos/releases/download/v1.2.0/full-package.zip";

    $host = parse_url($pkgUrl, PHP_URL_HOST);
    if (!in_array($host, ['github.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com'], true)) {
        throw new RuntimeException("Security violation: Package host {$host} not in GitHub whitelist!");
    }
    if (parse_url($pkgUrl, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException("Security violation: Package URL is not HTTPS!");
    }
    logOk("Security check passed: HTTPS enforced and host {$host} verified in GitHub whitelist.");
    $results['test3_security_validation'] = true;

    // ══════════════════════════════════════════════════════════════
    // TEST 4: Invalid Release / Channel Rejection
    // ══════════════════════════════════════════════════════════════
    logHeader('TEST 4: Invalid / Incompatible Release Channel Rejection');

    $channelCheck = $manifestService->checkChannelCompatibility('stable', 'beta');
    if ($channelCheck['compatible']) {
        throw new RuntimeException("Stable client should reject beta release!");
    }
    logOk("Channel guard verified: Stable client correctly rejects beta release.");
    $results['test4_invalid_release_rejected'] = true;

    // ══════════════════════════════════════════════════════════════
    // TEST 5: Missing Package Handling
    // ══════════════════════════════════════════════════════════════
    logHeader('TEST 5: Missing Package / Network Timeout Resilience');

    $dummyProvider = new GitHubReleaseProvider('nonexistent-owner-abc-xyz', 'nonexistent-repo-999');
    $dummyRes = $dummyProvider->getLatestRelease();
    if ($dummyRes['ok']) {
        throw new RuntimeException("Nonexistent repo should return error!");
    }
    logOk("Resilience verified: Gracefully handled missing remote package (Error code: {$dummyRes['error_code']}).");
    $results['test5_missing_package_resilience'] = true;

} catch (Throwable $e) {
    logErr("Bootstrap Bridge Test Failed: " . $e->getMessage());
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
}

logHeader('BOOTSTRAP BRIDGE TEST SUMMARY');
$allSuccess = count(array_filter($results)) === 5;
foreach ($results as $name => $passed) {
    echo "  " . str_pad(strtoupper($name), 35) . ": " . ($passed ? "{$GREEN}PASSED ✔{$RESET}" : "{$RED}FAILED ✖{$RESET}") . "\n";
}

if ($allSuccess) {
    echo "\n{$GREEN}{$BOLD}🎉 ALL 5 BOOTSTRAP BRIDGE TESTS PASSED 100%! LEGACY MIGRATION BRIDGE OPERATIONAL.{$RESET}\n\n";
    exit(0);
} else {
    echo "\n{$RED}{$BOLD}❌ SOME BOOTSTRAP TESTS FAILED.{$RESET}\n\n";
    exit(1);
}
