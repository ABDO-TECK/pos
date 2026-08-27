-- Migration: Extend update_history table with source, release_tag, and download_url
ALTER TABLE update_history 
    ADD COLUMN IF NOT EXISTS source VARCHAR(50) DEFAULT 'github_release' AFTER type,
    ADD COLUMN IF NOT EXISTS release_tag VARCHAR(50) NULL AFTER source,
    ADD COLUMN IF NOT EXISTS download_url VARCHAR(255) NULL AFTER backup_path;
