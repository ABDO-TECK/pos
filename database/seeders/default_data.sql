-- ============================================================
-- Database Seeder — بيانات البذر الافتراضية
-- ============================================================
-- يمكن تشغيله بشكل مستقل: php backend/cli/seed.php
-- يستخدم INSERT IGNORE لعدم الكتابة فوق بيانات موجودة.
-- ============================================================

-- Default branch
INSERT IGNORE INTO branches (id, name) VALUES (1, 'الفرع الرئيسي');

-- Default admin user (password: password) — ⚠️ يجب تغييرها فوراً
INSERT IGNORE INTO users (name, email, password, role, force_password_change) VALUES
('Admin', 'admin@pos.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1),
('Cashier', 'cashier@pos.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 1);

-- Default settings
INSERT IGNORE INTO settings (`key`, `value`) VALUES
('store_name', 'سوبر ماركت'),
('tax_enabled', '0'),
('tax_rate', '15'),
('loyalty_enabled', '0'),
('loyalty_points_per_rial', '1'),
('loyalty_rial_per_point', '0.01');

-- Default categories (أمثلة)
INSERT IGNORE INTO categories (id, name) VALUES
(1, 'مواد غذائية'),
(2, 'مشروبات'),
(3, 'منظفات'),
(4, 'أخرى');
