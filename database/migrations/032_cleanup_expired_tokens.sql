-- ============================================================
-- Migration 032: Clean up expired tokens and add scheduled purge
-- ============================================================
-- 1. Delete all currently expired tokens (one-time cleanup)
-- 2. Create a MySQL EVENT to auto-purge expired tokens daily
--    Uses a single-statement event to avoid BEGIN/END delimiter
--    issues with PDO-based migration runners.
-- ============================================================

-- One-time cleanup of existing expired tokens
DELETE FROM tokens WHERE expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP();
DELETE FROM refresh_tokens WHERE expires_at < UTC_TIMESTAMP();

-- Enable the MySQL Event Scheduler (required for CREATE EVENT)
-- This is idempotent: if already ON, it stays ON.
SET GLOBAL event_scheduler = ON;

-- Drop the event if it already exists (idempotent migration)
DROP EVENT IF EXISTS cleanup_expired_tokens;

-- Create a daily event to purge expired tokens
-- Uses a single DELETE so we avoid BEGIN...END which requires DELIMITER changes.
-- The refresh_tokens cleanup is handled by the application-level purge fallback.
CREATE EVENT cleanup_expired_tokens
ON SCHEDULE EVERY 1 DAY
STARTS (TIMESTAMP(CURRENT_DATE, '03:00:00') + INTERVAL 1 DAY)
DO
  DELETE FROM tokens WHERE expires_at IS NOT NULL AND expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR);
