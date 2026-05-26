<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load .env from the api/ folder or the project root
foreach ([__DIR__ . '/../.env', __DIR__ . '/../../.env'] as $envFile) {
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($key, $_ENV)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
        break;
    }
}

require_once __DIR__ . '/FirebaseService.php';

// Support JSON content via env var (for Vercel / serverless deployments)
$serviceAccountJson = getenv('FIREBASE_SERVICE_ACCOUNT_JSON') ?: '';
if ($serviceAccountJson) {
    $tmpPath = sys_get_temp_dir() . '/firebase-sa-' . md5($serviceAccountJson) . '.json';
    if (!file_exists($tmpPath)) {
        file_put_contents($tmpPath, $serviceAccountJson);
    }
    $serviceAccountPath = $tmpPath;
} else {
    $serviceAccountPath = getenv('FIREBASE_SERVICE_ACCOUNT')
        ?: __DIR__ . '/../../firebase-service-account.json';
}

if (!file_exists($serviceAccountPath)) {
    http_response_code(500);
    die(json_encode([
        'error' => true,
        'message' => 'Firebase service account file not found. Set FIREBASE_SERVICE_ACCOUNT_JSON in your environment variables with the JSON content of your service account key.',
    ]));
}

try {
    $firebase = new FirebaseService($serviceAccountPath);
} catch (\Throwable $e) {
    http_response_code(500);
    die('Firebase init failed: ' . $e->getMessage());
}

define('SITE_URL',            getenv('SITE_URL')            ?: 'http://localhost/car-repair-system/api/');
define('FIREBASE_API_KEY',    getenv('FIREBASE_API_KEY')    ?: 'AIzaSyB4b6VpoZbyrW-Xf309I3ihKfV2S8c6BSw');
define('FIREBASE_PROJECT_ID', getenv('FIREBASE_PROJECT_ID') ?: 'car-repair-system-36204');
define('FIREBASE_AUTH_DOMAIN', getenv('FIREBASE_AUTH_DOMAIN') ?: 'car-repair-system-36204.firebaseapp.com');
define('MAX_LOGIN_ATTEMPTS',  5);
define('LOCKOUT_TIME',        900);
