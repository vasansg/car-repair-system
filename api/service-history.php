<?php
ob_start();

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

// Fetch customer phone from Firestore
$userData       = $firebase->getDoc('users', $user_id);
$customer_phone = $userData['phone'] ?? 'N/A';

// Get current date for greeting
$hour = date('G');
if ($hour < 12) {
    $greeting = 'Good Morning';
} elseif ($hour < 17) {
    $greeting = 'Good Afternoon';
} else {
    $greeting = 'Good Evening';
}

// Fetch completed bookings from Firestore (denormalized – vehicle/service info stored in booking doc)
$bookings = $firebase->query('bookings', [
    ['user_id', '==', $user_id],
    ['status',  '==', 'completed'],
], 'created_at', 'DESCENDING');

// For each booking, fetch parts total from booking_parts collection
foreach ($bookings as &$booking) {
    $parts = $firebase->query('booking_parts', [['booking_id', '==', $booking['id']]]);
    $booking['parts_total'] = array_sum(array_column($parts, 'total_price'));
    // Map field names for template compatibility
    $booking['service_name']    = $booking['service_category_name'] ?? '';
    $booking['estimated_hours'] = $booking['estimated_hours'] ?? '';
}
unset($booking);

$stats = ['completed' => count($bookings)];

// Fetch parts for each booking
$parts_by_booking = [];
foreach ($bookings as $booking) {
    $parts_by_booking[$booking['id']] = $firebase->query('booking_parts', [['booking_id', '==', $booking['id']]]);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Service History | CS KUMARESAN MOTOR</title>
    
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
        
        /* ================= STATS CARDS (Smaller) ================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin: 1rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 14px;
            padding: 0.75rem 0.3rem;
            text-align: center;
            border: 1px solid #e2e8f0;
        }
        
        .stat-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1e40af;
        }
        
        .stat-label {
            font-size: 0.65rem;
            color: #64748b;
            text-transform: uppercase;
        }

        /* Search Box Styling */
.search-box {
    position: relative;
    width: 100%;
}

.search-box i {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 0.9rem;
    z-index: 10;
}

.search-box input {
    width: 100%;
    padding: 0.7rem 1rem 0.7rem 2.5rem;
    border: 2px solid #e2e8f0;
    border-radius: 40px;
    font-size: 0.85rem;
    transition: all 0.2s ease;
    background: white;
}

.search-box input:focus {
    border-color: #1e40af;
    box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
    outline: none;
}
        
        /* ================= FILTER TABS ================= */
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin: 0 1rem 1rem;
        }
        
        .filter-btn {
            flex: 1;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 30px;
            padding: 0.5rem;
            font-weight: 600;
            font-size: 0.8rem;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .filter-btn.active {
            background: #1e40af;
            border-color: #1e40af;
            color: white;
        }
        
        .filter-btn[data-filter="completed"].active {
            background: #059669;
            border-color: #059669;
        }
        
        .filter-btn[data-filter="cancelled"].active {
            background: #dc2626;
            border-color: #dc2626;
        }
        
        /* ================= HISTORY CARDS (Smaller, No Icon) ================= */
        .history-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
            margin: 0 1rem 1.5rem;
        }
        
        .history-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .history-card:active {
            transform: scale(0.99);
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
        
        .status-completed {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .card-body {
            padding: 0.75rem 1rem;
        }
        
        /* Vehicle Info - No Icon */
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
        
        /* Info Grid - Smaller */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            margin-bottom: 0.5rem;
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
        
        .total-amount {
            margin-top: 0.5rem;
            padding-top: 0.5rem;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .total-label {
            font-weight: 600;
            font-size: 0.75rem;
            color: #1e293b;
        }
        
        .total-value {
            font-size: 0.9rem;
            font-weight: 700;
            color: #059669;
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
        
        .btn-view:active {
            transform: scale(0.98);
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
        
        /* Modal Styles */
        .modal-content {
            border-radius: 20px;
            border: none;
        }
        
        .modal-header {
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            color: white;
            border-bottom: none;
            padding: 1rem 1.25rem;
        }
        
        .modal-header.bg-success {
            background: linear-gradient(135deg, #059669, #10b981);
        }
        
        .modal-header.bg-danger {
            background: linear-gradient(135deg, #dc2626, #991b1b);
        }
        
        .modal-title {
            font-weight: 700;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        
        .modal-body {
            padding: 1rem;
            max-height: 70vh;
            overflow-y: auto;
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
        
        .empty-state h4 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
        }
        
        .empty-state p {
            font-size: 0.8rem;
            color: #64748b;
        }
        
        @media (min-width: 768px) {
            .history-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .stats-grid {
                max-width: 400px;
                margin-left: auto;
                margin-right: auto;
            }
            
            .filter-tabs {
                max-width: 400px;
                margin-left: auto;
                margin-right: auto;
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
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="logo-text">
                <span id="pageTitle">Service History</span>
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

<!-- Statistics Cards - Centered -->
<div class="stats-grid" style="display: flex; justify-content: center;">
    <div class="stat-card" style="width: 150px;">
        <div class="stat-value"><?php echo $stats['completed']; ?></div>
        <div class="stat-label">Total Services</div>
    </div>
</div>


<!-- Filter Tabs with Search Box -->
<div class="filter-tabs" style="display: flex; gap: 0.5rem; margin: 0 1rem 1rem;">
    <div class="search-box" style="flex: 1;">
        <i class="bi bi-search"></i>
        <input type="text" id="searchInput" placeholder="Search by vehicle, service, or booking ID...">
    </div>
</div>

    <?php if (empty($bookings)): ?>
        <!-- Empty State -->
        <div class="empty-state">
            <i class="bi bi-clock-history"></i>
            <h4>No Service History</h4>
            <p>You don't have any past service records yet.</p>
            <a href="customer-service-booking.php" class="btn btn-primary btn-sm">Book a Service</a>
        </div>
    <?php else: ?>
        <!-- History Grid -->
        <div class="history-grid" id="historyGrid">
            <?php foreach ($bookings as $booking): 
                $parts = $parts_by_booking[$booking['id']] ?? [];
                $parts_total = array_sum(array_column($parts, 'total_price'));
                $status_class = $booking['status'] == 'completed' ? 'status-completed' : 'status-cancelled';
                $display_date = $booking['completed_date'] ?? $booking['booking_date'];
            ?>
                <!-- History Card - Smaller, No Icon, No Parts Preview -->
                <div class="history-card" data-status="<?php echo $booking['status']; ?>">
                    <div class="card-header">
                        <div>
                            <span class="booking-id">#<?php echo str_pad($booking['id'], 6, '0', STR_PAD_LEFT); ?></span>
                            <?php if ($booking['original_date']): ?>
                                <span class="reschedule-badge" style="background: #fef3c7; color: #92400e; padding: 0.2rem 0.5rem; border-radius: 20px; font-size: 0.6rem; margin-left: 0.3rem;">
                                    <i class="bi bi-arrow-repeat"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                        <span class="status-badge <?php echo $status_class; ?>">
                            <?php echo ucfirst($booking['status']); ?>
                        </span>
                    </div>
                    
                    <div class="card-body">
                        <!-- Vehicle Info - No Icon -->
                        <div class="vehicle-info">
                            <div class="vehicle-details">
                                <h4><?php echo htmlspecialchars($booking['brand_name'] . ' ' . $booking['model']); ?></h4>
                                <span class="vehicle-plate"><?php echo htmlspecialchars($booking['number_plate']); ?></span>
                            </div>
                            <div class="text-end">
                                <div class="info-value" style="font-size: 0.7rem; color: #64748b;">
                                    <?php echo $booking['year']; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Info Grid -->
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-tools"></i> Service</div>
                                <div class="info-value"><?php echo htmlspecialchars($booking['service_name']); ?></div>
                            </div>
                            
                            <div class="info-item">
                                <div class="info-label"><i class="bi bi-calendar"></i> Date</div>
                                <div class="info-value"><?php echo date('M d, Y', strtotime($display_date)); ?></div>
                            </div>
                        </div>
                        
                        <!-- Total Amount -->
                        <div class="total-amount">
                            <span class="total-label">Total Amount:</span>
                            <span class="total-value">RM <?php echo number_format($parts_total, 2); ?></span>
                        </div>
                        
                        <!-- View Details Button -->
                        <button class="btn-view" onclick="showHistoryDetails(<?php echo $booking['id']; ?>)">
                            <i class="bi bi-eye"></i> View Details
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

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
    <a href="track-service.php" class="nav-item">
        <i class="bi bi-activity"></i>
        <span>Track</span>
    </a>
    <a href="profile.php" class="nav-item">
        <i class="bi bi-person"></i>
        <span>Profile</span>
    </a>
</div>

<!-- History Details Modals -->
<?php foreach ($bookings as $booking): 
    $parts = $parts_by_booking[$booking['id']] ?? [];
    $parts_total = array_sum(array_column($parts, 'total_price'));
    $display_date = $booking['completed_date'] ?? $booking['booking_date'];
?>
    <div class="modal fade" id="historyModal<?php echo $booking['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header <?php echo $booking['status'] == 'completed' ? 'bg-success' : 'bg-danger'; ?>">
                    <h5 class="modal-title">
                        <i class="bi bi-clock-history"></i>
                        Service Details - #<?php echo str_pad($booking['id'], 6, '0', STR_PAD_LEFT); ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Status Badge -->
                    <div class="text-center mb-3">
                        <span class="status-badge <?php echo $booking['status'] == 'completed' ? 'status-completed' : 'status-cancelled'; ?> p-2" style="font-size: 0.85rem;">
                            <?php if ($booking['status'] == 'completed'): ?>
                                <i class="bi bi-check-circle"></i> Service Completed
                            <?php else: ?>
                                <i class="bi bi-x-circle"></i> Booking Cancelled
                            <?php endif; ?>
                        </span>
                    </div>
                    
                    <!-- Vehicle Details with Email & Phone -->
<div class="info-grid">
    <div class="info-item">
        <div class="info-label">Vehicle</div>
        <div class="info-value"><?php echo htmlspecialchars($booking['brand_name'] . ' ' . $booking['model']); ?></div>
    </div>
    <div class="info-item">
        <div class="info-label">Service Type</div>
        <div class="info-value"><?php echo htmlspecialchars($booking['service_name']); ?></div>
    </div>
    <div class="info-item">
        <div class="info-label">Year / Color</div>
        <div class="info-value"><?php echo $booking['year']; ?> • <?php echo htmlspecialchars($booking['color']); ?></div>
    </div>
    <div class="info-item">
        <div class="info-label">Number Plate</div>
        <div class="info-value"><?php echo htmlspecialchars($booking['number_plate']); ?></div>
    </div>
    <div class="info-item">
        <div class="info-label">Service Date</div>
        <div class="info-value"><?php echo date('M d, Y', strtotime($display_date)); ?></div>
        <?php if ($booking['original_date']): ?>
            <div class="original-date mt-1" style="font-size: 0.65rem; color: #64748b; text-decoration: line-through;">
                Originally: <?php echo date('M d, Y', strtotime($booking['original_date'])); ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="info-item">
        <div class="info-label"><i class="bi bi-envelope"></i> Email</div>
        <div class="info-value" style="font-size: 0.7rem; word-break: break-all;"><?php echo htmlspecialchars($email); ?></div>
    </div>
    <div class="info-item">
        <div class="info-label"><i class="bi bi-telephone"></i> Phone</div>
        <div class="info-value"><?php echo htmlspecialchars($customer_phone); ?></div>
    </div>
</div>
                    
                    <!-- Parts Used with Warranty Column -->
<?php if (!empty($parts)): ?>
    <h6 class="fw-bold mt-3 mb-2 small"><i class="bi bi-gear"></i> Parts Used</h6>
    <div class="table-responsive">
        <table class="table table-bordered table-sm">
            <thead class="table-light">
                <tr>
                    <th><small>Part</small></th>
                    <th><small>Units</small></th>
                    <th><small>Price</small></th>
                    <th><small>Total</small></th>
                    <th><small>Warranty</small></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($parts as $part): ?>
                    <tr>
                        <td><small><?php echo htmlspecialchars($part['part_name']); ?></small></td>
                        <td><small><?php echo $part['quantity']; ?></small></td>
                        <td><small>RM <?php echo number_format($part['unit_price'], 2); ?></small></td>
                        <td><small>RM <?php echo number_format($part['total_price'], 2); ?></small></td>
                        <td>
                            <?php if (!empty($part['warranty_months'])): ?>
                                <small>
                                    <i class="bi bi-shield-check text-success"></i> 
                                    <?php echo $part['warranty_months']; ?> months
                                    <?php if (!empty($part['warranty_info'])): ?>
                                        : <span class="text-muted"><?php echo htmlspecialchars($part['warranty_info']); ?></span>
                                    <?php endif; ?>
                                </small>
                            <?php else: ?>
                                <small class="text-muted">-</small>
                            <?php endif; ?>
                        </div>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-primary">
                <tr>
                    <th colspan="3" class="text-end"><small>TOTAL:</small></th>
                    <th><small>RM <?php echo number_format($parts_total, 2); ?></small></th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>
<?php endif; ?>
                    
                    <!-- Service Notes -->
                    <?php if (!empty($booking['admin_notes']) && $booking['status'] == 'completed'): ?>
                        <div class="mt-3 p-3 bg-light rounded border-start border-3 border-success">
                            <div class="fw-bold mb-1 small text-success">Service Notes</div>
                            <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($booking['admin_notes'])); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Customer Remarks -->
                    <?php if (!empty($booking['remarks'])): ?>
                        <div class="mt-3 p-2 bg-light rounded">
                            <div class="fw-bold mb-1 small">Your Remarks</div>
                            <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($booking['remarks'])); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    <?php if ($booking['status'] == 'completed'): ?>
                        <button type="button" class="btn btn-danger btn-sm" onclick="generateServicePDF(<?php echo $booking['id']; ?>)">
                            <i class="bi bi-file-pdf"></i> Download Invoice
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<!-- ================= SCRIPTS ================= -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
    function showHistoryDetails(bookingId) {
        const modal = new bootstrap.Modal(document.getElementById('historyModal' + bookingId));
        modal.show();
    }
    
function filterHistory(status, button) {
    // Update active button
    document.querySelectorAll('.filter-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    button.classList.add('active');
    
    // Filter cards (only 'all' filter needed since all are completed)
    const cards = document.querySelectorAll('.history-card');
    cards.forEach(card => {
        if (status === 'all') {
            card.style.display = 'block';
        }
    });
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
    const cards = document.querySelectorAll('.history-card, .btn-view, .filter-btn');
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
    
    function generateServicePDF(bookingId) {
    try {
        const { jsPDF } = window.jspdf;
        
        const modal = document.getElementById('historyModal' + bookingId);
        if (!modal) {
            alert('Booking data not found');
            return;
        }
        
        let bookingNumber = '';
        let vehicleName = '';
        let vehiclePlate = '';
        let vehicleYear = '';
        let vehicleColor = '';
        let serviceName = '';
        let serviceDate = '';
        let totalAmount = 0;
        let parts = [];
        let adminNotes = '';
        let customerRemarks = '';
        let customerPhone = '<?php echo $customer_phone; ?>';
        let customerEmail = '<?php echo $email; ?>';
        let customerName = '<?php echo $full_name; ?>';
        
        const modalTitle = modal.querySelector('.modal-title');
        if (modalTitle) {
            const titleText = modalTitle.textContent;
            const match = titleText.match(/#(\d+)/);
            if (match) bookingNumber = match[1];
        }
        
        // Get all info items from the modal
        const infoItems = modal.querySelectorAll('.info-item');
        infoItems.forEach(item => {
            const labelElement = item.querySelector('.info-label');
            const label = labelElement?.textContent?.trim() || '';
            const valueElement = item.querySelector('.info-value');
            const value = valueElement?.textContent?.trim() || '';
            
            if (label === 'Vehicle') vehicleName = value;
            if (label === 'Number Plate') vehiclePlate = value;
            if (label === 'Year / Color') {
                const splitData = value.split('•');
                vehicleYear = splitData[0]?.trim() || '';
                vehicleColor = splitData[1]?.trim() || '';
            }
            // FIXED: Service Type fetching
            if (label === 'Service Type') {
                serviceName = value;
            }
            if (label === 'Service Date') {
                serviceDate = value;
            }
        });
        
        // Fallback: Look for service name in modal body if not found
        if (!serviceName) {
            const modalBody = modal.querySelector('.modal-body');
            if (modalBody) {
                const allText = modalBody.innerText;
                const serviceMatch = allText.match(/Service Type\s+([^\n]+)/);
                if (serviceMatch && serviceMatch[1]) {
                    serviceName = serviceMatch[1].trim();
                }
            }
        }
        
        // Get parts from table
        const tableRows = modal.querySelectorAll('table tbody tr');
        tableRows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length >= 5) {
                const partTotal = parseFloat(cells[3]?.textContent?.replace('RM', '').trim()) || 0;
                let warrantyText = cells[4]?.textContent?.trim() || '-';
                warrantyText = warrantyText.replace(/\s+/g, ' ').trim();
                
                parts.push({
                    name: cells[0]?.textContent?.trim() || '',
                    qty: cells[1]?.textContent?.trim() || '1',
                    price: parseFloat(cells[2]?.textContent?.replace('RM', '').trim()) || 0,
                    total: partTotal,
                    warranty: warrantyText
                });
                totalAmount += partTotal;
            }
        });
        
        // Get service notes
        const notesDiv = modal.querySelector('.border-start.border-success');
        if (notesDiv) {
            adminNotes = notesDiv.querySelector('p')?.textContent || '';
        }
        
        // Get customer remarks
        const remarksDiv = modal.querySelector('.bg-light.rounded:not(.border-start)');
        if (remarksDiv && remarksDiv.querySelector('.fw-bold')?.textContent.includes('Your Remarks')) {
            customerRemarks = remarksDiv.querySelector('p')?.textContent || '';
        }
        
        const now = new Date();
        const currentDate = now.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });
        const currentTime = now.toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit'
        });
        
        const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
        const pageWidth = doc.internal.pageSize.getWidth();
        const pageHeight = doc.internal.pageSize.getHeight();
        
        const navy = [15, 23, 42];
        const blue = [37, 99, 235];
        const gray = [100, 116, 139];
        const lightGray = [241, 245, 249];
        const green = [5, 150, 105];
        
        // Header
        doc.setFillColor(navy[0], navy[1], navy[2]);
        doc.rect(0, 0, pageWidth, 45, 'F');
        
        doc.setFillColor(blue[0], blue[1], blue[2]);
        doc.circle(28, 22, 10, 'F');
        doc.setTextColor(255, 255, 255);
        doc.setFontSize(14);
        doc.setFont(undefined, 'bold');
        doc.text('CSK', 21.5, 24);
        
        doc.setTextColor(255, 255, 255);
        doc.setFontSize(20);
        doc.setFont(undefined, 'bold');
        doc.text('CS KUMARESAN MOTOR', 45, 18);
        doc.setFontSize(8);
        doc.setFont(undefined, 'normal');
        doc.text('Professional Automotive Service & Repair', 45, 24);
        doc.text('5, Taman Bunga Matahari, 32400 Ayer Tawar, Perak', 45, 30);
        doc.text('Phone: +60 12-528 9073', 45, 35);
        
        doc.setFillColor(255, 255, 255);
        doc.roundedRect(pageWidth - 58, 12, 42, 18, 4, 4, 'F');
        doc.setTextColor(navy[0], navy[1], navy[2]);
        doc.setFontSize(12);
        doc.setFont(undefined, 'bold');
        doc.text('INVOICE', pageWidth - 37, 20, { align: 'center' });
        doc.setFontSize(8);
        doc.setFont(undefined, 'normal');
        doc.text(`#INV-${String(bookingNumber).padStart(6, '0')}`, pageWidth - 37, 25, { align: 'center' });
        
        // Vehicle & Customer Information Section
        let startY = 55;
        doc.setFillColor(lightGray[0], lightGray[1], lightGray[2]);
        doc.roundedRect(15, startY, pageWidth - 30, 45, 4, 4, 'F');
        
        doc.setFont(undefined, 'bold');
        doc.setFontSize(10);
        doc.setTextColor(navy[0], navy[1], navy[2]);
        doc.text('Vehicle Information', 22, startY + 8);
        
        doc.setFont(undefined, 'normal');
        doc.setFontSize(8);
        doc.setTextColor(gray[0], gray[1], gray[2]);
        doc.text(`Vehicle: ${vehicleName || 'N/A'}`, 22, startY + 16);
        doc.text(`Plate Number: ${vehiclePlate || 'N/A'}`, 22, startY + 22);
        doc.text(`Year: ${vehicleYear || 'N/A'}`, 22, startY + 28);
        doc.text(`Customer: ${customerName || 'N/A'}`, 22, startY + 34);
        
        doc.setFont(undefined, 'bold');
        doc.setFontSize(10);
        doc.setTextColor(navy[0], navy[1], navy[2]);
        doc.text('Booking Details', 120, startY + 8);
        
        doc.setFont(undefined, 'normal');
        doc.setFontSize(8);
        doc.setTextColor(gray[0], gray[1], gray[2]);
        doc.text(`Booking ID: #${bookingNumber}`, 120, startY + 16);
        doc.text(`Service Type: ${serviceName || 'N/A'}`, 120, startY + 22);
        doc.text(`Service Date: ${serviceDate || 'N/A'}`, 120, startY + 28);
        doc.text(`Phone: ${customerPhone || 'N/A'}`, 120, startY + 34);
        doc.text(`Email: ${customerEmail || 'N/A'}`, 120, startY + 40);
        
        startY += 60;
        
        // Parts Table
        doc.setFont(undefined, 'bold');
        doc.setFontSize(11);
        doc.setTextColor(navy[0], navy[1], navy[2]);
        doc.text('Service Parts & Charges', 15, startY);
        
        const tableX = 15;
        const tableWidth = pageWidth - 30;
        const col1 = 70;
        const col2 = 15;
        const col3 = 30;
        const col4 = 28;
        const col5 = 45;
        
        startY += 8;
        doc.setFillColor(blue[0], blue[1], blue[2]);
        doc.rect(tableX, startY, tableWidth, 10, 'F');
        doc.setDrawColor(255, 255, 255);
        doc.rect(tableX, startY, tableWidth, 10);
        doc.line(tableX + col1, startY, tableX + col1, startY + 10);
        doc.line(tableX + col1 + col2, startY, tableX + col1 + col2, startY + 10);
        doc.line(tableX + col1 + col2 + col3, startY, tableX + col1 + col2 + col3, startY + 10);
        doc.line(tableX + col1 + col2 + col3 + col4, startY, tableX + col1 + col2 + col3 + col4, startY + 10);
        
        doc.setTextColor(255, 255, 255);
        doc.setFontSize(7);
        doc.setFont(undefined, 'bold');
        doc.text('Description', tableX + 3, startY + 6.5);
        doc.text('Qty', tableX + col1 + 3, startY + 6.5);
        doc.text('Unit Price (RM)', tableX + col1 + col2 + 2, startY + 6.5);
        doc.text('Total (RM)', tableX + col1 + col2 + col3 + 2, startY + 6.5);
        doc.text('Warranty', tableX + col1 + col2 + col3 + col4 + 2, startY + 6.5);
        
        let rowY = startY + 10;
        parts.forEach((part, index) => {
            if (index % 2 === 0) {
                doc.setFillColor(248, 250, 252);
            } else {
                doc.setFillColor(255, 255, 255);
            }
            doc.rect(tableX, rowY, tableWidth, 10, 'F');
            doc.setDrawColor(220, 220, 220);
            doc.rect(tableX, rowY, tableWidth, 10);
            doc.line(tableX + col1, rowY, tableX + col1, rowY + 10);
            doc.line(tableX + col1 + col2, rowY, tableX + col1 + col2, rowY + 10);
            doc.line(tableX + col1 + col2 + col3, rowY, tableX + col1 + col2 + col3, rowY + 10);
            doc.line(tableX + col1 + col2 + col3 + col4, rowY, tableX + col1 + col2 + col3 + col4, rowY + 10);
            
            doc.setTextColor(40, 40, 40);
            doc.setFont(undefined, 'normal');
            doc.setFontSize(7);
            
            const shortName = part.name.length > 35 ? part.name.substring(0, 32) + '...' : part.name;
            let warrantyDisplay = part.warranty;
            if (warrantyDisplay && warrantyDisplay !== '-' && warrantyDisplay !== '') {
                if (warrantyDisplay.length > 25) {
                    warrantyDisplay = warrantyDisplay.substring(0, 22) + '...';
                }
            } else {
                warrantyDisplay = '-';
            }
            
            doc.text(shortName, tableX + 3, rowY + 6.5);
            doc.text(part.qty.toString(), tableX + col1 + 5, rowY + 6.5);
            doc.text(`RM ${part.price.toFixed(2)}`, tableX + col1 + col2 + 2, rowY + 6.5);
            doc.text(`RM ${part.total.toFixed(2)}`, tableX + col1 + col2 + col3 + 2, rowY + 6.5);
            doc.text(warrantyDisplay, tableX + col1 + col2 + col3 + col4 + 3, rowY + 6.5);
            
            rowY += 10;
            
            if (rowY > 250) {
                doc.addPage();
                rowY = 30;
                doc.setFillColor(blue[0], blue[1], blue[2]);
                doc.rect(tableX, rowY, tableWidth, 10, 'F');
                doc.setDrawColor(255, 255, 255);
                doc.rect(tableX, rowY, tableWidth, 10);
                doc.line(tableX + col1, rowY, tableX + col1, rowY + 10);
                doc.line(tableX + col1 + col2, rowY, tableX + col1 + col2, rowY + 10);
                doc.line(tableX + col1 + col2 + col3, rowY, tableX + col1 + col2 + col3, rowY + 10);
                doc.line(tableX + col1 + col2 + col3 + col4, rowY, tableX + col1 + col2 + col3 + col4, rowY + 10);
                doc.setTextColor(255, 255, 255);
                doc.text('Description', tableX + 3, rowY + 6.5);
                doc.text('Qty', tableX + col1 + 3, rowY + 6.5);
                doc.text('Unit Price (RM)', tableX + col1 + col2 + 2, rowY + 6.5);
                doc.text('Total (RM)', tableX + col1 + col2 + col3 + 2, rowY + 6.5);
                doc.text('Warranty', tableX + col1 + col2 + col3 + col4 + 2, rowY + 6.5);
                rowY += 10;
            }
        });
        
        rowY += 8;
        doc.setFillColor(navy[0], navy[1], navy[2]);
        doc.roundedRect(pageWidth - 80, rowY, 65, 18, 3, 3, 'F');
        doc.setTextColor(255, 255, 255);
        doc.setFontSize(9);
        doc.setFont(undefined, 'normal');
        doc.text('Grand Total', pageWidth - 72, rowY + 7);
        doc.setFontSize(14);
        doc.setFont(undefined, 'bold');
        doc.text(`RM ${totalAmount.toFixed(2)}`, pageWidth - 22, rowY + 13, { align: 'right' });
        
        rowY += 28;
        
        if (adminNotes) {
            doc.setFillColor(240, 253, 244);
            doc.roundedRect(15, rowY, pageWidth - 30, 24, 3, 3, 'F');
            doc.setTextColor(green[0], green[1], green[2]);
            doc.setFont(undefined, 'bold');
            doc.setFontSize(8);
            doc.text('Service Notes', 20, rowY + 7);
            doc.setTextColor(70, 70, 70);
            doc.setFont(undefined, 'normal');
            const wrappedNotes = doc.splitTextToSize(adminNotes, pageWidth - 40);
            doc.text(wrappedNotes, 20, rowY + 14);
            rowY += 32;
        }
        
        if (customerRemarks && customerRemarks !== 'No remarks') {
            doc.setFillColor(248, 250, 252);
            doc.roundedRect(15, rowY, pageWidth - 30, 24, 3, 3, 'F');
            doc.setTextColor(navy[0], navy[1], navy[2]);
            doc.setFont(undefined, 'bold');
            doc.setFontSize(8);
            doc.text('Customer Remarks', 20, rowY + 7);
            doc.setTextColor(70, 70, 70);
            doc.setFont(undefined, 'normal');
            const wrappedRemarks = doc.splitTextToSize(customerRemarks, pageWidth - 40);
            doc.text(wrappedRemarks, 20, rowY + 14);
            rowY += 32;
        }
        
        doc.text(`Generated: ${currentDate} ${currentTime}`, pageWidth - 65, rowY + 8);
        
        const footerY = pageHeight - 18;
        doc.setDrawColor(220, 220, 220);
        doc.line(15, footerY - 5, pageWidth - 15, footerY - 5);
        doc.setTextColor(120, 120, 120);
        doc.setFontSize(6);
        doc.setFont(undefined, 'normal');
        doc.text('Thank you for choosing CS KUMARESAN MOTOR', pageWidth / 2, footerY, { align: 'center' });
        doc.text('This is a computer-generated invoice. No signature required.', pageWidth / 2, footerY + 5, { align: 'center' });
        
        doc.save(`Service_Invoice_${String(bookingNumber).padStart(6, '0')}.pdf`);
        
    } catch (error) {
        console.error('PDF Generation Error:', error);
        alert('Error generating PDF. Please try again.');
    }
}

// Search functionality for service history
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const cards = document.querySelectorAll('.history-card');
            
            cards.forEach(card => {
                const cardText = card.textContent.toLowerCase();
                if (searchTerm === '' || cardText.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    }
});
</script>

</body>
</html>
