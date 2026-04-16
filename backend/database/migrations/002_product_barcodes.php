<?php

return new class {
    public function up(PDO $db): void {
        $this->db->exec(
                'CREATE TABLE IF NOT EXISTS product_barcodes (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    product_id INT UNSIGNED NOT NULL,
                    barcode VARCHAR(100) NOT NULL,
                    UNIQUE KEY uq_product_barcodes_barcode (barcode),
                    KEY idx_product_barcodes_product (product_id),
                    CONSTRAINT fk_pb_product FOREIGN KEY (product_id)
                        REFERENCES products(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
    }
};