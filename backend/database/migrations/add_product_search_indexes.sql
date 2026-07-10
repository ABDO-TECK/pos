-- Performance: Add indexes for barcode exact-match and prefix search
-- Run once via: mysql -u root pos_db < backend/database/migrations/add_product_search_indexes.sql

-- Index on barcode columns for exact-match and prefix queries
ALTER TABLE products ADD INDEX idx_products_barcode (barcode);
ALTER TABLE products ADD INDEX idx_products_box_barcode (box_barcode);
ALTER TABLE product_barcodes ADD INDEX idx_pb_barcode (barcode);

-- Index on parent_product_id for the sizes batch query (Task 1.1)
ALTER TABLE products ADD INDEX idx_products_parent_id (parent_product_id);
