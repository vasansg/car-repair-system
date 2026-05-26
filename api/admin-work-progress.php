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
$full_name  = $_SESSION['full_name'];
$email      = $_SESSION['email'];
$first_name = explode(' ', $full_name)[0];

/* ================= DATABASE CONNECTION ================= */
require_once __DIR__ . '/includes/config.php';

/* ================= HANDLE ACTIONS ================= */
$message      = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $booking_id    = trim($_POST['booking_id']);
    $technician_id = trim($_POST['technician_id'] ?? '');
    $redirect      = false;
    $now           = gmdate('Y-m-d\TH:i:s\Z');

    switch ($_POST['action']) {
        case 'assign_technician':
            $sp = $firebase->getFirst('service_progress', [['booking_id', '==', $booking_id]]);
            if ($sp) {
                $firebase->updateDoc('service_progress', $sp['id'], ['technician_id' => $technician_id]);
            } else {
                $firebase->addDoc('service_progress', [
                    'booking_id'    => $booking_id,
                    'technician_id' => $technician_id,
                    'status'        => 'pending',
                    'started_at'    => null,
                    'completed_at'  => null,
                    'created_at'    => $now,
                ]);
            }
            $firebase->addDoc('booking_updates', [
                'booking_id'              => $booking_id,
                'technician_id'           => '',
                'message'                 => 'Technician assigned',
                'update_type'             => 'info',
                'is_visible_to_customer'  => true,
                'created_at'              => $now,
            ]);
            $_SESSION['message']      = "Technician assigned successfully!";
            $_SESSION['message_type'] = 'success';
            $redirect = true;
            break;

        case 'start_progress':
            $firebase->updateDoc('bookings', $booking_id, ['status' => 'repairing', 'updated_at' => $now]);
            $sp = $firebase->getFirst('service_progress', [['booking_id', '==', $booking_id]]);
            if ($sp) {
                $firebase->updateDoc('service_progress', $sp['id'], ['status' => 'in_progress', 'started_at' => $now]);
            }
            $firebase->addDoc('booking_updates', [
                'booking_id'             => $booking_id,
                'technician_id'          => $technician_id,
                'message'                => 'Service work has started',
                'update_type'            => 'success',
                'is_visible_to_customer' => true,
                'created_at'             => $now,
            ]);
            $_SESSION['message']      = "Service started successfully!";
            $_SESSION['message_type'] = 'success';
            $redirect = true;
            break;

        case 'complete_booking':
            $firebase->updateDoc('bookings', $booking_id, [
                'status'         => 'completed',
                'completed_date' => gmdate('Y-m-d'),
                'updated_at'     => $now,
            ]);
            $sp = $firebase->getFirst('service_progress', [['booking_id', '==', $booking_id]]);
            if ($sp) {
                $firebase->updateDoc('service_progress', $sp['id'], ['status' => 'completed', 'completed_at' => $now]);
            }
            $firebase->addDoc('booking_updates', [
                'booking_id'             => $booking_id,
                'technician_id'          => '',
                'message'                => 'Service completed successfully!',
                'update_type'            => 'success',
                'is_visible_to_customer' => true,
                'created_at'             => $now,
            ]);
            // Update matching pending suggestions to 'done'
            $bDoc = $firebase->getDoc('bookings', $booking_id);
            if ($bDoc) {
                $sugs = $firebase->query('service_suggestions', [['vehicle_id', '==', $bDoc['vehicle_id'] ?? '']]);
                foreach ($sugs as $sug) {
                    if (($sug['service_category_id'] ?? '') === ($bDoc['service_category_id'] ?? '')
                        && in_array($sug['status'] ?? '', ['pending', 'booked', 'in_progress'])) {
                        $firebase->updateDoc('service_suggestions', $sug['id'], [
                            'status'          => 'done',
                            'completed_notes' => 'Completed on ' . date('d/m/Y') . ' (Booking #' . substr($booking_id, -6) . ')',
                            'updated_at'      => $now,
                        ]);
                    }
                }
            }
            $_SESSION['message']      = "Booking #" . substr($booking_id, -6) . " completed successfully!";
            $_SESSION['message_type'] = 'success';
            $redirect = true;
            break;

        case 'add_update':
            $update_message      = trim($_POST['update_message']);
            $update_type         = $_POST['update_type'];
            $visible_to_customer = isset($_POST['visible_to_customer']);
            $firebase->addDoc('booking_updates', [
                'booking_id'             => $booking_id,
                'technician_id'          => $technician_id,
                'message'                => $update_message,
                'update_type'            => $update_type,
                'is_visible_to_customer' => $visible_to_customer,
                'created_at'             => $now,
            ]);
            $_SESSION['message']      = "Update added successfully!";
            $_SESSION['message_type'] = 'success';
            $redirect = true;
            break;
    }

    if ($redirect) {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

if (isset($_SESSION['message'])) {
    $message      = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message'], $_SESSION['message_type']);
}

/* ================= FETCH IN-PROGRESS BOOKINGS ================= */
$technicians = $firebase->query('technicians', [['is_active', '==', true]], 'name', 'ASCENDING');
$techById    = array_combine(array_column($technicians, 'id'), $technicians);

$rawBookings = $firebase->query('bookings', [['status', '==', 'repairing']], 'booking_date', 'ASCENDING');

// Sort by date then time
usort($rawBookings, function ($a, $b) {
    $d = strcmp($a['booking_date'] ?? '', $b['booking_date'] ?? '');
    return $d !== 0 ? $d : strcmp($a['booking_time'] ?? '', $b['booking_time'] ?? '');
});

$bookings           = [];
$updates_by_booking = [];

foreach ($rawBookings as $booking) {
    // Merge service_progress
    $sp = $firebase->getFirst('service_progress', [['booking_id', '==', $booking['id']]]);
    $booking['assigned_technician_id'] = $sp['technician_id'] ?? null;
    $booking['progress_status']        = $sp['status'] ?? 'pending';
    $booking['started_at']             = $sp['started_at'] ?? null;

    // Fetch user phone (not denormalized in booking)
    $uDoc = $firebase->getDoc('users', $booking['user_id'] ?? '');
    $booking['customer_phone'] = $uDoc['phone'] ?? '';

    // Map denormalized fields to template-expected names
    $booking['customer_name']  = $booking['user_name'] ?? '';
    $booking['customer_email'] = $booking['user_email'] ?? '';
    $booking['brand_name']     = $booking['vehicle_brand'] ?? '';
    $booking['model']          = $booking['vehicle_model'] ?? '';
    $booking['year']           = $booking['vehicle_year'] ?? '';
    $booking['number_plate']   = $booking['vehicle_plate'] ?? '';
    $booking['service_name']   = $booking['service_category_name'] ?? '';

    // Fetch last 10 updates
    $updates = $firebase->query('booking_updates', [['booking_id', '==', $booking['id']]], 'created_at', 'DESCENDING', 10);
    foreach ($updates as &$u) {
        $tid             = $u['technician_id'] ?? '';
        $u['technician_name'] = !empty($tid) ? ($techById[$tid]['name'] ?? 'System') : 'System';
    }
    unset($u);
    $updates_by_booking[$booking['id']] = $updates;

    $bookings[] = $booking;
}

$repairing_count = count($bookings);
$assigned_count  = count(array_filter($bookings, fn($b) => !empty($b['assigned_technician_id'])));

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
    <title>Work Progress | CS KUMARESAN MOTOR</title>
    
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
        
        /* ================= MAIN CONTENT ================= */
        .main-container {
            padding: 1.5rem;
        }
        
        /* ================= STATS CARDS ================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .stat-icon {
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
        
        .stat-info h3 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }
        
        .stat-info p {
            font-size: 0.7rem;
            color: #64748b;
            margin: 0;
            text-transform: uppercase;
        }
        
        /* ================= WORK CARDS ================= */
        .work-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1rem;
        }
        
        .work-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .work-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
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
        }
        
        .status-repairing {
            background: #fed7aa;
            color: #9a3412;
        }
        
        .card-body {
            padding: 0.75rem 1rem;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
        }
        
        .info-label {
            width: 80px;
            color: #64748b;
        }
        
        .info-value {
            flex: 1;
            color: #1e293b;
            font-weight: 500;
        }
        
        .customer-details {
            background: #f8fafc;
            border-radius: 10px;
            padding: 0.6rem;
            margin: 0.5rem 0;
            border: 1px solid #e2e8f0;
        }
        
        .customer-name {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.2rem;
        }
        
        .tech-select {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.8rem;
            margin: 0.5rem 0;
        }
        
        .btn-primary {
            background: #1e40af;
            color: white;
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
            border: none;
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .progress-section {
            background: #fef3c7;
            border-radius: 10px;
            padding: 0.6rem;
            margin: 0.5rem 0;
            border-left: 3px solid #f59e0b;
        }
        
        .updates-section {
            background: #f8fafc;
            border-radius: 10px;
            padding: 0.6rem;
            margin-top: 0.5rem;
        }
        
        .update-item {
            padding: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.75rem;
        }
        
        .update-item:last-child {
            border-bottom: none;
        }
        
        .update-message {
            font-weight: 500;
            margin-bottom: 0.2rem;
        }
        
        .update-meta {
            font-size: 0.6rem;
            color: #64748b;
            display: flex;
            gap: 0.5rem;
            margin-top: 0.2rem;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .update-badge {
            padding: 0.15rem 0.4rem;
            border-radius: 20px;
            font-size: 0.6rem;
            font-weight: 600;
        }
        
        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-waiting {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-issue {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .visible-badge {
            background: #e2e8f0;
            color: #475569;
            padding: 0.15rem 0.4rem;
            border-radius: 4px;
            font-size: 0.6rem;
        }
        
        .confirm-modal .modal-content {
            border-radius: 16px;
        }
        
        .confirm-modal .modal-header {
            background: #10b981;
            color: white;
            border-bottom: none;
        }
        
        .alert {
            border-radius: 10px;
            padding: 0.6rem 1rem;
            font-size: 0.8rem;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .work-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr; }
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
    
    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-tools"></i></div>
            <div class="stat-info">
                <h3><?php echo $repairing_count; ?></h3>
                <p>In Progress</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-person-badge"></i></div>
            <div class="stat-info">
                <h3><?php echo $assigned_count; ?></h3>
                <p>Technicians Assigned</p>
            </div>
        </div>
    </div>
    
    <!-- Message Alert -->
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
            <i class="bi bi-<?php echo $message_type == 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
        
        </div>
    <?php endif; ?>
    
    <!-- Work Cards Grid -->
    <?php if (empty($bookings)): ?>
        <div class="text-center py-5">
            <i class="bi bi-clipboard-x" style="font-size: 3rem; color: #cbd5e1;"></i>
            <h5 class="mt-3">No Work Found</h5>
            <p class="text-muted">There are no in-progress tasks at the moment.</p>
        </div>
    <?php else: ?>
        <div class="work-grid">
            <?php foreach ($bookings as $booking): 
                $progress_updates = $updates_by_booking[$booking['id']] ?? [];
            ?>
                <div class="work-card">
                    <div class="card-header">
                        <span class="booking-id">#<?php echo substr($booking['id'], -6); ?></span>
                        <span class="status-badge status-repairing">In Progress</span>
                    </div>
                    
                    <div class="card-body">
                        <!-- Customer Info -->
                        <div class="customer-details">
                            <div class="customer-name">
                                <i class="bi bi-person-circle me-1"></i>
                                <?php echo htmlspecialchars($booking['customer_name']); ?>
                            </div>
                            <div class="small text-muted">
                                <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($booking['customer_phone']); ?>
                                <span class="mx-1">•</span>
                                <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($booking['customer_email']); ?>
                            </div>
                        </div>
                        
                        <!-- Vehicle & Service Info -->
                        <div class="info-row">
                            <div class="info-label">Vehicle:</div>
                            <div class="info-value"><?php echo htmlspecialchars($booking['brand_name'] . ' ' . $booking['model'] . ' (' . $booking['year'] . ')'); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Plate:</div>
                            <div class="info-value"><?php echo htmlspecialchars($booking['number_plate']); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Service:</div>
                            <div class="info-value"><?php echo htmlspecialchars($booking['service_name']); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Scheduled:</div>
                            <div class="info-value"><?php echo date('M d, Y', strtotime($booking['booking_date'])); ?> at <?php echo date('h:i A', strtotime($booking['booking_time'])); ?></div>
                        </div>
                        
                        <!-- Technician Assignment -->
                        <?php if (!$booking['assigned_technician_id']): ?>
                            <form method="POST" class="mt-2">
                                <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                <input type="hidden" name="action" value="assign_technician">
                                
                                <label class="small fw-bold mb-1">Assign Technician:</label>
                                <select name="technician_id" class="tech-select" required>
                                    <option value="">Select technician</option>
                                    <?php foreach ($technicians as $tech): ?>
                                        <option value="<?php echo $tech['id']; ?>">
                                            <?php echo htmlspecialchars($tech['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <button type="submit" class="btn-primary w-100 mt-2">
                                    <i class="bi bi-person-plus"></i> Assign Technician
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="mt-2 p-2 bg-light rounded small">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <i class="bi bi-person-check-fill text-primary me-1"></i>
                                        <strong>Technician:</strong>
                                        <?php
                                        $tech_name = $techById[$booking['assigned_technician_id']]['name'] ?? '';
                                        echo htmlspecialchars($tech_name);
                                        ?>
                                    </div>
                                    <form id="startForm<?php echo $booking['id']; ?>" method="POST" style="display: inline;">
                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                        <input type="hidden" name="technician_id" value="<?php echo $booking['assigned_technician_id']; ?>">
                                        <input type="hidden" name="action" value="start_progress">
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Progress Section -->
                        <div class="progress-section">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <div class="spinner-border spinner-border-sm text-warning" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <strong class="small">Work in Progress</strong>
                            </div>
                            
                            <!-- Complete Button -->
                            <button type="button" class="btn-success w-100" 
                                    onclick="openCompleteModal(<?php echo $booking['id']; ?>, '<?php echo addslashes($booking['service_name']); ?>')">
                                <i class="bi bi-check-circle"></i> Mark as Completed
                            </button>
                        </div>
                        
                        <!-- Updates Section -->
                        <div class="updates-section">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong class="small"><i class="bi bi-chat-dots"></i> Recent Updates</strong>
                                <?php if ($booking['assigned_technician_id']): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" 
                                            onclick="showUpdateModal(<?php echo $booking['id']; ?>, <?php echo $booking['assigned_technician_id']; ?>)"
                                            style="font-size: 0.7rem;">
                                        <i class="bi bi-plus-circle"></i> Add
                                    </button>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (empty($progress_updates)): ?>
                                <p class="text-muted small mb-0">No updates yet</p>
                            <?php else: ?>
                                <?php foreach ($progress_updates as $update): ?>
                                    <div class="update-item">
                                        <div class="update-message"><?php echo htmlspecialchars($update['message']); ?></div>
                                        <div class="update-meta">
                                            <span><i class="bi bi-person"></i> <?php echo htmlspecialchars($update['technician_name'] ?? 'System'); ?></span>
                                            <span><i class="bi bi-clock"></i> <?php echo date('M d, h:i A', strtotime($update['created_at'])); ?></span>
                                            <span class="update-badge badge-<?php echo $update['update_type']; ?>">
                                                <?php 
                                                if ($update['update_type'] == 'info') echo 'Information';
                                                elseif ($update['update_type'] == 'success') echo 'Complete';
                                                elseif ($update['update_type'] == 'waiting') echo 'Waiting';
                                                elseif ($update['update_type'] == 'issue') echo 'Issue';
                                                else echo ucfirst($update['update_type']);
                                                ?>
                                            </span>
                                            <?php if ($update['is_visible_to_customer']): ?>
                                                <span class="visible-badge"><i class="bi bi-eye"></i> Visible</span>
                                            <?php else: ?>
                                                <span class="visible-badge"><i class="bi bi-eye-slash"></i> Staff</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Update Modal -->
                <div class="modal fade" id="updateModal<?php echo $booking['id']; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <div class="modal-header bg-primary text-white">
                                    <h5 class="modal-title">Add Update - #<?php echo substr($booking['id'], -6); ?></h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small">Update Message</label>
                                        <textarea class="form-control" name="update_message" rows="2" required></textarea>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small">Update Type</label>
                                        <select class="form-select" name="update_type">
                                            <option value="info">Information</option>
                                            <option value="waiting">Waiting For Spare Part</option>
                                            <option value="issue">Issue/Problem</option>
                                        </select>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="visible_to_customer" id="visible<?php echo $booking['id']; ?>" checked>
                                        <label class="form-check-label small" for="visible<?php echo $booking['id']; ?>">Visible to customer</label>
                                    </div>
                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                    <input type="hidden" name="technician_id" value="<?php echo $booking['assigned_technician_id']; ?>">
                                    <input type="hidden" name="action" value="add_update">
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary btn-sm">Post Update</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Confirmation Modal for Complete Service -->
<div class="modal fade" id="completeConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST" id="completeConfirmForm">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="bi bi-check-circle"></i> Complete Service
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <i class="bi bi-question-circle" style="font-size: 3rem; color: #10b981;"></i>
                    <p class="mt-3 mb-0">Are you sure you want to mark this service as <strong>Completed</strong>?</p>
                    
                    <input type="hidden" name="booking_id" id="confirmBookingId">
                    <input type="hidden" name="action" value="complete_booking">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-check-circle"></i> Yes, Complete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    function startService(bookingId) {
        if (confirm('Start this service?')) {
            document.getElementById('startForm' + bookingId).submit();
        }
    }
    
    function openCompleteModal(bookingId, serviceName) {
        document.getElementById('confirmBookingId').value = bookingId;
        new bootstrap.Modal(document.getElementById('completeConfirmModal')).show();
    }
    
    function showUpdateModal(bookingId, technicianId) {
        if (!technicianId) {
            alert('Please assign a technician first');
            return;
        }
        new bootstrap.Modal(document.getElementById('updateModal' + bookingId)).show();
    }
</script>

</body>
</html>
