<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Cache;
use App\Helpers\Response;
use PDO;
use App\Requests\SettingsRequest;


class SettingsController extends Controller {

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    private function all() {
        $cached = Cache::get('settings_all');
        if ($cached !== null) return $cached;

        $rows     = $this->db->query('SELECT `key`, `value` FROM settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        Cache::set('settings_all', $settings, 300); // 5 minutes
        return $settings;
    }

    public function index() {
        return Response::cacheable($this->all(), 300);
    }

    public function update() {
        $request = new SettingsRequest($this->getBody());
        $data = $request->validated();
        $allowed = ['store_name', 'tax_enabled', 'tax_rate', 'loyalty_enabled', 'loyalty_points_per_rial', 'loyalty_rial_per_point', 'store_logo'];

        return $this->withTransaction(function ($db) use ($data, $allowed) {
            $stmt = $db->prepare(
                'INSERT INTO settings (`key`, `value`) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE `value` = :v2'
            );

            foreach ($allowed as $key) {
                if (array_key_exists($key, $data)) {
                    $val = (string)$data[$key];
                    $stmt->execute(['k' => $key, 'v' => $val, 'v2' => $val]);
                }
            }

            // Clear cache before re-fetching to ensure fresh data in the response.
            // The event dispatch also clears cache (via CacheSubscriber), but clearing
            // explicitly here guarantees correct ordering regardless of listener execution.
            Cache::forget('settings_all');
            \App\Helpers\EventDispatcher::dispatch('settings.updated');
            return Response::success($this->all(), 'Settings updated');
        });
    }
}


