<?php
declare(strict_types=1);

/**
 * Bootstrap Release Generator (v1.1.47-bootstrap)
 * Creates the full migration release package for upgrading legacy POS clients.
 */

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\ManifestSignatureService;
use App\Services\UpdateManifestService;

$rootDir = realpath(__DIR__ . '/..');
$version = '1.1.47';
$outputDir = $rootDir . "/release/{$version}-bootstrap";

echo "📦 Generating Bootstrap Migration Release v{$version}...\n";
echo "Output Directory: {$outputDir}\n";

if (!is_dir($outputDir)) {
    @mkdir($outputDir, 0755, true);
}

// 1. Determine files to include in the full bootstrap package
$excludedPrefixes = [
    '.git/',
    '.github/',
    '.env',
    'release/',
    'storage/',
    'backend/storage/',
    'backend/logs/',
    'node_modules/',
    'dist-electron/',
    'backend/vendor/',
    'frontend/node_modules/',
    'backend/tests/',
    'frontend/src/',
    'backend/.phpunit.result.cache',
];

$includedFolders = [
    'backend/Controllers',
    'backend/Helpers',
    'backend/Middleware',
    'backend/Models',
    'backend/Services',
    'backend/certs',
    'backend/config',
    'backend/database',
    'backend/routes',
    'backend/bootstrap.php',
    'backend/server.php',
    'frontend/dist',
    'scripts',
    'docs',
    'version.json',
    'package.json',
];

$filesToPack = [];
$manifestFiles = [];

foreach ($includedFolders as $item) {
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
            $filePath = $fileInfo->getRealPath();
            $rel = str_replace('\\', '/', substr($filePath, strlen($rootDir) + 1));

            // Check exclusion
            $skip = false;
            foreach ($excludedPrefixes as $exc) {
                if (str_starts_with($rel, $exc)) {
                    $skip = true;
                    break;
                }
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

// 2. Create full-package.zip
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
echo "✅ Created full-package.zip ({$zipSizeMb} MB)\n";

// 3. Create manifest.json
$changelog = [
    'إطلاق نظام التحديثات الجزئية الجديد (Delta Updates) مع التحقق من التوقيع الرقمي RSA-2048.',
    'تكامل مباشر وسلس مع منصة GitHub Releases لتوزيع التحديثات بدون خوادم وسيطة.',
    'إضافة مركز إدارة التحديثات (Admin Update Center) في لوحة التحكم لمتابعة التحديثات وسجل العمليات.',
    'نظام النسخ الاحتياطي التلقائي للقطات الملفات وقاعدة البيانات مع إمكانية التراجع الفوري (Atomic Rollback).',
    'حماية مشددة ضد ثغرات مسارات الملفات (ZipSlip) وفحص تجزئات SHA-256 لجميع الملفات.',
    'إصلاحات شاملة لطباعة التقارير، توافق شاشات الإعدادات، واستقرار بيئة التشغيل.'
];

$manifestData = [
    'manifest_version' => '1.0',
    'version' => $version,
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

$manifestJson = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$manifestPath = $outputDir . '/manifest.json';
file_put_contents($manifestPath, $manifestJson);
echo "✅ Created manifest.json\n";

// 4. Generate RSA-2048 Digital Signature
$sigService = new ManifestSignatureService();
$privateKeyPath = $rootDir . '/release/private_key.pem';
$signature = $sigService->signData($manifestJson, $privateKeyPath);
if (!$signature) {
    throw new RuntimeException("Failed to generate RSA signature for bootstrap manifest.");
}
$sigPath = $outputDir . '/manifest.sig';
file_put_contents($sigPath, $signature);
echo "✅ Created manifest.sig (RSA-2048 Signature)\n";

// 5. Generate release-notes.md
$releaseNotes = <<<MD
# POS Desktop v1.1.47 (Bootstrap Migration Release)

### 🌟 Overview & Purpose
This is the **Bootstrap Migration Release** for POS Desktop. It upgrades existing customer installations running legacy versions (v1.0.0 – v1.1.46) into the modern **GitHub Releases & Delta Update Architecture**.

---

### 🚀 Key Features Included in This Release:
1. **Delta Update Engine**: Fast incremental updates downloading only changed files.
2. **Cryptographic Verification**: RSA-2048 digital signatures on all update manifests with SHA-256 per-file checksums.
3. **Admin Update Center**: Manage updates directly from **Settings > System and Maintenance**.
4. **Atomic Rollback Engine**: Pre-update snapshots and database dumps with automatic rollback on error.
5. **Startup Recovery Mode**: Automatic detection and recovery if an update is interrupted unexpectedly.
6. **ZipSlip Protection**: Strict path traversal barriers for all archives.

---

### 📦 Migration Instructions for Legacy Clients:
- Legacy clients running `v1.1.46` or earlier will automatically download this complete package (`full-package.zip`).
- Once migrated to `v1.1.47`, future updates (e.g. `v1.1.48+`) will use ultra-lightweight **Delta Updates** (typically < 100 KB).

---

### 🔒 Verification Checksums:
- **Manifest**: `manifest.json` (Signed via `manifest.sig`)
- **Package Archive**: `full-package.zip` (SHA-256: `{$zipSizeMb} MB`)
MD;

$releaseNotesPath = $outputDir . '/release-notes.md';
file_put_contents($releaseNotesPath, $releaseNotes);
echo "✅ Created release-notes.md\n";

echo "\n🎉 BOOTSTRAP RELEASE v{$version} ASSETS READY AT:\n{$outputDir}\n";
