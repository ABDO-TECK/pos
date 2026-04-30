<?php

namespace App\Services;

use App\Config\Database;
use PDO;
use RuntimeException;


class BackupService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Create a backup file in the specified directory.
     * Returns the full path to the backup file.
     */
    public function createBackupFile(string $backupDir): string {
        $sql = $this->generateBackupSql();

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0777, true);
        }
        $filename = rtrim($backupDir, '/\\') . '/pre_update_' . date('Y-m-d_H-i-s') . '.sql';
        file_put_contents($filename, $sql);

        // Keep only last 10 backups
        $files = glob(rtrim($backupDir, '/\\') . '/pre_update_*.sql') ?: [];
        rsort($files);
        foreach (array_slice($files, 10) as $old) {
            @unlink($old);
        }

        return $filename;
    }

    /**
     * Stream the backup directly to output (for download).
     */
    public function streamBackup(): void {
        $tables = $this->db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        echo "-- POS Database Backup\n";
        echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        echo "-- Host: " . DB_HOST . " | Database: " . DB_NAME . "\n\n";
        echo "SET FOREIGN_KEY_CHECKS=0;\n\n";
        flush();

        foreach ($tables as $table) {
            // Table structure
            $createStmt = $this->db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $ddl = $createStmt['Create Table'] ?? null;
            if ($ddl === null && is_array($createStmt)) {
                $vals = array_values($createStmt);
                $ddl  = $vals[1] ?? '';
            }
            if (!$ddl) {
                continue;
            }

            echo "-- Table: $table\n";
            echo "DROP TABLE IF EXISTS `$table`;\n";
            echo $ddl . ";\n\n";
            flush();

            // Table data (Streamed row by row)
            $stmt = $this->db->query("SELECT * FROM `$table`");
            $firstRow = true;
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($firstRow) {
                    $columns = '`' . implode('`, `', array_keys($row)) . '`';
                    echo "INSERT INTO `$table` ($columns) VALUES\n";
                    $firstRow = false;
                } else {
                    echo ",\n";
                }

                $escaped = array_map(function ($v) {
                    return $v === null ? 'NULL' : $this->db->quote((string)$v);
                }, array_values($row));
                
                echo '(' . implode(', ', $escaped) . ')';
            }
            
            if (!$firstRow) {
                echo ";\n\n";
            }
            flush();
        }

        echo "SET FOREIGN_KEY_CHECKS=1;\n";
        flush();
    }

    /**
     * Generate backup SQL string in memory.
     */
    private function generateBackupSql(): string {
        $tables = $this->db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $sql  = "-- POS Auto-Update Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $createStmt = $this->db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $ddl = $createStmt['Create Table'] ?? null;
            if ($ddl === null && is_array($createStmt)) {
                $vals = array_values($createStmt);
                $ddl  = $vals[1] ?? '';
            }
            if (!$ddl) {
                throw new RuntimeException("تعذر قراءة هيكل الجدول: $table");
            }

            $sql .= "-- Table: $table\n";
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $ddl . ";\n\n";

            $rows = $this->db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $sql .= "INSERT INTO `$table` ($columns) VALUES\n";
                $values = [];
                foreach ($rows as $row) {
                    $escaped = array_map(function ($v) {
                        return $v === null ? 'NULL' : $this->db->quote((string)$v);
                    }, array_values($row));
                    $values[] = '(' . implode(', ', $escaped) . ')';
                }
                $sql .= implode(",\n", $values) . ";\n\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        return $sql;
    }
}
