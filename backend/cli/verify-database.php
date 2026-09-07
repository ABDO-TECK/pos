<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Forbidden: CLI only.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';
\App\Helpers\ErrorHandler::register();
require_once __DIR__ . '/../Config/config.php';

use App\Config\Database;
use App\Helpers\Logger;

try {
    $db = Database::getInstance();

    // 1. Connection check
    $db->query('SELECT 1');

    // 2. schema_versions readable
    $stmt = $db->query('SELECT version FROM schema_versions ORDER BY version');
    $recordedVersions = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (empty($recordedVersions)) {
        throw new RuntimeException('schema_versions table is empty; no migrations are recorded.');
    }

    // 3. Find latest expected migration
    $pharRunning = \Phar::running(false);
    $migrationsDir = $pharRunning
        ? 'phar://' . str_replace('\\', '/', $pharRunning) . '/database/migrations/'
        : realpath(__DIR__ . '/../../database/migrations/') . DIRECTORY_SEPARATOR;

    $migrationFiles = [];
    if (is_dir($migrationsDir)) {
        $files = scandir($migrationsDir) ?: [];
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                $migrationFiles[] = $file;
            }
        }
    }
    sort($migrationFiles);
    $latestMigration = end($migrationFiles);

    if ($latestMigration && !in_array($latestMigration, $recordedVersions, true)) {
        throw new RuntimeException("Current expected migration '{$latestMigration}' is not recorded in schema_versions.");
    }

    // 4. Critical base tables required for startup are readable
    $requiredTables = ['users', 'products', 'branches', 'categories', 'invoices', 'schema_versions'];
    foreach ($requiredTables as $table) {
        $check = $db->prepare('SHOW TABLES LIKE ?');
        $check->execute([$table]);
        if (!$check->fetchColumn()) {
            throw new RuntimeException("Required base table '{$table}' is missing.");
        }
        $db->query("SELECT 1 FROM `{$table}` LIMIT 1");
    }

    echo json_encode([
        'ok' => true,
        'verified' => true,
        'latest_migration' => $latestMigration,
        'recorded_count' => count($recordedVersions),
    ], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
} catch (\Throwable $exception) {
    $reference = bin2hex(random_bytes(8));
    Logger::error('Database verification failed', [
        'reference' => $reference,
        'exception' => get_class($exception),
        'message' => $exception->getMessage(),
    ]);
    fwrite(STDERR, "Database verification failed: {$exception->getMessage()} (Reference: {$reference})\n");
    exit(1);
}
