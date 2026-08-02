ALTER TABLE job_queue
    ADD INDEX IF NOT EXISTS idx_status_created (status, created_at);
