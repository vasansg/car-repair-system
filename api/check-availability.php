<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/includes/config.php';

$date = isset($_GET['date']) ? $_GET['date'] : '';
$bookedSlots = [];

if ($date) {
    // Get all time slots with their max bookings
    $slots_sql = "SELECT slot_time, max_bookings FROM booking_timeslots WHERE is_active = 1";
    $slots_result = $pdo->query($slots_sql);

    foreach ($slots_result->fetchAll(PDO::FETCH_ASSOC) as $slot) {
        // Count current bookings for this time slot
        $count_sql = "SELECT COUNT(*) as booked_count FROM bookings
                      WHERE booking_date = ? AND booking_time = ? AND status NOT IN ('cancelled')";
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute([$date, $slot['slot_time']]);
        $booked_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['booked_count'];

        $bookedSlots[] = [
            'time' => $slot['slot_time'],
            'max_bookings' => $slot['max_bookings'],
            'booked_count' => $booked_count
        ];
    }
}

echo json_encode(['bookedSlots' => $bookedSlots]);
?>