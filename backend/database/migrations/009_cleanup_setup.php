<?php

return new class {
    public function up(PDO $db): void {
        // تنظيف ملفات اللوج القديمة
            try {
                Logger::cleanup();
                (new RateLimiter())->cleanup();
            } catch (Throwable) {}
    }
};