<?php
ob_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['brands' => []]);
    exit();
}

require_once __DIR__ . '/includes/config.php';

$part_id = trim($_GET['part_id'] ?? '');

$brands = [];
if (!empty($part_id)) {
    $part = $firebase->getDoc('spare_parts', $part_id);
    if ($part) {
        foreach ($part['brands'] ?? [] as $brand) {
            $brands[] = [
                'name'  => $brand['brand_name'] ?? '',
                'price' => $brand['price'] ?? null,
            ];
        }
    }
}

echo json_encode(['brands' => $brands]);
?>