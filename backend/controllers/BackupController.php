<?php

class BackupController extends Controller {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function download(): void {
        $tables = $this->db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $sql  = "-- POS Database Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Host: " . DB_HOST . " | Database: " . DB_NAME . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            // Skip internal tables
            if (in_array($table, ['settings'])) {
                // include settings in backup
            }

            // Table structure
            $createStmt = $this->db->query("SHOW CREATE TABLE `$table`")->fetch();
            $sql .= "-- Table: $table\n";
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $createStmt['Create Table'] . ";\n\n";

            // Table data
            $rows = $this->db->query("SELECT * FROM `$table`")->fetchAll();
            if (!empty($rows)) {
                $columns = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $sql .= "INSERT INTO `$table` ($columns) VALUES\n";
                $values = [];
                foreach ($rows as $row) {
                    $escaped = array_map(function ($v) {
                        if ($v === null) return 'NULL';
                        return "'" . addslashes((string)$v) . "'";
                    }, array_values($row));
                    $values[] = '(' . implode(', ', $escaped) . ')';
                }
                $sql .= implode(",\n", $values) . ";\n\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $filename = 'pos_backup_' . date('Y-m-d_H-i-s') . '.sql';

        // Override Content-Type for file download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        header('Cache-Control: no-cache');
        echo $sql;
        exit;
    }
}
