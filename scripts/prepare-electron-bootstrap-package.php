<?php
declare(strict_types=1);

/**
 * Prepares the Electron Bootstrap Installer release directory:
 * release/1.1.47-bootstrap/
 * ├── POS-Desktop-Setup-1.1.47.exe
 * ├── latest.yml
 * ├── manifest.json
 * ├── manifest.sig
 * └── release-notes.md
 */

require_once __DIR__ . '/../backend/vendor/autoload.php';

use App\Services\ManifestSignatureService;

$rootDir = realpath(__DIR__ . '/..');
$version = '1.1.47';
$outputDir = $rootDir . "/release/{$version}-bootstrap";

echo "📦 Preparing Electron Bootstrap Installer Release v{$version}...\n";

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// 1. Copy installer executable and latest.yml
$distExe = $rootDir . "/dist-electron/POS-Desktop-Setup-{$version}.exe";
$distYml = $rootDir . "/dist-electron/latest.yml";

if (!file_exists($distExe)) {
    throw new RuntimeException("Installer not found at {$distExe}. Run npm run electron:build first.");
}
if (!file_exists($distYml)) {
    throw new RuntimeException("latest.yml not found at {$distYml}.");
}

$destExe = $outputDir . "/POS-Desktop-Setup-{$version}.exe";
$destYml = $outputDir . "/latest.yml";

copy($distExe, $destExe);
copy($distYml, $destYml);

$exeSize = filesize($destExe);
$exeSha256 = hash_file('sha256', $destExe);
$exeSizeMb = round($exeSize / (1024 * 1024), 2);
echo "✅ Copied POS-Desktop-Setup-{$version}.exe ({$exeSizeMb} MB, SHA-256: {$exeSha256})\n";
echo "✅ Copied latest.yml\n";

// 2. Generate manifest.json
$changelog = [
    'إطلاق مثبت الترقية الشاملة لسطح المكتب (Electron Bootstrap Installer v1.1.47).',
    'ترقية عملاء الإصدارات السابقة (v1.1.46 وما قبلها) وتفعيل محرك التحديثات الذاتية (Update Engine v1.0.0).',
    'الاحتفاظ الكامل والتلقائي ببيانات المستخدمين وقواعد البيانات المحلية دون فقدان أي سجلات.',
    'تفعيل الدعم الكامل لتلقي التحديثات الجزئية الخفيفة (Delta Updates) لجميع الإصدارات القادمة (v1.1.48+).',
    'التحقق المشدد من التوقيع الرقمي RSA-2048 وحماية مسارات الملفات من ثغرات ZipSlip.'
];

$manifestData = [
    'manifest_version' => '1.0',
    'version' => $version,
    'type' => 'bootstrap_installer',
    'migration_release' => true,
    'requires_bootstrap' => true,
    'minimum_supported_version' => '1.0.0',
    'update_engine_version' => '1.0.0',
    'channel' => 'stable',
    'installer_name' => "POS-Desktop-Setup-{$version}.exe",
    'installer_sha256' => $exeSha256,
    'installer_size' => $exeSize,
    'released_at' => date('Y-m-d'),
    'changelog' => $changelog,
    'files' => [
        [
            'path' => "POS-Desktop-Setup-{$version}.exe",
            'action' => 'install',
            'sha256' => $exeSha256,
            'size' => $exeSize,
        ],
        [
            'path' => 'latest.yml',
            'action' => 'replace',
            'sha256' => hash_file('sha256', $destYml),
            'size' => filesize($destYml),
        ]
    ],
];

$manifestJson = json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$manifestPath = $outputDir . '/manifest.json';
file_put_contents($manifestPath, $manifestJson);
echo "✅ Created manifest.json\n";

// 3. Sign manifest with RSA-2048
$sigService = new ManifestSignatureService();
$privateKeyPath = $rootDir . '/release/private_key.pem';
$signature = $sigService->signData($manifestJson, $privateKeyPath);
if (!$signature) {
    throw new RuntimeException("Failed to generate RSA signature for manifest.json");
}
$sigPath = $outputDir . '/manifest.sig';
file_put_contents($sigPath, $signature);
echo "✅ Created manifest.sig (RSA-2048 Digital Signature)\n";

// 4. Generate release-notes.md
$releaseNotes = <<<MD
# POS Desktop v1.1.47 — Electron Bootstrap Migration Release

### 🌟 Overview & Purpose
This is the **Official Electron Bootstrap Installer Release** for POS Desktop. 
Existing clients running legacy versions (**v1.1.46 or earlier**) do not possess the delta update engine and must install this full standalone setup.

Once installed, **POS v1.1.47** activates the modern **Update Engine v1.0.0** allowing all future releases (**v1.1.48+**) to be delivered seamlessly via lightweight **Delta Updates**.

---

### 🚀 What's Included:
1. **Full Electron Desktop Runtime**: Complete bundled Node.js/Electron, PHP, MariaDB/SQLite portable runtime.
2. **Built-in Update Engine**: Automated GitHub Release ingestion, RSA-2048 verification, snapshot management, and atomic rollbacks.
3. **Data Preservation Guarantee**: Preserves customer databases, user credentials, and local configuration safely across migrations.
4. **Delta Update Readiness**: Full support for future micro-updates without redownloading installer packages.

---

### 📦 Artifacts & Checksums:
- **Installer Executable**: `POS-Desktop-Setup-1.1.47.exe` ({$exeSizeMb} MB)
  - **SHA-256**: `{$exeSha256}`
- **Updater Metadata**: `latest.yml`
- **Signed Manifest**: `manifest.json` (Signed with `manifest.sig`)
- **Public Certificate**: `backend/certs/update_public_key.pem`
MD;

$releaseNotesPath = $outputDir . '/release-notes.md';
file_put_contents($releaseNotesPath, $releaseNotes);
echo "✅ Created release-notes.md\n";

echo "\n🎉 Electron Bootstrap Package v{$version} successfully prepared at:\n{$outputDir}\n";
