-- Migration: Add update channel management permission and history tracking
INSERT IGNORE INTO permissions (name, description, module) VALUES
('updates.manage_channel', 'Change release update channel (stable/beta/rc)', 'updates');

-- Grant channel permission to admin role
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id FROM permissions WHERE name = 'updates.manage_channel' AND EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'role_permissions');

-- Add channel & rollout columns to update_history if not present
SET @dbname = DATABASE();
SET @tablename = "update_history";
SET @columnname = "channel";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  "SELECT 1",
  "ALTER TABLE update_history ADD COLUMN channel VARCHAR(20) DEFAULT 'stable' AFTER type;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname = "rollout_percentage";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  "SELECT 1",
  "ALTER TABLE update_history ADD COLUMN rollout_percentage INT DEFAULT 100 AFTER channel;"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
