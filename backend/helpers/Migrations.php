<?php

/**
 * Lightweight modular migration runner.
 * Each migration is now stored in backend/database/migrations/*.php
 */
class Migrations {

    private PDO $db;
    private array $appliedCache = [];
    private bool $cacheLoaded = false;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->bootstrap();
    }

    private function bootstrap(): void {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name       VARCHAR(200) NOT NULL UNIQUE,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function applied(string $name): bool {
        if (!$this->cacheLoaded) {
            try {
                $stmt = $this->db->query('SELECT name FROM migrations');
                if ($stmt) {
                    $this->appliedCache = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }
            } catch (Throwable $e) {
                // Fallback if table doesn't exist yet
            }
            $this->cacheLoaded = true;
        }
        return in_array($name, $this->appliedCache, true);
    }

    private function mark(string $name): void {
        $this->db->prepare('INSERT IGNORE INTO migrations (name) VALUES (?)')->execute([$name]);
        $this->appliedCache[] = $name; // Sync cache
    }

    public function run(): void {
        $migrationsDir = __DIR__ . '/../database/migrations';
        if (!is_dir($migrationsDir)) return;

        $files = glob($migrationsDir . '/*.php');
        sort($files);

        foreach ($files as $file) {
            $name = basename($file, '.php');

            if (!$this->applied($name)) {
                $migrationObject = require $file;
                
                if (is_callable($migrationObject)) {
                    $migrationObject($this->db);
                } elseif (is_object($migrationObject) && method_exists($migrationObject, 'up')) {
                    $migrationObject->up($this->db);
                }

                $this->mark($name);
            }
        }

        $this->runCleanups();
    }

    private function runCleanups(): void {
        try {
            if (mt_rand(1, 100) === 1) {
                $this->db->exec('DELETE FROM tokens WHERE expires_at IS NOT NULL AND expires_at < NOW()');
            }
        } catch (Throwable) {}

        try {
            if (mt_rand(1, 200) === 1) {
                if (class_exists('Logger')) Logger::cleanup();
                if (class_exists('RateLimiter')) (new RateLimiter())->cleanup();
            }
        } catch (Throwable) {}
    }
}

