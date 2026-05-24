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

require_once __DIR__ . '/includes/config.php';

/* ================= USER DATA ================= */
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$email = $_SESSION['email'];
$first_name = explode(' ', $full_name)[0];

/* ================= FETCH REAL STATISTICS ================= */


// Get current time for greeting
// Set timezone to Malaysia/Kuala Lumpur (GMT+8)
date_default_timezone_set('Asia/Kuala_Lumpur');

$hour = date('G'); // 24-hour format
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
    <title>Dashboard | CS KUMARESAN MOTOR</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    
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

/* 3-Dot Menu Button - Bigger Radius */
.menu-btn {
    background: #f1f5f9;
    border: none;
    font-size: 1.3rem;
    color: #1e293b;
    cursor: pointer;
    padding: 0;
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 30px;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.menu-btn i {
    font-size: 1.3rem;
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
        
        .nav-item:active {
            transform: scale(0.95);
        }
        
        /* ================= PROFILE SECTION ================= */
        .profile-section {
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            margin: 1rem;
            padding: 1rem;
            border-radius: 24px;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .profile-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            pointer-events: none;
        }
        
        .greeting-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        
        .greeting h2 {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }
        
        .greeting p {
            margin: 0.25rem 0 0 0;
            opacity: 0.9;
            font-size: 1.5rem;
        }
        
        .avatar-large {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(5px);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            border: 2px solid rgba(255,255,255,0.3);
        }
        
        .quick-stats {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .stat-item {
            flex: 1;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(5px);
            padding: 0.75rem;
            border-radius: 16px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .stat-value {
            font-size: 1.2rem;
            font-weight: 700;
        }
        
        .stat-label {
            font-size: 0.7rem;
            opacity: 0.8;
        }
        
        .welcome-message {
            margin-top: 0.5rem;
            font-size: 0.8rem;
            opacity: 0.9;
        }
        
        /* ================= SECTION HEADER ================= */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1rem;
            margin-bottom: 1rem;
        }
        
        .section-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0;
        }
        
        .section-link {
            color: #1e40af;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        /* ================= FEATURE CARDS ================= */
        .feature-cards {
            padding: 0 1rem;
            margin-bottom: 2rem;
        }
        
        .cards-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
        
        .feature-card {
            background: white;
            border-radius: 20px;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            color: inherit;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
            transition: all 0.2s ease;
        }
        
        .feature-card:active {
            background: #f8fafc;
            transform: translateX(5px);
        }
        
        .feature-icon {
            width: 55px;
            height: 55px;
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            color: #1e40af;
        }
        
        .feature-content {
            flex: 1;
        }
        
        .feature-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: #1e293b;
        }
        
        .feature-desc {
            font-size: 0.75rem;
            color: #64748b;
            margin: 0;
        }
        
        .feature-arrow {
            color: #94a3b8;
            font-size: 1.1rem;
        }
        
        /* ================= FOOTER ================= */
        .footer {
            background: #1e293b;
            color: white;
            padding: 1rem 0;
            margin-top: 2rem;
            text-align: center;
        }
        
        .footer small {
            font-size: 0.7rem;
            opacity: 0.7;
        }
        
        /* ================= RESPONSIVE ================= */
        @media (min-width: 768px) {
            .cards-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .feature-cards {
                max-width: 1200px;
                margin-left: auto;
                margin-right: auto;
            }
        }
        
        /* Animation */
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
        
        .feature-card {
            animation: fadeInUp 0.3s ease forwards;
        }
        
        .feature-card:nth-child(1) { animation-delay: 0.1s; }
        .feature-card:nth-child(2) { animation-delay: 0.2s; }
        .feature-card:nth-child(3) { animation-delay: 0.3s; }
        .feature-card:nth-child(4) { animation-delay: 0.4s; }
        .feature-card:nth-child(5) { animation-delay: 0.5s; }
        .feature-card:nth-child(6) { animation-delay: 0.6s; }
    </style>
</head>
<body>

<!-- ================= MOBILE NAVIGATION ================= -->
<div class="mobile-nav">
    <div class="nav-container">
        <div class="logo-area">

            <div class="logo-icon">
                <i class="bi bi-car-front-fill"></i>
            </div>
            <div class="logo-text">
                <span id="pageTitle">Dashboard</span>
                <span>CS KUMARESAN MOTOR</span>
            </div>
        </div>
        <div class="menu-area">
            <button class="menu-btn" onclick="toggleMenu()">
                <i class="bi bi-three-dots-vertical"></i>
            </button>
        </div>
    </div>
</div>

<!-- ================= MENU DROPDOWN ================= -->
<div class="menu-dropdown" id="menuDropdown">
    <div class="menu-overlay" onclick="toggleMenu()"></div>
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
            <span>Componentss</span>
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

    <!-- Profile Section -->

    </div>

    <!-- Feature Cards -->
    <div class="feature-cards">
        <div class="section-header">
        </div>
        
        <div class="cards-grid">
            <!-- Vehicle Management -->
            <a href="customer-vehicles.php" class="feature-card">
                <div class="feature-icon">
                    <i class="bi bi-car-front"></i>
                </div>
                <div class="feature-content">
                    <div class="feature-title">Vehicle Management</div>
                    <p class="feature-desc">Add, edit or view your vehicles</p>
                </div>
                <div class="feature-arrow">
                    <i class="bi bi-chevron-right"></i>
                </div>
            </a>
            
            <!-- Service Booking -->
            <a href="customer-service-booking.php" class="feature-card">
                <div class="feature-icon">
                    <i class="bi bi-calendar-check"></i>
                </div>
                <div class="feature-content">
                    <div class="feature-title">Service Booking</div>
                    <p class="feature-desc">Schedule your next service</p>
                </div>
                <div class="feature-arrow">
                    <i class="bi bi-chevron-right"></i>
                </div>
            </a>
            
            <!-- Service Tracking -->
            <a href="track-service.php" class="feature-card">
                <div class="feature-icon">
                    <i class="bi bi-activity"></i>
                </div>
                <div class="feature-content">
                    <div class="feature-title">Service Tracking</div>
                    <p class="feature-desc">Track your service progress</p>
                </div>
                <div class="feature-arrow">
                    <i class="bi bi-chevron-right"></i>
                </div>
            </a>
            
            <!-- Service History -->
            <a href="service-history.php" class="feature-card">
                <div class="feature-icon">
                    <i class="bi bi-clock-history"></i>
                </div>
                <div class="feature-content">
                    <div class="feature-title">Service History</div>
                    <p class="feature-desc">View past services & invoices</p>
                </div>
                <div class="feature-arrow">
                    <i class="bi bi-chevron-right"></i>
                </div>
            </a>
            
            <!-- Spare Parts -->
            <a href="spare-parts.php" class="feature-card">
                <div class="feature-icon">
                    <i class="bi bi-gear-wide-connected"></i>
                </div>
                <div class="feature-content">
                    <div class="feature-title">Components</div>
                    <p class="feature-desc">Browse parts & prices</p>
                </div>
                <div class="feature-arrow">
                    <i class="bi bi-chevron-right"></i>
                </div>
            </a>
            
            <!-- My Profile -->
            <a href="profile.php" class="feature-card">
                <div class="feature-icon">
                    <i class="bi bi-person"></i>
                </div>
                <div class="feature-content">
                    <div class="feature-title">My Profile</div>
                    <p class="feature-desc">Manage your account settings</p>
                </div>
                <div class="feature-arrow">
                    <i class="bi bi-chevron-right"></i>
                </div>
            </a>
        </div>
    </div>

</div>

<!-- ================= BOTTOM NAVIGATION ================= -->
<div class="bottom-nav">
    <a href="customer-dashboard.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'customer-dashboard.php' ? 'active' : ''; ?>">
        <i class="bi bi-house-door"></i>
        <span>Home</span>
    </a>
    <a href="customer-vehicles.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'customer-vehicles.php' ? 'active' : ''; ?>">
        <i class="bi bi-car-front"></i>
        <span>Vehicles</span>
    </a>
    <a href="customer-service-booking.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'customer-service-booking.php' ? 'active' : ''; ?>">
        <i class="bi bi-calendar-check"></i>
        <span>Book</span>
    </a>
    <a href="track-service.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'track-service.php' ? 'active' : ''; ?>">
        <i class="bi bi-activity"></i>
        <span>Track</span>
    </a>
    <a href="profile.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
        <i class="bi bi-person"></i>
        <span>Profile</span>
    </a>
</div>

<!-- ================= SCRIPTS ================= -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Get elements
    const menuBtn = document.getElementById('menuBtn');
    const menuDropdown = document.getElementById('menuDropdown');
    const menuOverlay = document.querySelector('.menu-overlay');
    
    // Function to open menu
    function openMenu() {
        if (menuDropdown) {
            menuDropdown.classList.add('show');
        }
    }
    
    // Function to close menu
    function closeMenu() {
        if (menuDropdown) {
            menuDropdown.classList.remove('show');
        }
    }
    
    // Toggle menu function
    function toggleMenu() {
        if (menuDropdown.classList.contains('show')) {
            closeMenu();
        } else {
            openMenu();
        }
    }
    
    // Add click event to menu button
    if (menuBtn) {
        menuBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleMenu();
        });
    }
    
    // Close menu when clicking overlay
    if (menuOverlay) {
        menuOverlay.addEventListener('click', function() {
            closeMenu();
        });
    }
    
    // Close menu when clicking outside
    document.addEventListener('click', function(event) {
        if (menuDropdown && menuDropdown.classList.contains('show')) {
            // Check if click is outside menu content and not on menu button
            if (!menuDropdown.querySelector('.menu-content').contains(event.target) && 
                !menuBtn.contains(event.target)) {
                closeMenu();
            }
        }
    });
    
    // Close menu when a menu item is clicked
    const menuItems = document.querySelectorAll('.menu-item');
    menuItems.forEach(item => {
        item.addEventListener('click', function() {
            closeMenu();
        });
    });
    
    // Set page title dynamically
    function setPageTitle(title) {
        const pageTitleSpan = document.querySelector('.logo-text span:first-child');
        if (pageTitleSpan) {
            pageTitleSpan.textContent = title;
        }
    }
    
    // Set title based on current page
    const currentPage = window.location.pathname.split('/').pop();
    const titles = {
        'customer-dashboard.php': 'Dashboard',
        'profile.php': 'My Profile',
        'customer-vehicles.php': 'My Vehicles',
        'customer-service-booking.php': 'Book Service',
        'track-service.php': 'Track Service',
        'service-history.php': 'Service History',
        'spare-parts.php': 'Spare Parts'
    };
    
    if (titles[currentPage]) {
        setPageTitle(titles[currentPage]);
    }
    
    // Touch feedback for interactive elements
    const interactiveElements = document.querySelectorAll('.feature-card, .menu-item, .nav-item, .btn-primary, .menu-btn');
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
    
    // Debug: Check if button is clickable
    console.log('Menu button found:', menuBtn);
    console.log('Menu dropdown found:', menuDropdown);
</script>

</body>
</html>