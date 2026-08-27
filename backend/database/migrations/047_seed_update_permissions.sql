-- Migration: Add update permissions for RBAC
INSERT IGNORE INTO permissions (name, description, module) VALUES
('updates.view', 'View system update status and history', 'updates'),
('updates.check', 'Check for new system updates', 'updates'),
('updates.apply', 'Install and apply system updates', 'updates'),
('updates.rollback', 'Rollback system updates from snapshots', 'updates');

-- Grant update permissions to admin role
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id FROM permissions WHERE module = 'updates' AND EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'role_permissions');
