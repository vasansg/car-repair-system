<?php
ob_start();

require_once __DIR__ . '/includes/config.php';

/* ================= AUTH CHECK ================= */
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

/* ================= ROLE CHECK ================= */
if ($_SESSION['role'] !== 'customer') {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin-dashboard.php");
    } elseif ($_SESSION['role'] === 'staff') {
        header("Location: staff-dashboard.php");
    }
    exit();
}

/* ================= USER DATA ================= */
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$email = $_SESSION['email'];
$first_name = explode(' ', $full_name)[0];

/* ================= TIMEZONE & GREETING ================= */
date_default_timezone_set('Asia/Kuala_Lumpur');
$hour = date('G');
if ($hour >= 5 && $hour < 12) {
    $greeting = 'Good Morning';
} elseif ($hour >= 12 && $hour < 18) {
    $greeting = 'Good Afternoon';
} else {
    $greeting = 'Good Evening';
}

/* ================= HANDLE FORM SUBMISSION ================= */
$error = '';
$success = '';
$selected_vehicle_id = '';
$selected_service_id = '';
$selected_date = '';
$selected_time = '';
$remarks = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_service'])) {
    $selected_vehicle_id = intval($_POST['vehicle_id']);
    $selected_service_id = intval($_POST['service_category']);
    $selected_date = $_POST['booking_date'];
    $selected_time = $_POST['booking_time'];
    $remarks = trim($_POST['remarks']);
    
    // Validate inputs
    $valid = true;
    
    if ($selected_vehicle_id <= 0) {
        $error = 'Please select a vehicle!';
        $valid = false;
    } elseif ($selected_service_id <= 0) {
        $error = 'Please select a service type!';
        $valid = false;
    } elseif (empty($selected_date)) {
        $error = 'Please select a date!';
        $valid = false;
    } elseif (empty($selected_time)) {
        $error = 'Please select a time slot!';
        $valid = false;
    } elseif (strtotime($selected_date) < strtotime(date('Y-m-d'))) {
        $error = 'Cannot book for past dates!';
        $valid = false;
    } elseif (strtotime($selected_date) > strtotime('+30 days')) {
        $error = 'Bookings can only be made up to 30 days in advance!';
        $valid = false;
    }
    
    if ($valid) {
        $day_of_week = date('w', strtotime($selected_date));
        if ($day_of_week == 0) {
            $error = 'Sorry, we are closed on Sundays! Please select another date.';
            $valid = false;
        }
    }
    
    // Check if selected time is in the past for today's date
    if ($valid && $selected_date == date('Y-m-d')) {
        $current_time = date('H:i:s');
        $buffer_time = date('H:i:s', strtotime('+30 minutes'));
        
        if ($selected_time < $buffer_time) {
            $error = 'You cannot book a service within the next 30 minutes. Please select a later time.';
            $valid = false;
        }
    }
    
    if ($valid) {
        $check_sql = "SELECT COUNT(*) as booked_count FROM bookings
                     WHERE booking_date = ? AND booking_time = ? AND status NOT IN ('cancelled')";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$selected_date, $selected_time]);
        $booked_count = $check_stmt->fetch(PDO::FETCH_ASSOC)['booked_count'];

        $max_sql = "SELECT max_bookings FROM booking_timeslots WHERE slot_time = ? AND is_active = 1";
        $max_stmt = $pdo->prepare($max_sql);
        $max_stmt->execute([$selected_time]);
        $max_bookings = $max_stmt->fetch(PDO::FETCH_ASSOC)['max_bookings'] ?? 3;
        
        if ($booked_count >= $max_bookings) {
            $error = 'This time slot is fully booked! Please choose another time.';
            $valid = false;
        }
    }
    
    if ($valid) {
        $price_sql = "SELECT base_price FROM service_categories WHERE id = ?";
        $price_stmt = $pdo->prepare($price_sql);
        $price_stmt->execute([$selected_service_id]);
        $service_price = $price_stmt->fetch(PDO::FETCH_ASSOC)['base_price'] ?? 0;

        $insert_sql = "INSERT INTO bookings (user_id, vehicle_id, service_category_id, booking_date, booking_time, remarks, estimated_price)
                      VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = $pdo->prepare($insert_sql);

        if ($insert_stmt->execute([$user_id, $selected_vehicle_id, $selected_service_id,
                                   $selected_date, $selected_time, $remarks, $service_price])) {
            $booking_id = $pdo->lastInsertId();
            $success = "Service booked successfully! Your booking ID is: #" . str_pad($booking_id, 6, '0', STR_PAD_LEFT);

            // FIX: Clear form data and redirect to prevent duplicate submission on refresh
            $_SESSION['booking_success'] = $success;
            $_SESSION['booking_id'] = $booking_id;

            // Redirect to the same page with a GET request
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
            exit();

        } else {
            $error = 'Error creating booking.';
        }
    }
}

/* ================= FETCH DATA ================= */
$vehicles = [];
$vehicles_sql = "SELECT * FROM vehicles WHERE user_id = ? ORDER BY brand_name, model";
$vehicles_stmt = $pdo->prepare($vehicles_sql);
$vehicles_stmt->execute([$user_id]);
$vehicles = $vehicles_stmt->fetchAll(PDO::FETCH_ASSOC);

$types_sql = "SELECT DISTINCT type FROM service_categories WHERE is_active = 1 ORDER BY type";
$service_types = $pdo->query($types_sql)->fetchAll(PDO::FETCH_ASSOC);

$services_by_type = [];
$services_sql = "SELECT * FROM service_categories WHERE is_active = 1 ORDER BY type, category_name";
foreach ($pdo->query($services_sql)->fetchAll(PDO::FETCH_ASSOC) as $service) {
    $services_by_type[$service['type']][] = $service;
}

/* ================= FETCH SUGGESTED SERVICES ================= */
$suggested_services = [];

$suggest_sql = "SELECT s.*, sc.category_name, sc.type, sc.base_price, sc.estimated_hours,
                       v.brand_name, v.model, v.number_plate, v.id as vehicle_id,
                       s.suggested_date - CURRENT_DATE as days_until_due
                FROM service_suggestions s
                JOIN service_categories sc ON s.service_category_id = sc.id
                JOIN vehicles v ON s.vehicle_id = v.id
                WHERE v.user_id = ?
                AND s.status = 'pending'
                ORDER BY v.id, s.suggested_date ASC";

$suggest_stmt = $pdo->prepare($suggest_sql);
$suggest_stmt->execute([$user_id]);
$suggested_services = $suggest_stmt->fetchAll(PDO::FETCH_ASSOC);

$time_slots = [];
$slots_sql = "SELECT slot_time FROM booking_timeslots WHERE is_active = 1 ORDER BY slot_time";
$time_slots = $pdo->query($slots_sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Service Booking | CS KUMARESAN MOTOR</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding-bottom: 75px;
        }
        
        /* ================= MOBILE NAVIGATION ================= */
        .mobile-nav {
            background: white;
            padding: 0.75rem 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .nav-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .logo-area {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .back-btn {
            background: none;
            border: none;
            font-size: 1.3rem;
            color: #1e293b;
            cursor: pointer;
            padding: 0.3rem;
            text-decoration: none;
            display: flex;
            align-items: center;
            border-radius: 30px;
            width: 38px;
            height: 38px;
            justify-content: center;
        }
        
        .back-btn:active {
            background: #f1f5f9;
        }
        
        .logo-icon {
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }
        
        .logo-text {
            font-weight: 700;
            font-size: 1rem;
            color: #1e293b;
            line-height: 1.2;
        }
        
        .logo-text span:first-child {
            font-size: 0.7rem;
            color: #64748b;
            display: block;
            font-weight: 400;
        }
        
        .menu-btn {
            background: #f1f5f9;
            border: none;
            font-size: 1.3rem;
            color: #1e293b;
            cursor: pointer;
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 30px;
            transition: all 0.2s ease;
        }
        
        .menu-btn:active {
            background: #e2e8f0;
            transform: scale(0.95);
        }
        
        /* ================= MENU DROPDOWN ================= */
        .menu-dropdown {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 2000;
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .menu-dropdown.show {
            visibility: visible;
            opacity: 1;
        }
        
        .menu-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
        }
        
        .menu-content {
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            width: 280px;
            background: white;
            box-shadow: -4px 0 20px rgba(0,0,0,0.1);
            transform: translateX(100%);
            transition: transform 0.3s ease;
            overflow-y: auto;
        }
        
        .menu-dropdown.show .menu-content {
            transform: translateX(0);
        }
        
        .menu-header {
            padding: 1.5rem;
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            color: white;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .menu-user-icon {
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.2);
            border-radius: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .menu-user-name {
            font-weight: 600;
            font-size: 1rem;
        }
        
        .menu-user-email {
            font-size: 0.7rem;
            opacity: 0.8;
        }
        
        .menu-divider {
            height: 1px;
            background: #e2e8f0;
            margin: 0.5rem 0;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.9rem 1.5rem;
            color: #1e293b;
            text-decoration: none;
            transition: background 0.2s;
        }
        
        .menu-item:active {
            background: #f1f5f9;
        }
        
        .menu-item i {
            width: 22px;
            font-size: 1.1rem;
        }
        
        .menu-item span {
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .text-danger {
            color: #dc2626 !important;
        }
        
        /* ================= STEPS PROGRESS ================= */
        .steps-container {
            display: flex;
            justify-content: space-between;
            margin: 1rem;
            position: relative;
        }
        
        .step-item {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 2;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            background: #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: 700;
            font-size: 1rem;
            color: #64748b;
            border: 2px solid #e2e8f0;
        }
        
        .step-item.active .step-circle {
            background: #1e40af;
            border-color: #1e40af;
            color: white;
        }
        
        .step-item.completed .step-circle {
            background: #10b981;
            border-color: #10b981;
            color: white;
        }
        
        .step-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #475569;
        }
        
        .progress-line {
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e2e8f0;
            z-index: 1;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #1e40af, #10b981);
            width: 0%;
            transition: width 0.5s ease;
        }
        
        /* ================= STEP CARDS ================= */
        .step-section {
            display: none;
        }
        
        .step-section.active {
            display: block;
        }
        
        .step-card {
            background: white;
            border-radius: 20px;
            margin: 1rem;
            padding: 1.5rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }
        
        .step-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .step-title i {
            color: #1e40af;
            font-size: 1.2rem;
        }
        
        .step-badge {
            background: #1e40af;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 600;
            margin-right: 0.5rem;
        }
        
        /* ================= VEHICLE CARDS ================= */
        .vehicles-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
        
        .vehicle-card {
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .vehicle-card.selected {
            border-color: #1e40af;
            background: #eff6ff;
        }
        
        .vehicle-icon {
            width: 50px;
            height: 50px;
            background: #f1f5f9;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #1e40af;
        }
        
        .vehicle-info {
            flex: 1;
        }
        
        .vehicle-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        
        .vehicle-details {
            display: flex;
            gap: 1rem;
            font-size: 0.75rem;
            color: #64748b;
        }
        
        .vehicle-plate {
            background: #1e293b;
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-top: 0.3rem;
            display: inline-block;
        }
        
        /* ================= SERVICE SEARCH ================= */
        .service-search {
            position: relative;
            margin-bottom: 1rem;
        }
        
        .service-search i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1.1rem;
            z-index: 10;
        }
        
        .service-search input {
            padding-left: 2.8rem;
            border: 2px solid #e2e8f0;
            border-radius: 40px;
            width: 100%;
            height: 48px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            background: white;
        }
        
        .service-search input:focus {
            border-color: #1e40af;
            box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1);
            outline: none;
        }
        
        /* ================= SERVICE TYPES ================= */
        .service-layout {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .service-types {
            display: flex;
            overflow-x: auto;
            gap: 0.5rem;
            padding: 0.25rem 0 1rem;
            -webkit-overflow-scrolling: touch;
        }
        
        .service-types::-webkit-scrollbar {
            display: none;
        }
        
        .type-chip {
            background: #f1f5f9;
            border: none;
            padding: 0.6rem 1rem;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 500;
            color: #475569;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .type-chip.active {
            background: #1e40af;
            color: white;
        }
        
        .type-chip .count {
            background: rgba(0,0,0,0.1);
            padding: 0.1rem 0.4rem;
            border-radius: 20px;
            font-size: 0.7rem;
        }
        
        .type-chip.active .count {
            background: rgba(255,255,255,0.2);
        }
        
        /* ================= SERVICE CARDS ================= */
        .services-grid {
            flex: 1;
        }
        
        .service-card {
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-bottom: 0.75rem;
        }
        
        .service-card.selected {
            border-color: #1e40af;
            background: #eff6ff;
        }
        
        .service-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.3rem;
        }
        
        .service-desc {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 0.5rem;
        }
        
        .service-price {
            background: #10b981;
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }
        
        /* ================= DATE & TIME ================= */
        .date-input input {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            background: #f8fafc;
        }
        
        .time-slots {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
        }
        
        .time-slot {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.75rem 0.25rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .time-slot.selected {
            border-color: #1e40af;
            background: #1e40af;
            color: white;
        }
        
        .time-slot.disabled {
            background: #f1f5f9;
            color: #94a3b8;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .remarks-input {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            margin-top: 1rem;
            resize: vertical;
        }
        
        /* ================= SUMMARY CARD ================= */
        .summary-card {
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            border-radius: 20px;
            padding: 1.5rem;
            color: white;
        }
        
        .summary-item {
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        .summary-item:last-child {
            border-bottom: none;
        }
        
        .summary-label {
            font-size: 0.75rem;
            opacity: 0.8;
            margin-bottom: 0.2rem;
        }
        
        .summary-value {
            font-weight: 600;
            font-size: 1rem;
        }
        
        /* ================= NAVIGATION BUTTONS ================= */
        .nav-buttons {
            display: flex;
            justify-content: space-between;
            margin: 1rem;
            gap: 0.75rem;
        }
        
        .btn-prev, .btn-next, .btn-confirm {
            flex: 1;
            padding: 1rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }
        
        .btn-prev {
            background: #e2e8f0;
            color: #475569;
        }
        
        .btn-next {
            background: #1e40af;
            color: white;
        }
        
        .btn-confirm {
            background: #10b981;
            color: white;
            flex: 2;
        }
        
        /* ================= ALERTS ================= */
        .alert {
            margin: 1rem;
            padding: 1rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            border: none;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .alert-info {
            background: #dbeafe;
            color: #1e40af;
        }
        
        /* ================= BOTTOM NAVIGATION ================= */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 0.5rem 0.75rem;
            box-shadow: 0 -4px 12px rgba(0,0,0,0.05);
            z-index: 1000;
            border-top: 1px solid #e2e8f0;
        }
        
        .nav-item {
            text-decoration: none;
            color: #94a3b8;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.2rem;
            font-size: 0.65rem;
            padding: 0.4rem 0.6rem;
            border-radius: 30px;
            transition: all 0.2s ease;
            flex: 1;
            max-width: 70px;
        }
        
        .nav-item i {
            font-size: 1.2rem;
        }
        
        .nav-item.active {
            color: #1e40af;
            background: #eff6ff;
        }
        
        .nav-item span {
            font-weight: 500;
        }
        
        /* ================= RESPONSIVE ================= */
        @media (min-width: 768px) {
            .vehicles-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .time-slots {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .service-layout {
                flex-direction: row;
            }
            
            .service-types {
                flex-direction: column;
                width: 250px;
                overflow-x: visible;
            }
            
            .type-chip {
                white-space: normal;
                justify-content: flex-start;
            }
            
            .services-grid {
                max-height: 500px;
                overflow-y: auto;
            }
        }
        
        @media (max-width: 480px) {
            .time-slots {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>

<!-- ================= MOBILE NAVIGATION ================= -->
<div class="mobile-nav">
    <div class="nav-container">
        <div class="logo-area">
            <a href="customer-dashboard.php" class="back-btn">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div class="logo-icon">
                <i class="bi bi-calendar-check"></i>
            </div>
            <div class="logo-text">
                <span id="pageTitle">Book Service</span>
                <span>CS KUMARESAN MOTOR</span>
            </div>
        </div>
        <div class="menu-area">
            <button class="menu-btn" id="menuBtn" type="button">
                <i class="bi bi-three-dots-vertical"></i>
            </button>
        </div>
    </div>
</div>

<!-- ================= MENU DROPDOWN ================= -->
<div class="menu-dropdown" id="menuDropdown">
    <div class="menu-overlay"></div>
    <div class="menu-content">
        <div class="menu-header">
            <div class="menu-user-icon">
                <i class="bi bi-person-circle"></i>
            </div>
            <div class="menu-user-info">
                <div class="menu-user-name"><?php echo htmlspecialchars($first_name); ?></div>
                <div class="menu-user-email"><?php echo htmlspecialchars($email); ?></div>
            </div>
        </div>
        <div class="menu-divider"></div>
        <a href="customer-dashboard.php" class="menu-item">
            <i class="bi bi-house-door"></i>
            <span>Dashboard</span>
        </a>
        <a href="profile.php" class="menu-item">
            <i class="bi bi-person"></i>
            <span>My Profile</span>
        </a>
        <a href="customer-vehicles.php" class="menu-item">
            <i class="bi bi-car-front"></i>
            <span>My Vehicles</span>
        </a>
        <a href="customer-service-booking.php" class="menu-item">
            <i class="bi bi-calendar-check"></i>
            <span>Book Service</span>
        </a>
        <a href="track-service.php" class="menu-item">
            <i class="bi bi-activity"></i>
            <span>Track Service</span>
        </a>
        <a href="service-history.php" class="menu-item">
            <i class="bi bi-clock-history"></i>
            <span>Service History</span>
        </a>
        <a href="spare-parts.php" class="menu-item">
            <i class="bi bi-gear-wide-connected"></i>
            <span>Components</span>
        </a>
        <div class="menu-divider"></div>
        <a href="logout.php" class="menu-item text-danger">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

<!-- ================= MAIN CONTENT ================= -->
<div class="content">

    <!-- Steps Progress -->
    <div class="steps-container">
        <div class="progress-line">
            <div class="progress-fill" id="progressFill"></div>
        </div>
        
        <div class="step-item active" id="step1-indicator">
            <div class="step-circle">1</div>
            <div class="step-label">Vehicle</div>
        </div>
        
        <div class="step-item" id="step2-indicator">
            <div class="step-circle">2</div>
            <div class="step-label">Service</div>
        </div>
        
        <div class="step-item" id="step3-indicator">
            <div class="step-circle">3</div>
            <div class="step-label">Date/Time</div>
        </div>
        
        <div class="step-item" id="step4-indicator">
            <div class="step-circle">4</div>
            <div class="step-label">Confirm</div>
        </div>
    </div>

    <!-- Display messages -->
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle-fill"></i>
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- Booking Form -->
    <form method="POST" action="" id="bookingForm">

        <!-- Step 1: Select Vehicle -->
        <div class="step-section active" id="step1">
            <div class="step-card">
                <div class="step-title">
                    <span class="step-badge">1</span>
                    Select Your Vehicle
                </div>
                
                <?php if (empty($vehicles)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        No vehicles found. 
                        <a href="customer-vehicles.php" class="alert-link">Add a vehicle</a> first.
                    </div>
                <?php else: ?>
                    <div class="vehicles-grid">
                        <?php foreach ($vehicles as $vehicle): ?>
                            <div class="vehicle-card" 
                                 data-vehicle-id="<?php echo $vehicle['id']; ?>"
                                 data-vehicle-name="<?php echo htmlspecialchars($vehicle['brand_name'] . ' ' . $vehicle['model']); ?>"
                                 data-vehicle-plate="<?php echo htmlspecialchars($vehicle['number_plate']); ?>">
                                
                                <div class="vehicle-icon">
                                    <i class="bi bi-car-front"></i>
                                </div>
                                <div class="vehicle-info">
                                    <div class="vehicle-name">
                                        <?php echo htmlspecialchars($vehicle['brand_name']); ?> <?php echo htmlspecialchars($vehicle['model']); ?>
                                    </div>
                                    <div class="vehicle-details">
                                        <span><i class="bi bi-calendar"></i> <?php echo $vehicle['year']; ?></span>
                                        <span><i class="bi bi-palette"></i> <?php echo htmlspecialchars($vehicle['color']); ?></span>
                                    </div>
                                    <span class="vehicle-plate"><?php echo htmlspecialchars($vehicle['number_plate']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="vehicle_id" id="vehicle_id" required>
                <?php endif; ?>
            </div>
            
            <div class="nav-buttons">
                <div></div>
                <?php if (!empty($vehicles)): ?>
                    <button type="button" class="btn-next" onclick="nextStep(1)">Continue <i class="bi bi-arrow-right"></i></button>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Step 2: Choose Service -->
        <div class="step-section" id="step2">
            <div class="step-card">
                <div class="step-title">
                    <span class="step-badge">2</span>
                    <i class="bi bi-tools"></i>
                    Choose Service
                </div>
                
                <div class="service-search">
                    <i class="bi bi-search"></i>
                    <input type="text" id="serviceSearch" placeholder="Search for any service...">
                </div>
                
                <div class="service-layout">
                    <div class="service-types">
                        <?php if (!empty($suggested_services)): ?>
                            <button type="button" class="type-chip suggested-tab active" data-type="suggested">
                                <i class="bi bi-star-fill text-warning"></i>
                                <span>Suggested for You</span>
                                <span class="count"><?php echo count($suggested_services); ?></span>
                            </button>
                        <?php endif; ?>
                        
                        <?php foreach ($service_types as $index => $type): ?>
                            <button type="button" class="type-chip <?php echo ($index === 0 && empty($suggested_services)) ? 'active' : ''; ?>" 
                                    data-type="<?php echo htmlspecialchars($type['type']); ?>">
                                <i class="bi bi-wrench"></i>
                                <span><?php echo htmlspecialchars($type['type']); ?></span>
                                <span class="count"><?php echo count($services_by_type[$type['type']] ?? []); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="services-grid" id="servicesGrid">
                        <!-- Suggested Services Section -->
<?php if (!empty($suggested_services)): ?>
    <div class="service-group" data-type="suggested" style="display: block;">
        <h6 class="mb-3 text-primary">
            <i class="bi bi-star-fill text-warning"></i> 
            Recommended Services for Your Selected Vehicle
        </h6>
        
        <?php 
        // Group suggestions by vehicle
        $suggestions_by_vehicle = [];
        foreach ($suggested_services as $suggestion) {
            $vehicle_key = $suggestion['vehicle_id'];
            if (!isset($suggestions_by_vehicle[$vehicle_key])) {
                $suggestions_by_vehicle[$vehicle_key] = [
                    'vehicle_id' => $suggestion['vehicle_id'],
                    'vehicle_name' => $suggestion['brand_name'] . ' ' . $suggestion['model'],
                    'vehicle_plate' => $suggestion['number_plate'],
                    'services' => []
                ];
            }
            $suggestions_by_vehicle[$vehicle_key]['services'][] = $suggestion;
        }
        ?>
        
        <?php foreach ($suggestions_by_vehicle as $vehicle_id => $vehicle_data): ?>
            <div class="vehicle-suggestions-group" data-vehicle-id="<?php echo $vehicle_id; ?>" 
                 style="display: <?php echo ($vehicle_id == $selected_vehicle_id) ? 'block' : 'none'; ?>">
                <div class="d-flex align-items-center gap-2 mb-2 p-2 bg-light rounded">
                    <i class="bi bi-car-front text-primary"></i>
                    <strong><?php echo htmlspecialchars($vehicle_data['vehicle_name']); ?></strong>
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($vehicle_data['vehicle_plate']); ?></span>
                </div>
                
                <?php foreach ($vehicle_data['services'] as $suggestion): 
                    $due_class = '';
                    $due_text = '';
                    if ($suggestion['days_until_due'] <= 0) {
                        $due_class = 'danger';
                        $due_text = 'Overdue';
                    } elseif ($suggestion['days_until_due'] <= 30) {
                        $due_class = 'warning';
                        $due_text = 'Due soon';
                    } else {
                        $due_class = 'info'; 
                        $due_text = 'Upcoming';
                    }
                ?>
                    <div class="service-card suggestion-card mb-2" 
                         data-service-id="<?php echo $suggestion['service_category_id']; ?>"
                         data-service-name="<?php echo htmlspecialchars($suggestion['category_name']); ?>"
                         data-service-price="<?php echo $suggestion['base_price']; ?>"
                         data-vehicle-id="<?php echo $suggestion['vehicle_id']; ?>"
                         data-vehicle-name="<?php echo htmlspecialchars($suggestion['brand_name'] . ' ' . $suggestion['model']); ?>"
                         data-vehicle-plate="<?php echo htmlspecialchars($suggestion['number_plate']); ?>">
                        
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="service-name">
                                    <?php echo htmlspecialchars($suggestion['category_name']); ?>
                                    <span class="badge bg-<?php echo $due_class; ?> ms-2"><?php echo $due_text; ?></span>
                                </div>
                                <div class="service-desc">
                                    <small class="text-<?php echo $due_class; ?>">
                                        <i class="bi bi-calendar"></i> 
                                        Due by: <?php echo date('M d, Y', strtotime($suggestion['suggested_date'])); ?>
                                    </small>
                                </div>
                                <?php if (!empty($suggestion['reason'])): ?>
                                    <div class="service-desc">
                                        <small class="text-muted">
                                            <i class="bi bi-info-circle"></i> 
                                            <?php echo htmlspecialchars($suggestion['reason']); ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Regular Service Categories -->
<?php foreach ($services_by_type as $type => $services): ?>
    <div class="service-group" data-type="<?php echo htmlspecialchars($type); ?>" 
         style="display: <?php echo (empty($suggested_services) && $type === ($service_types[0]['type'] ?? '')) ? 'block' : 'none'; ?>">
        <h6 class="mb-3 text-primary"><?php echo htmlspecialchars($type); ?></h6>
        <?php foreach ($services as $service): ?>
            <div class="service-card regular-card" 
                 data-service-id="<?php echo $service['id']; ?>"
                 data-service-name="<?php echo htmlspecialchars($service['category_name']); ?>"
                 data-service-price="<?php echo $service['base_price']; ?>">
                <div>
                    <div class="service-name"><?php echo htmlspecialchars($service['category_name']); ?></div>
                    <div class="service-desc"><?php echo htmlspecialchars($service['description']); ?></div>
                </div>
                <!-- REMOVED PRICE -->
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
                    </div>
                </div>
                
                <input type="hidden" name="service_category" id="service_category" required>
            </div>
            
            <div class="nav-buttons">
                <button type="button" class="btn-prev" onclick="prevStep(2)"><i class="bi bi-arrow-left"></i> Back</button>
                <button type="button" class="btn-next" onclick="nextStep(2)">Continue <i class="bi bi-arrow-right"></i></button>
            </div>
        </div>
        
        <!-- Step 3: Date & Time -->
        <div class="step-section" id="step3">
            <div class="step-card">
                <div class="step-title">
                    <span class="step-badge">3</span>
                    <i class="bi bi-calendar-check"></i>
                    Select Date & Time
                </div>
                
                <div class="date-input">
                    <label class="form-label fw-bold mb-2">Preferred Date</label>
                    <input type="text" id="booking_date" name="booking_date" placeholder="Select date" readonly>
                    <small class="text-muted mt-1 d-block">
                        <i class="bi bi-info-circle"></i> Closed on Sundays | Cannot book within 30 minutes of current time
                    </small>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold mb-2">Preferred Time</label>
                    <div class="time-slots" id="timeSlotsContainer">
                        <?php foreach ($time_slots as $slot): ?>
                            <div class="time-slot" data-time="<?php echo $slot['slot_time']; ?>"
                                 data-time-display="<?php echo date('h:i A', strtotime($slot['slot_time'])); ?>">
                                <?php echo date('h:i A', strtotime($slot['slot_time'])); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="booking_time" id="booking_time" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold mb-2">Remarks (Optional)</label>
                    <textarea class="remarks-input" id="remarks" name="remarks" rows="3" 
                              placeholder="Any specific issues or additional information?"></textarea>
                </div>
            </div>
            
            <div class="nav-buttons">
                <button type="button" class="btn-prev" onclick="prevStep(3)"><i class="bi bi-arrow-left"></i> Back</button>
                <button type="button" class="btn-next" onclick="nextStep(3)">Review <i class="bi bi-arrow-right"></i></button>
            </div>
        </div>
        
        <!-- Step 4: Confirmation -->
        <div class="step-section" id="step4">
            <div class="step-card">
                <div class="step-title">
                    <span class="step-badge">4</span>
                    <i class="bi bi-check-circle"></i>
                    Confirm Booking
                </div>
                
                <div class="summary-card">
                    <div class="summary-item">
                        <div class="summary-label">Vehicle</div>
                        <div class="summary-value" id="summaryVehicle">-</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Service</div>
                        <div class="summary-value" id="summaryService">-</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Date & Time</div>
                        <div class="summary-value" id="summaryDateTime">-</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Remarks</div>
                        <div class="summary-value" id="summaryRemarks">-</div>
                    </div>
                </div>
                
                <div class="alert alert-info mt-3">
                    <i class="bi bi-info-circle"></i>
                    Please review your booking details carefully.
                </div>
            </div>
            
            <div class="nav-buttons">
                <button type="button" class="btn-prev" onclick="prevStep(4)"><i class="bi bi-arrow-left"></i> Back</button>
                <button type="submit" name="book_service" class="btn-confirm">
                    <i class="bi bi-check-circle"></i> Confirm Booking
                </button>
            </div>
        </div>
    </form>

</div>

<!-- ================= BOTTOM NAVIGATION ================= -->
<div class="bottom-nav">
    <a href="customer-dashboard.php" class="nav-item">
        <i class="bi bi-house-door"></i>
        <span>Home</span>
    </a>
    <a href="customer-vehicles.php" class="nav-item">
        <i class="bi bi-car-front"></i>
        <span>Vehicles</span>
    </a>
    <a href="customer-service-booking.php" class="nav-item active">
        <i class="bi bi-calendar-check"></i>
        <span>Book</span>
    </a>
    <a href="track-service.php" class="nav-item">
        <i class="bi bi-activity"></i>
        <span>Track</span>
    </a>
    <a href="profile.php" class="nav-item">
        <i class="bi bi-person"></i>
        <span>Profile</span>
    </a>
</div>

<!-- ================= SCRIPTS ================= -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
    let currentStep = 1;
    let selectedData = {
        vehicle: { id: null, name: null, plate: null },
        service: { id: null, name: null, price: null },
        date: null,
        time: null,
        timeDisplay: null,
        remarks: null
    };
    
    document.addEventListener('DOMContentLoaded', function() {
    initializeDatePicker();
    initializeTimeSlots(); // Call this after time slots are rendered
    loadExistingSelections();
    addTouchFeedback();
    initializeMenu();

       // If a vehicle is already selected, filter suggestions
    if (selectedData.vehicle && selectedData.vehicle.id) {
        filterSuggestedServices(selectedData.vehicle.id);
    }
    
    // Service type switching
    document.querySelectorAll('.type-chip').forEach(btn => {
        btn.addEventListener('click', function() {
            const type = this.dataset.type;
            document.querySelectorAll('.type-chip').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            document.querySelectorAll('.service-group').forEach(group => {
                group.style.display = group.dataset.type === type ? 'block' : 'none';
            });
        });
    });
    
    // Service selection (including suggested services)
    document.querySelectorAll('.service-card').forEach(card => {
        card.addEventListener('click', function() {
            document.querySelectorAll('.service-card').forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');
            
            selectedData.service = {
                id: this.dataset.serviceId,
                name: this.dataset.serviceName,
                price: this.dataset.servicePrice
            };
            
            document.getElementById('service_category').value = this.dataset.serviceId;
            
            // If this is a suggested service, also select the vehicle
            const vehicleId = this.dataset.vehicleId;
            if (vehicleId) {
                const vehicleCard = document.querySelector(`.vehicle-card[data-vehicle-id="${vehicleId}"]`);
                if (vehicleCard) {
                    document.querySelectorAll('.vehicle-card').forEach(c => c.classList.remove('selected'));
                    vehicleCard.classList.add('selected');
                    
                    selectedData.vehicle = {
                        id: vehicleId,
                        name: this.dataset.vehicleName,
                        plate: this.dataset.vehiclePlate
                    };
                    
                    document.getElementById('vehicle_id').value = vehicleId;
                }
            }
            
            updateSummary();
        });
    });
});
    
    function initializeDatePicker() {
        flatpickr("#booking_date", {
            minDate: "today",
            maxDate: new Date().fp_incr(30),
            disable: [function(date) { return date.getDay() === 0; }],
            onChange: function(selectedDates, dateStr) {
                selectedData.date = dateStr;
                updateSummary();
                checkTimeSlotAvailability(dateStr);
            }
        });
    }

    // Function to filter suggested services based on selected vehicle
function filterSuggestedServices(vehicleId) {
    const suggestedServicesGroup = document.querySelector('.service-group[data-type="suggested"]');
    if (!suggestedServicesGroup) return;
    
    const suggestionCards = suggestedServicesGroup.querySelectorAll('.suggestion-card');
    let hasVisible = false;
    
    suggestionCards.forEach(card => {
        const cardVehicleId = card.getAttribute('data-vehicle-id');
        if (cardVehicleId == vehicleId) {
            card.style.display = 'block';
            hasVisible = true;
        } else {
            card.style.display = 'none';
        }
    });
    
    // Show/hide the entire suggested section based on whether there are visible suggestions
    const vehicleGroups = suggestedServicesGroup.querySelectorAll('.vehicle-suggestions-group');
    vehicleGroups.forEach(group => {
        const hasVisibleCards = group.querySelectorAll('.suggestion-card[style="display: block;"]').length > 0;
        group.style.display = hasVisibleCards ? 'block' : 'none';
    });
    
    // If no suggestions for this vehicle, hide the suggested tab
    const suggestedTab = document.querySelector('.type-chip.suggested-tab');
    if (suggestedTab) {
        if (!hasVisible) {
            suggestedTab.style.display = 'none';
            // If suggested tab was active and has no suggestions, switch to first available tab
            if (suggestedTab.classList.contains('active')) {
                const firstVisibleTab = document.querySelector('.type-chip:not(.suggested-tab)');
                if (firstVisibleTab) {
                    firstVisibleTab.click();
                }
            }
        } else {
            suggestedTab.style.display = 'flex';
        }
    }
}
    

    // Time slot selection - FIXED VERSION
function initializeTimeSlots() {
    const timeSlots = document.querySelectorAll('.time-slot');
    
    timeSlots.forEach(slot => {
        // Remove any existing listeners to avoid duplicates
        slot.removeEventListener('click', slot.clickHandler);
        
        // Create new click handler
        const clickHandler = function() {
            if (!this.classList.contains('disabled')) {
                // Remove selected class from all time slots
                document.querySelectorAll('.time-slot').forEach(s => s.classList.remove('selected'));
                
                // Add selected class to clicked slot
                this.classList.add('selected');
                
                // Store selected time data
                selectedData.time = this.getAttribute('data-time');
                selectedData.timeDisplay = this.getAttribute('data-time-display');
                
                // Update hidden input
                const timeInput = document.getElementById('booking_time');
                if (timeInput) timeInput.value = selectedData.time;
                
                // Update summary
                updateSummary();
                
                console.log('Time selected:', selectedData.time, selectedData.timeDisplay);
            }
        };
        
        // Store handler for potential removal
        slot.clickHandler = clickHandler;
        slot.addEventListener('click', clickHandler);
    });
}

// Check and disable past time slots
// Check and disable past time slots and fully booked slots
function checkTimeSlotAvailability(dateStr) {
    if (!dateStr) return;
    
    const timeSlots = document.querySelectorAll('.time-slot');
    const now = new Date();
    const today = new Date().toISOString().split('T')[0];
    const selectedDate = dateStr;
    
    // Reset all time slots first
    timeSlots.forEach(slot => {
        slot.classList.remove('disabled', 'selected', 'fully-booked');
        const originalDisplay = slot.getAttribute('data-time-display');
        if (originalDisplay) {
            slot.innerHTML = originalDisplay;
        }
        slot.style.opacity = '1';
        slot.style.cursor = 'pointer';
    });
    
    // Clear selected time if date changed
    if (selectedData.date !== dateStr) {
        selectedData.time = null;
        selectedData.timeDisplay = null;
        const timeInput = document.getElementById('booking_time');
        if (timeInput) timeInput.value = '';
        updateSummary();
    }
    
    // Check server for booked slots
    fetch('check-availability.php?date=' + encodeURIComponent(dateStr))
        .then(response => response.json())
        .then(data => {
            if (data.bookedSlots) {
                data.bookedSlots.forEach(slotInfo => {
                    const slot = document.querySelector(`.time-slot[data-time="${slotInfo.time}"]`);
                    if (slot) {
                        const maxBookings = slotInfo.max_bookings || 3;
                        const bookedCount = slotInfo.booked_count || 0;
                        const remaining = maxBookings - bookedCount;
                        
                        if (remaining <= 0) {
                            // Fully booked - add full icon
                            slot.classList.add('disabled', 'fully-booked');
                            slot.style.opacity = '0.5';
                            slot.style.cursor = 'not-allowed';
                            const originalDisplay = slot.getAttribute('data-time-display');
                            if (originalDisplay && !slot.innerHTML.includes('🔴')) {
                                slot.innerHTML = originalDisplay + ' <span style="font-size: 0.6rem;">🔴 Full</span>';
                            }
                        } else if (remaining <= 2) {
                            // Limited slots left - show warning
                            const originalDisplay = slot.getAttribute('data-time-display');
                            if (originalDisplay && !slot.innerHTML.includes('⚠️')) {
                                slot.innerHTML = originalDisplay + ` <span style="font-size: 0.6rem;">⚠️ ${remaining} left</span>`;
                            }
                        }
                    }
                });
            }
        })
        .catch(error => console.error('Error checking availability:', error));
    
    // Only disable past time slots for today (client-side)
    if (selectedDate === today) {
        const currentHour = now.getHours();
        const currentMinute = now.getMinutes();
        const currentTimeInMinutes = currentHour * 60 + currentMinute;
        const bufferMinutes = 30;
        
        timeSlots.forEach(slot => {
            // Skip if already marked as fully booked
            if (slot.classList.contains('fully-booked')) return;
            
            const timeValue = slot.getAttribute('data-time');
            if (timeValue) {
                const [hours, minutes] = timeValue.split(':');
                const slotTimeInMinutes = parseInt(hours) * 60 + parseInt(minutes);
                
                if (slotTimeInMinutes < currentTimeInMinutes + bufferMinutes) {
                    slot.classList.add('disabled');
                    slot.style.opacity = '0.5';
                    slot.style.cursor = 'not-allowed';
                    const originalDisplay = slot.getAttribute('data-time-display');
                    if (originalDisplay && !slot.innerHTML.includes('❌')) {
                        slot.innerHTML = originalDisplay + ' <span style="font-size: 0.6rem;">❌ Passed</span>';
                    }
                }
            }
        });
    }
}
    
    // Vehicle selection - UPDATED to filter suggestions
document.querySelectorAll('.vehicle-card').forEach(card => {
    card.addEventListener('click', function() {
        document.querySelectorAll('.vehicle-card').forEach(c => c.classList.remove('selected'));
        this.classList.add('selected');
        
        const vehicleId = this.dataset.vehicleId;
        const vehicleName = this.dataset.vehicleName;
        const vehiclePlate = this.dataset.vehiclePlate;
        
        selectedData.vehicle = {
            id: vehicleId,
            name: vehicleName,
            plate: vehiclePlate
        };
        
        document.getElementById('vehicle_id').value = vehicleId;
        
        // Filter suggested services based on selected vehicle
        filterSuggestedServices(vehicleId);
        
        updateSummary();
        
        // Optional: Auto-switch to suggested tab if there are suggestions for this vehicle
        const suggestedTab = document.querySelector('.type-chip.suggested-tab');
        if (suggestedTab && suggestedTab.style.display !== 'none') {
            const visibleSuggestions = document.querySelectorAll('.suggestion-card[style="display: block;"]').length;
            if (visibleSuggestions > 0) {
                suggestedTab.click();
            }
        }
    });
});
    
    // Remarks input
    document.getElementById('remarks').addEventListener('input', function() {
        selectedData.remarks = this.value;
        updateSummary();
    });
    
    function nextStep(step) {
        if (!validateStep(step)) return;
        
        document.getElementById(`step${step}`).classList.remove('active');
        document.getElementById(`step${step + 1}`).classList.add('active');
        
        document.getElementById(`step${step}-indicator`).classList.remove('active');
        document.getElementById(`step${step}-indicator`).classList.add('completed');
        document.getElementById(`step${step + 1}-indicator`).classList.add('active');
        
        currentStep = step + 1;
        updateProgressBar();
        
        if (currentStep === 4) updateSummary();
        
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    
    function prevStep(step) {
        document.getElementById(`step${step}`).classList.remove('active');
        document.getElementById(`step${step - 1}`).classList.add('active');
        
        document.getElementById(`step${step}-indicator`).classList.remove('active');
        document.getElementById(`step${step - 1}-indicator`).classList.remove('completed');
        document.getElementById(`step${step - 1}-indicator`).classList.add('active');
        
        currentStep = step - 1;
        updateProgressBar();
        
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    
    function validateStep(step) {
        switch(step) {
            case 1:
                if (!selectedData.vehicle.id) {
                    alert('Please select a vehicle');
                    return false;
                }
                return true;
            case 2:
                if (!selectedData.service.id) {
                    alert('Please select a service');
                    return false;
                }
                return true;
            case 3:
                if (!selectedData.date) {
                    alert('Please select a date');
                    return false;
                }
                if (!selectedData.time) {
                    alert('Please select a time');
                    return false;
                }
                const selectedSlot = document.querySelector(`.time-slot[data-time="${selectedData.time}"]`);
                if (selectedSlot && selectedSlot.classList.contains('disabled')) {
                    alert('This time slot is no longer available. Please select another time.');
                    return false;
                }
                return true;
            default:
                return true;
        }
    }
    
    function updateProgressBar() {
        const progress = ((currentStep - 1) / 3) * 100;
        document.getElementById('progressFill').style.width = progress + '%';
    }
    
    function updateSummary() {
        document.getElementById('summaryVehicle').innerHTML = selectedData.vehicle.name ? 
            `${selectedData.vehicle.name}<br><small class="opacity-75">${selectedData.vehicle.plate}</small>` : '-';
        
        document.getElementById('summaryService').innerHTML = selectedData.service.name ? 
            `${selectedData.service.name}` : '-';
        
        if (selectedData.date && selectedData.timeDisplay) {
            const date = new Date(selectedData.date);
            const formattedDate = date.toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' });
            document.getElementById('summaryDateTime').innerHTML = 
                `${formattedDate}<br><small class="opacity-75">${selectedData.timeDisplay}</small>`;
        } else {
            document.getElementById('summaryDateTime').textContent = '-';
        }
        
        document.getElementById('summaryRemarks').textContent = selectedData.remarks || '-';
    }
    
    function loadExistingSelections() {
    // Load selected vehicle
    const selectedVehicle = document.querySelector('.vehicle-card.selected');
    if (selectedVehicle) {
        selectedData.vehicle = {
            id: selectedVehicle.dataset.vehicleId,
            name: selectedVehicle.dataset.vehicleName,
            plate: selectedVehicle.dataset.vehiclePlate
        };
    }
    
    // Load selected service
    const selectedService = document.querySelector('.service-card.selected');
    if (selectedService) {
        selectedData.service = {
            id: selectedService.dataset.serviceId,
            name: selectedService.dataset.serviceName,
            price: selectedService.dataset.servicePrice
        };
    }
    
    // Load selected date
    const dateInput = document.getElementById('booking_date');
    if (dateInput && dateInput.value) {
        selectedData.date = dateInput.value;
    }
    
    // Load selected time
    const timeInput = document.getElementById('booking_time');
    if (timeInput && timeInput.value) {
        selectedData.time = timeInput.value;
        const timeSlot = document.querySelector(`.time-slot[data-time="${timeInput.value}"]`);
        if (timeSlot) {
            selectedData.timeDisplay = timeSlot.getAttribute('data-time-display');
            timeSlot.classList.add('selected');
        }
    }
    
    updateSummary();
}
    
    function addTouchFeedback() {
        const touchables = document.querySelectorAll('.vehicle-card, .service-card, .time-slot, .btn-prev, .btn-next, .btn-confirm, .type-chip');
        touchables.forEach(el => {
            el.addEventListener('touchstart', () => {
                el.style.opacity = '0.7';
            });
            el.addEventListener('touchend', () => {
                el.style.opacity = '1';
                setTimeout(() => { el.style.opacity = ''; }, 100);
            });
        });
    }
    
    // Search functionality
    document.getElementById('serviceSearch').addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        const serviceCards = document.querySelectorAll('.service-card');
        
        if (searchTerm.length < 2) {
            document.querySelectorAll('.service-group').forEach(group => {
                group.style.display = 'block';
            });
            document.querySelectorAll('.service-card').forEach(card => {
                card.style.display = 'block';
            });
            return;
        }
        
        serviceCards.forEach(card => {
            const serviceName = card.querySelector('.service-name')?.textContent.toLowerCase() || '';
            const serviceDesc = card.querySelector('.service-desc')?.textContent.toLowerCase() || '';
            
            if (serviceName.includes(searchTerm) || serviceDesc.includes(searchTerm)) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
        
        document.querySelectorAll('.service-group').forEach(group => {
            const visibleCards = group.querySelectorAll('.service-card[style="display: block;"]').length;
            group.style.display = visibleCards > 0 ? 'block' : 'none';
        });
    });
    
    // Menu functionality
    function initializeMenu() {
        const menuBtn = document.getElementById('menuBtn');
        const menuDropdown = document.getElementById('menuDropdown');
        const menuOverlay = document.querySelector('.menu-overlay');
        
        function openMenu() { if (menuDropdown) menuDropdown.classList.add('show'); }
        function closeMenu() { if (menuDropdown) menuDropdown.classList.remove('show'); }
        function toggleMenu() { menuDropdown.classList.contains('show') ? closeMenu() : openMenu(); }
        
        if (menuBtn) {
            menuBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleMenu();
            });
        }
        
        if (menuOverlay) menuOverlay.addEventListener('click', closeMenu);
        
        document.addEventListener('click', function(event) {
            if (menuDropdown && menuDropdown.classList.contains('show')) {
                if (!menuDropdown.querySelector('.menu-content').contains(event.target) && 
                    !menuBtn.contains(event.target)) {
                    closeMenu();
                }
            }
        });
        
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', closeMenu);
        });
    }
    
    document.getElementById('bookingForm').addEventListener('submit', function(e) {
        if (!selectedData.vehicle.id || !selectedData.service.id || !selectedData.date || !selectedData.time) {
            e.preventDefault();
            alert('Please complete all steps');
        }
    });
</script>

</body>
</html>