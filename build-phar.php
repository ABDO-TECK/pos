<?php
/**
 * PHAR Compilation Script
 * 
 * Compiles the backend directory into a single backend.phar file.
 * 
 * IMPORTANT INTEGRITY DISCLAIMER:
 * PHAR SHA-512 signing is used STRICTLY as an integrity validation check to detect
 * accidental file corruption or modification. It is NOT encryption, obfuscation,
 * or authenticity protection against a malicious actor who can replace both the
 * PHAR and expected metadata.
 * Do NOT store credentials, keys, or secrets inside the PHAR file.
 */

$pharFile = __DIR__ . '/backend/backend.phar';
$tempPharFile = __DIR__ . '/backend_temp.phar';

if (file_exists($tempPharFile)) {
    unlink($tempPharFile);
}

// Ensure the target directory exists
if (!is_dir(dirname($pharFile))) {
    mkdir(dirname($pharFile), 0755, true);
}

$phar = new Phar($tempPharFile, 0, 'backend.phar');
$phar->startBuffering();

// 1. Walk through the backend directory and compile it
$backendDir = __DIR__ . '/backend';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($backendDir, FilesystemIterator::SKIP_DOTS)
);

// Define directories and files to exclude from the final build
$excludePatterns = [
    '/^backend\.phar$/',
    '/^tests/',
    '/^docs/',
    '/^swagger/',
    '/^logs/',
    '/^storage\/cache/',
    '/^storage\/logs/',
    '/^storage\/backups/',
    '/^storage\/runtime/',
    '/^\.env/',
    '/^\.env\.example/',
    '/^adminer\.php/',
    '/^adminer-local\.php/',
    '/^composer\.json/',
    '/^composer\.lock/',
    '/^composer\.phar/',
    '/^phpunit\.xml/',
    '/^\.phpunit/',
    '/^\.git/',
    '/^\.github/',
    '/^vendor\/phpunit/',
    '/^vendor\/zircote/',
    '/^vendor\/bin/',
];

foreach ($iterator as $file) {
    if ($file->isFile()) {
        $filePath = $file->getPathname();
        $realBackendDir = realpath($backendDir);
        $realFilePath = realpath($filePath);
        $relativePath = '';
        if ($realBackendDir !== false && $realFilePath !== false && strpos(strtolower($realFilePath), strtolower($realBackendDir)) === 0) {
            $relativePath = substr($realFilePath, strlen($realBackendDir));
            $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR . '/');
        } else {
            $relativePath = str_replace($backendDir . DIRECTORY_SEPARATOR, '', $filePath);
        }
        $relativePath = str_replace('\\', '/', $relativePath); // Standardize directory separators

        // Filter exclusions
        $exclude = false;
        foreach ($excludePatterns as $pattern) {
            if (preg_match($pattern, $relativePath)) {
                $exclude = true;
                break;
            }
        }

        // Also exclude any Markdown files or log files from the Phar
        $ext = pathinfo($relativePath, PATHINFO_EXTENSION);
        if ($ext === 'md' || $ext === 'log') {
            $exclude = true;
        }

        if (!$exclude) {
            echo "Adding: " . $relativePath . "\n";
            $phar->addFile($filePath, $relativePath);
        } else {
            // echo "Excluding: " . $relativePath . "\n";
        }
    }
}

// 2. Include database/migrations
$migrationsDir = __DIR__ . '/database/migrations';
if (is_dir($migrationsDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($migrationsDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $filePath = $file->getPathname();
            $realMigrationsDir = realpath($migrationsDir);
            $realFilePath = realpath($filePath);
            $rel = '';
            if ($realMigrationsDir !== false && $realFilePath !== false && strpos(strtolower($realFilePath), strtolower($realMigrationsDir)) === 0) {
                $rel = substr($realFilePath, strlen($realMigrationsDir));
                $rel = ltrim($rel, DIRECTORY_SEPARATOR . '/');
            } else {
                $rel = str_replace($migrationsDir . DIRECTORY_SEPARATOR, '', $filePath);
            }
            $relativePath = 'database/migrations/' . str_replace('\\', '/', $rel);
            echo "Adding migration: " . $relativePath . "\n";
            $phar->addFile($filePath, $relativePath);
        }
    }
}

// 3. Include version.json inside the Phar
$versionFile = __DIR__ . '/version.json';
if (file_exists($versionFile)) {
    $phar->addFile($versionFile, 'version.json');
}

// 4. Define custom Stub
$stub = <<<PHP
<?php
/**
 * Custom PHAR Stub
 * Map Phar name and handle routing and CLI commands.
 */
Phar::mapPhar('backend.phar');

if (php_sapi_name() === 'cli') {
    // CLI commands passthrough
    if (\$argc > 1 && \$argv[1] === 'process-jobs') {
        unset(\$argv[1]);
        \$argv = array_values(\$argv);
        \$argc = count(\$argv);
        require 'phar://backend.phar/cli/process-jobs.php';
        exit(0);
    }
    
    if (\$argc > 1 && \$argv[1] === 'migrate') {
        unset(\$argv[1]);
        \$argv = array_values(\$argv);
        \$argc = count(\$argv);
        require 'phar://backend.phar/cli/migrate.php';
        exit(0);
    }

    if (\$argc > 1 && \$argv[1] === 'websocket-server') {
        unset(\$argv[1]);
        \$argv = array_values(\$argv);
        \$argc = count(\$argv);
        require 'phar://backend.phar/cli/websocket-server.php';
        exit(0);
    }

    // Recovery Mode / verify-admin placeholder
    if (\$argc > 1 && \$argv[1] === 'verify-admin') {
        echo "Placeholder for verify-admin in Recovery Mode.\n";
        exit(1);
    }
}

// Default web routing
require 'phar://backend.phar/router.php';

__HALT_COMPILER();
PHP;

$phar->setStub($stub);

// 5. Apply SHA-512 integrity verification check (not cryptographic signature or encryption)
$phar->setSignatureAlgorithm(Phar::SHA512);

$phar->stopBuffering();

if (file_exists($pharFile)) {
    unlink($pharFile);
}
rename($tempPharFile, $pharFile);

echo "backend.phar with SHA-512 integrity check generated successfully!\n";
