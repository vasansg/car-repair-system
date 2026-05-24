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

/* ================= HANDLE FORM SUBMISSIONS ================= */
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    
    // Add part via AJAX
    if (isset($_POST['add_part_ajax'])) {
        $booking_id = intval($_POST['booking_id']);
        $part_name = trim($_POST['part_name']);
        $quantity = intval($_POST['quantity']);
        $unit_price = floatval($_POST['unit_price']);
        $warranty_months = intval($_POST['warranty_months']);
        $warranty_info = trim($_POST['warranty_info']);
        
        $insert_sql = "INSERT INTO service_parts (booking_id, part_name, quantity, unit_price, warranty_months, warranty_info)
                       VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = $pdo->prepare($insert_sql);

        if ($insert_stmt->execute([$booking_id, $part_name, $quantity, $unit_price, $warranty_months, $warranty_info])) {
            $new_id = $pdo->lastInsertId();
            echo json_encode([
                'success' => true,
                'id' => $new_id,
                'part_name' => $part_name,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'total_price' => $quantity * $unit_price,
                'warranty_months' => $warranty_months,
                'warranty_info' => $warranty_info
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => '']);
        }
        exit();
    }
    
    // Handle delete via AJAX
    if (isset($_POST['delete_part_ajax'])) {
        $part_id = intval($_POST['part_id']);
        $booking_id = intval($_POST['booking_id']);
        
        $delete_sql = "DELETE FROM service_parts WHERE id = ? AND booking_id = ?";
        $delete_stmt = $pdo->prepare($delete_sql);

        if ($delete_stmt->execute([$part_id, $booking_id])) {
            echo json_encode(['success' => true, 'part_id' => $part_id]);
        } else {
            echo json_encode(['success' => false, 'error' => '']);
        }
        exit();
    }

    // Regular delete part (non-AJAX)
    if (isset($_POST['delete_part'])) {
        $part_id = intval($_POST['part_id']);
        $booking_id = intval($_POST['booking_id']);

        $delete_sql = "DELETE FROM service_parts WHERE id = ? AND booking_id = ?";
        $delete_stmt = $pdo->prepare($delete_sql);

        if ($delete_stmt->execute([$part_id, $booking_id])) {
            $message = "Part removed successfully!";
            $message_type = 'success';
        }
    }
}

/* ================= FETCH COMPLETED BOOKINGS ================= */
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
            sc.base_price as service_price,
            (SELECT SUM(total_price) FROM service_parts WHERE booking_id = b.id) as parts_total
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        JOIN vehicles v ON b.vehicle_id = v.id
        JOIN service_categories sc ON b.service_category_id = sc.id
        WHERE b.status = 'completed'
        ORDER BY b.completed_date DESC, b.id DESC";

$result = $pdo->query($sql);
if ($result) {
    $bookings = $result->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch parts for each booking
$parts_by_booking = [];

foreach ($bookings as $booking) {
    // Fetch parts
    $parts_sql = "SELECT * FROM service_parts WHERE booking_id = ? ORDER BY id";
    $parts_stmt = $pdo->prepare($parts_sql);
    $parts_stmt->execute([$booking['id']]);
    $parts_by_booking[$booking['id']] = $parts_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch service updates for timeline
$updates_by_booking = [];
foreach ($bookings as $booking) {
    $update_sql = "SELECT u.*, t.name as technician_name 
                   FROM service_updates u
                   LEFT JOIN technicians t ON u.technician_id = t.id
                   WHERE u.booking_id = ?
                   ORDER BY u.created_at ASC";
    $update_stmt = $pdo->prepare($update_sql);
    $update_stmt->execute([$booking['id']]);
    $updates_by_booking[$booking['id']] = $update_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get statistics
$stats = [
    'total_completed' => count($bookings),
    'total_revenue' => 0,
    'avg_service_cost' => 0
];

foreach ($bookings as $booking) {
    $stats['total_revenue'] += ($booking['final_price'] ?? 0);
}
if ($stats['total_completed'] > 0) {
    $stats['avg_service_cost'] = $stats['total_revenue'] / $stats['total_completed'];
}

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
    <title>Service History | CS KUMARESAN MOTOR</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    
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
            grid-template-columns: repeat(3, 1fr);
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
        
        /* ================= SEARCH BAR ================= */
        .search-section {
            margin-bottom: 1rem;
        }
        
        .search-box {
            position: relative;
            max-width: 300px;
        }
        
        .search-box i {
            position: absolute;
            left: 0.8rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }
        
        .search-box input {
            width: 100%;
            padding: 0.5rem 0.8rem 0.5rem 2rem;
            border: 1px solid #e2e8f0;
            border-radius: 30px;
            font-size: 0.8rem;
        }
        
        .search-box input:focus {
            border-color: #1e40af;
            outline: none;
        }
        
        /* ================= MAIN CARD ================= */
        .main-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.75rem 1rem;
        }
        
        .card-header h2 {
            font-size: 1rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* ================= TABLE ================= */
        .table {
            margin: 0;
            font-size: 0.8rem;
        }
        
        .table thead th {
            background: #f8fafc;
            padding: 0.6rem 0.8rem;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .table tbody td {
            padding: 0.6rem 0.8rem;
            vertical-align: middle;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .table tbody tr:hover {
            background: #f8fafc;
        }
        
        /* DataTables Customization */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            padding: 0.75rem 1rem;
        }
        
        .dataTables_wrapper .dataTables_length {
            float: left;
            text-decoration: none !important;
        }
        
        .dataTables_wrapper .dataTables_filter {
            float: right;
            text-decoration: none !important;
        }
        
        .dataTables_wrapper .dataTables_info {
            float: left;
            clear: both;
            text-decoration: none !important;
        }
        
        .dataTables_wrapper .dataTables_paginate {
            float: right;
            text-decoration: none !important;
        }
        
        .dataTables_wrapper .paginate_button {
            padding: 0.3rem 0.7rem;
            margin: 0 0.2rem;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            background: white;
            color: #475569;
            cursor: pointer;
            text-decoration: none !important;
        }
        
        .dataTables_wrapper .paginate_button.current {
            background: #1e40af;
            color: white;
            border-color: #1e40af;
            text-decoration: none !important;
        }
        
        .dataTables_wrapper .paginate_button:hover {
            background: #f1f5f9;
            text-decoration: none !important;
        }
        
        .booking-id {
            font-weight: 700;
            color: #1e40af;
        }
        
        .customer-name {
            font-weight: 600;
        }
        
        .customer-phone {
            font-size: 0.7rem;
            color: #64748b;
        }
        
        .vehicle-model {
            font-weight: 500;
        }
        
        .vehicle-plate {
            font-size: 0.65rem;
            color: #64748b;
        }
        
        /* Action Buttons */
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
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-action:hover {
            transform: scale(1.05);
        }
        
        .btn-invoice {
            background: #d1fae5;
            color: #059669;
        }
        
        .btn-timeline {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .btn-pdf {
            background: #fee2e2;
            color: #dc2626;
        }
        
        /* Modal Styles */
        .modal-content {
            border-radius: 16px;
        }
        
        .modal-header {
            padding: 0.75rem 1rem;
        }
        
        .modal-header.bg-success {
            background: linear-gradient(135deg, #059669, #10b981) !important;
        }
        
        .modal-header.bg-info {
            background: linear-gradient(135deg, #1e40af, #3b82f6) !important;
        }
        
        .modal-title {
            font-weight: 600;
            font-size: 1rem;
        }
        
        .modal-body {
            padding: 1rem;
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .modal-footer {
            padding: 0.75rem 1rem;
            border-top: 1px solid #e2e8f0;
        }
        
        .add-part-container {
            background: #f8fafc;
            border-radius: 10px;
            padding: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .invoice-table {
            width: 100%;
            font-size: 0.75rem;
        }
        
        .invoice-table th {
            background: #f1f5f9;
            padding: 0.5rem;
        }
        
        .invoice-table td {
            padding: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .timeline {
            position: relative;
            padding-left: 1.5rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 6px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 1rem;
        }
        
        .timeline-dot {
            position: absolute;
            left: -1.5rem;
            top: 0.2rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 0 0 1px #e2e8f0;
        }
        
        .timeline-dot.info { background: #3b82f6; }
        .timeline-dot.waiting { background: #f59e0b; }
        .timeline-dot.issue { background: #ef4444; }
        .timeline-dot.complete { background: #10b981; }
        
        .timeline-content {
            background: #f8fafc;
            padding: 0.6rem;
            border-radius: 10px;
        }
        
        .timeline-message {
            font-weight: 500;
            font-size: 0.75rem;
            margin-bottom: 0.2rem;
        }
        
        .timeline-meta {
            display: flex;
            gap: 0.5rem;
            font-size: 0.6rem;
            color: #64748b;
        }
        
        .alert {
            border-radius: 10px;
            padding: 0.5rem 0.8rem;
            font-size: 0.75rem;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 768px) {
            .nav-container {
                flex-direction: column;
                align-items: flex-start;
            }
            .dataTables_wrapper .dataTables_length,
            .dataTables_wrapper .dataTables_filter,
            .dataTables_wrapper .dataTables_info,
            .dataTables_wrapper .dataTables_paginate {
                float: none;
                text-align: center;
            }
            .search-box {
                max-width: 100%;
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
    
    <!-- Message Alert -->
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
            <i class="bi bi-<?php echo $message_type == 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    
    <!-- Search Section -->
    <div class="search-section">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" id="searchInput" placeholder="Search by ID, customer, vehicle...">
        </div>
    </div>
    
    <!-- Main Card -->
    <div class="main-card">
        <div class="card-header">
            <h2><i class="bi bi-clock-history"></i> Completed Services</h2>
        </div>
        
        <div class="table-responsive">
            <table class="table" id="historyTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Vehicle</th>
                        <th>Service</th>
                        <th>Completed Date</th>
                        <th>Total</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $booking): 
                        $parts_total = $parts_by_booking[$booking['id']] ? array_sum(array_column($parts_by_booking[$booking['id']], 'total_price')) : 0;
                    ?>
                        <tr>
                            <td><span class="booking-id">#<?php echo str_pad($booking['id'], 6, '0', STR_PAD_LEFT); ?></span></td>
                            <td>
                                <div class="customer-name"><?php echo htmlspecialchars($booking['customer_name']); ?></div>
                                <div class="customer-phone"><?php echo htmlspecialchars($booking['customer_phone']); ?></div>
                            </div>
                            <td>
                                <div class="vehicle-model"><?php echo htmlspecialchars($booking['brand_name'] . ' ' . $booking['model']); ?></div>
                                <div class="vehicle-plate"><?php echo htmlspecialchars($booking['number_plate']); ?></div>
                            </div>
                            <td><?php echo htmlspecialchars($booking['service_name']); ?></div>
                            <td><?php echo date('M d, Y', strtotime($booking['completed_date'] ?? $booking['booking_date'])); ?></div>
                            <td><strong>RM <?php echo number_format($parts_total, 2); ?></strong></div>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-action btn-invoice" onclick="showInvoice(<?php echo $booking['id']; ?>)" title="Invoice">
                                        <i class="bi bi-receipt"></i>
                                    </button>
                                    <button class="btn-action btn-timeline" onclick="showTimeline(<?php echo $booking['id']; ?>)" title="Timeline">
                                        <i class="bi bi-clock-history"></i>
                                    </button>
                                    <button class="btn-action btn-pdf" onclick="generateAdminInvoicePDF(<?php echo $booking['id']; ?>)" title="PDF">
                                        <i class="bi bi-file-pdf"></i>
                                    </button>
                                </div>
                            </div>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (empty($bookings)): ?>
            <div class="text-center py-4">
                <i class="bi bi-archive" style="font-size: 2rem; color: #cbd5e1;"></i>
                <p class="mt-2 text-muted">No service history found</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ================= INVOICE MODALS ================= -->
<?php foreach ($bookings as $booking): 
    $parts = $parts_by_booking[$booking['id']] ?? [];
    $parts_total = array_sum(array_column($parts, 'total_price'));
?>
    <!-- Invoice Modal -->
    <div class="modal fade" id="invoiceModal<?php echo $booking['id']; ?>" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-receipt"></i> Invoice - #<?php echo str_pad($booking['id'], 6, '0', STR_PAD_LEFT); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Invoice Header -->
                    <div class="invoice-header d-flex justify-content-between mb-3 pb-2 border-bottom">
                        <div class="invoice-company">
                            <h3 class="text-primary">CS KUMARESAN MOTOR</h3>
                            <p class="text-muted small">Workshop Management System</p>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold">INVOICE</div>
                            <p class="text-muted small">Date: <?php echo date('M d, Y', strtotime($booking['completed_date'] ?? $booking['booking_date'])); ?></p>
                            <p class="text-muted small">Invoice #INV-<?php echo str_pad($booking['id'], 6, '0', STR_PAD_LEFT); ?></p>
                        </div>
                    </div>
                    
                    <!-- Customer Details -->
                    <div class="invoice-details bg-light rounded p-3 mb-3">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Customer:</strong> <?php echo htmlspecialchars($booking['customer_name']); ?><br>
                                <strong>Phone:</strong> <?php echo htmlspecialchars($booking['customer_phone']); ?><br>
                                <strong>Email:</strong> <?php echo htmlspecialchars($booking['customer_email']); ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Vehicle:</strong> <?php echo htmlspecialchars($booking['brand_name'] . ' ' . $booking['model'] . ' (' . $booking['year'] . ')'); ?><br>
                                <strong>Plate:</strong> <?php echo htmlspecialchars($booking['number_plate']); ?><br>
                                <strong>Service:</strong> <?php echo htmlspecialchars($booking['service_name']); ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Add Part Form -->
                    <div class="add-part-container bg-light rounded p-3 mb-3">
                        <div class="row g-2">
                            <div class="col-md-3"><input type="text" class="form-control form-control-sm" id="part_name_<?php echo $booking['id']; ?>" placeholder="Part Name"></div>
                            <div class="col-md-1"><input type="number" class="form-control form-control-sm" id="quantity_<?php echo $booking['id']; ?>" value="1" min="1"></div>
                            <div class="col-md-2"><input type="number" step="0.01" class="form-control form-control-sm" id="unit_price_<?php echo $booking['id']; ?>" placeholder="Unit Price (RM)"></div>
                            <div class="col-md-2"><input type="number" class="form-control form-control-sm" id="warranty_months_<?php echo $booking['id']; ?>" placeholder="Warranty (mth)"></div>
                            <div class="col-md-2"><input type="text" class="form-control form-control-sm" id="warranty_info_<?php echo $booking['id']; ?>" placeholder="Warranty Details"></div>
                            <div class="col-md-2">
                                <button type="button" class="btn btn-success btn-sm w-100" onclick="addPartToTable(<?php echo $booking['id']; ?>)">
                                    <i class="bi bi-plus-circle"></i> Add Part
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Parts Table -->
                    <div class="table-responsive">
                        <table class="invoice-table table table-sm" id="parts-table-<?php echo $booking['id']; ?>">
                            <thead class="table-light">
                                <tr>
                                    <th>Description</th>
                                    <th>Qty</th>
                                    <th>Unit Price (RM)</th>
                                    <th>Total (RM)</th>
                                    <th>Warranty</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="parts-list-<?php echo $booking['id']; ?>">
                                <?php foreach ($parts as $part): ?>
                                    <tr id="part-row-<?php echo $booking['id']; ?>-<?php echo $part['id']; ?>">
                                        <td><?php echo htmlspecialchars($part['part_name']); ?></td>
                                        <td><?php echo $part['quantity']; ?></td>
                                        <td><?php echo number_format($part['unit_price'], 2); ?></div>
                                        <td class="part-total-<?php echo $booking['id']; ?>-<?php echo $part['id']; ?>"><?php echo number_format($part['total_price'], 2); ?></div>
                                        <td>
                                            <?php if ($part['warranty_months']): ?>
                                                <?php echo $part['warranty_months']; ?> months :
                                                <?php if ($part['warranty_info']): ?>
                                                    <br><small class="text-muted fst-italic"><?php echo htmlspecialchars($part['warranty_info']); ?></small><?php endif; ?>
                                            <?php else: ?>-<?php endif; ?>
                                        </div>
                                        <td>
                                            <button class="btn btn-sm btn-outline-danger" onclick="markForDeletion(<?php echo $booking['id']; ?>, <?php echo $part['id']; ?>)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="3" class="text-end"><strong>SUB TOTAL:</strong></td>
                                    <td colspan="3" id="sub-total-<?php echo $booking['id']; ?>"><strong>RM <?php echo number_format($parts_total, 2); ?></strong></td>
                                </tr>
                                <tr class="table-primary">
                                    <td colspan="3" class="text-end"><strong>TOTAL AMOUNT:</strong></td>
                                    <td colspan="3" id="grand-total-<?php echo $booking['id']; ?>"><strong>RM <?php echo number_format($parts_total, 2); ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <!-- Service Notes -->
                    <?php if (!empty($booking['admin_notes'])): ?>
                        <div class="mt-3 p-2 bg-light rounded border-start border-3 border-success">
                            <strong class="text-success">Service Notes:</strong>
                            <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($booking['admin_notes'])); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Payment Status -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="saveChanges(<?php echo $booking['id']; ?>)">
                        <i class="bi bi-save"></i> Save Changes
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="generateAdminInvoicePDF(<?php echo $booking['id']; ?>)">
                        <i class="bi bi-file-pdf"></i> Download PDF
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Timeline Modal -->
    <div class="modal fade" id="timelineModal<?php echo $booking['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-clock-history"></i> Service Timeline - #<?php echo str_pad($booking['id'], 6, '0', STR_PAD_LEFT); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="invoice-details bg-light rounded p-3 mb-3">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Customer:</strong> <?php echo htmlspecialchars($booking['customer_name']); ?><br>
                                <strong>Vehicle:</strong> <?php echo htmlspecialchars($booking['brand_name'] . ' ' . $booking['model']); ?><br>
                                <strong>Plate:</strong> <?php echo htmlspecialchars($booking['number_plate']); ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Service:</strong> <?php echo htmlspecialchars($booking['service_name']); ?><br>
                                <strong>Booked:</strong> <?php echo date('M d, Y', strtotime($booking['booking_date'])); ?><br>
                                <strong>Completed:</strong> <?php echo date('M d, Y', strtotime($booking['completed_date'] ?? $booking['booking_date'])); ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($updates_by_booking[$booking['id']])): ?>
                        <div class="timeline">
                            <?php foreach ($updates_by_booking[$booking['id']] as $update): 
                                $dot_class = 'info';
                                $badge_class = 'info';
                                $display_text = 'Information';
                                
                                if ($update['update_type'] == 'waiting') {
                                    $dot_class = 'waiting';
                                    $badge_class = 'warning';
                                    $display_text = 'Waiting';
                                } elseif ($update['update_type'] == 'issue') {
                                    $dot_class = 'issue';
                                    $badge_class = 'danger';
                                    $display_text = 'Issue';
                                } elseif ($update['update_type'] == 'complete' || $update['update_type'] == 'success') {
                                    $dot_class = 'complete';
                                    $badge_class = 'success';
                                    $display_text = 'Completed';
                                }
                            ?>
                                <div class="timeline-item">
                                    <div class="timeline-dot <?php echo $dot_class; ?>"></div>
                                    <div class="timeline-content">
                                        <div class="timeline-message"><?php echo htmlspecialchars($update['message']); ?></div>
                                        <div class="timeline-meta">
                                            <span><i class="bi bi-person"></i> <?php echo htmlspecialchars($update['technician_name'] ?? 'Workshop'); ?></span>
                                            <span><i class="bi bi-clock"></i> <?php echo date('M d, h:i A', strtotime($update['created_at'])); ?></span>
                                            <span class="badge bg-<?php echo $badge_class; ?>"><?php echo $display_text; ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-clock-history" style="font-size: 2rem; color: #cbd5e1;"></i>
                            <p class="mt-2 text-muted">No timeline updates available</p>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
    let newParts = {};
    let deletedParts = {};
    
    // Initialize DataTable
    $(document).ready(function() {
        var table = $('#historyTable').DataTable({
            pageLength: 10,
            order: [[4, 'desc']],
            language: {
                search: "",
                searchPlaceholder: "Search...",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                emptyTable: "No service history found",
                paginate: {
                    first: '<i class="bi bi-chevron-double-left"></i>',
                    last: '<i class="bi bi-chevron-double-right"></i>',
                    next: '<i class="bi bi-chevron-right"></i>',
                    previous: '<i class="bi bi-chevron-left"></i>'
                }
            },
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                 '<"row"<"col-sm-12"tr>>' +
                 '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>'
        });
        
        // Custom search
        $('#searchInput').on('keyup', function() {
            table.search(this.value).draw();
        });
        
        // Hide default DataTable search
        $('.dataTables_filter').hide();
    });
    
    function showInvoice(bookingId) {
        new bootstrap.Modal(document.getElementById('invoiceModal' + bookingId)).show();
    }
    
    function showTimeline(bookingId) {
        new bootstrap.Modal(document.getElementById('timelineModal' + bookingId)).show();
    }
    
    function addPartToTable(bookingId) {
        const partName = document.getElementById('part_name_' + bookingId).value;
        const quantity = document.getElementById('quantity_' + bookingId).value;
        const unitPrice = document.getElementById('unit_price_' + bookingId).value;
        const warrantyMonths = document.getElementById('warranty_months_' + bookingId).value || 0;
        const warrantyInfo = document.getElementById('warranty_info_' + bookingId).value;
        
        if (!partName || !quantity || !unitPrice) {
            alert('Please fill all required fields');
            return;
        }
        
        const tempId = 'temp_' + Date.now();
        const total = quantity * unitPrice;
        
        if (!newParts[bookingId]) newParts[bookingId] = [];
        newParts[bookingId].push({
            tempId: tempId,
            part_name: partName,
            quantity: quantity,
            unit_price: unitPrice,
            warranty_months: warrantyMonths,
            warranty_info: warrantyInfo,
            total: total
        });
        
        const tbody = document.getElementById('parts-list-' + bookingId);
        const newRow = document.createElement('tr');
        newRow.id = 'part-row-' + bookingId + '-' + tempId;
        newRow.style.backgroundColor = '#e6f7e6';
        newRow.innerHTML = `
            <td>${partName} <span class="badge bg-success">New</span></td>
            <td>${quantity}</td>
            <td>${parseFloat(unitPrice).toFixed(2)}</div>
            <td class="part-total-${bookingId}-${tempId}">${total.toFixed(2)}</div>
            <td>${warrantyMonths > 0 ? warrantyMonths + ' months' + (warrantyInfo ? '<br><small>' + warrantyInfo + '</small>' : '') : '-'}</div>
            <td>
                <button class="btn btn-sm btn-outline-danger" onclick="removeNewPart(${bookingId}, '${tempId}')">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `;
        tbody.appendChild(newRow);
        
        // Clear form
        document.getElementById('part_name_' + bookingId).value = '';
        document.getElementById('quantity_' + bookingId).value = '1';
        document.getElementById('unit_price_' + bookingId).value = '';
        document.getElementById('warranty_months_' + bookingId).value = '';
        document.getElementById('warranty_info_' + bookingId).value = '';
        
        updateGrandTotal(bookingId);
    }
    
    function removeNewPart(bookingId, tempId) {
        if (newParts[bookingId]) {
            newParts[bookingId] = newParts[bookingId].filter(p => p.tempId !== tempId);
        }
        const row = document.getElementById('part-row-' + bookingId + '-' + tempId);
        if (row) row.remove();
        updateGrandTotal(bookingId);
    }
    
    function markForDeletion(bookingId, partId) {
        if (!confirm('Remove this part?')) return;
        
        if (!deletedParts[bookingId]) deletedParts[bookingId] = [];
        deletedParts[bookingId].push(partId);
        
        const row = document.getElementById('part-row-' + bookingId + '-' + partId);
        if (row) {
            row.style.backgroundColor = '#ffe6e6';
            row.style.textDecoration = 'line-through';
            row.style.opacity = '0.6';
            const btn = row.querySelector('button');
            if (btn) btn.disabled = true;
        }
        updateGrandTotal(bookingId);
    }
    
    function updateGrandTotal(bookingId) {
        let total = 0;
        const rows = document.querySelectorAll('#parts-list-' + bookingId + ' tr');
        rows.forEach(row => {
            if (row.style.textDecoration !== 'line-through') {
                const totalCell = row.querySelector('td:nth-child(4)');
                if (totalCell) {
                    const value = parseFloat(totalCell.textContent.replace('RM', '').replace(',', ''));
                    if (!isNaN(value)) total += value;
                }
            }
        });
        document.getElementById('sub-total-' + bookingId).innerHTML = '<strong>RM ' + total.toFixed(2) + '</strong>';
        document.getElementById('grand-total-' + bookingId).innerHTML = '<strong>RM ' + total.toFixed(2) + '</strong>';
    }
    
    function saveChanges(bookingId) {
        const saveBtn = document.querySelector('#invoiceModal' + bookingId + ' .btn-primary');
        const originalText = saveBtn.innerHTML;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';
        saveBtn.disabled = true;
        
        const deletePromises = [];
        if (deletedParts[bookingId] && deletedParts[bookingId].length > 0) {
            deletedParts[bookingId].forEach(partId => {
                const formData = new FormData();
                formData.append('delete_part_ajax', '1');
                formData.append('part_id', partId);
                formData.append('booking_id', bookingId);
                deletePromises.push(fetch(window.location.href, { method: 'POST', body: formData }).then(r => r.json()));
            });
        }
        
        const addPromises = [];
        if (newParts[bookingId] && newParts[bookingId].length > 0) {
            newParts[bookingId].forEach(part => {
                const formData = new FormData();
                formData.append('add_part_ajax', '1');
                formData.append('booking_id', bookingId);
                formData.append('part_name', part.part_name);
                formData.append('quantity', part.quantity);
                formData.append('unit_price', part.unit_price);
                formData.append('warranty_months', part.warranty_months);
                formData.append('warranty_info', part.warranty_info);
                addPromises.push(fetch(window.location.href, { method: 'POST', body: formData }).then(r => r.json()));
            });
        }
        
        Promise.all([...deletePromises, ...addPromises]).then(() => {
            alert('Changes saved successfully!');
            location.reload();
        }).catch(() => {
            alert('Error saving changes');
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
        });
    }
    
    function generateAdminInvoicePDF(bookingId) {
    try {
        const { jsPDF } = window.jspdf;

        // ================= GET BOOKING DATA =================
        const modal = document.getElementById('invoiceModal' + bookingId);

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
        let customerName = '';
        let customerPhone = '';
        let customerEmail = '';

        // Booking number from modal title
        const modalTitle = modal.querySelector('.modal-title');
        if (modalTitle) {
            const titleText = modalTitle.textContent;
            const match = titleText.match(/#(\d+)/);
            if (match) bookingNumber = match[1];
        }

        // Get customer and vehicle details from invoice-details section
        const detailsDiv = modal.querySelector('.invoice-details');
        if (detailsDiv) {
            const text = detailsDiv.textContent;
            const html = detailsDiv.innerHTML;
            
            if (text.includes('Customer:')) {
                const customerMatch = text.match(/Customer:\s*([^\n]+)/);
                if (customerMatch) customerName = customerMatch[1].trim();
            }
            if (text.includes('Phone:')) {
                const phoneMatch = text.match(/Phone:\s*([^\n]+)/);
                if (phoneMatch) customerPhone = phoneMatch[1].trim();
            }
            if (text.includes('Email:')) {
                const emailMatch = text.match(/Email:\s*([^\n]+)/);
                if (emailMatch) customerEmail = emailMatch[1].trim();
            }
            if (text.includes('Vehicle:')) {
                const vehicleMatch = text.match(/Vehicle:\s*([^\n]+)/);
                if (vehicleMatch) {
                    let fullVehicleName = vehicleMatch[1].trim();
                    // Remove year in parentheses from vehicle name
                    fullVehicleName = fullVehicleName.replace(/\s*\(\d{4}\)\s*/, '').trim();
                    vehicleName = fullVehicleName;
                }
            }
            if (text.includes('Plate:')) {
                const plateMatch = text.match(/Plate:\s*([^\n]+)/);
                if (plateMatch) vehiclePlate = plateMatch[1].trim();
            }
            if (text.includes('Service:')) {
                const serviceMatch = text.match(/Service:\s*([^\n]+)/);
                if (serviceMatch) serviceName = serviceMatch[1].trim();
            }
            
            // Extract year from vehicle name which contains year in parentheses
// The vehicle name format is like "PROTON WAJA (2006)"
if (vehicleName) {
    const yearMatch = vehicleName.match(/\((\d{4})\)/);
    if (yearMatch) {
        vehicleYear = yearMatch[1];
        // Remove the year from vehicle name for cleaner display
        vehicleName = vehicleName.replace(/\s*\(\d{4}\)\s*/, '').trim();
    }
}

// If year not found in vehicle name, try to get from the text
if (!vehicleYear || vehicleYear === '') {
    const yearPattern = /\b(19|20)\d{2}\b/;
    const yearFound = text.match(yearPattern);
    if (yearFound) vehicleYear = yearFound[0];
}
        }

        // Try to get data from the row details in the modal body
        const modalBody = modal.querySelector('.modal-body');
        if (modalBody) {
            const bodyText = modalBody.textContent;
            if (!vehicleYear || vehicleYear === 'N/A' || vehicleYear === '') {
                const yearPattern = /\b(19|20)\d{2}\b/;
                const yearFound = bodyText.match(yearPattern);
                if (yearFound) vehicleYear = yearFound[0];
            }
            
            // Look for color in the text (could be any word)
            if (!vehicleColor || vehicleColor === 'N/A' || vehicleColor === '') {
                // Try to find color from the vehicle line
                const vehicleLineMatch = bodyText.match(/Vehicle:\s*([^\n]+)/i);
                if (vehicleLineMatch) {
                    const vehicleText = vehicleLineMatch[1];
                    // Look for color patterns - might be at the end of vehicle description
                    const colorAtEnd = vehicleText.match(/\s([A-Z]{2,})$/);
                    if (colorAtEnd) vehicleColor = colorAtEnd[1];
                }
            }
        }

        // Get service date from modal
        if (detailsDiv) {
            const dateText = detailsDiv.textContent;
            const datePatterns = [
                /(?:Date|Completed):\s*([A-Za-z]{3}\s+\d{1,2},\s+\d{4})/i,
                /(\d{4}-\d{2}-\d{2})/,
                /([A-Za-z]{3}\s+\d{1,2},\s+\d{4})/
            ];
            for (let pattern of datePatterns) {
                const dateMatch = dateText.match(pattern);
                if (dateMatch && dateMatch[1]) {
                    serviceDate = dateMatch[1];
                    break;
                }
            }
        }

        // Get parts total and warranty info from the parts table
        const partsBody = document.getElementById('parts-list-' + bookingId);
        if (partsBody) {
            const rows = partsBody.querySelectorAll('tr');
            rows.forEach(row => {
                if (row.style.textDecoration !== 'line-through') {
                    const cells = row.querySelectorAll('td');
                    if (cells.length >= 5) {
                        let partTotal = 0;
                        let unitPrice = 0;
                        
                        // Get part name (remove any "New" badge)
                        let partName = cells[0]?.textContent?.replace('New', '').trim() || '';
                        
                        // Get quantity
                        let qty = cells[1]?.textContent?.trim() || '1';
                        
                        // Get unit price
                        const priceText = cells[2]?.textContent?.replace(/RM/g, '').trim() || '0';
                        unitPrice = parseFloat(priceText) || 0;
                        
                        // Get total
                        const totalText = cells[3]?.textContent?.replace(/RM/g, '').trim() || '0';
                        partTotal = parseFloat(totalText) || 0;
                        
                        // Get warranty info - this is in the 5th column (index 4)
                        let warranty = cells[4]?.textContent?.trim() || '-';
                        // Clean up warranty text - remove extra spaces and newlines
                        warranty = warranty.replace(/\s+/g, ' ').trim();
                        
                        parts.push({
                            name: partName,
                            qty: qty,
                            price: unitPrice,
                            total: partTotal,
                            warranty: warranty || '-'
                        });
                        totalAmount += partTotal;
                    }
                }
            });
        }

        // Get total from grand total element
        const grandTotalElement = document.getElementById('grand-total-' + bookingId);
        if (grandTotalElement) {
            let totalText = grandTotalElement.textContent;
            totalText = totalText.replace('RM', '').replace('TOTAL AMOUNT:', '').replace(/<[^>]*>/g, '').trim();
            const parsedTotal = parseFloat(totalText);
            if (!isNaN(parsedTotal) && parsedTotal > 0) {
                totalAmount = parsedTotal;
            }
        }

        if (totalAmount === 0) {
            const subTotalElement = document.getElementById('sub-total-' + bookingId);
            if (subTotalElement) {
                let subTotalText = subTotalElement.textContent;
                subTotalText = subTotalText.replace('RM', '').replace(/<[^>]*>/g, '').trim();
                totalAmount = parseFloat(subTotalText) || 0;
            }
        }

        if (parts.length === 0) {
            parts.push({
                name: 'Service - ' + (serviceName || 'Vehicle Service'),
                qty: '1',
                price: totalAmount || 0,
                total: totalAmount || 0,
                warranty: '-'
            });
        }

        const notesDiv = modal.querySelector('.border-start.border-success');
        if (notesDiv) {
            adminNotes = notesDiv.querySelector('p')?.textContent || '';
        }

        // ================= DATE =================
        const now = new Date();
        const currentDate = now.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });

        // If service date is still empty, try to get from completed date in the modal footer or header
        if (!serviceDate) {
            const allText = modal.textContent;
            const datePattern = /(?:Completed|Date):\s*([A-Za-z]{3}\s+\d{1,2},\s+\d{4})/i;
            const dateMatch = allText.match(datePattern);
            if (dateMatch && dateMatch[1]) {
                serviceDate = dateMatch[1];
            } else {
                serviceDate = currentDate;
            }
        }

        // Ensure values are set
        vehicleYear = vehicleYear && vehicleYear !== 'N/A' && vehicleYear !== '' ? vehicleYear : '-';
        vehicleColor = vehicleColor && vehicleColor !== 'N/A' && vehicleColor !== '' ? vehicleColor : '-';

        // ================= PDF SETUP =================
        const doc = new jsPDF({
            orientation: 'portrait',
            unit: 'mm',
            format: 'a4'
        });

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

        // Customer Box
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
        doc.text(`Year: ${vehicleYear}`, 22, startY + 28);
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
        doc.text(`Service Date: ${serviceDate}`, 120, startY + 28);
        doc.text(`Phone: ${customerPhone || 'N/A'}`, 120, startY + 34);
        doc.text(`Email: ${customerEmail || 'N/A'}`, 120, startY + 40);

        // Parts Table
        startY += 55;
        doc.setFont(undefined, 'bold');
        doc.setFontSize(11);
        doc.setTextColor(navy[0], navy[1], navy[2]);
        doc.text('Service Parts & Charges', 15, startY);

        const tableX = 15;
        const tableWidth = pageWidth - 30;
        const col1 = 70;  // Reduced for description
        const col2 = 15;  // Units
        const col3 = 30;  // Unit Price
        const col4 = 28;  // Total
        const col5 = 40;  // Warranty - made wider

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
            const warranty = part.warranty && part.warranty !== '-' && part.warranty !== '' ? part.warranty : '-';

            doc.text(shortName, tableX + 3, rowY + 6.5);
            doc.text(part.qty.toString(), tableX + col1 + 5, rowY + 6.5);
            doc.text(part.price.toFixed(2), tableX + col1 + col2 + 4, rowY + 6.5);
            doc.text(part.total.toFixed(2), tableX + col1 + col2 + col3 + 4, rowY + 6.5);
            doc.text(warranty, tableX + col1 + col2 + col3 + col4 + 3, rowY + 6.5, { maxWidth: 38 });

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
</script>

</body>
</html>
