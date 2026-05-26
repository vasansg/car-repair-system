<?php
ob_start();

// ========== PREVENT CACHE AND BACK BUTTON AFTER LOGOUT ==========
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");

require_once __DIR__ . '/includes/config.php';

// Check if user is logged in for personalization
$logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

// ========== AUTH CHECK - REDIRECT IF NOT LOGGED IN ==========
if (!$logged_in) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$first_name = '';
$email = '';
if ($logged_in) {
    $full_name = $_SESSION['full_name'];
    $first_name = explode(' ', $full_name)[0];
    $email = $_SESSION['email'] ?? '';
}

/* ================= GET FILTERS ================= */
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? trim($_GET['category']) : '';
$brand_filter = isset($_GET['brand']) ? trim($_GET['brand']) : '';

/* ================= FETCH SPARE PARTS FROM FIRESTORE ================= */
$spare_parts = [];
$allParts = $firebase->query('spare_parts', [['status', '==', 'active']], 'part_name', 'ASCENDING');

foreach ($allParts as $row) {
    // Apply search filter
    if (!empty($search)) {
        $haystack = strtolower(($row['part_name'] ?? '') . ' ' . ($row['description'] ?? ''));
        if (strpos($haystack, strtolower($search)) === false) continue;
    }
    // Apply category filter
    if (!empty($category_filter) && ($row['category'] ?? '') !== $category_filter) continue;

    // Brands are stored as an embedded array in the Firestore document
    $part_brands  = $row['brands'] ?? [];
    $brands_arr   = array_column($part_brands, 'brand_name');
    $brand_prices = array_column($part_brands, 'price');

    // Apply brand filter
    if (!empty($brand_filter)) {
        $brandIdx = array_search($brand_filter, $brands_arr);
        if ($brandIdx === false) continue;
        $brands_arr   = [$brands_arr[$brandIdx]];
        $brand_prices = [$brand_prices[$brandIdx]];
    }

    $display_price_min = $row['price_min'] ?? 0;
    $display_price_max = $row['price_max'] ?? 0;
    if (empty($display_price_min) && !empty($brand_prices)) {
        $display_price_min = min($brand_prices);
        $display_price_max = max($brand_prices);
    }

    $row['brands_array']       = $brands_arr;
    $row['brand_prices']       = $brand_prices;
    $row['display_price_min']  = $display_price_min;
    $row['display_price_max']  = $display_price_max;

    $spare_parts[] = $row;
}

/* ================= FETCH CATEGORIES & BRANDS FOR FILTER ================= */
$all_for_filter = $firebase->query('spare_parts', [['status', '==', 'active']]);
$cat_vals = array_filter(array_unique(array_column($all_for_filter, 'category')));
sort($cat_vals);
$categories = array_map(fn($c) => ['category' => $c], $cat_vals);

$brand_vals = [];
foreach ($all_for_filter as $p) {
    foreach (($p['brands'] ?? []) as $b) {
        if (!empty($b['brand_name'])) $brand_vals[] = $b['brand_name'];
    }
}
$brand_vals = array_values(array_unique($brand_vals));
sort($brand_vals);
$brands = array_map(fn($b) => ['brand_name' => $b], $brand_vals);

// Get current date for greeting
$hour = date('G');
if ($hour < 12) {
    $greeting = 'Good Morning';
} elseif ($hour < 17) {
    $greeting = 'Good Afternoon';
} else {
    $greeting = 'Good Evening';
}

// Check if any filter is active
$has_active_filters = !empty($search) || !empty($category_filter) || !empty($brand_filter);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Components | CS KUMARESAN MOTOR</title>
    
    <!-- Prevent back button after logout - CACHE CONTROL META TAGS -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    
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
        
        /* ================= HORIZONTAL FILTER BAR ================= */
        .filter-bar {
            background: white;
            margin: 1rem;
            border-radius: 16px;
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 140px;
        }
        
        .filter-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 0.25rem;
            display: block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 0.55rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.8rem;
            background: #f8fafc;
            transition: all 0.2s;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #1e40af;
            background: white;
        }
        
        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-filter {
            background: #1e40af;
            color: white;
            border: none;
            padding: 0.55rem 1.2rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-filter:active {
            transform: scale(0.96);
        }
        
        .btn-reset {
            background: #f1f5f9;
            color: #64748b;
            border: none;
            padding: 0.55rem 1rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.2s;
        }
        
        .btn-reset:active {
            background: #e2e8f0;
        }
        
        /* ================= CHIP FILTERS (Active Filters) ================= */
        .active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin: 0 1rem 1rem 1rem;
        }
        
        .filter-chip {
            background: #eff6ff;
            color: #1e40af;
            padding: 0.3rem 0.7rem;
            border-radius: 30px;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-chip .remove {
            cursor: pointer;
            font-weight: bold;
            color: #dc2626;
            background: none;
            border: none;
            font-size: 1rem;
            line-height: 1;
        }
        
        /* ================= PRODUCTS GRID ================= */
        .products-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin: 1rem;
        }
        
        .product-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            transition: all 0.2s ease;
        }
        
        .product-card:active {
            transform: scale(0.99);
        }
        
        .product-image-container {
            width: 100%;
            height: 160px;
            overflow: hidden;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .product-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .product-image-placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
        }
        
        .product-image-placeholder i {
            font-size: 2.5rem;
        }
        
        .product-body {
            padding: 1rem;
        }

        .price-range {
        font-size: 0.75rem;
        }
        
        .product-title {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.25rem;
        }
        
        .product-category {
            font-size: 0.65rem;
            color: #64748b;
            margin-bottom: 0.5rem;
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            padding: 0.15rem 0.5rem;
            background: #f1f5f9;
            border-radius: 20px;
        }
        
        .product-description {
            font-size: 0.75rem;
            color: #64748b;
            margin-bottom: 0.75rem;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .brands-section {
            margin-bottom: 0.75rem;
        }
        
        .brands-title {
            font-size: 0.65rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.3rem;
        }
        
        .brand-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.3rem;
        }
        
        .brand-item {
            background: #eff6ff;
            color: #1e40af;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 500;
        }
        
        .contact-btn {
            width: 100%;
            padding: 0.6rem;
            background: #1e40af;
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
            transition: all 0.2s ease;
            margin-top: 0.5rem;
        }
        
        .contact-btn:active {
            transform: scale(0.98);
        }
        
        /* Empty State */
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
        
        @media (min-width: 768px) {
            .products-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 1024px) {
            .products-grid {
                grid-template-columns: repeat(3, 1fr);
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
                <i class="bi bi-gear-wide-connected"></i>
            </div>
            <div class="logo-text">
                <span id="pageTitle">Components</span>
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

    <!-- Horizontal Filter Bar -->
    <div class="filter-bar">
        <form method="GET" id="filterForm">
            <div class="filter-row">
                <div class="filter-group">
                    <label class="filter-label">Search</label>
                    <input type="text" name="search" placeholder="Search parts..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <?php if (!empty($categories)): ?>
                <div class="filter-group">
                    <label class="filter-label">Category</label>
                    <select name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $category_filter == $cat['category'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($brands)): ?>
                <div class="filter-group">
                    <label class="filter-label">Brand</label>
                    <select name="brand">
                        <option value="">All Brands</option>
                        <?php foreach ($brands as $brand): ?>
                            <option value="<?php echo htmlspecialchars($brand['brand_name']); ?>" <?php echo $brand_filter == $brand['brand_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($brand['brand_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">
                        <i class="bi bi-search"></i> Apply
                    </button>
                    <a href="spare-parts.php" class="btn-reset">
                        <i class="bi bi-arrow-repeat"></i> Reset
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Active Filters Display -->
    <?php if ($has_active_filters): ?>
    <div class="active-filters">
        <?php if (!empty($search)): ?>
            <span class="filter-chip">
                Search: <?php echo htmlspecialchars($search); ?>
                <button class="remove" data-filter="search" data-value="<?php echo htmlspecialchars($search); ?>">&times;</button>
            </span>
        <?php endif; ?>
        <?php if (!empty($category_filter)): ?>
            <span class="filter-chip">
                Category: <?php echo htmlspecialchars($category_filter); ?>
                <button class="remove" data-filter="category" data-value="<?php echo htmlspecialchars($category_filter); ?>">&times;</button>
            </span>
        <?php endif; ?>
        <?php if (!empty($brand_filter)): ?>
            <span class="filter-chip">
                Brand: <?php echo htmlspecialchars($brand_filter); ?>
                <button class="remove" data-filter="brand" data-value="<?php echo htmlspecialchars($brand_filter); ?>">&times;</button>
            </span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Products Grid -->
    <?php if (empty($spare_parts)): ?>
        <div class="empty-state">
            <i class="bi bi-box-seam"></i>
            <h4>No Components Found</h4>
            <p>Try adjusting your filters</p>
            <a href="spare-parts.php" class="btn btn-primary btn-sm" style="background: #1e40af;">View All</a>
        </div>
    <?php else: ?>
        <div class="products-grid">
            <?php foreach ($spare_parts as $part): ?>
                <div class="product-card">
                    <div class="product-image-container">
                        <?php if (!empty($part['image_path']) && file_exists($part['image_path'])): ?>
                            <img src="<?php echo $part['image_path']; ?>" class="product-image" alt="<?php echo htmlspecialchars($part['part_name']); ?>">
                        <?php else: ?>
                            <div class="product-image-placeholder">
                                <i class="bi bi-image"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="product-body">
                        <h3 class="product-title"><?php echo htmlspecialchars($part['part_name']); ?></h3>
                        <?php if (!empty($part['category'])): ?>
                            <span class="product-category">
                                <i class="bi bi-tag"></i> <?php echo htmlspecialchars($part['category']); ?>
                            </span>
                        <?php endif; ?>
                        
                        <?php if (!empty($part['description'])): ?>
                            <p class="product-description"><?php echo htmlspecialchars($part['description']); ?></p>
                        <?php endif; ?>
                        
                        <!-- Price Range -->
                        <?php if (!empty($part['display_price_min']) && !empty($part['display_price_max'])): ?>
                            <div class="price-range" style="margin-bottom: 0.75rem;">
                                <span style="font-size: 0.7rem; color: #64748b;">Price Range:</span>
                                <span style="font-weight: 700; color: #059669;">
                                    RM <?php echo number_format($part['display_price_min'], 2); ?> - RM <?php echo number_format($part['display_price_max'], 2); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Brands - Display All, No Prices -->
                        <?php if (!empty($part['brands_array'])): ?>
                            <div class="brands-section">
                                <div class="brands-title">Available Brands:</div>
                                <div class="brand-list">
                                    <?php foreach ($part['brands_array'] as $brand): ?>
                                        <span class="brand-item"><?php echo htmlspecialchars($brand); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Contact Button -->
                        <button class="contact-btn" onclick="contactSales('<?php echo htmlspecialchars($part['part_name']); ?>')">
                            <i class="bi bi-whatsapp"></i> Enquire Now
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

<script>
    function contactSales(partName) {
        var phone = "60125289073";
        var message = "Hello, I'm interested in purchasing " + partName + ". Can you please provide more information?";
        var whatsappUrl = "https://wa.me/" + phone + "?text=" + encodeURIComponent(message);
        window.open(whatsappUrl, '_blank');
    }
    
    function confirmLogout() {
        // Clear any stored data
        sessionStorage.clear();
        localStorage.clear();
        return confirm('Are you sure you want to logout?');
    }
    
    function removeFilter(type) {
        var url = new URL(window.location.href);
        switch(type) {
            case 'search': url.searchParams.delete('search'); break;
            case 'category': url.searchParams.delete('category'); break;
            case 'brand': url.searchParams.delete('brand'); break;
        }
        window.location.href = url.toString();
    }
    
    // Remove filter chips
    document.querySelectorAll('.filter-chip .remove').forEach(el => {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            removeFilter(this.dataset.filter);
        });
    });
    
    // Auto-submit on select change
    var filterForm = document.getElementById('filterForm');
    if (filterForm) {
        var selects = filterForm.querySelectorAll('select');
        selects.forEach(select => {
            select.addEventListener('change', () => filterForm.submit());
        });
    }
    
    // Debounced search
    var searchTimeout;
    var searchInput = document.querySelector('input[name="search"]');
    if (searchInput && filterForm) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => filterForm.submit(), 500);
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
    
    // ========== PREVENT BACK BUTTON AFTER LOGOUT ==========
    (function() {
        // Force page to not be cached in history
        window.history.pushState(null, null, window.location.href);
        window.addEventListener('popstate', function() {
            // When back button is pressed, redirect to login
            window.location.href = 'login.php';
        });
        
        // Check authentication status periodically (every 5 seconds)
        setInterval(function() {
            fetch('check-auth.php')
                .then(response => response.json())
                .then(data => {
                    if (!data.logged_in) {
                        window.location.href = 'login.php';
                    }
                })
                .catch(() => {});
        }, 5000);
    })();
</script>

</body>
</html>
