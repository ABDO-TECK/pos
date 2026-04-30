<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Cache;
use App\Helpers\Response;
use PDO;


class SettingsController extends Controller {

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    private function all() {
        $rows     = $this->db->query('SELECT `key`, `value` FROM settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }
        return $settings;
    }

    public function index() {
        return Response::cacheable($this->all(), 300); // Cache for 5 minutes
    }

    public function update() {
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

        return Response::success($this->all(), 'Settings updated');
    }
}


