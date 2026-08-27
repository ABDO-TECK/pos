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
$addedFileCount = 0;
$addedMigrationCount = 0;
$addedSeederCount = 0;

// 1. Walk through the backend directory and compile it
$backendDir = __DIR__ . '/backend';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($backendDir, FilesystemIterator::SKIP_DOTS)
);

// Define directories and files to exclude from the final build
$excludePatterns = [
    '/^backend\.phar$/',
    '/^database\//',
    '/^tests/',
    '/^docs/',
    '/^swagger/',
    '/^logs/',
    // Runtime state and generated credentials must never be packaged.
    '/^storage\//',
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
    '/^vendor\/nikic\/php-parser/',
    '/^vendor\/phar-io/',
    '/^vendor\/sebastian/',
    '/^vendor\/symfony\/finder/',
    '/^vendor\/symfony\/polyfill-ctype/',
    '/^vendor\/symfony\/yaml/',
    '/^vendor\/theseer/',
    '/^vendor\/zircote/',
    '/^vendor\/bin/',
    '/^vendor\/.*\/(?:tests?|\.github)\//',
    '/^vendor\/.*\/(?:docs?|examples?)\//',
];

function getFilteredAutoloadFile($filePath, $relativePath, $backendDir) {
    $tempFile = tempnam(sys_get_temp_dir(), 'composer_autoload_');
    
    $vendorDir = $backendDir . '/vendor';
    $baseDir = $backendDir;
    
    $vendorDirReal = realpath($vendorDir);
    $baseDirReal = realpath($baseDir);

    $formatPaths = function ($arr) use ($vendorDirReal, $baseDirReal) {
        $formatted = [];
        foreach ($arr as $key => $val) {
            $valReal = realpath($val);
            if ($valReal === false) {
                $valReal = $val;
            }
            $valStandard = str_replace('\\', '/', $valReal);
            $vendorStandard = str_replace('\\', '/', $vendorDirReal);
            $baseStandard = str_replace('\\', '/', $baseDirReal);
            
            if (strpos(strtolower($valStandard), strtolower($vendorStandard)) === 0) {
                $rel = substr($valStandard, strlen($vendorStandard));
                $formatted[$key] = '##VENDOR_DIR##' . $rel;
            } elseif (strpos(strtolower($valStandard), strtolower($baseStandard)) === 0) {
                $rel = substr($valStandard, strlen($baseStandard));
                $formatted[$key] = '##BASE_DIR##' . $rel;
            } else {
                $formatted[$key] = $val;
            }
        }
        return $formatted;
    };

    $formatDirs = function ($arr) use ($vendorDirReal, $baseDirReal) {
        $formatted = [];
        foreach ($arr as $key => $dirs) {
            $formattedDirs = [];
            foreach ($dirs as $dir) {
                $dirReal = realpath($dir);
                if ($dirReal === false) {
                    $dirReal = $dir;
                }
                $dirStandard = str_replace('\\', '/', $dirReal);
                $vendorStandard = str_replace('\\', '/', $vendorDirReal);
                $baseStandard = str_replace('\\', '/', $baseDirReal);
                
                if (strpos(strtolower($dirStandard), strtolower($vendorStandard)) === 0) {
                    $rel = substr($dirStandard, strlen($vendorStandard));
                    $formattedDirs[] = '##VENDOR_DIR##' . $rel;
                } elseif (strpos(strtolower($dirStandard), strtolower($baseStandard)) === 0) {
                    $rel = substr($dirStandard, strlen($baseStandard));
                    $formattedDirs[] = '##BASE_DIR##' . $rel;
                } else {
                    $formattedDirs[] = $dir;
                }
            }
            $formatted[$key] = $formattedDirs;
        }
        return $formatted;
    };

    $isExcludedPath = function ($path) {
        $normalized = str_replace('\\', '/', $path);
        return (
            strpos($normalized, '/phpunit/') !== false ||
            strpos($normalized, '/nikic/php-parser/') !== false ||
            strpos($normalized, '/zircote/') !== false ||
            strpos($normalized, '/sebastian/') !== false ||
            strpos($normalized, '/phar-io/') !== false ||
            strpos($normalized, '/symfony/finder/') !== false ||
            strpos($normalized, '/symfony/polyfill-ctype/') !== false ||
            strpos($normalized, '/symfony/yaml/') !== false ||
            strpos($normalized, '/theseer/') !== false
        );
    };

    if ($relativePath === 'vendor/composer/autoload_files.php' || 
        $relativePath === 'vendor/composer/autoload_classmap.php' || 
        $relativePath === 'vendor/composer/autoload_psr4.php') {
        
        $arr = include $filePath;
        $filtered = [];
        foreach ($arr as $key => $val) {
            if (is_array($val)) {
                $filteredDirs = [];
                foreach ($val as $dir) {
                    if (!$isExcludedPath($dir)) {
                        $filteredDirs[] = $dir;
                    }
                }
                if (!empty($filteredDirs)) {
                    $filtered[$key] = $filteredDirs;
                }
            } else {
                if (!$isExcludedPath($val)) {
                    $filtered[$key] = $val;
                }
            }
        }

        if (is_array(reset($filtered))) {
            $formatted = $formatDirs($filtered);
        } else {
            $formatted = $formatPaths($filtered);
        }

        $export = var_export($formatted, true);
        $export = str_replace("'##VENDOR_DIR##", "\$vendorDir . '", $export);
        $export = str_replace("'##BASE_DIR##", "\$baseDir . '", $export);

        $code = "<?php\n\n// autoload_files.php/classmap.php/psr4.php @generated by Composer (Filtered)\n\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\n\nreturn " . $export . ";\n";
        file_put_contents($tempFile, $code);
        return $tempFile;
    }

    if ($relativePath === 'vendor/composer/autoload_static.php') {
        $content = file_get_contents($filePath);
        if (preg_match('/class (ComposerStaticInit[a-f0-9]+)/', $content, $matches)) {
            $classShortName = $matches[1];
            include $filePath;
            $className = 'Composer\\Autoload\\' . $classShortName;
            
            $reflection = new \ReflectionClass($className);
            $staticProperties = $reflection->getStaticProperties();
            
            $code = "<?php\n\nnamespace Composer\Autoload;\n\nclass {$classShortName}\n{\n";
            $loaderAssignments = "";
            
            foreach ($staticProperties as $propName => $propValue) {
                if (!is_array($propValue)) {
                    continue;
                }
                
                $filteredValue = [];
                if ($propName === 'prefixLengthsPsr4') {
                    continue;
                }
                
                if ($propName === 'prefixDirsPsr4') {
                    foreach ($propValue as $namespace => $dirs) {
                        $filteredDirs = [];
                        foreach ($dirs as $dir) {
                            if (!$isExcludedPath($dir)) {
                                $filteredDirs[] = $dir;
                            }
                        }
                        if (!empty($filteredDirs)) {
                            $filteredValue[$namespace] = $filteredDirs;
                        }
                    }
                } else {
                    foreach ($propValue as $key => $val) {
                        if (is_array($val)) {
                            $filteredSub = [];
                            foreach ($val as $subKey => $subVal) {
                                if (!$isExcludedPath($subVal)) {
                                    $filteredSub[$subKey] = $subVal;
                                }
                            }
                            if (!empty($filteredSub)) {
                                $filteredValue[$key] = $filteredSub;
                            }
                        } else {
                            if (!$isExcludedPath($val)) {
                                $filteredValue[$key] = $val;
                            }
                        }
                    }
                }
                
                if ($propName === 'prefixDirsPsr4') {
                    $filteredPrefixLengthsPsr4 = [];
                    foreach ($filteredValue as $namespace => $dirs) {
                        $firstLetter = $namespace[0];
                        $length = strlen($namespace);
                        $filteredPrefixLengthsPsr4[$firstLetter][$namespace] = $length;
                    }
                    ksort($filteredPrefixLengthsPsr4);
                    foreach ($filteredPrefixLengthsPsr4 as $letter => &$namespaces) {
                        arsort($namespaces);
                    }
                    
                    $prefixLengthsExport = var_export($filteredPrefixLengthsPsr4, true);
                    $prefixLengthsExport = str_replace("'##VENDOR_DIR##", "__DIR__ . '/..' . '", $prefixLengthsExport);
                    $prefixLengthsExport = str_replace("'##BASE_DIR##", "__DIR__ . '/../..' . '", $prefixLengthsExport);
                    $code .= "    public static \$prefixLengthsPsr4 = " . $prefixLengthsExport . ";\n\n";
                    $loaderAssignments .= "            \$loader->prefixLengthsPsr4 = {$classShortName}::\$prefixLengthsPsr4;\n";
                }
                
                if (is_array(reset($filteredValue))) {
                    $formattedValue = $formatDirs($filteredValue);
                } else {
                    $formattedValue = $formatPaths($filteredValue);
                }
                
                $export = var_export($formattedValue, true);
                $export = str_replace("'##VENDOR_DIR##", "__DIR__ . '/..' . '", $export);
                $export = str_replace("'##BASE_DIR##", "__DIR__ . '/../..' . '", $export);
                
                $code .= "    public static \${$propName} = " . $export . ";\n\n";
                
                if ($propName !== 'files') {
                    $loaderAssignments .= "            \$loader->{$propName} = {$classShortName}::\${$propName};\n";
                }
            }
            
            $code .= "    public static function getInitializer(ClassLoader \$loader)\n";
            $code .= "    {\n";
            $code .= "        return \Closure::bind(function () use (\$loader) {\n";
            $code .= $loaderAssignments;
            $code .= "        }, null, ClassLoader::class);\n";
            $code .= "    }\n";
            $code .= "}\n";
            
            file_put_contents($tempFile, $code);
            return $tempFile;
        }
    }

    return $filePath;
}

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
            $targetPath = getFilteredAutoloadFile($filePath, $relativePath, $backendDir);
            $phar->addFile($targetPath, $relativePath);
            $addedFileCount++;
            if ($targetPath !== $filePath) {
                unlink($targetPath); // Clean up the temp file
            }
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
            $phar->addFile($filePath, $relativePath);
            $addedMigrationCount++;
        }
    }
}

// 2b. Include default seeders used by first-install and factory-reset flows.
$seedersDir = __DIR__ . '/database/seeders';
if (is_dir($seedersDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($seedersDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $filePath = $file->getPathname();
            $realSeedersDir = realpath($seedersDir);
            $realFilePath = realpath($filePath);
            $rel = '';
            if ($realSeedersDir !== false && $realFilePath !== false && strpos(strtolower($realFilePath), strtolower($realSeedersDir)) === 0) {
                $rel = substr($realFilePath, strlen($realSeedersDir));
                $rel = ltrim($rel, DIRECTORY_SEPARATOR . '/');
            } else {
                $rel = str_replace($seedersDir . DIRECTORY_SEPARATOR, '', $filePath);
            }
            $relativePath = 'database/seeders/' . str_replace('\\', '/', $rel);
            $phar->addFile($filePath, $relativePath);
            $addedSeederCount++;
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

    if (\$argc > 1 && \$argv[1] === 'cleanup-logs') {
        unset(\$argv[1]);
        \$argv = array_values(\$argv);
        \$argc = count(\$argv);
        require 'phar://backend.phar/cli/cleanup-logs.php';
        exit(0);
    }

    if (\$argc > 1 && \$argv[1] === 'restore-backup') {
        unset(\$argv[1]);
        \$argv = array_values(\$argv);
        \$argc = count(\$argv);
        require 'phar://backend.phar/cli/restore-backup.php';
        exit(0);
    }

    if (\$argc > 1 && \$argv[1] === 'reset-password') {
        unset(\$argv[1]);
        \$argv = array_values(\$argv);
        \$argc = count(\$argv);
        require 'phar://backend.phar/cli/reset-password.php';
        exit(0);
    }

    if (\$argc > 1 && \$argv[1] === 'seed') {
        unset(\$argv[1]);
        \$argv = array_values(\$argv);
        \$argc = count(\$argv);
        require 'phar://backend.phar/cli/seed.php';
        exit(0);
    }

    if (\$argc > 1 && \$argv[1] === 'initialize-admin') {
        unset(\$argv[1]);
        \$argv = array_values(\$argv);
        \$argc = count(\$argv);
        require 'phar://backend.phar/cli/initialize-admin.php';
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

echo "backend.phar generated successfully ({$addedFileCount} backend files, {$addedMigrationCount} migrations, {$addedSeederCount} seeders; SHA-512 integrity check).\n";
