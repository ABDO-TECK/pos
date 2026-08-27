-- Migration 050: Add Update Recovery & Self-Healing Permissions
-- Phase 12 Self-Healing Update Infrastructure

INSERT IGNORE INTO `permissions` (`name`, `description`, `created_at`) VALUES
('updates.recovery.view', 'View update recovery status, diagnostics, and audit logs', NOW()),
('updates.recovery.manage', 'Execute manual update recovery, rollbacks, and self-healing actions', NOW());

-- Automatically assign recovery permissions to the admin role
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id 
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.name = 'admin' 
  AND p.name IN ('updates.recovery.view', 'updates.recovery.manage');
