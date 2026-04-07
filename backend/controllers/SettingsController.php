<?php

class SettingsController extends Controller {

    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureTable();
    }

    /** Create the settings table + default rows if they don't exist yet. */
    private function ensureTable(): void {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                `key`       VARCHAR(100) NOT NULL PRIMARY KEY,
                `value`     TEXT         NULL,
                updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $defaults = [
            'store_name'  => 'سوبر ماركت',
            'tax_enabled' => '1',
            'tax_rate'    => '15',
        ];

        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO settings (`key`, `value`) VALUES (:key, :value)'
        );
        foreach ($defaults as $key => $value) {
            $stmt->execute(['key' => $key, 'value' => $value]);
        }
    }

    private function all(): array {
        $rows     = $this->db->query('SELECT `key`, `value` FROM settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }
        return $settings;
    }

    public function index(): void {
        Response::success($this->all());
    }

    public function update(): void {
        $data = $this->getBody();

        $allowed = ['store_name', 'tax_enabled', 'tax_rate'];
        $stmt    = $this->db->prepare(
            'INSERT INTO settings (`key`, `value`) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `value` = :v2'
        );

        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $val = (string)$data[$key];
                $stmt->execute(['k' => $key, 'v' => $val, 'v2' => $val]);
            }
        }

        Response::success($this->all(), 'Settings updated');
    }
}
