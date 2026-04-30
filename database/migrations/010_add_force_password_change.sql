ALTER TABLE users ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
UPDATE users SET force_password_change = 1 WHERE id IN (1, 2);
