<?php
session_start();

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

/* ================= FETCH CUSTOMER DETAILS ================= */
$customer = [];
$sql = "SELECT id, full_name, email, phone, created_at
        FROM users
        WHERE id = ? AND role = 'customer'";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

/* ================= FETCH REGISTERED VEHICLES ================= */
$vehicles = [];
$vehicle_sql = "SELECT id, brand_name, model, year, color, number_plate
                FROM vehicles
                WHERE user_id = ?
                ORDER BY id DESC";
$vehicle_stmt = $pdo->prepare($vehicle_sql);
$vehicle_stmt->execute([$user_id]);
$vehicles = $vehicle_stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= UPDATE PROFILE ================= */
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    
    $update_sql = "UPDATE users SET full_name = ?, phone = ? WHERE id = ?";
    $update_stmt = $pdo->prepare($update_sql);

    if ($update_stmt->execute([$full_name, $phone, $user_id])) {
        $_SESSION['full_name'] = $full_name;
        $message = "Profile updated successfully!";
        $message_type = 'success';

        // Refresh customer data
        $customer['full_name'] = $full_name;
        $customer['phone'] = $phone;
        $first_name = explode(' ', $full_name)[0];
    } else {
        $message = "Error updating profile.";
        $message_type = 'danger';
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>My Profile | CS KUMARESAN MOTOR</title>
    
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
        
        /* ================= WELCOME TEXT ================= */
        .welcome-text {
            padding: 1rem 1rem 0rem 1rem;
        }
        
        .welcome-text h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.1rem;
        }
        
        .welcome-text p {
            font-size: 0.8rem;
            color: #64748b;
            margin: 0;
        }
        
        /* ================= PROFILE CARD ================= */
        .profile-card {
            background: white;
            margin: 1rem;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
        }
        
        .card-header {
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            padding: 0.85rem 1.25rem;
            color: white;
        }
        
        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-body {
            padding: 1rem;
        }
        
        /* Info Row */
        .info-row {
            display: flex;
            padding: 0.6rem 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-label {
            width: 100px;
            font-weight: 600;
            font-size: 0.85rem;
            color: #475569;
        }
        
        .info-value {
            flex: 1;
            font-size: 0.85rem;
            color: #1e293b;
        }
        
        /* Vehicle Card */
        .vehicle-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            border: 1px solid #e2e8f0;
        }
        
        .vehicle-plate {
            font-weight: 700;
            color: #0c0c0c;
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }
        
        .vehicle-details {
            font-size: 0.75rem;
            color: #64748b;
        }
        
        .empty-state {
            text-align: center;
            padding: 1.5rem;
            color: #94a3b8;
        }
        
        .empty-state i {
            font-size: 2rem;
        }
        
        /* Form Styling */
        .form-group {
            margin-bottom: 0.85rem;
        }
        
        .form-label {
            font-weight: 600;
            font-size: 0.8rem;
            color: #475569;
            margin-bottom: 0.4rem;
            display: block;
        }
        
        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.6rem 0.85rem;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }
        
        .form-control:focus {
            border-color: #1e40af;
            box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1);
            outline: none;
        }
        
        .btn-primary {
            background: #1e40af;
            border: none;
            padding: 0.6rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.85rem;
            width: 50%;
        }
        
        .btn-outline-secondary {
            border: 2px solid #e2e8f0;
            padding: 0.6rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.85rem;
            background: white;
            color: #64748b;
        }
        
        .btn-edit {
            background: #eff6ff;
            color: #1e40af;
            border: none;
            padding: 0.25rem 0.7rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
        }
        
        .alert {
            margin: 1rem;
            padding: 0.75rem;
            border-radius: 12px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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
        
        /* Footer */
        .footer {
            background: #1e293b;
            color: white;
            padding: 1rem 0;
            margin-top: 2rem;
            text-align: center;
        }
        
        .footer small {
            font-size: 0.65rem;
            opacity: 0.7;
        }
        
        @media (max-width: 480px) {
            .info-row {
                flex-direction: column;
            }
            
            .info-label {
                width: 100%;
                margin-bottom: 0.2rem;
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
                <i class="bi bi-person-circle"></i>
            </div>
            <div class="logo-text">
                <span id="pageTitle">My Profile</span>
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
                <div class="menu-user-email"><?php echo htmlspecialchars($customer['email'] ?? $email); ?></div>
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

    <!-- Message Alert -->
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
            <i class="bi <?php echo $message_type == 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Profile Card -->
    <div class="profile-card" id="profileCard">
        <div class="card-header">
            <h3><i class="bi bi-person-badge"></i> Personal Information</h3>
        </div>
        <div class="card-body">
            <!-- View Mode -->
            <div id="viewMode">
                <div class="info-row">
                    <div class="info-label">Full Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['full_name'] ?? '-'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Email</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['email'] ?? '-'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Phone Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></div>
                </div>
                <div class="text-end mt-2">
                    <button class="btn-edit" onclick="toggleEditMode(true)">
                        <i class="bi bi-pencil"></i> Edit Profile
                    </button>
                </div>
            </div>
            
            <!-- Edit Mode -->
            <div id="editMode" style="display: none;">
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($customer['full_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control" value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>" disabled>
                        <small class="text-muted" style="font-size: 0.65rem;">Email cannot be changed</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="bi bi-save"></i> Save Changes
                        </button>
                        <button type="button" class="btn-outline-secondary" onclick="toggleEditMode(false)" style="flex:1;">
                            <i class="bi bi-x-circle"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Registered Vehicles Card -->
    <div class="profile-card">
        <div class="card-header">
            <h3><i class="bi bi-car-front"></i> Registered Vehicles</h3>
        </div>
        <div class="card-body">
            <?php if (empty($vehicles)): ?>
                <div class="empty-state">
                    <i class="bi bi-car-front"></i>
                    <p class="mt-1" style="font-size: 0.8rem;">No vehicles registered yet</p>
                    <a href="customer-vehicles.php" class="btn btn-primary btn-sm mt-2" style="width: auto; padding: 0.4rem 0.8rem; font-size: 0.75rem;">
                        <i class="bi bi-plus-circle"></i> Add Vehicle
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($vehicles as $vehicle): ?>
                    <div class="vehicle-card">
                        <div class="vehicle-plate">
                            <i class="bi bi-car-front"></i> <?php echo htmlspecialchars($vehicle['number_plate']); ?>
                        </div>
                        <div class="vehicle-details">
                            <?php echo htmlspecialchars($vehicle['brand_name'] . ' ' . $vehicle['model'] . ' (' . $vehicle['year'] . ')'); ?>
                            <br>
                            Color: <?php echo htmlspecialchars($vehicle['color']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="text-center mt-2">
                    <a href="customer-vehicles.php" class="btn-edit">
                        <i class="bi bi-gear"></i> Manage Vehicles
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

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
    <a href="profile.php" class="nav-item active">
        <i class="bi bi-person"></i>
        <span>Profile</span>
    </a>
</div>

<script>
    function toggleEditMode(edit) {
        const viewMode = document.getElementById('viewMode');
        const editMode = document.getElementById('editMode');
        
        if (edit) {
            viewMode.style.display = 'none';
            editMode.style.display = 'block';
        } else {
            viewMode.style.display = 'block';
            editMode.style.display = 'none';
        }
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
    
    // Touch feedback for mobile
    const buttons = document.querySelectorAll('.btn-primary, .btn-edit, .nav-item, .btn-outline-secondary');
    buttons.forEach(btn => {
        btn.addEventListener('touchstart', () => {
            btn.style.opacity = '0.7';
        });
        btn.addEventListener('touchend', () => {
            btn.style.opacity = '1';
            setTimeout(() => { btn.style.opacity = ''; }, 100);
        });
    });
</script>

</body>
</html>
