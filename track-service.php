<?php
session_start();

// ================= AJAX CHECK - MUST BE FIRST =================
if (isset($_GET['ajax_check'])) {
    header('Content-Type: application/json');
    
    // Check authentication
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    
    require_once __DIR__ . '/includes/config.php';

    $user_id = $_SESSION['user_id'];
    $last_check = isset($_GET['last_check']) ? intval($_GET['last_check']) : 0;

    // Check for booking status changes (when status was updated)
    $status_change_sql = "SELECT b.id as booking_id, b.status as new_status,
                                 b.updated_at, 'status_change' as change_type
                          FROM bookings b
                          WHERE b.user_id = ?
                          AND b.status IN ('pending', 'confirmed', 'repairing', 'completed', 'cancelled')
                          AND UNIX_TIMESTAMP( b.updated_at) > ?";

    $status_stmt = $pdo->prepare($status_change_sql);
    $status_stmt->execute([$user_id, $last_check]);
    $status_changes = $status_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get latest service updates
    $updates_sql = "SELECT u.*, b.id as booking_id, b.status as booking_status,
                    v.brand_name, v.model, v.number_plate,
                    sc.category_name as service_name, 'update' as change_type
                    FROM service_updates u
                    JOIN bookings b ON u.booking_id = b.id
                    JOIN vehicles v ON b.vehicle_id = v.id
                    JOIN service_categories sc ON b.service_category_id = sc.id
                    WHERE b.user_id = ?
                    AND b.status IN ('pending', 'confirmed', 'repairing')
                    AND u.is_visible_to_customer = 1
                    AND UNIX_TIMESTAMP( u.created_at) > ?
                    ORDER BY u.created_at DESC";

    $updates_stmt = $pdo->prepare($updates_sql);
    $updates_stmt->execute([$user_id, $last_check]);
    $new_updates = $updates_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Merge both types of changes
    $all_changes = array_merge($status_changes, $new_updates);
    $has_changes = count($all_changes) > 0;

    // Get current counts (always get fresh counts)
    $stats_sql = "SELECT
        SUM(CASE WHEN status IN ('pending', 'confirmed', 'repairing') THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count
        FROM bookings WHERE user_id = ?";
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute([$user_id]);
    $stats_data = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'has_updates' => $has_changes,
        'has_status_change' => count($status_changes) > 0,
        'updates' => $all_changes,
        'stats' => [
            'active' => intval($stats_data['active_count'] ?? 0),
            'completed' => intval($stats_data['completed_count'] ?? 0),
            'cancelled' => intval($stats_data['cancelled_count'] ?? 0)
        ],
        'timestamp' => time()
    ]);
    exit();
}


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

require_once __DIR__ . '/includes/config.php';

// Get current tab from URL parameter (default to 'active')
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'active';
$valid_tabs = ['active', 'completed', 'cancelled'];
if (!in_array($current_tab, $valid_tabs)) {
    $current_tab = 'active';
}

// Fetch customer's bookings based on tab
$bookings = [];
$status_filter = '';

switch ($current_tab) {
    case 'active':
        $status_filter = "b.status IN ('pending', 'confirmed', 'repairing')";
        break;
    case 'completed':
        $status_filter = "b.status = 'completed'";
        break;
    case 'cancelled':
        $status_filter = "b.status = 'cancelled'";
        break;
}

$sql = "SELECT 
            b.*,
            v.brand_name,
            v.model,
            v.year,
            v.color,
            v.number_plate,
            sc.category_name as service_name,
            (SELECT COUNT(*) FROM service_updates WHERE booking_id = b.id AND is_visible_to_customer = 1) as update_count
        FROM bookings b
        JOIN vehicles v ON b.vehicle_id = v.id
        JOIN service_categories sc ON b.service_category_id = sc.id
        WHERE b.user_id = ? AND $status_filter
        ORDER BY 
            CASE b.status 
                WHEN 'repairing' THEN 1
                WHEN 'confirmed' THEN 2
                WHEN 'pending' THEN 3
                WHEN 'completed' THEN 4
                WHEN 'cancelled' THEN 5
            END,
            b.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics for all tabs
$stats = [
    'active' => 0,
    'completed' => 0,
    'cancelled' => 0
];

$stats_sql = "SELECT
    SUM(CASE WHEN status IN ('pending', 'confirmed', 'repairing') THEN 1 ELSE 0 END) as active_count,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count
FROM bookings WHERE user_id = ?";
$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([$user_id]);
$stats_data = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$stats['active'] = $stats_data['active_count'] ?? 0;
$stats['completed'] = $stats_data['completed_count'] ?? 0;
$stats['cancelled'] = $stats_data['cancelled_count'] ?? 0;

// Fetch updates for each booking
$updates_by_booking = [];
foreach ($bookings as $booking) {
    $update_sql = "SELECT u.*, t.name as technician_name
                   FROM service_updates u
                   LEFT JOIN technicians t ON u.technician_id = t.id
                   WHERE u.booking_id = ? AND u.is_visible_to_customer = 1
                   ORDER BY u.created_at DESC";
    $update_stmt = $pdo->prepare($update_sql);
    $update_stmt->execute([$booking['id']]);
    $updates_by_booking[$booking['id']] = $update_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Track Service | CS KUMARESAN MOTOR</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
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
        
        /* Auto-refresh notification */
        .refresh-indicator {
            position: fixed;
            bottom: 80px;
            right: 20px;
            background: #1e40af;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }
        
        .refresh-indicator.show {
            opacity: 1;
        }
        
        .refresh-indicator i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* ================= UPDATE COLOR LEGEND ================= */
        .legend-container {
            background: white;
            margin: 0.5rem 1rem;
            padding: 0.6rem 1rem;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            justify-content: center;
        }
        
        .legend-title {
            font-size: 0.7rem;
            font-weight: 600;
            color: #475569;
            margin-right: 0.25rem;
        }
        
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.7rem;
            color: #64748b;
        }
        
        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .legend-dot.info { background: #3b82f6; }
        .legend-dot.waiting { background: #f59e0b; }
        .legend-dot.issue { background: #ef4444; }
        .legend-dot.complete { background: #10b981; }
        
        /* All your existing CSS styles continue here */
        
        /* Mobile Navigation */
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
        
        .tab-container {
            display: flex;
            gap: 0.75rem;
            margin: 1rem;
        }
        
        .tab-box {
            flex: 1;
            background: white;
            border-radius: 14px;
            padding: 0.75rem 0.3rem;
            text-align: center;
            text-decoration: none;
            color: #1e293b;
            border: 2px solid #e2e8f0;
            transition: all 0.2s ease;
        }
        
        .tab-box.active {
            border-color: #1e40af;
            background: #eff6ff;
        }
        
        .tab-icon {
            font-size: 1.3rem;
            margin-bottom: 0.2rem;
        }
        
        .tab-label {
            font-weight: 600;
            font-size: 0.75rem;
            margin-bottom: 0.15rem;
        }
        
        .tab-count {
            font-size: 1rem;
            font-weight: 700;
            color: #1e40af;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 1rem 1rem 0.75rem;
        }
        
        .section-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        
        .bookings-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
            margin: 0 1rem 1.5rem;
        }
        
        .booking-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .card-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f8fafc;
        }
        
        .booking-id {
            font-weight: 700;
            color: #1e40af;
            font-size: 0.85rem;
        }
        
        .status-badge {
            padding: 0.25rem 0.7rem;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-confirmed {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-repairing {
            background: #fed7aa;
            color: #9a3412;
        }
        
        .card-body {
            padding: 0.75rem 1rem;
        }
        
        .vehicle-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .vehicle-details h4 {
            font-weight: 700;
            margin: 0 0 0.2rem 0;
            font-size: 0.9rem;
            color: #1e293b;
        }
        
        .vehicle-plate {
            background: #1e293b;
            color: white;
            padding: 0.15rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        
        .info-item {
            background: #f8fafc;
            padding: 0.5rem;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }
        
        .info-label {
            font-size: 0.6rem;
            color: #64748b;
            margin-bottom: 0.15rem;
            display: flex;
            align-items: center;
            gap: 0.2rem;
        }
        
        .info-value {
            font-weight: 600;
            color: #1e293b;
            font-size: 0.8rem;
        }
        
        .updates-section {
            margin-top: 0.5rem;
            border-top: 1px solid #e2e8f0;
            padding-top: 0.5rem;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 0.5rem;
            padding-left: 1.2rem;
        }
        
        .timeline-dot {
            position: absolute;
            left: 0;
            top: 0.2rem;
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        
        .timeline-dot.info { background: #3b82f6; }
        .timeline-dot.waiting { background: #f59e0b; }
        .timeline-dot.issue { background: #ef4444; }
        .timeline-dot.complete { background: #10b981; }
        
        .timeline-content {
            background: #f8fafc;
            padding: 0.5rem;
            border-radius: 10px;
        }
        
        .timeline-message {
            font-weight: 500;
            margin-bottom: 0.2rem;
            font-size: 0.75rem;
            color: #1e293b;
        }
        
        .timeline-meta {
            display: flex;
            gap: 0.5rem;
            font-size: 0.6rem;
            color: #64748b;
            flex-wrap: wrap;
        }
        
        .btn-view {
            width: 100%;
            padding: 0.5rem;
            background: #1e40af;
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
            transition: all 0.2s ease;
            margin-top: 0.5rem;
        }
        
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
        
        .empty-state {
            text-align: center;
            padding: 2rem;
            background: white;
            border-radius: 16px;
            margin: 1rem;
        }
        
        .empty-state i {
            font-size: 2.5rem;
            color: #cbd5e1;
            margin-bottom: 0.5rem;
        }
        
        @media (min-width: 768px) {
            .bookings-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .tab-container {
                max-width: 500px;
                margin-left: auto;
                margin-right: auto;
            }
            .legend-container {
                max-width: 500px;
                margin-left: auto;
                margin-right: auto;
            }
        }
        
        @media (max-width: 480px) {
            .legend-container {
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>

<!-- ================= AUTO-REFRESH INDICATOR ================= -->
<div class="refresh-indicator" id="refreshIndicator">
    <i class="bi bi-arrow-repeat"></i>
    <span>Checking for updates...</span>
</div>

<!-- ================= MOBILE NAVIGATION ================= -->
<div class="mobile-nav">
    <div class="nav-container">
        <div class="logo-area">
            <a href="customer-dashboard.php" class="back-btn">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div class="logo-icon">
                <i class="bi bi-activity"></i>
            </div>
            <div class="logo-text">
                <span id="pageTitle">Track Service</span>
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

    <!-- Tab Election Boxes -->
    <div class="tab-container" id="tabContainer">
        <a href="?tab=active" class="tab-box <?php echo $current_tab == 'active' ? 'active' : ''; ?>" data-tab="active">
            <div class="tab-icon"><i class="bi bi-clock-history"></i></div>
            <div class="tab-label">Active</div>
            <div class="tab-count" id="activeCount"><?php echo $stats['active']; ?></div>
        </a>
        
        <a href="?tab=completed" class="tab-box <?php echo $current_tab == 'completed' ? 'active' : ''; ?>" data-tab="completed">
            <div class="tab-icon"><i class="bi bi-check-circle"></i></div>
            <div class="tab-label">Completed</div>
            <div class="tab-count" id="completedCount"><?php echo $stats['completed']; ?></div>
        </a>
        
        <a href="?tab=cancelled" class="tab-box <?php echo $current_tab == 'cancelled' ? 'active' : ''; ?>" data-tab="cancelled">
            <div class="tab-icon"><i class="bi bi-x-circle"></i></div>
            <div class="tab-label">Cancelled</div>
            <div class="tab-count" id="cancelledCount"><?php echo $stats['cancelled']; ?></div>
        </a>
    </div>

    <!-- Color Legend for Update Types -->
    <div class="legend-container">
        <span class="legend-title">Update Types:</span>
        <div class="legend-item">
            <span class="legend-dot info"></span>
            <span>Information</span>
        </div>
        <div class="legend-item">
            <span class="legend-dot waiting"></span>
            <span>Waiting</span>
        </div>
        <div class="legend-item">
            <span class="legend-dot issue"></span>
            <span>Issue</span>
        </div>
    </div>

    <!-- Section Title -->
    <div class="section-header">
        <h3>
            <?php 
            if ($current_tab == 'active') echo '<i class="bi bi-clock-history"></i> Active Bookings';
            elseif ($current_tab == 'completed') echo '<i class="bi bi-check-circle"></i> Completed Services';
            else echo '<i class="bi bi-x-circle"></i> Cancelled Bookings';
            ?>
        </h3>
        <span class="badge bg-secondary" style="font-size: 0.7rem;" id="bookingCount"><?php echo count($bookings); ?></span>
    </div>

    <!-- Bookings Container -->
    <div id="bookingsContainer">
        <?php if (empty($bookings)): ?>
            <div class="empty-state" id="emptyState">
                <i class="bi 
                    <?php 
                    if ($current_tab == 'active') echo 'bi-clock-history';
                    elseif ($current_tab == 'completed') echo 'bi-check-circle';
                    else echo 'bi-x-circle';
                    ?>
                "></i>
                <h4>No <?php echo ucfirst($current_tab); ?> Bookings</h4>
                <p>
                    <?php 
                    if ($current_tab == 'active') {
                        echo "You don't have any active bookings.";
                    } elseif ($current_tab == 'completed') {
                        echo "No completed services found.";
                    } else {
                        echo "No cancelled bookings found.";
                    }
                    ?>
                </p>
                <?php if ($current_tab == 'active'): ?>
                    <a href="customer-service-booking.php" class="btn btn-primary btn-sm">Book a Service</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="bookings-grid" id="bookingsGrid">
                <?php foreach ($bookings as $booking): ?>
                    <div class="booking-card" data-booking-id="<?php echo $booking['id']; ?>">
                        <div class="card-header">
                            <div>
                                <span class="booking-id">#<?php echo str_pad($booking['id'], 6, '0', STR_PAD_LEFT); ?></span>
                                <?php if ($booking['original_date']): ?>
                                    <span class="reschedule-badge" style="background: #fef3c7; color: #92400e; padding: 0.2rem 0.5rem; border-radius: 20px; font-size: 0.6rem; margin-left: 0.3rem;">
                                        <i class="bi bi-arrow-repeat"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge status-<?php echo $booking['status']; ?>">
                                <?php 
                                if ($booking['status'] == 'repairing') echo 'In Progress';
                                elseif ($booking['status'] == 'confirmed') echo 'Confirmed';
                                elseif ($booking['status'] == 'pending') echo 'Pending';
                                else echo ucfirst($booking['status']);
                                ?>
                            </span>
                        </div>
                        
                        <div class="card-body">
                            <div class="vehicle-info">
                                <div class="vehicle-details">
                                    <h4><?php echo htmlspecialchars($booking['brand_name'] . ' ' . $booking['model']); ?></h4>
                                    <span class="vehicle-plate"><?php echo htmlspecialchars($booking['number_plate']); ?></span>
                                </div>
                                <div class="text-end">
                                    <div class="info-value" style="font-size: 0.7rem; color: #64748b;">
                                        <?php echo $booking['year']; ?> • <?php echo htmlspecialchars($booking['color']); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label"><i class="bi bi-tools"></i> Service</div>
                                    <div class="info-value"><?php echo htmlspecialchars($booking['service_name']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label"><i class="bi bi-calendar"></i> Date</div>
                                    <div class="info-value"><?php echo date('M d, Y', strtotime($booking['booking_date'])); ?></div>
                                    <div class="info-value" style="font-size: 0.7rem;"><?php echo date('h:i A', strtotime($booking['booking_time'])); ?></div>
                                </div>
                            </div>
                            
                            <?php if ($current_tab == 'active' && !empty($updates_by_booking[$booking['id']])): 
                                $latest = $updates_by_booking[$booking['id']][0];
                            ?>
                                <div class="updates-section" id="updates-<?php echo $booking['id']; ?>">
                                    <div class="updates-title">
                                        <i class="bi bi-chat-dots"></i> Latest Update
                                    </div>
                                    <div class="timeline-item" style="margin-bottom: 0;">
                                        <div class="timeline-dot <?php echo $latest['update_type']; ?>"></div>
                                        <div class="timeline-content">
                                            <div class="timeline-message"><?php echo htmlspecialchars(substr($latest['message'], 0, 60)); ?></div>
                                            <div class="timeline-meta">
                                                <span><i class="bi bi-clock"></i> <?php echo date('M d, h:i A', strtotime($latest['created_at'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <button class="btn-view" onclick="showBookingDetails(<?php echo $booking['id']; ?>)">
                                <i class="bi bi-eye"></i> View Details
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Auto-refresh indicator -->
<div class="refresh-indicator" id="refreshIndicator">
    <i class="bi bi-arrow-repeat"></i> Refreshing in 30s
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
    <a href="customer-service-booking.php" class="nav-item">
        <i class="bi bi-calendar-check"></i>
        <span>Book</span>
    </a>
    <a href="track-service.php" class="nav-item active">
        <i class="bi bi-activity"></i>
        <span>Track</span>
    </a>
    <a href="profile.php" class="nav-item">
        <i class="bi bi-person"></i>
        <span>Profile</span>
    </a>
</div>

<!-- Booking Details Modals -->
<!-- Booking Details Modals - Static Content -->
<?php foreach ($bookings as $booking): 
    $updates = $updates_by_booking[$booking['id']] ?? [];
?>
    <div class="modal fade" id="bookingModal<?php echo $booking['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-info-circle"></i>
                        Booking #<?php echo str_pad($booking['id'], 6, '0', STR_PAD_LEFT); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Status Badge -->
                    <div class="text-center mb-3">
                        <span class="status-badge status-<?php echo $booking['status']; ?> p-2" style="font-size: 0.85rem;">
                            <?php 
                            if ($booking['status'] == 'repairing') echo '⚡ In Progress';
                            elseif ($booking['status'] == 'confirmed') echo '✓ Confirmed';
                            elseif ($booking['status'] == 'pending') echo '⏳ Pending';
                            elseif ($booking['status'] == 'completed') echo '✅ Completed';
                            else echo '✗ Cancelled';
                            ?>
                        </span>
                    </div>
                    
                    <!-- Vehicle & Service Details -->
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Vehicle</div>
                            <div class="info-value"><?php echo htmlspecialchars($booking['brand_name'] . ' ' . $booking['model']); ?></div>
                            <div class="info-value small"><?php echo $booking['year']; ?> • <?php echo htmlspecialchars($booking['color']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Number Plate</div>
                            <div class="info-value"><?php echo htmlspecialchars($booking['number_plate']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Service Type</div>
                            <div class="info-value"><?php echo htmlspecialchars($booking['service_name']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Scheduled Date</div>
                            <div class="info-value"><?php echo date('M d, Y', strtotime($booking['booking_date'])); ?></div>
                            <div class="info-value small"><?php echo date('h:i A', strtotime($booking['booking_time'])); ?></div>
                        </div>
                    </div>
                    
                    <!-- Customer Remarks -->
                    <?php if (!empty($booking['remarks'])): ?>
                        <div class="mt-3 p-2 bg-light rounded">
                            <div class="fw-bold mb-1 small">Your Remarks:</div>
                            <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($booking['remarks'])); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Service Timeline -->
                    <?php if (!empty($updates)): ?>
                        <h6 class="fw-bold mt-3 mb-2 small">Service Timeline</h6>
                        <div class="timeline">
                            <?php foreach ($updates as $update): ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot <?php echo $update['update_type']; ?>"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-message"><?php echo htmlspecialchars($update['message']); ?></div>
                                        <div class="timeline-meta">
                                            <span><i class="bi bi-person"></i> <?php echo htmlspecialchars($update['technician_name'] ?? 'Workshop'); ?></span>
                                            <span><i class="bi bi-clock"></i> <?php echo date('M d, h:i A', strtotime($update['created_at'])); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted small mt-3">
                            <i class="bi bi-chat-dots"></i> No updates yet.
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // ================= AUTO-REFRESH FUNCTIONALITY =================
    let lastCheckTime = Math.floor(Date.now() / 1000);
    let refreshInterval;
    let isRefreshing = false;
    let pendingReload = false;
    const currentTab = '<?php echo $current_tab; ?>';
    
    // Function to check for updates via AJAX
    function checkForUpdates() {
        if (isRefreshing) return;
        
        isRefreshing = true;
        const refreshIndicator = document.getElementById('refreshIndicator');
        if (refreshIndicator) refreshIndicator.classList.add('show');
        
        // Fixed URL - use proper query parameter format
        const ajaxUrl = window.location.pathname + '?tab=' + currentTab + '&ajax_check=1&last_check=' + lastCheckTime;
        
        fetch(ajaxUrl)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (refreshIndicator) refreshIndicator.classList.remove('show');
                isRefreshing = false;
                
                if (data.has_updates && data.updates && data.updates.length > 0) {
                    // Update last check time
                    lastCheckTime = data.timestamp;
                    
                    // Update statistics counts
                    if (data.stats) {
                        const activeCount = document.getElementById('activeCount');
                        const completedCount = document.getElementById('completedCount');
                        const cancelledCount = document.getElementById('cancelledCount');
                        if (activeCount) activeCount.textContent = data.stats.active;
                        if (completedCount) completedCount.textContent = data.stats.completed;
                        if (cancelledCount) cancelledCount.textContent = data.stats.cancelled;
                    }
                    
                    // Show notification for new updates
                    /*if (data.updates.length > 1) {
                        showToast('Getting new updates! Refreshing...');
                    } else if (data.updates.length > 1) {
                        showToast(`Getting new updates! Refreshing...`);
                    }*/
                    
                    // Reload page to show new updates
                    if (!pendingReload) {
                        pendingReload = true;
                        setTimeout(() => window.location.reload(), 2000);
                    }
                }
            })
            .catch(error => {
                console.error('Error checking updates:', error);
                if (refreshIndicator) refreshIndicator.classList.remove('show');
                isRefreshing = false;
            });
    }
    
    // Function to show toast notification
    function showToast(message) {
        let toast = document.getElementById('updateToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'updateToast';
            toast.className = 'position-fixed bottom-50 start-50 translate-middle-x bg-success text-white px-4 py-2 rounded-3 shadow-lg';
            toast.style.zIndex = '9999';
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s ease';
            toast.style.fontSize = '0.85rem';
            document.body.appendChild(toast);
        }
        
        toast.innerHTML = `<i class="bi bi-bell-fill me-2"></i>${message}`;
        toast.style.opacity = '1';
        
        setTimeout(() => {
            toast.style.opacity = '0';
        }, 3000);
    }
    
    // Start auto-refresh (every 15 seconds)
    function startAutoRefresh() {
        if (refreshInterval) clearInterval(refreshInterval);
        refreshInterval = setInterval(checkForUpdates, 15000); // Check every 15 seconds
    }
    
    // Stop auto-refresh
    function stopAutoRefresh() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }
    }
    
    // ================= EXISTING FUNCTIONS =================
    function showBookingDetails(bookingId) {
        const modal = new bootstrap.Modal(document.getElementById('bookingModal' + bookingId));
        modal.show();
    }
    
    // Menu functionality
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
    
    // Touch feedback
    const cards = document.querySelectorAll('.booking-card, .btn-view, .tab-box');
    cards.forEach(card => {
        card.addEventListener('touchstart', () => {
            card.style.opacity = '0.7';
        });
        card.addEventListener('touchend', () => {
            card.style.opacity = '1';
            setTimeout(() => { card.style.opacity = ''; }, 100);
        });
        card.addEventListener('touchcancel', () => {
            card.style.opacity = '1';
        });
    });
    
    // Start auto-refresh on page load
    startAutoRefresh();
    
    // Stop auto-refresh when page is hidden to save resources
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopAutoRefresh();
        } else {
            startAutoRefresh();
            // Immediately check for updates when page becomes visible
            checkForUpdates();
        }
    });
    
    // Clean up on page unload
    window.addEventListener('beforeunload', function() {
        stopAutoRefresh();
    });
</script>
</body>
</html>
