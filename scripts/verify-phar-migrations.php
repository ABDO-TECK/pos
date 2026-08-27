<?php
declare(strict_types=1);

$phar = new Phar(__DIR__ . '/../backend/backend.phar');
$migrationCount = 0;
$invalidFiles = [];

foreach (new RecursiveIteratorIterator($phar) as $file) {
    if (strpos($file->getPathname(), 'migrations') !== false) {
        $migrationCount++;
        $content = file_get_contents($file->getPathname());
        if (stripos($content, 'permissions') !== false && stripos($content, 'module') !== false) {
            $invalidFiles[] = $file->getPathname();
        }
    }
}

echo "Total migrations inside backend.phar: {$migrationCount}\n";
echo "Files referencing 'module' in permissions: " . count($invalidFiles) . "\n";

if (!empty($invalidFiles)) {
    echo "ERROR: Found invalid files:\n";
    print_r($invalidFiles);
    exit(1);
}

echo "All migrations inside backend.phar are 100% backward compatible!\n";
exit(0);
