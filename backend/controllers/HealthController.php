<?php

class HealthController {
    public function check() {
        http_response_code(200);
        
        $dbStatus = 'disconnected';
        try {
            // Simple check to see if we can get an instance
            $db = Database::getInstance()->getConnection();
            if ($db) {
                $dbStatus = 'connected';
            }
        } catch (Throwable $e) {
            $dbStatus = 'error';
        }

        echo json_encode([
            'status' => 'healthy',
            'database' => $dbStatus,
            'timestamp' => time()
        ]);
        exit;
    }
}
