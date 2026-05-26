<?php
ob_start();

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

// ================= AJAX ENDPOINT FOR UPDATES =================
if (isset($_GET['check_updates'])) {
    header('Content-Type: application/json');
    
    $last_check = isset($_GET['last_check']) ? intval($_GET['last_check']) : 0;
    
    // Get last viewed times from session
    $last_viewed_bookings = isset($_SESSION['viewed_bookings']) ? $_SESSION['viewed_bookings'] : 0;
    $last_viewed_customers = isset($_SESSION['viewed_customers']) ? $_SESSION['viewed_customers'] : 0;
    $last_viewed_vehicles = isset($_SESSION['viewed_vehicles']) ? $_SESSION['viewed_vehicles'] : 0;
    
    $lastCheckTs       = gmdate('Y-m-d\TH:i:s\Z', $last_check);
    $lastViewedBkTs    = gmdate('Y-m-d\TH:i:s\Z', $last_viewed_bookings);
    $lastViewedCustTs  = gmdate('Y-m-d\TH:i:s\Z', $last_viewed_customers);
    $lastViewedVehTs   = gmdate('Y-m-d\TH:i:s\Z', $last_viewed_vehicles);

    // Fetch from Firestore and filter in PHP
    $allBookings  = $firebase->query('bookings');
    $allCustomers = $firebase->query('users', [['role', '==', 'customer']]);
    $allVehicles  = $firebase->query('vehicles');

    $new_bookings_since_check  = count(array_filter($allBookings,  fn($b) => ($b['status'] ?? '') === 'pending' && ($b['created_at'] ?? '') > $lastCheckTs));
    $new_customers_since_check = count(array_filter($allCustomers, fn($u) => ($u['created_at'] ?? '') > $lastCheckTs));
    $new_vehicles_since_check  = count(array_filter($allVehicles,  fn($v) => ($v['created_at'] ?? '') > $lastCheckTs));

    $unviewed_bookings  = count(array_filter($allBookings,  fn($b) => ($b['status'] ?? '') === 'pending' && ($b['created_at'] ?? '') > $lastViewedBkTs));
    $unviewed_customers = count(array_filter($allCustomers, fn($u) => ($u['created_at'] ?? '') > $lastViewedCustTs));
    $unviewed_vehicles  = count(array_filter($allVehicles,  fn($v) => ($v['created_at'] ?? '') > $lastViewedVehTs));

    $total_customers  = count($allCustomers);
    $total_vehicles   = count($allVehicles);
    $pending_bookings = count(array_filter($allBookings, fn($b) => ($b['status'] ?? '') === 'pending'));
    $active_services  = count(array_filter($allBookings, fn($b) => ($b['status'] ?? '') === 'repairing'));
    
    echo json_encode([
        'has_updates' => ($new_bookings_since_check > 0 || $new_customers_since_check > 0 || $new_vehicles_since_check > 0),
        'new_bookings' => $new_bookings_since_check,
        'new_customers' => $new_customers_since_check,
        'new_vehicles' => $new_vehicles_since_check,
        'unviewed_bookings' => $unviewed_bookings,
        'unviewed_customers' => $unviewed_customers,
        'unviewed_vehicles' => $unviewed_vehicles,
        'stats' => [
            'customers' => $total_customers,
            'vehicles' => $total_vehicles,
            'pending' => $pending_bookings,
            'active' => $active_services
        ],
        'timestamp' => time()
    ]);
    exit();
}

// ================= MARK MODULE AS VIEWED =================
if (isset($_POST['mark_module_viewed'])) {
    session_start();
    $module = $_POST['module'];
    if ($module === 'bookings') {
        $_SESSION['viewed_bookings'] = time();
    } elseif ($module === 'customers') {
        $_SESSION['viewed_customers'] = time();
    }
    echo json_encode(['success' => true]);
    exit();
}

// Get stats for dashboard from Firestore
$allBookings  = $firebase->query('bookings');
$allCustomers = $firebase->query('users', [['role', '==', 'customer']]);
$allVehicles  = $firebase->query('vehicles');

$total_customers    = count($allCustomers);
$total_vehicles     = count($allVehicles);
$pending_bookings   = count(array_filter($allBookings, fn($b) => ($b['status'] ?? '') === 'pending'));
$confirmed_bookings = count(array_filter($allBookings, fn($b) => ($b['status'] ?? '') === 'confirmed'));
$active_services    = count(array_filter($allBookings, fn($b) => ($b['status'] ?? '') === 'repairing'));

// Count new items since last view
$last_viewed_bookings  = $_SESSION['viewed_bookings']  ?? 0;
$last_viewed_customers = $_SESSION['viewed_customers'] ?? 0;
$lastViewedBkTs   = gmdate('Y-m-d\TH:i:s\Z', $last_viewed_bookings);
$lastViewedCustTs = gmdate('Y-m-d\TH:i:s\Z', $last_viewed_customers);

$new_bookings_count  = count(array_filter($allBookings,  fn($b) => ($b['status'] ?? '') === 'pending' && ($b['created_at'] ?? '') > $lastViewedBkTs));
$new_customers_count = count(array_filter($allCustomers, fn($u) => ($u['created_at'] ?? '') > $lastViewedCustTs));

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | CS KUMARESAN MOTOR</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
        
        
        /* ================= STATS CARDS ================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin: 1rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
            position: relative;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            background: #eff6ff;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: #1e40af;
        }
        
        .stat-info h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }
        
        .stat-info p {
            font-size: 0.7rem;
            color: #64748b;
            margin: 0;
            text-transform: uppercase;
            font-weight: 500;
        }
        
        /* ================= SECTION HEADER ================= */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 1.5rem 1rem 1rem;
        }
        
        .section-header h2 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .refresh-btn {
            background: #f1f5f9;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 500;
            color: #475569;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .refresh-btn:hover {
            background: #e2e8f0;
        }
        
        .refresh-btn i {
            transition: transform 0.3s ease;
        }
        
        .refresh-btn:active i {
            transform: rotate(180deg);
        }
        
        /* ================= MODULES GRID ================= */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1rem;
        }
        
        .module-card {
            background: white;
            border-radius: 16px;
            padding: 1.25rem;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
            text-decoration: none;
            display: block;
            position: relative;
            overflow: hidden;
        }
        
        .module-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            border-color: #1e40af;
        }
        
        .module-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            margin-bottom: 1rem;
        }
        
        .module-icon.blue { background: #eff6ff; color: #1e40af; }
        .module-icon.green { background: #d1fae5; color: #059669; }
        .module-icon.purple { background: #f3e8ff; color: #9333ea; }
        .module-icon.orange { background: #ffedd5; color: #ea580c; }
        .module-icon.red { background: #fee2e2; color: #dc2626; }
        .module-icon.teal { background: #ccfbf1; color: #0d9488; }
        
        .module-title {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        
        .module-desc {
            font-size: 0.75rem;
            color: #64748b;
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }
        
        .module-badge {
            font-size: 0.7rem;
            background: #f1f5f9;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            color: #475569;
            display: inline-block;
        }
        
        /* ================= NEW INDICATOR BADGE ================= */
        .new-indicator {
            position: absolute;
            top: 2px;
            right: 2px;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            min-width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
            animation: bounce 0.5s ease infinite;
            z-index: 10;
            padding: 0 6px;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-3px); }
        }
        
        .new-indicator.pulse {
            animation: pulse 1s ease infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.15); }
        }
        
        /* ================= TOAST NOTIFICATION ================= */
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
            border-left: 4px solid #1e40af;
            transform: translateX(400px);
            transition: transform 0.3s ease;
        }
        
        .toast-notification.show {
            transform: translateX(0);
        }
        
        .toast-content {
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .toast-icon {
            width: 40px;
            height: 40px;
            background: #eff6ff;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: #1e40af;
        }
        
        .toast-message {
            flex: 1;
        }
        
        .toast-title {
            font-weight: 600;
            font-size: 0.9rem;
            color: #1e293b;
        }
        
        .toast-text {
            font-size: 0.75rem;
            color: #64748b;
        }
        
        .refresh-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #1e40af;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            z-index: 9998;
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
        
        /* ================= RESPONSIVE ================= */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .modules-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .modules-grid {
                grid-template-columns: 1fr;
            }
            .welcome-banner {
                text-align: center;
            }
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
                <i class="bi bi-house"></i> Dashboard
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
<div class="container-fluid">
    
    
    <!-- Statistics Cards (4 cards - removed spare parts) -->
    <div class="stats-grid" id="statsGrid">
        <div class="stat-card" data-stat="customers">
            <div class="stat-icon">
                <i class="bi bi-people"></i>
            </div>
            <div class="stat-info">
                <h3 id="customerCount"><?php echo $total_customers; ?></h3>
                <p>Customers</p>
            </div>
        </div>
        
        <div class="stat-card" data-stat="vehicles">
            <div class="stat-icon">
                <i class="bi bi-car-front"></i>
            </div>
            <div class="stat-info">
                <h3 id="vehicleCount"><?php echo $total_vehicles; ?></h3>
                <p>Vehicles</p>
            </div>
        </div>
        
        <div class="stat-card" data-stat="pending">
            <div class="stat-icon">
                <i class="bi bi-calendar-check"></i>
            </div>
            <div class="stat-info">
                <h3 id="pendingCount"><?php echo $pending_bookings; ?></h3>
                <p>Pending Bookings</p>
            </div>
        </div>
        
        <div class="stat-card" data-stat="active">
            <div class="stat-icon">
                <i class="bi bi-tools"></i>
            </div>
            <div class="stat-info">
                <h3 id="activeCount"><?php echo $active_services; ?></h3>
                <p>In Progress</p>
            </div>
        </div>
    </div>
    
    <!-- Management Modules -->
    <div class="section-header">
        <h2><i class="bi bi-grid-3x3-gap-fill"></i> Management Modules</h2>
        <button class="refresh-btn" onclick="manualRefresh()">
            <i class="bi bi-arrow-repeat"></i> Refresh
        </button>
    </div>
    
    <div class="modules-grid">
    <!-- Booking Management -->
    <a href="admin-bookings.php" class="module-card" id="bookingModule" onclick="markModuleViewed('bookings')">
        <div class="module-icon blue">
            <i class="bi bi-calendar-plus"></i>
        </div>
        <div class="module-title">Booking Management</div>
        <div class="module-desc">View, accept, or reschedule new service bookings</div>
        <div class="booking-stats">
            <span class="module-badge pending-badge">
                <i class="bi bi-clock-history"></i> Pending: <?php echo $pending_bookings; ?>
            </span>
            <span class="module-badge confirmed-badge">
                <i class="bi bi-check-circle"></i> Confirmed: <?php echo $confirmed_bookings; ?>
            </span>
        </div>
        <?php if ($new_bookings_count > 0): ?>
            <div class="new-indicator pulse" id="bookingNewIndicator">
                +<?php echo $new_bookings_count; ?>
            </div>
        <?php endif; ?>
    </a>
    
    <!-- Work Progress -->
    <a href="admin-work-progress.php" class="module-card">
        <div class="module-icon green">
            <i class="bi bi-activity"></i>
        </div>
        <div class="module-title">Work Progress</div>
        <div class="module-desc">Monitor and update job status in real-time</div>
        <span class="module-badge"><?php echo $active_services; ?> active</span>
    </a>
    
    <!-- Service History -->
    <a href="admin-service-history.php" class="module-card">
        <div class="module-icon purple">
            <i class="bi bi-clock-history"></i>
        </div>
        <div class="module-title">Service History</div>
        <div class="module-desc">View complete service records and costs</div>
        <span class="module-badge">View all</span>
    </a>
    
    <!-- Service Suggestions -->
    <a href="admin-suggestions.php" class="module-card">
        <div class="module-icon orange">
            <i class="bi bi-lightbulb"></i>
        </div>
        <div class="module-title">Service Suggestions</div>
        <div class="module-desc">Send personalized service recommendations to customers</div>
        <span class="module-badge">Reminders</span>
    </a>
    
    <!-- Customer Details -->
    <a href="customer-details.php" class="module-card" id="customerModule" onclick="markModuleViewed('customers')">
        <div class="module-icon teal">
            <i class="bi bi-people"></i>
        </div>
        <div class="module-title">Customer Details</div>
        <div class="module-desc">View and manage customer profiles and vehicle details</div>
        <span class="module-badge"><?php echo $total_customers; ?> customers</span>
    </a>
    
    <!-- Components (Spare Parts) - ADD THIS MODULE -->
    <a href="admin-spare-parts.php" class="module-card">
        <div class="module-icon red">
            <i class="bi bi-gear-wide-connected"></i>
        </div>
        <div class="module-title">Components</div>
        <div class="module-desc">Manage spare parts, brands, and component inventory</div>
        <span class="module-badge">Inventory</span>
    </a>
</div>
    
</div>

<!-- Toast Notification -->
<div class="toast-notification" id="toastNotification">
    <div class="toast-content">
        <div class="toast-icon">
            <i class="bi bi-bell-fill"></i>
        </div>
        <div class="toast-message">
            <div class="toast-title">New Update!</div>
            <div class="toast-text" id="toastMessage">New activity detected</div>
        </div>
    </div>
</div>

<!-- Refresh Indicator -->
<div class="refresh-indicator" id="refreshIndicator">
    <i class="bi bi-arrow-repeat"></i>
    <span>Checking for updates...</span>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    let lastCheckTime = Math.floor(Date.now() / 1000);
    let refreshInterval;
    let isChecking = false;
    let pendingReload = false;
    
    // Function to mark module as viewed
    function markModuleViewed(module) {
        fetch(window.location.pathname, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'mark_module_viewed=1&module=' + module
        });
        
        // Hide the indicator immediately
        if (module === 'bookings') {
            const indicator = document.getElementById('bookingNewIndicator');
            if (indicator) indicator.style.display = 'none';
        } else if (module === 'customers') {
            const indicator = document.getElementById('customerNewIndicator');
            if (indicator) indicator.style.display = 'none';
        }
    }
    
    // Function to check for updates
    // Function to check for updates
function checkForUpdates() {
    if (isChecking) return;
    
    isChecking = true;
    const refreshIndicator = document.getElementById('refreshIndicator');
    refreshIndicator.classList.add('show');
    
    fetch(window.location.pathname + '?check_updates=1&last_check=' + lastCheckTime)
        .then(response => response.json())
        .then(data => {
            refreshIndicator.classList.remove('show');
            isChecking = false;
            
            if (data.has_updates) {
                lastCheckTime = data.timestamp;
                
                // Update statistics counts
                if (data.stats) {
                    document.getElementById('customerCount').textContent = data.stats.customers;
                    document.getElementById('vehicleCount').textContent = data.stats.vehicles;
                    document.getElementById('pendingCount').textContent = data.stats.pending;
                    document.getElementById('activeCount').textContent = data.stats.active;
                    
                    // Update module badges
                    const bookingModule = document.getElementById('bookingModule');
                    if (bookingModule) {
                        const badge = bookingModule.querySelector('.module-badge');
                        if (badge) {
                            badge.textContent = data.stats.pending + ' pending';
                        }
                    }
                    
                    const customerModule = document.getElementById('customerModule');
                    if (customerModule) {
                        const badge = customerModule.querySelector('.module-badge');
                        if (badge) {
                            badge.textContent = data.stats.customers + ' customers';
                        }
                    }
                }
                
                // Handle new bookings indicator
                if (data.new_bookings > 0) {
                    let existingIndicator = document.getElementById('bookingNewIndicator');
                    // Get current count from existing indicator or 0
                    let currentCount = 0;
                    if (existingIndicator) {
                        const currentText = existingIndicator.textContent;
                        currentCount = parseInt(currentText.replace('+', '')) || 0;
                    }
                    
                    const totalNewCount = currentCount + data.new_bookings;
                    
                    if (existingIndicator) {
                        existingIndicator.textContent = '+' + totalNewCount;
                        existingIndicator.style.display = 'flex';
                        existingIndicator.classList.add('pulse');
                        // Remove pulse class after animation
                        setTimeout(() => {
                            if (existingIndicator) existingIndicator.classList.remove('pulse');
                        }, 1000);
                    } else {
                        const bookingCard = document.getElementById('bookingModule');
                        if (bookingCard) {
                            const indicator = document.createElement('div');
                            indicator.className = 'new-indicator pulse';
                            indicator.id = 'bookingNewIndicator';
                            indicator.textContent = '+' + totalNewCount;
                            bookingCard.style.position = 'relative';
                            bookingCard.appendChild(indicator);
                            setTimeout(() => {
                                indicator.classList.remove('pulse');
                            }, 1000);
                        }
                    }
                    showToast(`${data.new_bookings} new booking${data.new_bookings > 1 ? 's' : ''} received!`);
                }
                
                // Handle new customers indicator
                if (data.new_customers > 0) {
                    let existingIndicator = document.getElementById('customerNewIndicator');
                    // Get current count from existing indicator or 0
                    let currentCount = 0;
                    if (existingIndicator) {
                        const currentText = existingIndicator.textContent;
                        currentCount = parseInt(currentText.replace('+', '')) || 0;
                    }
                    
                    const totalNewCount = currentCount + data.new_customers;
                    
                    if (existingIndicator) {
                        existingIndicator.textContent = '+' + totalNewCount;
                        existingIndicator.style.display = 'flex';
                        existingIndicator.classList.add('pulse');
                        setTimeout(() => {
                            if (existingIndicator) existingIndicator.classList.remove('pulse');
                        }, 1000);
                    } else {
                        const customerCard = document.getElementById('customerModule');
                        if (customerCard) {
                            const indicator = document.createElement('div');
                            indicator.className = 'new-indicator pulse';
                            indicator.id = 'customerNewIndicator';
                            indicator.textContent = '+' + totalNewCount;
                            customerCard.style.position = 'relative';
                            customerCard.appendChild(indicator);
                            setTimeout(() => {
                                indicator.classList.remove('pulse');
                            }, 1000);
                        }
                    }
                    showToast(`${data.new_customers} new customer${data.new_customers > 1 ? 's' : ''} registered!`);
                }
            }
        })
        .catch(error => {
            console.error('Error checking updates:', error);
            refreshIndicator.classList.remove('show');
            isChecking = false;
        });
}
    
    // Function to show toast notification
    function showToast(message) {
        const toast = document.getElementById('toastNotification');
        const toastMessage = document.getElementById('toastMessage');
        
        toastMessage.textContent = message;
        toast.classList.add('show');
        
        setTimeout(() => {
            toast.classList.remove('show');
        }, 5000);
    }
    
    // Manual refresh function
    function manualRefresh() {
        const refreshBtn = document.querySelector('.refresh-btn i');
        if (refreshBtn) {
            refreshBtn.style.transform = 'rotate(180deg)';
            setTimeout(() => {
                refreshBtn.style.transform = '';
            }, 300);
        }
        window.location.reload();
    }
    
    // Start auto-refresh (every 15 seconds)
    function startAutoRefresh() {
        if (refreshInterval) clearInterval(refreshInterval);
        refreshInterval = setInterval(checkForUpdates, 15000);
    }
    
    // Stop auto-refresh
    function stopAutoRefresh() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
            refreshInterval = null;
        }
    }
    
    // Touch feedback
    const cards = document.querySelectorAll('.module-card, .stat-card, .refresh-btn');
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
    
    // Stop when page is hidden
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopAutoRefresh();
        } else {
            startAutoRefresh();
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
