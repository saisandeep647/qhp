<?php
// DB configuration - default uses SQLite for easy hosting.
// To use MySQL, change DB_TYPE to 'mysql' and fill the MySQL DSN / credentials.
return (object)[
    'DB_TYPE' => 'sqlite', // 'sqlite' or 'mysql'
    'SQLITE_PATH' => __DIR__ . '/data.db', // path to sqlite file (must be writable by PHP)
    // MySQL settings (used only if DB_TYPE === 'mysql')
    'MYSQL_DSN' => 'mysql:host=localhost;dbname=business_verifier;charset=utf8mb4',
    'MYSQL_USER' => 'dbuser',
    'MYSQL_PASS' => 'dbpass',
    // App settings
    'BASE_URL' => '', // leave empty for same-origin; if you deploy API to another domain, set here (e.g. https://api.example.com)
];
