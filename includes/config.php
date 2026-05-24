<?php
session_start();

// Load .env file for local development
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($key, $_ENV)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

// Build DSN — supports Railway's DATABASE_URL or individual vars
$database_url = getenv('DATABASE_URL');
if ($database_url) {
    $p = parse_url($database_url);
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=require',
        $p['host'],
        $p['port'] ?? 5432,
        ltrim($p['path'], '/')
    );
    $db_user = $p['user'];
    $db_pass = $p['pass'];
} else {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_PORT') ?: '5432',
        getenv('DB_NAME') ?: 'car_repair_db'
    );
    $db_user = getenv('DB_USER') ?: 'postgres';
    $db_pass = getenv('DB_PASS') ?: '';
}

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed: ' . $e->getMessage());
}

define('SITE_URL', getenv('SITE_URL') ?: 'http://localhost/car-repair-system/');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900);
