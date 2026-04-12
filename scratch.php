<?php
require 'backend/helpers/EnvLoader.php';
EnvLoader::load('backend/.env');
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'pos_db');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

try {
    $db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);
    
    // Add missing column to purchases
    try {
        echo "Adding purchase_invoice_id to purchases... ";
        $db->exec('ALTER TABLE purchases ADD COLUMN purchase_invoice_id INT UNSIGNED NULL AFTER id');
        echo "Done.\n";
    } catch (Throwable $e) { echo "Error: " . $e->getMessage() . "\n"; }

} catch (Exception $e) {
    echo $e->getMessage();
}
