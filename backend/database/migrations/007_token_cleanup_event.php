<?php

return new class {
    public function up(PDO $db): void {
        try {
                // إنشاء event لتنظيف الـ tokens (يومياً)
                // إذا كان scheduler غير مفعّل — نحذف يدوياً
                $db->exec('DELETE FROM tokens WHERE expires_at IS NOT NULL AND expires_at < NOW()');
            } catch (Throwable $e) {
                Logger::warning('Migration 007', ['error' => $e->getMessage()]);
            }
    }
};