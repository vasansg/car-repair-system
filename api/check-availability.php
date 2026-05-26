<?php
ob_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/includes/config.php';

$date        = isset($_GET['date']) ? $_GET['date'] : '';
$bookedSlots = [];

if ($date) {
    // Get all active time slots from Firestore
    $slots = $firebase->query('booking_timeslots', [['is_active', '==', true]], 'slot_time', 'ASCENDING');

    foreach ($slots as $slot) {
        // Fetch all bookings for this date and time that are not cancelled
        $bookings = $firebase->query('bookings', [
            ['booking_date', '==', $date],
            ['booking_time', '==', $slot['slot_time']],
        ]);
        // Filter out cancelled in PHP (Firestore can't do NOT IN + multiple equality without composite index)
        $booked_count = count(array_filter($bookings, fn($b) => ($b['status'] ?? '') !== 'cancelled'));

        $bookedSlots[] = [
            'time'         => $slot['slot_time'],
            'max_bookings' => (int)($slot['max_bookings'] ?? 3),
            'booked_count' => $booked_count,
        ];
    }
}

echo json_encode(['bookedSlots' => $bookedSlots]);
?>