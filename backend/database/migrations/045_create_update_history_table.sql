-- Migration: Create update_history table for tracking update transactions and rollbacks
CREATE TABLE IF NOT EXISTS update_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_version VARCHAR(50) NOT NULL,
    to_version VARCHAR(50) NOT NULL,
    type ENUM('delta', 'full') DEFAULT 'delta',
    status ENUM('success', 'failed', 'rolled_back') NOT NULL,
    files_count INT DEFAULT 0,
    backup_path VARCHAR(255) NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
