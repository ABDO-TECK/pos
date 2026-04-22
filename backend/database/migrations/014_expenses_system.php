<?php

return new class {
    public function up(PDO $db): void
    {
        // Create expense_categories table
        $db->exec("
            CREATE TABLE IF NOT EXISTS expense_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Insert some default categories if table is empty
        $count = $db->query("SELECT COUNT(*) FROM expense_categories")->fetchColumn();
        if ($count == 0) {
            $db->exec("
                INSERT INTO expense_categories (name) VALUES 
                ('إيجار'),
                ('كهرباء ومياه'),
                ('رواتب عاملين'),
                ('نثريات وضيافة'),
                ('صيانة وإصلاح'),
                ('تسويق وإعلانات')
            ");
        }

        // Create expenses table
        $db->exec("
            CREATE TABLE IF NOT EXISTS expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                category_id INT NOT NULL,
                user_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                notes TEXT,
                expense_date DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
};
