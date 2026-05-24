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

require_once __DIR__ . '/includes/config.php';

/* ================= GET CUSTOMER ID ================= */
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($customer_id === 0) {
    header("Location: admin-customers.php");
    exit();
}

/* ================= HANDLE FORM SUBMISSIONS ================= */
$error = '';
$success = '';

// Update customer details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_customer'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $username = trim($_POST['username']);
    
    if (empty($full_name) || empty($email) || empty($username)) {
        $error = 'Full name, email, and username are required!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address!';
    } else {
        // Check if email or username exists for other users
        $checkSql = "SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ?";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([$email, $username, $customer_id]);
        $checkRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($checkRow) {
            $error = 'Email or Username already exists for another user!';
        } else {
            $updateSql = "UPDATE users SET full_name = ?, email = ?, phone = ?, username = ? WHERE id = ?";
            $updateStmt = $pdo->prepare($updateSql);

            if ($updateStmt->execute([$full_name, $email, $phone, $username, $customer_id])) {
                $success = 'Customer details updated successfully!';
            } else {
                $error = 'Error updating customer.';
            }
        }
    }
}

// Add new vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_vehicle'])) {
    $brand_name = strtoupper(trim($_POST['brand_name']));
    $model = strtoupper(trim($_POST['model']));
    $year = trim($_POST['year']);
    $color = strtoupper(trim($_POST['color']));
    $number_plate = strtoupper(str_replace(' ', '', trim($_POST['number_plate'])));
    
    if (empty($brand_name) || empty($model) || empty($year) || empty($color) || empty($number_plate)) {
        $error = 'All vehicle fields are required!';
    } elseif (!is_numeric($year) || $year < 1900 || $year > date('Y') + 1) {
        $error = 'Please enter a valid year!';
    } else {
        // Check if number plate exists
        $checkSql = "SELECT id FROM vehicles WHERE number_plate = ?";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([$number_plate]);
        $checkRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($checkRow) {
            $error = 'This number plate is already registered!';
        } else {
            $insertSql = "INSERT INTO vehicles (user_id, brand_name, model, year, color, number_plate)
                         VALUES (?, ?, ?, ?, ?, ?)";
            $insertStmt = $pdo->prepare($insertSql);

            if ($insertStmt->execute([$customer_id, $brand_name, $model, $year, $color, $number_plate])) {
                $success = 'Vehicle added successfully!';
                // Refresh page to show new vehicle
                header("Location: customer-details.php?id=" . $customer_id . "&success=1");
                exit();
            } else {
                $error = 'Error adding vehicle.';
            }
        }
    }
}

// Update vehicle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_vehicle'])) {
    $vehicle_id = intval($_POST['vehicle_id']);
    $brand_name = strtoupper(trim($_POST['brand_name']));
    $model = strtoupper(trim($_POST['model']));
    $year = trim($_POST['year']);
    $color = strtoupper(trim($_POST['color']));
    $number_plate = strtoupper(str_replace(' ', '', trim($_POST['number_plate'])));
    
    if (empty($brand_name) || empty($model) || empty($year) || empty($color) || empty($number_plate)) {
        $error = 'All vehicle fields are required!';
    } elseif (!is_numeric($year) || $year < 1900 || $year > date('Y') + 1) {
        $error = 'Please enter a valid year!';
    } else {
        // Check if number plate exists for other vehicles
        $checkSql = "SELECT id FROM vehicles WHERE number_plate = ? AND id != ?";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([$number_plate, $vehicle_id]);
        $checkRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($checkRow) {
            $error = 'This number plate is already registered for another vehicle!';
        } else {
            $updateSql = "UPDATE vehicles SET brand_name = ?, model = ?, year = ?, color = ?, number_plate = ?
                         WHERE id = ? AND user_id = ?";
            $updateStmt = $pdo->prepare($updateSql);

            if ($updateStmt->execute([$brand_name, $model, $year, $color, $number_plate, $vehicle_id, $customer_id])) {
                $success = 'Vehicle updated successfully!';
                header("Location: customer-details.php?id=" . $customer_id . "&updated=1");
                exit();
            } else {
                $error = 'Error updating vehicle.';
            }
        }
    }
}

// Delete vehicle
if (isset($_GET['delete_vehicle'])) {
    $vehicle_id = intval($_GET['delete_vehicle']);
    
    $deleteSql = "DELETE FROM vehicles WHERE id = ? AND user_id = ?";
    $deleteStmt = $pdo->prepare($deleteSql);

    if ($deleteStmt->execute([$vehicle_id, $customer_id])) {
        $success = 'Vehicle deleted successfully!';
        header("Location: customer-details.php?id=" . $customer_id . "&deleted=1");
        exit();
    } else {
        $error = 'Error deleting vehicle.';
    }
}

// Check for success param
if (isset($_GET['success']) || isset($_GET['updated']) || isset($_GET['deleted'])) {
    if (isset($_GET['success'])) $success = 'Vehicle added successfully!';
    if (isset($_GET['updated'])) $success = 'Vehicle updated successfully!';
    if (isset($_GET['deleted'])) $success = 'Vehicle deleted successfully!';
}

/* ================= FETCH CUSTOMER DETAILS ================= */
$customerSql = "SELECT id, full_name, email, phone, username, created_at
                FROM users WHERE id = ?";
$customerStmt = $pdo->prepare($customerSql);
$customerStmt->execute([$customer_id]);
$customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    header("Location: customer-details.php");
    exit();
}

/* ================= FETCH CUSTOMER VEHICLES ================= */
$vehiclesSql = "SELECT * FROM vehicles WHERE user_id = ? ORDER BY created_at DESC";
$vehiclesStmt = $pdo->prepare($vehiclesSql);
$vehiclesStmt->execute([$customer_id]);
$vehicles = $vehiclesStmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Customer Details | CS KUMARESAN MOTOR</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
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
        }
        
        .logo-text span {
            font-size: 0.7rem;
            color: #64748b;
            display: block;
            font-weight: 400;
        }
        
        .back-btn {
            background: none;
            border: none;
            font-size: 1.3rem;
            color: #1e293b;
            cursor: pointer;
            padding: 0.3rem;
            text-decoration: none;
            border-radius: 30px;
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .back-btn:hover {
            background: #f1f5f9;
        }
        
        .nav-links {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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
        }
        
        /* ================= MAIN CONTENT ================= */
        .main-container {
            padding: 1.5rem;
        }
        
        /* ================= BREADCRUMB ================= */
        .breadcrumb-bar {
            background: white;
            padding: 0.75rem 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
        }
        
        .breadcrumb-bar a {
            color: #1e40af;
            text-decoration: none;
            font-size: 0.8rem;
        }
        
        .breadcrumb-bar .separator {
            color: #94a3b8;
            margin: 0 0.5rem;
        }
        
        .breadcrumb-bar span {
            font-size: 0.8rem;
            color: #64748b;
        }
        
        /* ================= CARDS ================= */
        .main-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 1.5rem;
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
        
        .card-body {
            padding: 1rem;
        }
        
        /* ================= PROFILE SECTION ================= */
        .profile-header {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
            flex-wrap: wrap;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            font-weight: 600;
        }
        
        .profile-info h3 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        
        .profile-meta {
            display: flex;
            gap: 1rem;
            color: #64748b;
            font-size: 0.75rem;
        }
        
        .profile-meta i {
            margin-right: 0.2rem;
        }
        
        /* ================= INFO GRID ================= */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .info-item {
            background: #f8fafc;
            padding: 0.75rem;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        
        .info-label {
            font-size: 0.7rem;
            color: #64748b;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        
        .info-value {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1e293b;
        }
        
        /* ================= FORM ================= */
        .form-control, .form-select {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            transition: all 0.2s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #1e40af;
            outline: none;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }
        
        .form-label {
            font-weight: 600;
            font-size: 0.75rem;
            color: #475569;
            margin-bottom: 0.3rem;
        }
        
        .btn-primary {
            background: #1e40af;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .btn-primary:hover {
            background: #1e3a8a;
        }
        
        .btn-success {
            background: #059669;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .btn-success:hover {
            background: #047857;
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
        
        /* ================= DATATABLES PAGINATION ================= */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            padding: 0.75rem 1rem;
        }
        
        .dataTables_wrapper .dataTables_length {
            float: left;
        }
        
        .dataTables_wrapper .dataTables_filter {
            float: right;
        }
        
        .dataTables_wrapper .dataTables_filter input {
            margin-left: 0.5rem;
            padding: 0.3rem 0.8rem;
            border: 1px solid #e2e8f0;
            border-radius: 30px;
            font-size: 0.8rem;
        }
        
        .dataTables_wrapper .dataTables_filter input:focus {
            outline: none;
            border-color: #1e40af;
        }
        
        .dataTables_wrapper .dataTables_info {
            float: left;
            clear: both;
            font-size: 0.8rem;
            color: #64748b;
            padding-top: 0.5rem;
        }
        
        .dataTables_wrapper .dataTables_paginate {
            float: right;
            padding-top: 0.25rem;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0.3rem 0.7rem;
            margin: 0 0.2rem;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            background: white;
            color: #475569;
            cursor: pointer;
            display: inline-block;
            font-size: 0.8rem;
            transition: all 0.2s ease;
            text-decoration: none !important;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #1e40af;
            color: white !important;
            border-color: #1e40af;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: #1e40af;
            color: white !important;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #475569;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled,
        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
            color: #cbd5e1 !important;
            background: white;
            border-color: #e2e8f0;
            cursor: default;
        }
        
        /* Fix for DataTable search and length styling */
        .dataTables_length label,
        .dataTables_filter label {
            font-size: 0.8rem !important;
            color: #475569 !important;
            font-weight: 500 !important;
        }
        
        .dataTables_length select {
            margin: 0 0.3rem;
            padding: 0.2rem 0.4rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.8rem;
        }
        
        /* ================= VEHICLE BADGES ================= */
        .vehicle-plate {
            background: #1e293b;
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .color-badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            background: #f1f5f9;
            color: #1e293b;
        }
        
        /* ================= ACTION BUTTONS ================= */
        .btn-action {
            padding: 0.3rem 0.6rem;
            border-radius: 6px;
            font-size: 0.7rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
        }
        
        .btn-edit {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .btn-edit:hover {
            background: #bfdbfe;
        }
        
        .btn-delete {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .btn-delete:hover {
            background: #fecaca;
        }
        
        /* ================= EMPTY STATE ================= */
        .empty-state {
            text-align: center;
            padding: 2rem;
        }
        
        .empty-state i {
            font-size: 2.5rem;
            color: #cbd5e1;
            margin-bottom: 0.5rem;
        }
        
        .empty-state h5 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
        }
        
        /* ================= ALERTS ================= */
        .alert {
            border-radius: 10px;
            padding: 0.5rem 0.8rem;
            font-size: 0.75rem;
            margin-bottom: 1rem;
        }
        
        /* ================= MODAL ================= */
        .modal-content {
            border-radius: 16px;
        }
        
        .modal-header {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .modal-title {
            font-size: 1rem;
            font-weight: 600;
        }
        
        .modal-body {
            padding: 1rem;
        }
        
        .modal-footer {
            padding: 0.75rem 1rem;
            border-top: 1px solid #e2e8f0;
        }
        
        @media (max-width: 768px) {
            .nav-container {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .nav-links {
                order: 3;
                width: 100%;
                overflow-x: auto;
                padding-bottom: 0.5rem;
            }
            
            .profile-header {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-meta {
                justify-content: center;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .dataTables_wrapper .dataTables_length,
            .dataTables_wrapper .dataTables_filter,
            .dataTables_wrapper .dataTables_info,
            .dataTables_wrapper .dataTables_paginate {
                float: none;
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
                <i class="bi bi-people"></i>
            </div>
            <div class="logo-text">
                Customer Details
                <span>CS KUMARESAN MOTOR</span>
            </div>
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
    <div class="container-fluid">
        
        <!-- Breadcrumb -->
        <div class="breadcrumb-bar">
            <a href="admin-customers.php"><i class="bi bi-people"></i> Customers</a>
            <span class="separator">›</span>
            <span><?php echo htmlspecialchars($customer['full_name']); ?></span>
        </div>
        
        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <!-- Customer Profile Card -->
        <div class="main-card">
            <div class="card-header">
                <h2><i class="bi bi-person-circle"></i> Customer Profile</h2>
            </div>
            <div class="card-body">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($customer['full_name'], 0, 1)); ?>
                    </div>
                    <div class="profile-info">
                        <h3><?php echo htmlspecialchars($customer['full_name']); ?></h3>
                        <div class="profile-meta">
                            <span><i class="bi bi-calendar3"></i> Joined: <?php echo date('M d, Y', strtotime($customer['created_at'])); ?></span>
                            <span><i class="bi bi-car-front"></i> <?php echo count($vehicles); ?> vehicle(s)</span>
                        </div>
                    </div>
                </div>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label"><i class="bi bi-envelope"></i> Email Address</div>
                        <div class="info-value"><?php echo htmlspecialchars($customer['email']); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label"><i class="bi bi-telephone"></i> Phone Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($customer['phone'] ?? 'Not provided'); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label"><i class="bi bi-person"></i> Username</div>
                        <div class="info-value"><?php echo htmlspecialchars($customer['username']); ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label"><i class="bi bi-hash"></i> Customer ID</div>
                        <div class="info-value">#<?php echo str_pad($customer['id'], 5, '0', STR_PAD_LEFT); ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Edit Customer Form -->
        <div class="main-card">
            <div class="card-header">
                <h2><i class="bi bi-pencil-square"></i> Edit Customer Information</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" 
                                   value="<?php echo htmlspecialchars($customer['full_name']); ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" 
                                   value="<?php echo htmlspecialchars($customer['email']); ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-control" 
                                   value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                                   placeholder="e.g., 012-3456789">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" 
                                   value="<?php echo htmlspecialchars($customer['username']); ?>" required>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" name="update_customer" class="btn btn-primary">
                                <i class="bi bi-check-lg me-2"></i>Update Customer
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Vehicles Management -->
        <div class="main-card">
            <div class="card-header">
                <h2><i class="bi bi-car-front"></i> Registered Vehicles</h2>
            </div>
            <div class="card-body">
                <!-- Add Vehicle Button -->
                <button type="button" class="btn btn-success btn-sm mb-3" data-bs-toggle="modal" data-bs-target="#addVehicleModal">
                    <i class="bi bi-plus-circle me-2"></i>Add New Vehicle
                </button>
                
                <?php if (empty($vehicles)): ?>
                    <div class="empty-state">
                        <i class="bi bi-car-front"></i>
                        <h5>No Vehicles Registered</h5>
                        <p class="text-muted small">This customer hasn't registered any vehicles yet.</p>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addVehicleModal">
                            <i class="bi bi-plus-circle me-2"></i>
                            Add First Vehicle
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="vehiclesTable">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Brand</th>
                                    <th>Model</th>
                                    <th>Year</th>
                                    <th>Color</th>
                                    <th>Number Plate</th>
                                    <th>Registered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vehicles as $index => $vehicle): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></div>
                                        <td><?php echo htmlspecialchars($vehicle['brand_name']); ?></div>
                                        <td><?php echo htmlspecialchars($vehicle['model']); ?></div>
                                        <td><?php echo htmlspecialchars($vehicle['year']); ?></div>
                                        <td><span class="color-badge"><?php echo htmlspecialchars($vehicle['color']); ?></span></div>
                                        <td><span class="vehicle-plate"><?php echo htmlspecialchars($vehicle['number_plate']); ?></span></div>
                                        <td>
                                            <i class="bi bi-calendar3 me-1"></i>
                                            <?php echo date('d/m/Y', strtotime($vehicle['created_at'])); ?>
                                        </div>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button type="button" 
                                                        class="btn-action btn-edit" 
                                                        data-id="<?php echo $vehicle['id']; ?>"
                                                        data-brand="<?php echo htmlspecialchars($vehicle['brand_name']); ?>"
                                                        data-model="<?php echo htmlspecialchars($vehicle['model']); ?>"
                                                        data-year="<?php echo $vehicle['year']; ?>"
                                                        data-color="<?php echo htmlspecialchars($vehicle['color']); ?>"
                                                        data-plate="<?php echo htmlspecialchars($vehicle['number_plate']); ?>"
                                                        onclick="editVehicle(this)"
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#editVehicleModal">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </button>
                                                <a href="?id=<?php echo $customer_id; ?>&delete_vehicle=<?php echo $vehicle['id']; ?>" 
                                                   class="btn-action btn-delete"
                                                   onclick="return confirm('Are you sure you want to delete this vehicle?');">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </div>
                                        </div>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
    </div>
</div>

<!-- Add Vehicle Modal -->
<div class="modal fade" id="addVehicleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add New Vehicle</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Brand Name <span class="text-danger">*</span></label>
                            <input type="text" name="brand_name" class="form-control" 
                                   placeholder="e.g., TOYOTA" required style="text-transform: uppercase;">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Model <span class="text-danger">*</span></label>
                            <input type="text" name="model" class="form-control" 
                                   placeholder="e.g., CAMRY" required style="text-transform: uppercase;">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Year <span class="text-danger">*</span></label>
                            <input type="number" name="year" class="form-control" 
                                   min="1900" max="<?php echo date('Y') + 1; ?>" 
                                   placeholder="2020" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Color <span class="text-danger">*</span></label>
                            <input type="text" name="color" class="form-control" 
                                   placeholder="e.g., BLACK" required style="text-transform: uppercase;">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Number Plate <span class="text-danger">*</span></label>
                            <input type="text" name="number_plate" class="form-control" 
                                   placeholder="ABC1234" required style="text-transform: uppercase;">
                            <small class="text-muted">No spaces allowed</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_vehicle" class="btn btn-success btn-sm">
                        <i class="bi bi-check-lg me-2"></i>Add Vehicle
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Vehicle Modal -->
<div class="modal fade" id="editVehicleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Edit Vehicle</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="vehicle_id" id="edit_vehicle_id">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Brand Name <span class="text-danger">*</span></label>
                            <input type="text" name="brand_name" id="edit_brand_name" class="form-control" required style="text-transform: uppercase;">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Model <span class="text-danger">*</span></label>
                            <input type="text" name="model" id="edit_model" class="form-control" required style="text-transform: uppercase;">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Year <span class="text-danger">*</span></label>
                            <input type="number" name="year" id="edit_year" class="form-control" 
                                   min="1900" max="<?php echo date('Y') + 1; ?>" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Color <span class="text-danger">*</span></label>
                            <input type="text" name="color" id="edit_color" class="form-control" required style="text-transform: uppercase;">
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Number Plate <span class="text-danger">*</span></label>
                            <input type="text" name="number_plate" id="edit_number_plate" class="form-control" required style="text-transform: uppercase;">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_vehicle" class="btn btn-primary btn-sm">
                        <i class="bi bi-check-lg me-2"></i>Update Vehicle
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>

<script>
// Initialize DataTable
$(document).ready(function() {
    if ($('#vehiclesTable tbody tr').length > 0) {
        $('#vehiclesTable').DataTable({
            pageLength: 50,
            lengthChange: false,
            searching: false,
            info: false,
            order: [[6, 'desc']],
            language: {
                infoEmpty: "No vehicles found",
                emptyTable: "No vehicles found"
            }
        });
    }
});

// Edit vehicle function - FIXED to populate form fields
function editVehicle(button) {
    // Get values from data attributes
    var vehicleId = button.getAttribute('data-id');
    var brandName = button.getAttribute('data-brand');
    var model = button.getAttribute('data-model');
    var year = button.getAttribute('data-year');
    var color = button.getAttribute('data-color');
    var numberPlate = button.getAttribute('data-plate');
    
    // Set values in the edit form
    document.getElementById('edit_vehicle_id').value = vehicleId;
    document.getElementById('edit_brand_name').value = brandName;
    document.getElementById('edit_model').value = model;
    document.getElementById('edit_year').value = year;
    document.getElementById('edit_color').value = color;
    document.getElementById('edit_number_plate').value = numberPlate;
}
</script>

</body>
</html>
