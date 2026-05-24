<?php

/* ================= AUTH CHECK ================= */
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

/* ================= ROLE CHECK ================= */
if ($_SESSION['role'] !== 'admin') {
    if ($_SESSION['role'] === 'customer') {
        header("Location: customer-dashboard.php");
    } elseif ($_SESSION['role'] === 'staff') {
        header("Location: staff-dashboard.php");
    }
    exit();
}

/* ================= USER DATA ================= */
$full_name = $_SESSION['full_name'];
$email = $_SESSION['email'];
$first_name = explode(' ', $full_name)[0];

/* ================= DATABASE CONNECTION ================= */
require_once __DIR__ . '/includes/config.php';

// Add admin_viewed column if not exists
$pdo->query("ALTER TABLE bookings ADD COLUMN IF NOT EXISTS admin_viewed SMALLINT DEFAULT 0");

// ================= MARK BOOKING AS VIEWED =================
if (isset($_POST['mark_booking_viewed'])) {
    $booking_id = intval($_POST['booking_id']);
    $update_sql = "UPDATE bookings SET admin_viewed = 1 WHERE id = ?";
    $update_stmt = $pdo->prepare($update_sql);
    $update_stmt->execute([$booking_id]);

    // Get updated unviewed count
    $count_sql = "SELECT COUNT(*) as unviewed FROM bookings WHERE status = 'pending' AND admin_viewed = 0";
    $count_result = $pdo->query($count_sql);
    $unviewed = $count_result->fetch(PDO::FETCH_ASSOC)['unviewed'];
    
    echo json_encode(['success' => true, 'unviewed' => $unviewed]);
    exit();
}

// ================= AJAX ENDPOINT FOR AUTO REFRESH =================
if (isset($_GET['check_new_bookings'])) {
    header('Content-Type: application/json');
    
    $last_check = isset($_GET['last_check']) ? intval($_GET['last_check']) : 0;
    
    // Check for new pending bookings
    $new_sql = "SELECT COUNT(*) as new_count FROM bookings
                WHERE status = 'pending' AND UNIX_TIMESTAMP(created_at) > ? AND admin_viewed = 0";
    $new_stmt = $pdo->prepare($new_sql);
    $new_stmt->execute([$last_check]);
    $new_count = $new_stmt->fetch(PDO::FETCH_ASSOC)['new_count'];

    // Get updated statistics (only pending, confirmed, repairing)
    $stats_sql = "SELECT
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
        SUM(CASE WHEN status = 'repairing' THEN 1 ELSE 0 END) as repairing_count,
        SUM(CASE WHEN status = 'pending' AND admin_viewed = 0 THEN 1 ELSE 0 END) as unviewed_count
    FROM bookings";
    $stats_result = $pdo->query($stats_sql);
    $stats_data = $stats_result->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'has_new' => $new_count > 0,
        'new_count' => $new_count,
        'unviewed_count' => $stats_data['unviewed_count'] ?? 0,
        'stats' => $stats_data,
        'timestamp' => time()
    ]);
    exit();
}

/* ================= HANDLE ACTIONS ================= */
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $booking_id = intval($_POST['booking_id']);
        $redirect_url = "admin-bookings.php?filter=" . ($_GET['filter'] ?? 'pending') . "&order=" . ($_GET['order'] ?? 'desc');
        
        switch ($_POST['action']) {
            case 'confirm':
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed', admin_viewed = 1 WHERE id = ?");
                if ($stmt->execute([$booking_id])) {
                    $_SESSION['message'] = "Booking #" . str_pad($booking_id, 6, '0', STR_PAD_LEFT) . " confirmed successfully!";
                    $_SESSION['message_type'] = 'success';
                }
                break;

            case 'cancel':
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled', admin_viewed = 1 WHERE id = ?");
                if ($stmt->execute([$booking_id])) {
                    $_SESSION['message'] = "Booking #" . str_pad($booking_id, 6, '0', STR_PAD_LEFT) . " cancelled successfully!";
                    $_SESSION['message_type'] = 'success';
                }
                break;

            case 'start':
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'repairing', admin_viewed = 1 WHERE id = ?");
                if ($stmt->execute([$booking_id])) {
                    $_SESSION['message'] = "Booking #" . str_pad($booking_id, 6, '0', STR_PAD_LEFT) . " marked as In Progress!";
                    $_SESSION['message_type'] = 'success';
                }
                break;

            case 'complete':
                $final_price = floatval($_POST['final_price']);
                $admin_notes = trim($_POST['admin_notes']);
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'completed', final_price = ?, admin_notes = ?, completed_date = CURRENT_DATE, admin_viewed = 1 WHERE id = ?");
                if ($stmt->execute([$final_price, $admin_notes, $booking_id])) {
                    $_SESSION['message'] = "Booking #" . str_pad($booking_id, 6, '0', STR_PAD_LEFT) . " completed successfully!";
                    $_SESSION['message_type'] = 'success';
                }
                break;

            case 'reschedule':
    $new_date = $_POST['new_date'];
    $new_time = $_POST['new_time'];
    $reason = trim($_POST['reschedule_reason']);

    // Get original date/time
    $get_stmt = $pdo->prepare("SELECT booking_date, booking_time FROM bookings WHERE id = ?");
    $get_stmt->execute([$booking_id]);
    $orig_row = $get_stmt->fetch(PDO::FETCH_ASSOC);
    $original_date = $orig_row['booking_date'];
    $original_time = $orig_row['booking_time'];

    // Update booking - 6 placeholders total
    $update_stmt = $pdo->prepare("UPDATE bookings SET
        booking_date = ?,
        booking_time = ?,
        original_date = ?,
        original_time = ?,
        reschedule_reason = ?,
        rescheduled_by = 'admin',
        status = 'confirmed',
        admin_viewed = 1
        WHERE id = ?");

    if ($update_stmt->execute([$new_date, $new_time, $original_date, $original_time, $reason, $booking_id])) {
        $_SESSION['message'] = "Booking #" . str_pad($booking_id, 6, '0', STR_PAD_LEFT) . " rescheduled and confirmed!";
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = "Error rescheduling booking.";
        $_SESSION['message_type'] = 'danger';
    }
    break;

            case 'revert_to_repairing':
                $stmt = $pdo->prepare("UPDATE bookings SET status = 'repairing', final_price = NULL, admin_notes = NULL, completed_date = NULL WHERE id = ?");
                if ($stmt->execute([$booking_id])) {
                    $_SESSION['message'] = "Booking #" . str_pad($booking_id, 6, '0', STR_PAD_LEFT) . " reverted to In Progress!";
                    $_SESSION['message_type'] = 'success';
                }
                break;
        }
        
        header("Location: " . $redirect_url);
        exit();
    }
}

// Check for session messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

/* ================= GET FILTER, ORDER, AND SORT ================= */
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'pending';
$order = isset($_GET['order']) ? $_GET['order'] : 'desc';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'created_at';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'desc';

$valid_filters = ['pending', 'confirmed', 'repairing'];
$valid_orders = ['asc', 'desc'];
$valid_sort_by = ['created_at', 'booking_date'];
$valid_sort_order = ['asc', 'desc'];

if (!in_array($filter, $valid_filters)) $filter = 'pending';
if (!in_array($order, $valid_orders)) $order = 'desc';
if (!in_array($sort_by, $valid_sort_by)) $sort_by = 'created_at';
if (!in_array($sort_order, $valid_sort_order)) $sort_order = 'desc';

/* ================= FETCH BOOKINGS ================= */
$bookings = [];
$sql = "SELECT 
            b.*,
            u.full_name as customer_name,
            u.email as customer_email,
            u.phone as customer_phone,
            v.brand_name,
            v.model,
            v.year,
            v.color,
            v.number_plate,
            sc.category_name as service_name,
            sc.base_price as service_price
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        JOIN vehicles v ON b.vehicle_id = v.id
        JOIN service_categories sc ON b.service_category_id = sc.id
        WHERE b.status = '$filter'
        ORDER BY $sort_by $sort_order";

$result = $pdo->query($sql);
if ($result) {
    $bookings = $result->fetchAll(PDO::FETCH_ASSOC);
}

// Get statistics (only pending, confirmed, repairing)
$stats = [];
$stats_sql = "SELECT
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count,
    SUM(CASE WHEN status = 'repairing' THEN 1 ELSE 0 END) as repairing_count,
    SUM(CASE WHEN status = 'pending' AND admin_viewed = 0 THEN 1 ELSE 0 END) as unviewed_count
FROM bookings";
$stats_result = $pdo->query($stats_sql);
if ($stats_result) {
    $stats = $stats_result->fetch(PDO::FETCH_ASSOC);
}

// Fetch available time slots
$time_slots = [];
$slots_sql = "SELECT slot_time FROM booking_timeslots WHERE is_active = 1 ORDER BY slot_time";
$slots_result = $pdo->query($slots_sql);
$time_slots = $slots_result->fetchAll(PDO::FETCH_ASSOC);

date_default_timezone_set('Asia/Kuala_Lumpur');
$hour = date('G');
if ($hour >= 5 && $hour < 12) {
    $greeting = 'Good Morning';
} elseif ($hour >= 12 && $hour < 18) {
    $greeting = 'Good Afternoon';
} else {
    $greeting = 'Good Evening';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Management | CS KUMARESAN MOTOR</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        /* ================= TOP NAVIGATION ================= */
.top-nav {
    background: white;
    padding: 0.75rem 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    position: sticky;
    top: 0;
    z-index: 1000;
}

.nav-container {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
}

.logo-area {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-width: 200px;
}

.logo-icon {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #1e40af, #1e3a8a);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.3rem;
}

.logo-text {
    font-weight: 700;
    font-size: 1rem;
    color: #1e293b;
    line-height: 1.3;
}

.logo-text span {
    font-size: 0.65rem;
    color: #64748b;
    display: block;
    font-weight: 400;
}

.nav-links {
    display: flex;
    gap: 0.25rem;
    flex-wrap: wrap;
    flex: 1;
    justify-content: center;
}

.nav-link-item {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 0.8rem;
    border-radius: 30px;
    text-decoration: none;
    color: #475569;
    font-size: 0.8rem;
    font-weight: 500;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.nav-link-item:hover {
    background: #f1f5f9;
    color: #1e40af;
}

.nav-link-item.active {
    background: #eff6ff;
    color: #1e40af;
}

.user-area {
    display: flex;
    align-items: center;
    gap: 1rem;
    min-width: 150px;
    justify-content: flex-end;
}

.user-info {
    text-align: right;
}

.user-name {
    font-weight: 600;
    font-size: 0.85rem;
    color: #1e293b;
}

.user-role {
    font-size: 0.65rem;
    color: #1e40af;
}

.logout-btn {
    background: #fee2e2;
    color: #dc2626;
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

.logout-btn:hover {
    background: #fecaca;
    color: #dc2626;
}

/* Mobile responsive */
@media (max-width: 1024px) {
    .nav-links {
        order: 3;
        width: 100%;
        justify-content: flex-start;
        overflow-x: auto;
        padding-bottom: 0.5rem;
    }
    
    .nav-container {
        flex-direction: column;
        align-items: stretch;
    }
    
    .logo-area {
        min-width: auto;
    }
    
    .user-area {
        min-width: auto;
        justify-content: space-between;
    }
}

@media (max-width: 768px) {
    .top-nav {
        padding: 0.75rem 1rem;
    }
    
    .nav-link-item {
        padding: 0.4rem 0.6rem;
        font-size: 0.7rem;
    }
    
    .nav-link-item i {
        font-size: 0.8rem;
    }
}
        
        .main-container {
            padding: 1.5rem;
        }
        
        /* Stats Cards - Only 3 cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
            border: 1px solid #e2e8f0;
            position: relative;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .stat-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }
        
        .stat-card p {
            font-size: 0.7rem;
            color: #64748b;
            margin: 0;
            text-transform: uppercase;
            font-weight: 500;
        }
        
        .new-dot {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 12px;
            height: 12px;
            background: #ef4444;
            border-radius: 50%;
            animation: pulse 1s ease infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
        }
        
        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            border: 1px solid #e2e8f0;
        }
        
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .filter-tab {
            padding: 0.4rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .filter-tab:hover {
            background: #f1f5f9;
        }
        
        .filter-tab.active {
            background: #1e40af;
            color: white;
        }
        
        .order-select {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .order-select label {
            font-size: 0.75rem;
            color: #64748b;
        }
        
        .order-select select {
            padding: 0.3rem 0.6rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.75rem;
        }
        
        .search-box {
            position: relative;
            width: 250px;
        }
        
        .search-box i {
            position: absolute;
            left: 0.8rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.8rem;
        }
        
        .search-box input {
            width: 100%;
            padding: 0.5rem 0.8rem 0.5rem 2rem;
            border: 1px solid #e2e8f0;
            border-radius: 30px;
            font-size: 0.75rem;
        }
        
        /* Table */
        .main-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow-x: auto;
        }
        
        .table {
            margin: 0;
            min-width: 1000px;
        }
        
        .table thead th {
            background: #f8fafc;
            padding: 0.75rem 1rem;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .table tbody td {
            padding: 0.75rem 1rem;
            vertical-align: middle;
            font-size: 0.8rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .table tbody tr.unviewed {
            background: #eff6ff;
        }
        
        .new-booking-dot {
            width: 8px;
            height: 8px;
            background: #ef4444;
            border-radius: 50%;
            display: inline-block;
            margin-left: 0.3rem;
            animation: pulse 1s ease infinite;
        }
        
        .status-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-confirmed { background: #dbeafe; color: #1e40af; }
        .status-repairing { background: #fed7aa; color: #9a3412; }
        
        .action-buttons {
            display: flex;
            gap: 0.3rem;
            flex-wrap: wrap;
        }
        
        .btn-action {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-action:hover {
            transform: scale(1.05);
        }
        
        .btn-view { background: #e2e8f0; color: #475569; }
        .btn-confirm { background: #d1fae5; color: #059669; }
        .btn-cancel { background: #fee2e2; color: #dc2626; }
        .btn-reschedule { background: #fef3c7; color: #d97706; }
        .btn-start { background: #dbeafe; color: #1e40af; }
        
        /* Modal */
        .modal-content { border-radius: 16px; }
        .modal-header { padding: 1rem; }
        .modal-body { padding: 1rem; }
        .modal-footer { padding: 1rem; }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        
        .info-box {
            background: #f8fafc;
            padding: 0.6rem;
            border-radius: 10px;
        }
        
        .info-label { font-size: 0.6rem; color: #64748b; }
        .info-value { font-weight: 600; font-size: 0.8rem; }
        
        /* Toast & Indicator */
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            border-left: 4px solid #1e40af;
            border-radius: 10px;
            padding: 0.7rem 1rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            transform: translateX(400px);
            transition: transform 0.3s ease;
        }
        
        .toast-notification.show { transform: translateX(0); }
        
        .refresh-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #1e40af;
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 30px;
            font-size: 0.7rem;
            display: flex;
            gap: 0.5rem;
            z-index: 999;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .refresh-indicator.show { opacity: 1; }
        .refresh-indicator i { animation: spin 1s linear infinite; }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .alert {
            border-radius: 10px;
            padding: 0.6rem 1rem;
            font-size: 0.8rem;
            margin-bottom: 1rem;
        }
        
        .footer {
            background: #1e293b;
            color: white;
            text-align: center;
            padding: 0.75rem;
            margin-top: 1.5rem;
            font-size: 0.65rem;
        }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .search-box { width: 100%; }
            .nav-container { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

<!-- ================= TOP NAVIGATION ================= -->
<div class="top-nav">
    <div class="nav-container">
        <div class="logo-area">
            <div class="logo-icon">
                <i class="bi bi-car-front-fill"></i>
            </div>
            <div class="logo-text">
                CS KUMARESAN MOTOR
                <span>Admin Panel</span>
            </div>
        </div>
        
        <div class="nav-links">
            <a href="admin-dashboard.php" class="nav-link-item <?php echo basename($_SERVER['PHP_SELF']) == 'admin-dashboard.php' ? 'active' : ''; ?>">
                <i class="bi bi-house""></i> Dashboard
            </a>
            <a href="admin-bookings.php" class="nav-link-item <?php echo basename($_SERVER['PHP_SELF']) == 'admin-bookings.php' ? 'active' : ''; ?>">
                <i class="bi bi-calendar-check"></i> Bookings
            </a>
            <a href="admin-work-progress.php" class="nav-link-item <?php echo basename($_SERVER['PHP_SELF']) == 'admin-work-progress.php' ? 'active' : ''; ?>">
                <i class="bi bi-activity"></i> Work Progress
            </a>
            <a href="admin-service-history.php" class="nav-link-item <?php echo basename($_SERVER['PHP_SELF']) == 'admin-service-history.php' ? 'active' : ''; ?>">
                <i class="bi bi-clock-history"></i> History
            </a>
            <a href="admin-spare-parts.php" class="nav-link-item <?php echo basename($_SERVER['PHP_SELF']) == 'admin-spare-parts.php' ? 'active' : ''; ?>">
                <i class="bi bi-gear-wide-connected"></i> Components
            </a>
            <a href="admin-suggestions.php" class="nav-link-item <?php echo basename($_SERVER['PHP_SELF']) == 'admin-suggestions.php' ? 'active' : ''; ?>">
                <i class="bi bi-lightbulb"></i> Suggestions
            </a>
            <a href="admin-customers.php" class="nav-link-item <?php echo basename($_SERVER['PHP_SELF']) == 'admin-customers.php' ? 'active' : ''; ?>">
                <i class="bi bi-people"></i> Customers
            </a>
        </div>
        
        <div class="user-area">
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($full_name); ?></div>
                <div class="user-role">Administrator</div>
            </div>
            <a href="logout.php" class="logout-btn"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </div>
</div>

<!-- ================= MAIN CONTENT ================= -->
<div class="main-container">
    
    <!-- Stats Cards - Only 3 cards -->
    <div class="stats-grid">
        <div class="stat-card" onclick="window.location.href='?filter=pending&order=<?php echo $order; ?>'">
            <h3 id="pendingCount"><?php echo $stats['pending_count'] ?? 0; ?></h3>
            <p>Pending</p>
            <?php if (($stats['unviewed_count'] ?? 0) > 0): ?>
                <span class="new-dot" id="pendingNewDot"></span>
            <?php endif; ?>
        </div>
        <div class="stat-card" onclick="window.location.href='?filter=confirmed&order=<?php echo $order; ?>'">
            <h3 id="confirmedCount"><?php echo $stats['confirmed_count'] ?? 0; ?></h3>
            <p>Confirmed</p>
        </div>
        <div class="stat-card" onclick="window.location.href='?filter=repairing&order=<?php echo $order; ?>'">
            <h3 id="repairingCount"><?php echo $stats['repairing_count'] ?? 0; ?></h3>
            <p>In Progress</p>
        </div>
    </div>
    
    <!-- Message Alert -->
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
            <i class="bi bi-<?php echo $message_type == 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <!-- Filter Bar -->
<div class="filter-bar">
    <div class="filter-tabs">
        <a href="?filter=pending&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>" class="filter-tab <?php echo $filter == 'pending' ? 'active' : ''; ?>">Pending</a>
        <a href="?filter=confirmed&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>" class="filter-tab <?php echo $filter == 'confirmed' ? 'active' : ''; ?>">Confirmed</a>
        <a href="?filter=repairing&sort_by=<?php echo $sort_by; ?>&sort_order=<?php echo $sort_order; ?>" class="filter-tab <?php echo $filter == 'repairing' ? 'active' : ''; ?>">In Progress</a>
    </div>

    <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="searchInput" placeholder="Search bookings...">
    </div>

    <div class="order-select">
        <label><i class="bi bi-sort-down"></i> Sort by:</label>
        <select id="sortBySelect">
            <option value="created_at_desc" <?php echo ($sort_by == 'created_at' && $sort_order == 'desc') ? 'selected' : ''; ?>>📅 Newest first (Booked date)</option>
            <option value="created_at_asc" <?php echo ($sort_by == 'created_at' && $sort_order == 'asc') ? 'selected' : ''; ?>>📅 Oldest first (Booked date)</option>
            <option value="booking_date_asc" <?php echo ($sort_by == 'booking_date' && $sort_order == 'asc') ? 'selected' : ''; ?>>📆 Schedule (Earliest first)</option>
            <option value="booking_date_desc" <?php echo ($sort_by == 'booking_date' && $sort_order == 'desc') ? 'selected' : ''; ?>>📆 Schedule (Last first)</option>
        </select>
    </div>
</div>
    
    <!-- Bookings Table -->
    <div class="main-card">
        <div class="table-responsive">
            <table class="table" id="bookingsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Booking Date</th>
                        <th>Customer</th>
                        <th>Vehicle</th>
                        <th>Service</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $booking): 
                        $is_unviewed = ($booking['status'] == 'pending' && !($booking['admin_viewed'] ?? 0));
                    ?>
                        <tr class="<?php echo $is_unviewed ? 'unviewed' : ''; ?>" data-booking-id="<?php echo $booking['id']; ?>">
                            <td>
                                #<?php echo str_pad($booking['id'], 6, '0', STR_PAD_LEFT); ?>
                                <?php if ($is_unviewed): ?>
                                    <span class="new-booking-dot" title="New booking"></span>
                                <?php endif; ?>
                            </div>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($booking['created_at'])); ?><br><small><?php echo date('h:i A', strtotime($booking['created_at'])); ?></small></td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($booking['customer_name']); ?></div>
                                <small><?php echo htmlspecialchars($booking['customer_phone']); ?></small>
                            </div>
                            <td>
                                <?php echo htmlspecialchars($booking['brand_name'] . ' ' . $booking['model']); ?>
                                <div class="mt-1"><small><?php echo htmlspecialchars($booking['number_plate']); ?></small></div>
                            </div>
                            <td><?php echo htmlspecialchars($booking['service_name']); ?></div>
                            <td>
                                <div><?php echo date('M d, Y', strtotime($booking['booking_date'])); ?></div>
                                <small><?php echo date('h:i A', strtotime($booking['booking_time'])); ?></small>
                                <?php if ($booking['original_date']): ?>
                                    <span class="badge bg-warning mt-1" style="font-size: 0.6rem;">Rescheduled</span>
                                <?php endif; ?>
                            </div>
                            <td>
                                <span class="status-badge status-<?php echo $booking['status']; ?>">
                                    <?php 
                                    if ($booking['status'] == 'repairing') echo 'In Progress';
                                    else echo ucfirst($booking['status']);
                                    ?>
                                </span>
                            </div>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-action btn-view" onclick="markAsViewed(<?php echo $booking['id']; ?>); showViewModal(<?php echo $booking['id']; ?>)" title="View">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    
                                    <?php if ($booking['status'] == 'pending'): ?>
                                        <button class="btn-action btn-confirm" onclick="confirmAction(<?php echo $booking['id']; ?>, 'confirm')" title="Confirm"><i class="bi bi-check-lg"></i></button>
                                        <button class="btn-action btn-cancel" onclick="confirmAction(<?php echo $booking['id']; ?>, 'cancel')" title="Cancel"><i class="bi bi-x-lg"></i></button>
                                        <button class="btn-action btn-reschedule" onclick="showRescheduleModal(<?php echo $booking['id']; ?>, '<?php echo date('M d, Y', strtotime($booking['booking_date'])); ?>', '<?php echo date('h:i A', strtotime($booking['booking_time'])); ?>')" title="Reschedule"><i class="bi bi-calendar-event"></i></button>
                                        
                                    <?php elseif ($booking['status'] == 'confirmed'): ?>
                                        <button class="btn-action btn-start" onclick="confirmAction(<?php echo $booking['id']; ?>, 'start')" title="Start"><i class="bi bi-play-fill"></i></button>
                                        <button class="btn-action btn-cancel" onclick="confirmAction(<?php echo $booking['id']; ?>, 'cancel')" title="Cancel"><i class="bi bi-x-lg"></i></button>
                                        <button class="btn-action btn-reschedule" onclick="showRescheduleModal(<?php echo $booking['id']; ?>, '<?php echo date('M d, Y', strtotime($booking['booking_date'])); ?>', '<?php echo date('h:i A', strtotime($booking['booking_time'])); ?>')" title="Reschedule"><i class="bi bi-calendar-event"></i></button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (empty($bookings)): ?>
            <div class="text-center py-5">
                <i class="bi bi-calendar-x" style="font-size: 2rem; color: #cbd5e1;"></i>
                <p class="mt-2 text-muted">No <?php echo $filter; ?> bookings found</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- View Modals -->
<?php foreach ($bookings as $booking): ?>
    <div class="modal fade" id="viewModal<?php echo $booking['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Booking #<?php echo str_pad($booking['id'], 6, '0', STR_PAD_LEFT); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="info-grid">
                        <div class="info-box"><div class="info-label">Customer</div><div class="info-value"><?php echo htmlspecialchars($booking['customer_name']); ?></div></div>
                        <div class="info-box"><div class="info-label">Contact</div><div class="info-value"><?php echo htmlspecialchars($booking['customer_phone']); ?></div></div>
                        <div class="info-box"><div class="info-label">Vehicle</div><div class="info-value"><?php echo htmlspecialchars($booking['brand_name'] . ' ' . $booking['model']); ?></div></div>
                        <div class="info-box"><div class="info-label">Number Plate</div><div class="info-value"><?php echo htmlspecialchars($booking['number_plate']); ?></div></div>
                        <div class="info-box"><div class="info-label">Service</div><div class="info-value"><?php echo htmlspecialchars($booking['service_name']); ?></div></div>
                        <div class="info-box"><div class="info-label">Schedule</div><div class="info-value"><?php echo date('M d, Y', strtotime($booking['booking_date'])); ?> at <?php echo date('h:i A', strtotime($booking['booking_time'])); ?></div></div>
                        <div class="info-box"><div class="info-label">Placed On</div><div class="info-value"><?php echo date('M d, Y', strtotime($booking['created_at'])); ?> at <?php echo date('h:i A', strtotime($booking['created_at'])); ?></div></div>
                        <div class="info-box"><div class="info-label">Status</div><div class="info-value"><span class="status-badge status-<?php echo $booking['status']; ?>"><?php echo ucfirst($booking['status']); ?></span></div></div>
                    </div>
                    <?php if (!empty($booking['remarks'])): ?>
                        <div class="mt-3 p-2 bg-light rounded"><strong>Remarks:</strong><br><?php echo nl2br(htmlspecialchars($booking['remarks'])); ?></div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<!-- Reschedule Modal -->
<div class="modal fade" id="rescheduleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="rescheduleForm">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title">Reschedule Booking</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2"><strong>Current:</strong> <span id="currentSchedule"></span></div>
                    <div class="mb-2"><label>New Date</label><input type="date" name="new_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="mb-2"><label>New Time</label>
                        <select name="new_time" class="form-select" required>
                            <?php foreach ($time_slots as $slot): ?>
                                <option value="<?php echo $slot['slot_time']; ?>"><?php echo date('h:i A', strtotime($slot['slot_time'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label>Reason</label><textarea name="reschedule_reason" class="form-control" rows="2"></textarea></div>
                    <input type="hidden" name="booking_id" id="rescheduleBookingId">
                    <input type="hidden" name="action" value="reschedule">
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning">Reschedule</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Toast & Indicator -->
<div class="toast-notification" id="toastNotification"><div class="d-flex gap-2 align-items-center"><i class="bi bi-bell-fill text-primary"></i><div><strong>New Booking!</strong><br><small id="toastMessage"></small></div></div></div>
<div class="refresh-indicator" id="refreshIndicator"><i class="bi bi-arrow-repeat"></i> Checking...</div>



<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    let lastCheckTime = Math.floor(Date.now() / 1000);
    let refreshInterval;
    let isChecking = false;
    
    // Search functionality
    $('#searchInput').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        $('#bookingsTable tbody tr').filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
        });
    });
    
    // Sort select change
$('#sortBySelect').on('change', function() {
    var value = $(this).val();
    var parts = value.split('_');
    var sortBy = parts[0] + '_' + parts[1]; // created_at or booking_date
    var sortOrder = parts[2]; // asc or desc
    
    window.location.href = '?filter=<?php echo $filter; ?>&order=<?php echo $order; ?>&sort_by=' + sortBy + '&sort_order=' + sortOrder;
});

// Order select change (keep this if you still need it, or remove if using sortBySelect only)
$('#orderSelect').on('change', function() {
    // This is now handled by sortBySelect, you can remove this function
});
    
    // Mark booking as viewed and remove dot
    function markAsViewed(bookingId) {
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: { mark_booking_viewed: 1, booking_id: bookingId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Remove highlight from row
                    $('tr[data-booking-id="' + bookingId + '"]').removeClass('unviewed');
                    $('tr[data-booking-id="' + bookingId + '"] .new-booking-dot').remove();
                    
                    // Update pending stat card count and remove dot if no unviewed left
                    if (response.unviewed === 0) {
                        $('#pendingNewDot').remove();
                    }
                }
            }
        });
    }
    
    // Show View Modal
    function showViewModal(bookingId) {
        var modal = new bootstrap.Modal(document.getElementById('viewModal' + bookingId));
        modal.show();
    }
    
    // Show Reschedule Modal
    function showRescheduleModal(bookingId, currentDate, currentTime) {
        $('#currentSchedule').text(currentDate + ' at ' + currentTime);
        $('#rescheduleBookingId').val(bookingId);
        var modal = new bootstrap.Modal(document.getElementById('rescheduleModal'));
        modal.show();
    }
    
    // Confirm Action
    function confirmAction(bookingId, action) {
        let msg = '';
        switch(action) {
            case 'confirm': msg = 'Confirm this booking?'; break;
            case 'cancel': msg = 'Cancel this booking?'; break;
            case 'start': msg = 'Start this service?'; break;
            default: return;
        }
        if (confirm(msg)) {
            $('<form method="POST">').append(
                '<input type="hidden" name="booking_id" value="' + bookingId + '">' +
                '<input type="hidden" name="action" value="' + action + '">'
            ).appendTo('body').submit();
        }
    }
    
    // Auto-refresh for new bookings
    function checkForNewBookings() {
        if (isChecking) return;
        isChecking = true;
        $('#refreshIndicator').addClass('show');
        
        fetch(window.location.pathname + '?check_new_bookings=1&last_check=' + lastCheckTime)
            .then(response => response.json())
            .then(data => {
                $('#refreshIndicator').removeClass('show');
                isChecking = false;
                
                if (data.has_new) {
                    lastCheckTime = data.timestamp;
                    
                    // Update stats
                    $('#pendingCount').text(data.stats.pending_count);
                    $('#confirmedCount').text(data.stats.confirmed_count);
                    $('#repairingCount').text(data.stats.repairing_count);
                    
                    // Update pending dot
                    if (data.unviewed_count > 0) {
                        if ($('#pendingNewDot').length === 0) {
                            $('.stat-card:first-child').append('<span class="new-dot" id="pendingNewDot"></span>');
                        }
                    } else {
                        $('#pendingNewDot').remove();
                    }
                    
                    // Show toast
                    $('#toastMessage').text(data.new_count + ' new booking(s) received!');
                    $('#toastNotification').addClass('show');
                    setTimeout(() => $('#toastNotification').removeClass('show'), 5000);
                    
                    // If current filter is pending, reload to show new
                    if ('<?php echo $filter; ?>' === 'pending' && !document.hidden) {
                        setTimeout(() => {
                            if (confirm(data.new_count + ' new booking(s). Refresh to see them?')) {
                                window.location.reload();
                            }
                        }, 2000);
                    }
                }
            })
            .catch(error => { console.error(error); $('#refreshIndicator').removeClass('show'); isChecking = false; });
    }
    
    // Start auto-refresh
    function startAutoRefresh() { 
        if (refreshInterval) clearInterval(refreshInterval); 
        refreshInterval = setInterval(checkForNewBookings, 15000); 
    }
    
    startAutoRefresh();
    document.addEventListener('visibilitychange', function() { 
        document.hidden ? clearInterval(refreshInterval) : startAutoRefresh(); 
    });
</script>

</body>
</html>