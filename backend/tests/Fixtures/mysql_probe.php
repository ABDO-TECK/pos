<?php

declare(strict_types=1);

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "pdo_mysql is unavailable.\n");
    exit(3);
}

$port = (int) (getenv('DB_PORT') ?: 3307);
if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "DB_PORT must be between 1 and 65535.\n");
    exit(3);
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$database = getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: 'pos');
$user = getenv('DB_USER') ?: (getenv('DB_USERNAME') ?: 'root');
$password = getenv('DB_PASS') !== false
    ? (string) getenv('DB_PASS')
    : (getenv('DB_PASSWORD') ?: '');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
    );
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    fwrite(STDOUT, "MySQL {$version} reachable at {$host}:{$port}.\n");
} catch (PDOException $exception) {
    fwrite(STDERR, "MySQL unavailable at {$host}:{$port}: {$exception->getMessage()}\n");
    exit(2);
}
