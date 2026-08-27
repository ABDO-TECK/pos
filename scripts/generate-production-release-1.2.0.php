<?php
declare(strict_types=1);

/**
 * Production Bootstrap & Migration Release Generator (v1.2.0)
 * Builds release/1.2.0-bootstrap/ assets for the first real production release.
 */

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\ManifestSignatureService;
use App\Services\UpdateManifestService;

$rootDir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$version = '1.2.0';
$outputDir = $rootDir . "/release/{$version}-bootstrap";

echo "📦 Generating Production Release Package v{$version}-bootstrap...\n";
echo "Output Directory: {$outputDir}\n";

if (!is_dir($outputDir)) {
    @mkdir($outputDir, 0755, true);
}

// 1. Files and directories to exclude (strict security and cleanliness)
$excludedPrefixes = [
    '.git/',
    '.github/',
    '.env',
    '.env.',
    'release/',
    'storage/',
    'backend/storage/',
    'backend/logs/',
    'backend/tests/',
    'backend/.phpunit.result.cache',
    'frontend/node_modules/',
    'frontend/src/',
    'frontend/dist-electron/',
    'dist-electron/',
    'node_modules/',
    'backend/certs/private_key.pem',
    'backend/certs/update_private_key.pem',
    'release/private_key.pem',
];

$includedItems = [
    'backend/Config',
    'backend/Controllers',
    'backend/Helpers',
    'backend/Middleware',
    'backend/Models',
    'backend/Services',
    'backend/certs/update_public_key.pem',
    'backend/database',
    'backend/routes',
    'backend/vendor',
    'backend/bootstrap.php',
    'backend/server.php',
    'backend/index.php',
    'frontend/dist',
    'scripts',
    'docs',
    'version.json',
    'package.json',
];

$filesToPack = [];
$manifestFiles = [];

foreach ($includedItems as $item) {
    $fullPath = $rootDir . '/' . $item;
    if (is_file($fullPath)) {
        $rel = $item;
        $filesToPack[] = $rel;
        $sha = hash_file('sha256', $fullPath);
        $size = filesize($fullPath);
        $manifestFiles[] = [
            'path' => $rel,
            'action' => 'replace',
            'sha256' => $sha,
            'size' => $size,
        ];
    } elseif (is_dir($fullPath)) {
        $dirIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($dirIterator as $fileInfo) {
            if ($fileInfo->isDir()) continue;
            $filePath = str_replace('\\', '/', $fileInfo->getRealPath());
            $rel = substr($filePath, strlen($rootDir) + 1);

            // Check exclusion
            $skip = false;
            foreach ($excludedPrefixes as $exc) {
                if (str_starts_with($rel, $exc) || (str_ends_with($rel, '.pem') && str_contains($rel, 'private'))) {
                    $skip = true;
                    break;
                }
            }
            if (
                str_ends_with($rel, '.lock') ||
                str_ends_with($rel, '.log') ||
                str_contains($rel, '/.github/') ||
                str_contains($rel, '/.git/') ||
                str_contains($rel, '/tests/') ||
                str_contains($rel, '/test/')
            ) {
                $skip = true;
            }
            if ($skip) continue;


            $filesToPack[] = $rel;
            $sha = hash_file('sha256', $filePath);
            $size = filesize($filePath);
            $manifestFiles[] = [
                'path' => $rel,
                'action' => 'replace',
                'sha256' => $sha,
                'size' => $size,
            ];
        }
    }
}

echo "Found " . count($filesToPack) . " production files for bootstrap package.\n";

// 2. Build full-package.zip
$zipPath = $outputDir . '/full-package.zip';
if (file_exists($zipPath)) {
    @unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException("Failed to create ZIP package: {$zipPath}");
}

foreach ($filesToPack as $relPath) {
    $realPath = $rootDir . '/' . $relPath;
    $zip->addFile($realPath, $relPath);
}
$zip->close();

$zipSizeMb = round(filesize($zipPath) / (1024 * 1024), 2);
$zipSha256 = hash_file('sha256', $zipPath);
echo "✅ Created full-package.zip ({$zipSizeMb} MB, SHA-256: {$zipSha256})\n";

// 3. Generate manifest.json
$changelog = [
    '🚀 إطلاق الجيل الجديد من نظام التحديثات الذاتية المستقرة (Production Release v1.2.0).',
    '🛡️ تفعيل نظام المعالجة والتعافي الذاتي (Self-Healing & Auto-Rollback Engine).',
    '📡 دمج لوحة تحكم أسطول نقاط البيع والمراقبة عن بُعد (Fleet Management & Telemetry Dashboard).',
    '🔒 تعزيز الأمان والتوقيع الرقمي RSA-2048 مع دعم تدوير المفاتيح والحماية من هجمات ZipSlip.',
    '🎯 دعم قنوات الإصدار (Stable, Beta, RC) والإطلاق التدريجي (Gradual Rollout).'
];

$manifestData = [
    'manifest_version' => '1.0',
    'version' => $version,
    'type' => 'full',
    'migration_release' => true,
    'minimum_version' => '1.0.0',
    'update_engine_version' => '1.2.0',
    'channel' => 'stable',
    'released_at' => date('Y-m-d'),
    'package_sha256' => $zipSha256,
    'package_size' => filesize($zipPath),
    'changelog' => $changelog,
    'files' => $manifestFiles,
    'deleted_files' => [],
];

$manifestJson = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$manifestPath = $outputDir . '/manifest.json';
file_put_contents($manifestPath, $manifestJson);
echo "✅ Created manifest.json\n";

// 4. Generate RSA-2048 digital signature
$sigService = new ManifestSignatureService();
$privateKeyPath = $rootDir . '/release/private_key.pem';
if (!is_file($privateKeyPath)) {
    // Generate release signing keypair if not present
    $keyPair = ManifestSignatureService::generateKeyPair(2048);
    @mkdir(dirname($privateKeyPath), 0755, true);
    file_put_contents($privateKeyPath, $keyPair['private_key']);
    file_put_contents($rootDir . '/backend/certs/update_public_key.pem', $keyPair['public_key']);
    file_put_contents($rootDir . '/release/public_key.pem', $keyPair['public_key']);
}

$signature = $sigService->signData($manifestJson, $privateKeyPath);
if (!$signature) {
    throw new RuntimeException("Failed to generate RSA signature for manifest.");
}
$sigPath = $outputDir . '/manifest.sig';
file_put_contents($sigPath, $signature);
echo "✅ Created manifest.sig (RSA-2048 SHA-256)\n";

// 5. Generate release-notes.md
$releaseNotes = <<<MD
# POS Desktop v1.2.0 (Production Migration & Bootstrap Release)

### 🌟 Release Summary
POS v1.2.0 is the **First Major Production Release** featuring the complete, self-healing, cryptographic update infrastructure. Existing clients migrate seamlessly through this package, enabling future zero-downtime delta updates.

---

### 🚀 Highlights & Capabilities:
1. **Delta Update Engine**: Ultra-fast incremental updates downloading only modified byte streams.
2. **Self-Healing & Auto-Recovery**: Fault-injection tested with automatic rollback on interrupted power, failed downloads, or database migration errors.
3. **Fleet Management & Telemetry**: Remote device health telemetry, version distribution graphs, and automated failure alerts.
4. **Cryptographic Signing (RSA-2048 / SHA-256)**: Authenticity verification with zero-downtime key rotation support.
5. **Channel & Gradual Rollout Management**: Target updates across `stable`, `beta`, and `rc` channels with hash-bucket percentage rollouts.
6. **Hardened File System Defenses**: Anti-ZipSlip path traversal barriers and protected system asset guarding (`.env`, private keys, SSL certs).

---

### 📦 Upgrade Paths:
- **Legacy Terminals (<= v1.1.46)**: Download full bootstrap package once, automatically upgrading database schema and runtime.
- **Modern Terminals (v1.1.47+)**: Receive lightweight Delta Updates with automatic pre-update backup snapshots.

---

### 🔒 Cryptographic Assets & Hashes:
- **Manifest**: `manifest.json`
- **Signature**: `manifest.sig` (RSA-2048 / SHA-256)
- **Full Archive**: `full-package.zip` (SHA-256: `{$zipSha256}`, Size: `{$zipSizeMb} MB`)
MD;

$releaseNotesPath = $outputDir . '/release-notes.md';
file_put_contents($releaseNotesPath, $releaseNotes);
echo "✅ Created release-notes.md\n";

// 6. Validate the generated package
$validation = (new UpdateManifestService())->validateManifest($manifestJson);
if (!$validation['valid']) {
    throw new RuntimeException("Generated manifest is invalid: " . implode('; ', $validation['errors']));
}

$sigValid = $sigService->verifySignature($manifestJson, $signature);
if (!$sigValid) {
    throw new RuntimeException("Generated signature failed verification against pinned public key!");
}

echo "\n🎉 PRODUCTION BOOTSTRAP RELEASE v{$version} ASSETS SUCCESSFULLY GENERATED & VERIFIED!\n";
echo "Location: {$outputDir}\n";
