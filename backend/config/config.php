<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'pos_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_ENV', 'development');
define('APP_DEBUG', true);

define('TOKEN_LIFETIME', 60 * 60 * 24 * 7); // 7 days in seconds

define('LOW_STOCK_THRESHOLD', 5);

define('TAX_RATE', 0.15); // 15% VAT

define('FRONTEND_URL', 'http://localhost:5173');
