<?php

use App\Middleware\RateLimitStore;

if ($argc !== 4) {
    fwrite(STDERR, "Expected storage directory, key, and increment count.\n");
    exit(2);
}

[$script, $storageDirectory, $key, $increments] = $argv;
putenv('APP_STORAGE_DIR=' . $storageDirectory);
$_ENV['APP_STORAGE_DIR'] = $storageDirectory;

$backendDirectory = dirname(__DIR__, 2);
spl_autoload_register(
    static function (string $class) use ($backendDirectory): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $path = $backendDirectory
            . '/'
            . str_replace('\\', '/', substr($class, strlen($prefix)))
            . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
);

for ($increment = 0; $increment < (int) $increments; $increment++) {
    if (RateLimitStore::increment($key, time() + 60) === null) {
        fwrite(STDERR, "Rate-limit storage unavailable.\n");
        exit(3);
    }
}
