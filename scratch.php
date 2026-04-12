<?php
require 'backend/helpers/EnvLoader.php';
EnvLoader::load('backend/.env');
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'pos_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

try {
    $db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if column exists
    $stmt = $db->query("SHOW COLUMNS FROM products LIKE 'box_barcode'");
    if ($stmt->rowCount() == 0) {
        echo "Adding box_barcode... ";
        $db->exec('ALTER TABLE products ADD COLUMN box_barcode VARCHAR(100) NULL DEFAULT NULL UNIQUE AFTER barcode');
        echo "Done.\n";
    } else {
        echo "box_barcode already exists.\n";
    }

    $stmt = $db->query("SHOW COLUMNS FROM products LIKE 'units_per_box'");
    if ($stmt->rowCount() == 0) {
        echo "Adding units_per_box... ";
        $db->exec('ALTER TABLE products ADD COLUMN units_per_box INT NOT NULL DEFAULT 1 AFTER low_stock_threshold');
        echo "Done.\n";
    } else {
        echo "units_per_box already exists.\n";
    }

} catch (Exception $e) {
    echo $e->getMessage();
}
