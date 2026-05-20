<?php
/**
 * CLI Seeder — تشغيل بيانات البذر الافتراضية.
 *
 * الاستخدام:
 *   php backend/cli/seed.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: CLI only.');
}

require_once __DIR__ . '/../Config/config.php';

use App\Config\Database;

echo "Running Database Seeders...\n";
echo "===========================\n";

try {
    $db = Database::getInstance();
    $seedDir = realpath(__DIR__ . '/../../database/seeders');

    if (!$seedDir || !is_dir($seedDir)) {
        echo "Seeder directory not found: database/seeders/\n";
        exit(1);
    }

    $files = glob($seedDir . '/*.sql');
    sort($files);

    $executed = 0;
    foreach ($files as $file) {
        $name = basename($file);
        echo "  Seeding: {$name} ... ";

        $sql = file_get_contents($file);
        if (!$sql) {
            echo "EMPTY (skipped)\n";
            continue;
        }

        // تنفيذ كل statement على حدة
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => $s !== '' && !str_starts_with($s, '--')
        );

        foreach ($statements as $stmt) {
            $db->exec($stmt);
        }

        echo "OK\n";
        $executed++;
    }

    echo "===========================\n";
    echo "Done. Executed {$executed} seeder(s).\n";
} catch (\Throwable $e) {
    echo "ERROR: {$e->getMessage()}\n";
    exit(1);
}
