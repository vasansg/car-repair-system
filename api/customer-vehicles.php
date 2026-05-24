<?php

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

require_once __DIR__ . '/includes/config.php';

/* ================= USER DATA ================= */
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$email = $_SESSION['email'];
$first_name = explode(' ', $full_name)[0];

//* ================= HANDLE FORM SUBMISSION ================= */
$error = '';
$success = '';

// Check for success message from session (after redirect)
if (isset($_SESSION['vehicle_added'])) {
    $success = $_SESSION['vehicle_added'];
    unset($_SESSION['vehicle_added']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_vehicle'])) {
    $brand_name = strtoupper(trim($_POST['brand_name']));
    $model = strtoupper(trim($_POST['model']));
    $year = trim($_POST['year']);
    $color = strtoupper(trim($_POST['color']));
    $number_plate = strtoupper(str_replace(' ', '', trim($_POST['number_plate'])));
    
    if (empty($brand_name) || empty($model) || empty($year) || empty($color) || empty($number_plate)) {
        $error = 'All fields are required!';
    } elseif (!preg_match('/^[A-Z0-9\-]+$/', $number_plate)) {
        $error = 'Number plate must contain only letters, numbers, and hyphens!';
    } elseif ($year < 1000 || $year > date('Y') + 1) {
        $error = 'Please enter a valid year!';
    } else {
        $sql = "INSERT INTO vehicles (user_id, brand_name, model, year, color, number_plate) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);

        if ($stmt->execute([$user_id, $brand_name, $model, $year, $color, $number_plate])) {
            // Store success message in session
            $_SESSION['vehicle_added'] = 'Vehicle added successfully!';

            // Redirect to prevent resubmission on refresh
            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
            exit();
        } else {
            $error = 'Error adding vehicle.';
        }
    }
}

/* ================= FETCH VEHICLES ================= */
$vehicles = array();
$sql = "SELECT * FROM vehicles WHERE user_id = ? ORDER BY created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
$vehicle_count = count($vehicles);

$hour = date('G');
if ($hour < 12) {
    $greeting = 'Good Morning';
} elseif ($hour < 17) {
    $greeting = 'Good Afternoon';
} else {
    $greeting = 'Good Evening';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>My Vehicles | CS KUMARESAN MOTOR</title>
    
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
        
        /* ================= STATS CARD ================= */
        .stats-card {
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            margin: 1rem;
            padding: 1.5rem;
            border-radius: 24px;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .stats-left h3 {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            line-height: 1;
        }
        
        .stats-left p {
            margin: 0.25rem 0 0 0;
            opacity: 0.9;
            font-size: 0.85rem;
        }
        
        .stats-icon {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(5px);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            border: 2px solid rgba(255,255,255,0.3);
        }
        
        /* ================= ADD BUTTON ================= */
        .fab-container {
            padding: 0 1rem;
            margin-bottom: 1rem;
        }
        
        .btn-add-vehicle {
            background: white;
            border: 2px solid #e2e8f0;
            padding: 1rem;
            border-radius: 16px;
            font-weight: 600;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            background: white;
            color: #1e293b;
            transition: all 0.2s ease;
        }
        
        .btn-add-vehicle:active {
            background: #f8fafc;
            transform: scale(0.98);
        }
        
        .btn-add-vehicle .btn-left {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-add-vehicle i:first-child {
            color: #1e40af;
            font-size: 1.3rem;
        }
        
        .arrow-icon {
            transition: transform 0.3s ease;
        }
        
        .arrow-icon.rotated {
            transform: rotate(180deg);
        }
        
        /* ================= FORM CARD ================= */
        .form-card {
            background: white;
            border-radius: 20px;
            margin: 0 1rem 1rem 1rem;
            padding: 1.5rem;
            border: 1px solid #e2e8f0;
            display: none;
        }
        
        .form-card.show {
            display: block;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            font-weight: 500;
            color: #475569;
            margin-bottom: 0.4rem;
            font-size: 0.85rem;
            display: block;
        }
        
        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            background: #f8fafc;
        }
        
        .form-control:focus {
            border-color: #1e40af;
            box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1);
            background: white;
            outline: none;
        }
        
        .input-hint {
            font-size: 0.7rem;
            color: #64748b;
            margin-top: 0.3rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            color: white;
            border: none;
            padding: 0.85rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            width: 100%;
            margin-top: 0.5rem;
            transition: all 0.2s ease;
        }
        
        .btn-submit:active {
            transform: scale(0.98);
        }
        
        /* ================= VEHICLES SECTION ================= */
        .vehicles-section {
            padding: 0 1rem;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        
        .section-header h2 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
        }
        
        .vehicle-count {
            background: #e2e8f0;
            padding: 0.3rem 0.8rem;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #475569;
        }
        
        .vehicles-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        
        .vehicle-card {
            background: white;
            border-radius: 18px;
            padding: 1rem;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }
        
        .vehicle-card:active {
            background: #f8fafc;
        }
        
        .vehicle-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        
        .vehicle-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: #1e40af;
        }
        
        .vehicle-info h4 {
            font-weight: 700;
            margin: 0;
            font-size: 1rem;
            color: #1e293b;
        }
        
        .vehicle-plate {
            background: #1e293b;
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 16px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
            margin-top: 0.25rem;
        }
        
        .vehicle-details {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            padding-top: 0.75rem;
            border-top: 1px solid #e2e8f0;
            margin-bottom: 0.5rem;
        }
        
        .detail-item {
            text-align: center;
        }
        
        .detail-label {
            font-size: 0.6rem;
            color: #64748b;
            margin-bottom: 0.2rem;
        }
        
        .detail-value {
            font-weight: 600;
            color: #1e293b;
            font-size: 0.85rem;
        }
        
        .registered-date {
            font-size: 0.7rem;
            color: #64748b;
            text-align: center;
            padding-top: 0.5rem;
            border-top: 1px solid #e2e8f0;
        }
        
        /* ================= EMPTY STATE ================= */
        .empty-state {
            background: white;
            border-radius: 20px;
            padding: 2.5rem 1.5rem;
            text-align: center;
            border: 1px solid #e2e8f0;
        }
        
        .empty-state i {
            font-size: 3.5rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        
        .empty-state h3 {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }
        
        .empty-state p {
            color: #64748b;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
        }
        
        .btn-empty {
            background: #1e40af;
            color: white;
            border: none;
            padding: 0.7rem 1.5rem;
            border-radius: 30px;
            font-weight: 500;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* ================= INFO NOTE (Hint Style) ================= */
/* ================= INFO NOTE (Input Hint Style) ================= */
.info-note {
    margin: 0.5rem 1rem 1rem 1rem;
    padding: 0.5rem 0.75rem;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: #f8fafc;
}

.info-note i {
    color: #64748b;
    font-size: 0.75rem;
}

.info-note span {
    font-size: 0.7rem;
    color: #64748b;
}

.info-note strong {
    color: #475569;
    font-weight: 600;
}
        
        /* ================= ALERTS ================= */
        .alert {
            margin: 1rem;
            padding: 0.85rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
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
            
            .container {
                max-width: 800px;
                margin: 0 auto;
            }
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .vehicle-card {
            animation: fadeInUp 0.3s ease forwards;
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
                <i class="bi bi-car-front-fill"></i>
            </div>
            <div class="logo-text">
                <span id="pageTitle">My Vehicles</span>
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

    <!-- Stats Card -->
    <div class="stats-card">
        <div class="stats-left">
            <h3><?php echo $vehicle_count; ?></h3>
            <p>Registered Vehicles</p>
        </div>
        <div class="stats-icon">
            <i class="bi bi-car-front"></i>
        </div>
    </div>

    <!-- Add Vehicle Button -->
    <div class="fab-container">
        <button class="btn-add-vehicle" id="toggleFormBtn">
            <div class="btn-left">
                <i class="bi bi-plus-circle"></i>
                <span id="btnText">Add New Vehicle</span>
            </div>
            <i class="bi bi-chevron-down arrow-icon" id="arrowIcon"></i>
        </button>
    </div>

    <!-- Add Vehicle Form -->
    <div class="form-card" id="addVehicleForm">
        <div class="form-title">
            <i class="bi bi-plus-circle"></i>
            Vehicle Details
        </div>
        <form method="POST" action="" id="vehicleForm">
            <div class="form-group">
                <label class="form-label">Brand Name</label>
                <input type="text" class="form-control" name="brand_name" 
                       value="<?php echo htmlspecialchars($_POST['brand_name'] ?? ''); ?>" 
                       placeholder="e.g., TOYOTA, HONDA" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Model</label>
                <input type="text" class="form-control" name="model" 
                       value="<?php echo htmlspecialchars($_POST['model'] ?? ''); ?>" 
                       placeholder="e.g., CAMRY, CIVIC" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Year</label>
                <input type="number" class="form-control" name="year" 
                       min="1900" max="<?php echo date('Y') + 1; ?>"
                       value="<?php echo htmlspecialchars($_POST['year'] ?? ''); ?>" 
                       placeholder="e.g., 2020" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Color</label>
                <input type="text" class="form-control" name="color" 
                       value="<?php echo htmlspecialchars($_POST['color'] ?? ''); ?>" 
                       placeholder="e.g., BLACK, WHITE" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Number Plate</label>
                <input type="text" class="form-control" name="number_plate" 
                       value="<?php echo htmlspecialchars($_POST['number_plate'] ?? ''); ?>" 
                       placeholder="e.g., ABC1234" required>
            </div>
            
            <button type="submit" name="add_vehicle" class="btn-submit">
                <i class="bi bi-check-lg"></i> Add Vehicle
            </button>
        </form>
    </div>

    <!-- Display Messages -->
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo htmlspecialchars($error); ?>

    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill"></i>
            <?php echo htmlspecialchars($success); ?>
        
        </div>
    <?php endif; ?>

    <!-- Vehicles List -->
    <div class="vehicles-section">
        <div class="section-header">
            <h2>Your Vehicles</h2>
            <span class="vehicle-count"><?php echo $vehicle_count; ?> total</span>
        </div>
        
        <?php if (empty($vehicles)): ?>
            <div class="empty-state">
                <i class="bi bi-car-front"></i>
                <h3>No Vehicles Yet</h3>
                <p>Add your first vehicle to get started with service bookings</p>
                <button class="btn-empty" id="emptyStateBtn">
                    <i class="bi bi-plus-circle"></i> Add Vehicle
                </button>
            </div>
        <?php else: ?>
            <div class="vehicles-grid">
                <?php foreach ($vehicles as $vehicle): ?>
                    <div class="vehicle-card">
                        <div class="vehicle-header">

                            <div class="vehicle-info">
                                <h4><?php echo htmlspecialchars($vehicle['brand_name']); ?> <?php echo htmlspecialchars($vehicle['model']); ?></h4>
                                <span class="vehicle-plate"><?php echo htmlspecialchars($vehicle['number_plate']); ?></span>
                            </div>
                        </div>
                        
                        <div class="vehicle-details">
                            <div class="detail-item">
                                <div class="detail-label">Year</div>
                                <div class="detail-value"><?php echo $vehicle['year']; ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Color</div>
                                <div class="detail-value"><?php echo htmlspecialchars($vehicle['color']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status</div>
                                <div class="detail-value">Active</div>
                            </div>
                        </div>
                        
                        <div class="registered-date">
                            <i class="bi bi-calendar3"></i>
                            Registered on <?php echo date('M d, Y', strtotime($vehicle['created_at'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- ================= INFO NOTE (Styled like input hint) ================= -->
<div class="info-note">
    <i class="bi bi-info-circle"></i>
    <span><strong>Note:</strong> Once added, vehicle details cannot be modified. Please contact the administrator for any changes.</span>
</div>

<!-- ================= BOTTOM NAVIGATION ================= -->
<div class="bottom-nav">
    <a href="customer-dashboard.php" class="nav-item">
        <i class="bi bi-house-door"></i>
        <span>Home</span>
    </a>
    <a href="customer-vehicles.php" class="nav-item active">
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

<!-- ================= SCRIPTS ================= -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Toggle form visibility
    const toggleBtn = document.getElementById('toggleFormBtn');
    const formCard = document.getElementById('addVehicleForm');
    const btnText = document.getElementById('btnText');
    const arrowIcon = document.getElementById('arrowIcon');
    const emptyStateBtn = document.getElementById('emptyStateBtn');
    
    function toggleForm() {
        formCard.classList.toggle('show');
        
        if (formCard.classList.contains('show')) {
            btnText.textContent = 'Hide Form';
            arrowIcon.classList.add('rotated');
        } else {
            btnText.textContent = 'Add New Vehicle';
            arrowIcon.classList.remove('rotated');
        }
    }
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleForm);
    }
    
    if (emptyStateBtn) {
        emptyStateBtn.addEventListener('click', toggleForm);
    }
    
    // Show form if there are validation errors
    <?php if ($error): ?>
    formCard.classList.add('show');
    btnText.textContent = 'Hide Form';
    arrowIcon.classList.add('rotated');
    <?php endif; ?>
    
    // Convert inputs to uppercase in real-time
    const uppercaseInputs = document.querySelectorAll('input[placeholder*="e.g."]');
    uppercaseInputs.forEach(function(input) {
        input.addEventListener('input', function(e) {
            const start = this.selectionStart;
            const end = this.selectionEnd;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(start, end);
        });
    });
    
    // Auto-dismiss alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);
    
    // ================= MENU FUNCTIONALITY =================
    const menuBtn = document.getElementById('menuBtn');
    const menuDropdown = document.getElementById('menuDropdown');
    const menuOverlay = document.querySelector('.menu-overlay');
    
    function openMenu() {
        if (menuDropdown) menuDropdown.classList.add('show');
    }
    
    function closeMenu() {
        if (menuDropdown) menuDropdown.classList.remove('show');
    }
    
    function toggleMenu() {
        if (menuDropdown.classList.contains('show')) {
            closeMenu();
        } else {
            openMenu();
        }
    }
    
    if (menuBtn) {
        menuBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleMenu();
        });
    }
    
    if (menuOverlay) {
        menuOverlay.addEventListener('click', closeMenu);
    }
    
    document.addEventListener('click', function(event) {
        if (menuDropdown && menuDropdown.classList.contains('show')) {
            if (!menuDropdown.querySelector('.menu-content').contains(event.target) && 
                !menuBtn.contains(event.target)) {
                closeMenu();
            }
        }
    });
    
    const menuItems = document.querySelectorAll('.menu-item');
    menuItems.forEach(item => {
        item.addEventListener('click', closeMenu);
    });
    
    // Touch feedback
    const interactiveElements = document.querySelectorAll('.btn-add-vehicle, .btn-submit, .vehicle-card, .menu-item, .nav-item, .btn-empty');
    interactiveElements.forEach(el => {
        el.addEventListener('touchstart', () => {
            el.style.opacity = '0.7';
        });
        el.addEventListener('touchend', () => {
            el.style.opacity = '1';
            setTimeout(() => { el.style.opacity = ''; }, 100);
        });
        el.addEventListener('touchcancel', () => {
            el.style.opacity = '1';
        });
    });
</script>

</body>
</html>