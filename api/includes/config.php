<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// Build DSN — supports Railway's MYSQL_PUBLIC_URL or individual DB_* vars
$mysql_url = getenv('MYSQL_PUBLIC_URL') ?: getenv('MYSQL_URL') ?: getenv('DATABASE_URL');
if ($mysql_url) {
    $p = parse_url($mysql_url);
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $p['host'],
        $p['port'] ?? 3306,
        ltrim($p['path'], '/')
    );
    $db_user = $p['user'];
    $db_pass = $p['pass'];
} else {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: '127.0.0.1',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_NAME') ?: 'car_repair_db'
    );
    $db_user = getenv('DB_USER') ?: 'root';
    $db_pass = getenv('DB_PASS') ?: '';
}

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed: ' . $e->getMessage());
}

define('SITE_URL', getenv('SITE_URL') ?: 'http://localhost/car-repair-system/');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900);
