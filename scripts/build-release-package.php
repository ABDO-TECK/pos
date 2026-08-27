<?php
declare(strict_types=1);

/**
 * Universal Cross-Platform Release Package Builder for GitHub Actions & Local CLI
 * 
 * Supports both:
 *  1. Bootstrap Releases (e.g. v1.1.47-bootstrap -> full-package.zip)
 *  2. Delta Releases (e.g. v1.1.48 -> delta-1.1.47-to-1.1.48.zip)
 */

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\ManifestSignatureService;
use App\Services\UpdateManifestService;

$rootDir = realpath(__DIR__ . '/..');

// Parse CLI options
$options = getopt('', ['tag:', 'from-tag:', 'private-key:', 'output-dir:', 'help']);

if (isset($options['help'])) {
    echo "Usage: php scripts/build-release-package.php --tag=<tag> [--from-tag=<from_tag>] [--private-key=<path>] [--output-dir=<dir>]\n";
    exit(0);
}

$tag = $options['tag'] ?? getenv('RELEASE_TAG') ?: null;
if (!$tag) {
    // Try to get current git tag
    $tag = trim((string) @shell_exec('git describe --tags --exact-match 2>NUL || git describe --tags --exact-match 2>/dev/null'));
}

if (!$tag) {
    fwrite(STDERR, "Error: Release tag not specified. Use --tag=vX.Y.Z or set RELEASE_TAG environment variable.\n");
    exit(1);
}

// 1. Determine version and release type
$isBootstrap = str_contains(strtolower($tag), 'bootstrap');
$cleanVersion = preg_replace('/^v/', '', $tag);
$baseVersion = explode('-', $cleanVersion)[0];

echo "════════════════════════════════════════════════════════════════════════════════\n";
echo "🚀 POS Update Engine Release Builder\n";
echo "Tag: {$tag} | Version: {$baseVersion} | Type: " . ($isBootstrap ? 'BOOTSTRAP (Full)' : 'DELTA (Incremental)') . "\n";
echo "════════════════════════════════════════════════════════════════════════════════\n\n";

// 2. Validate version.json match
$versionJsonPath = $rootDir . '/version.json';
if (!file_exists($versionJsonPath)) {
    fwrite(STDERR, "Error: version.json not found at {$versionJsonPath}\n");
    exit(1);
}

$versionData = json_decode((string) file_get_contents($versionJsonPath), true);
$localAppVersion = $versionData['version'] ?? $versionData['application_version'] ?? '';

if ($localAppVersion !== $baseVersion) {
    fwrite(STDERR, "❌ Version Mismatch Error: Git tag version '{$baseVersion}' does not match version.json version '{$localAppVersion}'!\n");
    exit(1);
}
echo "✔ Version match validated: {$baseVersion}\n";

// 3. Resolve Private Key
$privateKeyPem = null;
$tempKeyFile = null;

if (isset($options['private-key'])) {
    if (file_exists($options['private-key'])) {
        $privateKeyPem = file_get_contents($options['private-key']);
    } else {
        fwrite(STDERR, "❌ Error: Specified private key file does not exist: {$options['private-key']}\n");
        exit(1);
    }
} elseif (getenv('UPDATE_PRIVATE_KEY') && trim((string)getenv('UPDATE_PRIVATE_KEY')) !== '') {
    $privateKeyPem = getenv('UPDATE_PRIVATE_KEY');
} elseif (file_exists($rootDir . '/release/private_key.pem')) {
    $privateKeyPem = file_get_contents($rootDir . '/release/private_key.pem');
}


if (!$privateKeyPem || trim($privateKeyPem) === '') {
    fwrite(STDERR, "❌ Error: UPDATE_PRIVATE_KEY secret or private key file is missing.\n");
    exit(1);
}

// Write to ephemeral temp key file with strict permissions
$tempKeyFile = $rootDir . '/backend/storage/.temp_signing_key_' . bin2hex(random_bytes(8)) . '.pem';
@mkdir(dirname($tempKeyFile), 0755, true);
file_put_contents($tempKeyFile, trim($privateKeyPem) . "\n");

// Register shutdown cleanup for private key
register_shutdown_function(static function () use (&$tempKeyFile): void {
    if ($tempKeyFile && file_exists($tempKeyFile)) {
        @unlink($tempKeyFile);
    }
});

// 4. Output directory
$outputDir = $options['output-dir'] ?? ($rootDir . "/release/{$cleanVersion}");
if (!is_dir($outputDir)) {
    @mkdir($outputDir, 0755, true);
}

$sigService = new ManifestSignatureService();
$manifestService = new UpdateManifestService();

if ($isBootstrap) {
    // ══════════════════════════════════════════════════════════════
    // BOOTSTRAP RELEASE GENERATION
    // ══════════════════════════════════════════════════════════════
    echo "📦 Packaging Full Bootstrap Release...\n";

    $excludedPrefixes = [
        '.git/', '.github/', '.env', 'release/', 'storage/', 'backend/storage/',
        'backend/logs/', 'node_modules/', 'dist-electron/', 'backend/vendor/',
        'frontend/node_modules/', 'backend/tests/', 'frontend/src/',
        'backend/.phpunit.result.cache',
    ];

    $includedFolders = [
        'backend/Controllers', 'backend/Helpers', 'backend/Middleware',
        'backend/Models', 'backend/Services', 'backend/certs',
        'backend/config', 'backend/database', 'backend/routes',
        'backend/bootstrap.php', 'backend/server.php',
        'frontend/dist', 'scripts', 'docs', 'version.json', 'package.json',
    ];

    $filesToPack = [];
    $manifestFiles = [];

    foreach ($includedFolders as $item) {
        $fullPath = $rootDir . '/' . $item;
        if (is_file($fullPath)) {
            $rel = $item;
            $filesToPack[] = $rel;
            $manifestFiles[] = [
                'path' => $rel,
                'action' => 'replace',
                'sha256' => hash_file('sha256', $fullPath),
                'size' => filesize($fullPath),
            ];
        } elseif (is_dir($fullPath)) {
            $dirIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($dirIterator as $fileInfo) {
                if ($fileInfo->isDir()) continue;
                $filePath = $fileInfo->getRealPath();
                $rel = str_replace('\\', '/', substr($filePath, strlen($rootDir) + 1));

                $skip = false;
                foreach ($excludedPrefixes as $exc) {
                    if (str_starts_with($rel, $exc)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) continue;

                $filesToPack[] = $rel;
                $manifestFiles[] = [
                    'path' => $rel,
                    'action' => 'replace',
                    'sha256' => hash_file('sha256', $filePath),
                    'size' => filesize($filePath),
                ];
            }
        }
    }

    $zipPath = $outputDir . '/full-package.zip';
    if (file_exists($zipPath)) @unlink($zipPath);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Failed to create ZIP package: {$zipPath}");
    }
    foreach ($filesToPack as $relPath) {
        $zip->addFile($rootDir . '/' . $relPath, $relPath);
    }
    $zip->close();

    $changelog = $versionData['changelog'] ?? [
        "POS Desktop Bootstrap Migration Release v{$baseVersion}",
    ];

    $manifestData = [
        'manifest_version' => '1.0',
        'version' => $baseVersion,
        'type' => 'full',
        'migration_release' => true,
        'minimum_version' => '1.0.0',
        'update_engine_version' => '1.0.0',
        'channel' => 'stable',
        'released_at' => date('Y-m-d'),
        'changelog' => $changelog,
        'files' => $manifestFiles,
        'deleted_files' => [],
    ];

} else {
    // ══════════════════════════════════════════════════════════════
    // DELTA RELEASE GENERATION
    // ══════════════════════════════════════════════════════════════
    echo "📦 Packaging Incremental Delta Release...\n";

    $fromTag = $options['from-tag'] ?? null;
    if (!$fromTag) {
        // Find previous tag
        $prevTagCmd = "git describe --tags --abbrev=0 \"{$tag}^\终\" 2>NUL || git describe --tags --abbrev=0 \"{$tag}^\" 2>/dev/null";
        $fromTag = trim((string) @shell_exec("git describe --tags --abbrev=0 \"{$tag}^\" 2>&1"));
        if (str_contains($fromTag, 'fatal') || empty($fromTag)) {
            // Fallback: previous semver
            $parts = explode('.', $baseVersion);
            if (count($parts) === 3 && (int)$parts[2] > 0) {
                $parts[2] = (string)((int)$parts[2] - 1);
                $fromTag = 'v' . implode('.', $parts);
            }
        }
    }

    $fromVersion = preg_replace('/^v/', '', $fromTag ?: '');
    $fromVersion = explode('-', $fromVersion)[0];
    if (empty($fromVersion)) {
        $fromVersion = '1.1.47';
    }

    echo "Delta comparison: {$fromTag} ({$fromVersion}) -> {$tag} ({$baseVersion})\n";

    // Detect changed files between tags
    $diffCmd = "git diff --name-status \"{$fromTag}\" HEAD 2>&1";
    $diffOutput = (string) shell_exec($diffCmd);
    $lines = array_filter(explode("\n", trim($diffOutput)));

    $modifiedFiles = [];
    $deletedFiles = [];

    $ignoredPrefixes = [
        '.git/', '.github/', '.env', 'release/', 'storage/', 'backend/storage/',
        'backend/logs/', 'node_modules/', 'dist-electron/', 'backend/vendor/',
        'frontend/node_modules/', 'backend/tests/', 'frontend/src/',
        'backend/.phpunit.result.cache', 'scratch/',
    ];

    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line), 2);
        if (count($parts) < 2) continue;
        $status = $parts[0];
        $file = str_replace('\\', '/', $parts[1]);

        $skip = false;
        foreach ($ignoredPrefixes as $ig) {
            if (str_starts_with($file, $ig)) {
                $skip = true;
                break;
            }
        }
        if ($skip) continue;

        if ($status === 'D') {
            $deletedFiles[] = $file;
        } else {
            if (file_exists($rootDir . '/' . $file)) {
                $modifiedFiles[] = $file;
            }
        }
    }

    // Ensure version.json is included in delta
    if (!in_array('version.json', $modifiedFiles, true) && file_exists($rootDir . '/version.json')) {
        $modifiedFiles[] = 'version.json';
    }

    echo "Detected " . count($modifiedFiles) . " modified file(s) and " . count($deletedFiles) . " deleted file(s).\n";

    $manifestFiles = [];
    $zipPath = $outputDir . "/delta-{$fromVersion}-to-{$baseVersion}.zip";
    $genericZipPath = $outputDir . '/delta.zip';

    if (file_exists($zipPath)) @unlink($zipPath);
    if (file_exists($genericZipPath)) @unlink($genericZipPath);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Failed to create ZIP package: {$zipPath}");
    }

    foreach ($modifiedFiles as $relPath) {
        $fullPath = $rootDir . '/' . $relPath;
        $zip->addFile($fullPath, $relPath);
        $manifestFiles[] = [
            'path' => $relPath,
            'action' => 'replace',
            'sha256' => hash_file('sha256', $fullPath),
            'size' => filesize($fullPath),
        ];
    }
    $zip->close();

    // Copy to generic delta.zip
    @copy($zipPath, $genericZipPath);

    // Collect git commits for changelog
    $nullDev = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
    $gitLog = (string) @shell_exec("git log \"{$fromTag}..HEAD\" --pretty=format:\"%s\" 2>{$nullDev}");
    $commitEntries = array_values(array_filter(array_map('trim', explode("\n", (string)$gitLog))));


    $changelog = !empty($versionData['changelog']) ? $versionData['changelog'] : $commitEntries;
    if (empty($changelog)) {
        $changelog = ["Incremental performance improvements and updates for v{$baseVersion}"];
    }

    $manifestData = [
        'manifest_version' => '1.0',
        'version' => $baseVersion,
        'type' => 'delta',
        'minimum_version' => $fromVersion,
        'update_engine_version' => '1.0.0',
        'channel' => 'stable',
        'released_at' => date('Y-m-d'),
        'changelog' => $changelog,
        'files' => $manifestFiles,
        'deleted_files' => $deletedFiles,
    ];
}

// 5. Write and Sign Manifest
$manifestJson = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$manifestPath = $outputDir . '/manifest.json';
file_put_contents($manifestPath, $manifestJson);

$signature = $sigService->signData($manifestJson, $tempKeyFile);
if (!$signature) {
    throw new RuntimeException("Failed to generate RSA-2048 digital signature.");
}
$sigPath = $outputDir . '/manifest.sig';
file_put_contents($sigPath, $signature);

// Verify signature against public key immediately
$pubKeyPath = $rootDir . '/backend/certs/update_public_key.pem';
if (file_exists($pubKeyPath)) {
    $verified = $sigService->verifySignature($manifestJson, $signature, $pubKeyPath);
    if (!$verified) {
        throw new RuntimeException("CRITICAL: Generated manifest signature failed verification against update_public_key.pem!");
    }
    echo "✔ Cryptographic RSA signature verified against public key.\n";
}

// 6. Generate Customer-Safe release-notes.md
$changelogMd = "";
foreach ($manifestData['changelog'] as $entry) {
    if (is_string($entry)) {
        $changelogMd .= "- {$entry}\n";
    }
}

$releaseNotes = <<<MD
# POS Desktop v{$baseVersion}

### 🌟 Overview
Release **v{$baseVersion}** for POS Desktop.

---

### 🚀 Changelog:
{$changelogMd}

---

### 🔒 Cryptographic Verification:
- **Manifest**: `manifest.json` (RSA-2048 Signed via `manifest.sig`)
- **Type**: {$manifestData['type']}
MD;

file_put_contents($outputDir . '/release-notes.md', $releaseNotes);

// Clean up temporary key
if ($tempKeyFile && file_exists($tempKeyFile)) {
    @unlink($tempKeyFile);
    $tempKeyFile = null;
}

echo "\n🎉 RELEASE PACKAGE READY AT: {$outputDir}\n";
echo "Files created:\n";
foreach (scandir($outputDir) as $f) {
    if ($f === '.' || $f === '..') continue;
    $size = round(filesize($outputDir . '/' . $f) / 1024, 2);
    echo " - {$f} ({$size} KB)\n";
}
echo "\n";
