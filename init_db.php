<?php
// Run this once in the browser or CLI to create the DB and table.
// After running, delete or protect this file.
$config = require __DIR__ . '/config.php';

try {
    if ($config->DB_TYPE === 'sqlite') {
        $pdo = new PDO('sqlite:' . $config->SQLITE_PATH);
        // Enable foreign keys pragma
        $pdo->exec('PRAGMA foreign_keys = ON;');
    } else {
        $pdo = new PDO($config->MYSQL_DSN, $config->MYSQL_USER, $config->MYSQL_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS entries (
  id TEXT PRIMARY KEY,
  category TEXT NOT NULL,
  businessName TEXT NOT NULL,
  ownerName TEXT,
  location TEXT,
  personalPhone TEXT,
  businessPhone TEXT,
  verified INTEGER DEFAULT 0,
  status TEXT DEFAULT 'pending', -- pending|accepted|rejected
  rejectReason TEXT,
  createdAt TEXT,
  updatedAt TEXT
);
SQL;

    $pdo->exec($sql);

    echo "DB initialized successfully. If using SQLite, ensure '" . $config->SQLITE_PATH . "' is writable by PHP.\n";
    echo "Remove or protect init_db.php now.";
} catch (Exception $e) {
    http_response_code(500);
    echo "DB init failed: " . htmlspecialchars($e->getMessage());
}
