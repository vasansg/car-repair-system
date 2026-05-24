<?php
ob_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['brands' => []]);
    exit();
}

require_once __DIR__ . '/includes/config.php';

$part_id = isset($_GET['part_id']) ? intval($_GET['part_id']) : 0;

$brands = [];
if ($part_id > 0) {
    $sql = "SELECT b.brand_name, spb.price 
            FROM spare_part_brands spb
            JOIN brands b ON spb.brand_id = b.id
            WHERE spb.spare_part_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$part_id]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $brands[] = [
            'name' => $row['brand_name'],
            'price' => $row['price']
        ];
    }
}

echo json_encode(['brands' => $brands]);
?>