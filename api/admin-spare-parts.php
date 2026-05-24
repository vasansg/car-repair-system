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

// Create uploads directory if not exists
if (!file_exists('uploads/spare_parts/')) {
    mkdir('uploads/spare_parts/', 0777, true);
}

/* ================= HANDLE FORM SUBMISSIONS ================= */
$message = '';
$message_type = '';

// ADD NEW SPARE PART
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_part'])) {
    $part_name = trim($_POST['part_name']);
    $category = trim($_POST['category']);
    $new_category = trim($_POST['new_category']);  // NEW: Get manual category input
    $description = trim($_POST['description'] ?? '');
    $price_min = !empty($_POST['price_min']) ? floatval($_POST['price_min']) : null;
    $price_max = !empty($_POST['price_max']) ? floatval($_POST['price_max']) : null;
    $status = $_POST['status'];
    
    // ========== HANDLE NEW CATEGORY ==========
    // If user selected "other" and entered a new category name
    if ($category === 'other' && !empty($new_category)) {
        $category = $new_category;
        
        // Check if category already exists in spare_parts_categories table
        $check_cat = $pdo->prepare("SELECT id FROM spare_parts_categories WHERE category_name = ?");
        $check_cat->execute([$category]);
        $cat_result = $check_cat->fetch(PDO::FETCH_ASSOC);

        // If category does not exist, insert it for future use
        if (!$cat_result) {
            $insert_cat = $pdo->prepare("INSERT INTO spare_parts_categories (category_name, is_active) VALUES (?, 1)");
            $insert_cat->execute([$category]);
        }
    }
    // ========== END CATEGORY HANDLING ==========
    
    // Check if part already exists
    $check_sql = "SELECT id FROM spare_parts WHERE part_name = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$part_name]);
    $check_result = $check_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($check_result) > 0) {
        $message = "Part '$part_name' already exists!";
        $message_type = 'danger';
    } else {
        // Handle image upload
        $image_path = '';
        if (isset($_FILES['part_image']) && $_FILES['part_image']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $filename = $_FILES['part_image']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                $new_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $part_name) . '.' . $ext;
                $upload_path = 'uploads/spare_parts/' . $new_filename;
                
                if (move_uploaded_file($_FILES['part_image']['tmp_name'], $upload_path)) {
                    $image_path = $upload_path;
                }
            }
        }
        
        $insert_sql = "INSERT INTO spare_parts (part_name, category, description, price_min, price_max, image_path, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $insert_stmt = $pdo->prepare($insert_sql);

        if ($insert_stmt->execute([$part_name, $category, $description, $price_min, $price_max, $image_path, $status])) {
            $part_id = $pdo->lastInsertId();

            // Add brands and prices
            if (isset($_POST['brands']) && is_array($_POST['brands'])) {
                foreach ($_POST['brands'] as $brand_data) {
                    if (!empty($brand_data['name'])) {
                        $brand_name = trim($brand_data['name']);
                        $brand_price = !empty($brand_data['price']) ? floatval($brand_data['price']) : null;

                        // Check if brand exists
                        $check_brand = $pdo->prepare("SELECT id FROM brands WHERE brand_name = ?");
                        $check_brand->execute([$brand_name]);
                        $brand_row = $check_brand->fetch(PDO::FETCH_ASSOC);

                        if ($brand_row) {
                            $brand_id = $brand_row['id'];
                        } else {
                            $insert_brand = $pdo->prepare("INSERT INTO brands (brand_name) VALUES (?)");
                            $insert_brand->execute([$brand_name]);
                            $brand_id = $pdo->lastInsertId();
                        }

                        // Link part with brand
                        $link_sql = "INSERT INTO spare_part_brands (spare_part_id, brand_id, price) VALUES (?, ?, ?)";
                        $link_stmt = $pdo->prepare($link_sql);
                        $link_stmt->execute([$part_id, $brand_id, $brand_price]);
                    }
                }
            }

            $message = "Spare part added successfully! ID: " . $part_id;
            $message_type = 'success';
        } else {
            $message = "Error adding spare part.";
            $message_type = 'danger';
        }
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// UPDATE SPARE PART
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_part'])) {
    $part_id = intval($_POST['part_id']);
    $part_name = trim($_POST['part_name']);
    $category = trim($_POST['category']);
    $description = trim($_POST['description'] ?? '');
    $price_min = !empty($_POST['price_min']) ? floatval($_POST['price_min']) : null;
    $price_max = !empty($_POST['price_max']) ? floatval($_POST['price_max']) : null;
    $status = $_POST['status'];
    
    // Handle image upload
    $image_path = $_POST['existing_image'] ?? '';
    if (isset($_FILES['part_image']) && $_FILES['part_image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $filename = $_FILES['part_image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $new_filename = time() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $part_name) . '.' . $ext;
            $upload_path = 'uploads/spare_parts/' . $new_filename;
            
            if (move_uploaded_file($_FILES['part_image']['tmp_name'], $upload_path)) {
                if (!empty($image_path) && file_exists($image_path)) {
                    unlink($image_path);
                }
                $image_path = $upload_path;
            }
        }
    }
    
    $update_sql = "UPDATE spare_parts SET part_name = ?, category = ?, description = ?, price_min = ?, price_max = ?, image_path = ?, status = ? WHERE id = ?";
    $update_stmt = $pdo->prepare($update_sql);

    if ($update_stmt->execute([$part_name, $category, $description, $price_min, $price_max, $image_path, $status, $part_id])) {
        // Update brands
        if (isset($_POST['brands']) && is_array($_POST['brands'])) {
            // First, delete existing brand links
            $delete_links = $pdo->prepare("DELETE FROM spare_part_brands WHERE spare_part_id = ?");
            $delete_links->execute([$part_id]);

            // Then add updated brands
            foreach ($_POST['brands'] as $brand_data) {
                if (!empty($brand_data['name'])) {
                    $brand_name = trim($brand_data['name']);
                    $brand_price = !empty($brand_data['price']) ? floatval($brand_data['price']) : null;

                    // Check if brand exists
                    $check_brand = $pdo->prepare("SELECT id FROM brands WHERE brand_name = ?");
                    $check_brand->execute([$brand_name]);
                    $brand_row = $check_brand->fetch(PDO::FETCH_ASSOC);

                    if ($brand_row) {
                        $brand_id = $brand_row['id'];
                    } else {
                        $insert_brand = $pdo->prepare("INSERT INTO brands (brand_name) VALUES (?)");
                        $insert_brand->execute([$brand_name]);
                        $brand_id = $pdo->lastInsertId();
                    }

                    // Link part with brand
                    $link_sql = "INSERT INTO spare_part_brands (spare_part_id, brand_id, price) VALUES (?, ?, ?)";
                    $link_stmt = $pdo->prepare($link_sql);
                    $link_stmt->execute([$part_id, $brand_id, $brand_price]);
                }
            }
        }

        $message = "Spare part updated successfully!";
        $message_type = 'success';
    } else {
        $message = "Error updating spare part.";
        $message_type = 'danger';
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// DELETE SPARE PART
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_part'])) {
    $part_id = intval($_POST['part_id']);
    
    // Delete related records first
    $delete_links = $pdo->prepare("DELETE FROM spare_part_brands WHERE spare_part_id = ?");
    $delete_links->execute([$part_id]);

    // Get image path to delete
    $img_sql = "SELECT image_path FROM spare_parts WHERE id = ?";
    $img_stmt = $pdo->prepare($img_sql);
    $img_stmt->execute([$part_id]);
    $img_row = $img_stmt->fetch(PDO::FETCH_ASSOC);
    if ($img_row) {
        if (!empty($img_row['image_path']) && file_exists($img_row['image_path'])) {
            unlink($img_row['image_path']);
        }
    }

    // Delete the spare part
    $delete_sql = "DELETE FROM spare_parts WHERE id = ?";
    $delete_stmt = $pdo->prepare($delete_sql);

    if ($delete_stmt->execute([$part_id])) {
        $message = "Spare part deleted successfully!";
        $message_type = 'success';
    } else {
        $message = "Error deleting spare part.";
        $message_type = 'danger';
    }
    
    header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($message) . "&type=" . $message_type);
    exit();
}

// Check for messages from redirect
if (isset($_GET['msg'])) {
    $message = urldecode($_GET['msg']);
    $message_type = $_GET['type'] ?? 'info';
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? trim($_GET['category']) : '';

// FETCH ALL SPARE PARTS with filters
$spare_parts = [];
$sql = "SELECT * FROM spare_parts WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND part_name LIKE ?";
    $params[] = "%$search%";
    $types .= "s";
}
if (!empty($category_filter)) {
    $sql .= " AND category = ?";
    $params[] = $category_filter;
    $types .= "s";
}
$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    // Get brands for this part
    $brands_sql = "SELECT b.brand_name, spb.price
                   FROM spare_part_brands spb
                   JOIN brands b ON spb.brand_id = b.id
                   WHERE spb.spare_part_id = ?";
    $brands_stmt = $pdo->prepare($brands_sql);
    $brands_stmt->execute([$row['id']]);
    $brands = [];
    $prices = [];
    while ($brand_row = $brands_stmt->fetch(PDO::FETCH_ASSOC)) {
        $brands[] = $brand_row['brand_name'];
        $prices[] = $brand_row['price'];
    }

    $row['brands_array'] = $brands;
    $row['brand_prices'] = $prices;
    $row['display_price_min'] = $row['price_min'];
    $row['display_price_max'] = $row['price_max'];

    // If no price range set, use brand prices
    if (empty($row['display_price_min']) && !empty($prices)) {
        $row['display_price_min'] = min($prices);
        $row['display_price_max'] = max($prices);
    }

    $spare_parts[] = $row;
}

// Get categories for filter
$categories = [];
$cat_sql = "SELECT * FROM spare_parts_categories WHERE is_active = 1 ORDER BY category_name";
$cat_result = $pdo->query($cat_sql);
$categories = $cat_result->fetchAll(PDO::FETCH_ASSOC);

$hour = date('G');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Components Management | CS KUMARESAN MOTOR</title>
    
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
        
        /* ================= PAGINATION STYLES (Matching other pages) ================= */
        .dataTables_wrapper .dataTables_paginate {
            float: right;
            text-decoration: none !important;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0.3rem 0.7rem;
            margin: 0 0.2rem;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            background: white;
            color: #475569;
            cursor: pointer;
            text-decoration: none !important;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: #1e40af;
            color: white;
            border-color: #1e40af;
            text-decoration: none !important;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: #f1f5f9;
            text-decoration: none !important;
        }
        
        /* ================= MAIN CONTENT ================= */
        .main-container {
            padding: 1.5rem;
        }
        
        /* ================= SEARCH/FILTER BAR ================= */
        .filter-bar {
            background: white;
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
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
            text-decoration: none !important;
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
        
        /* Part Image */
        .part-image {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            background: #f1f5f9;
        }
        
        /* Brand Badges */
        .brand-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.25rem;
        }
        
        .brand-badge {
            background: #eff6ff;
            color: #1e40af;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }
        
        /* Price Range */
        .price-range {
            color: #059669;
            font-weight: 600;
        }
        
        /* Action Buttons */
        .btn-action {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0 2px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-action:hover {
            transform: scale(1.05);
        }
        
        .btn-edit {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .btn-delete {
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
            
            .nav-links {
                order: 3;
                width: 100%;
                overflow-x: auto;
                padding-bottom: 0.5rem;
                justify-content: flex-start;
            }
            
            .filter-bar .row {
                flex-direction: column;
            }
            
            .card-header {
                flex-direction: column;
                text-align: center;
            }
            
            .dataTables_wrapper .dataTables_length,
            .dataTables_wrapper .dataTables_filter,
            .dataTables_wrapper .dataTables_info,
            .dataTables_wrapper .dataTables_paginate {
                float: none;
                text-align: center;
                text-decoration: none !important;
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
    <div class="container-fluid">
        
        <!-- Message Alert -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                <i class="bi bi-<?php echo $message_type == 'success' ? 'check-circle-fill' : ($message_type == 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill'); ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Filter Bar -->
        <div class="filter-bar">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Part name..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['category_name']; ?>" <?php echo $category_filter == $cat['category_name'] ? 'selected' : ''; ?>>
                                <?php echo $cat['category_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>
        </div>
        
        <!-- Main Card -->
        <div class="main-card">
            <div class="card-header">
                <h2><i class="bi bi-box-seam"></i> Components Inventory</h2>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPartModal">
                    <i class="bi bi-plus-circle"></i> Add New Part
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($spare_parts)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-box-seam" style="font-size: 3rem; color: #cbd5e1;"></i>
                        <h5 class="mt-3">No Components Found</h5>
                        <p class="text-muted">Click "Add New Part" to start building your inventory.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="partsTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 80px">Image</th>
                                    <th>Part Name</th>
                                    <th>Category</th>
                                    <th>Brands Available</th>
                                    <th>Price Range (RM)</th>
                                    <th>Status</th>
                                    <th style="width: 80px">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($spare_parts as $part): ?>
                                <tr>
                                  
                                    <td>
                                        <?php if (!empty($part['image_path']) && file_exists($part['image_path'])): ?>
                                            <img src="<?php echo $part['image_path']; ?>" class="part-image" alt="<?php echo htmlspecialchars($part['part_name']); ?>">
                                        <?php else: ?>
                                            <div class="bg-light d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; border-radius: 8px;">
                                                <i class="bi bi-image text-muted" style="font-size: 1.5rem;"></i>
                                            </div>
                                        <?php endif; ?>
                                       </div>
                                    </div>
                                    <td>
                                        <strong><?php echo htmlspecialchars($part['part_name']); ?></strong>
                                        <?php if (!empty($part['description'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($part['description'], 0, 50)); ?></small>
                                        <?php endif; ?>
                                       </div>
                                    </div>
                                    <td><?php echo htmlspecialchars($part['category'] ?? '-'); ?></div>
                                    <td>
                                        <div class="brand-list">
                                            <?php foreach ($part['brands_array'] as $brand): ?>
                                                <span class="brand-badge">
                                                    <?php echo htmlspecialchars($brand); ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if (empty($part['brands_array'])): ?>
                                                <span class="text-muted">No brands added</span>
                                            <?php endif; ?>
                                        </div>
                                       </div>
                                    <td class="price-range">
                                        <?php if ($part['display_price_min'] && $part['display_price_max']): ?>
                                            RM <?php echo number_format($part['display_price_min'], 2); ?> - RM <?php echo number_format($part['display_price_max'], 2); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                       </div>
                                    <td>
                                        <span class="badge bg-<?php echo $part['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($part['status']); ?>
                                        </span>
                                       </div>
                                    <td>
                                        <button class="btn-action btn-edit" onclick='editPart(<?php echo htmlspecialchars(json_encode($part), ENT_QUOTES, 'UTF-8'); ?>)' data-bs-toggle="modal" data-bs-target="#editPartModal" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn-action btn-delete" onclick="deletePart(<?php echo $part['id']; ?>, '<?php echo htmlspecialchars($part['part_name']); ?>')" data-bs-toggle="modal" data-bs-target="#deletePartModal" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
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

<!-- Add Part Modal -->
<div class="modal fade" id="addPartModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add New Spare Part</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Part Name <span class="text-danger">*</span></label>
                            <input type="text" name="part_name" class="form-control" required>
                        </div>
                        
                        <!-- Category Section with Dropdown + Manual Input -->
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select" id="categorySelect">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['category_name']; ?>"><?php echo $cat['category_name']; ?></option>
                                <?php endforeach; ?>
                                <option value="other">+ Add New Category</option>
                            </select>
                            <input type="text" name="new_category" id="newCategoryInput" class="form-control mt-2" 
                                   style="display: none;" placeholder="Enter new category name">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Part Image</label>
                            <input type="file" name="part_image" class="form-control" accept="image/*">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label">Brands & Prices</label>
                            <div id="brandsContainer">
                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <input type="text" name="brands[0][name]" class="form-control" placeholder="Brand name">
                                    </div>
                                    <div class="col-md-6">
                                        <input type="number" step="0.01" name="brands[0][price]" class="form-control" placeholder="Price (RM)">
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addBrandField()">
                                <i class="bi bi-plus-circle"></i> Add Another Brand
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_part" class="btn btn-success btn-sm">Add Part</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Part Modal -->
<div class="modal fade" id="editPartModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Edit Spare Part</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="part_id" id="edit_part_id">
                    <input type="hidden" name="existing_image" id="edit_existing_image">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Part Name *</label>
                            <input type="text" name="part_name" id="edit_part_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <select name="category" id="edit_category" class="form-select">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['category_name']; ?>"><?php echo $cat['category_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Min Price (RM)</label>
                            <input type="number" step="0.01" name="price_min" id="edit_price_min" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Max Price (RM)</label>
                            <input type="number" step="0.01" name="price_max" id="edit_price_max" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Part Image</label>
                            <input type="file" name="part_image" class="form-control" accept="image/*">
                            <small class="text-muted">Leave empty to keep current image</small>
                            <div id="edit_image_preview" class="mt-2"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Brands & Prices</label>
                            <div id="editBrandsContainer">
                                <!-- Brands will be loaded here dynamically -->
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="addEditBrandField()">
                                <i class="bi bi-plus-circle"></i> Add Another Brand
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_part" class="btn btn-primary btn-sm">Update Part</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Part Modal -->
<div class="modal fade" id="deletePartModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Delete Spare Part</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="part_id" id="delete_part_id">
                    <p>Are you sure you want to delete <strong id="delete_part_name"></strong>?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_part" class="btn btn-danger btn-sm">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>

<script>
let brandCounter = 1;
let editBrandCounter = 0;

function addBrandField() {
    const container = document.getElementById('brandsContainer');
    const div = document.createElement('div');
    div.className = 'row g-2 mb-2';
    div.innerHTML = `
        <div class="col-md-6">
            <input type="text" name="brands[${brandCounter}][name]" class="form-control" placeholder="Brand name">
        </div>
        <div class="col-md-5">
            <input type="number" step="0.01" name="brands[${brandCounter}][price]" class="form-control" placeholder="Price (RM)">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.parentElement.remove()">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;
    container.appendChild(div);
    brandCounter++;
}

function addEditBrandField() {
    const container = document.getElementById('editBrandsContainer');
    const div = document.createElement('div');
    div.className = 'row g-2 mb-2';
    div.innerHTML = `
        <div class="col-md-6">
            <input type="text" name="brands[${editBrandCounter}][name]" class="form-control" placeholder="Brand name">
        </div>
        <div class="col-md-5">
            <input type="number" step="0.01" name="brands[${editBrandCounter}][price]" class="form-control" placeholder="Price (RM)">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.parentElement.remove()">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;
    container.appendChild(div);
    editBrandCounter++;
}

// Show/hide manual category input when "Add New Category" is selected
document.getElementById('categorySelect').addEventListener('change', function() {
    const newCategoryInput = document.getElementById('newCategoryInput');
    if (this.value === 'other') {
        newCategoryInput.style.display = 'block';
        newCategoryInput.required = true;
        newCategoryInput.focus();
    } else {
        newCategoryInput.style.display = 'none';
        newCategoryInput.required = false;
        newCategoryInput.value = '';
    }
});

function editPart(part) {
    document.getElementById('edit_part_id').value = part.id;
    document.getElementById('edit_part_name').value = part.part_name;
    document.getElementById('edit_category').value = part.category || '';
    document.getElementById('edit_description').value = part.description || '';
    document.getElementById('edit_price_min').value = part.price_min || '';
    document.getElementById('edit_price_max').value = part.price_max || '';
    document.getElementById('edit_status').value = part.status;
    document.getElementById('edit_existing_image').value = part.image_path || '';
    
    if (part.image_path && part.image_path !== '') {
        document.getElementById('edit_image_preview').innerHTML = `<img src="${part.image_path}" style="width: 80px; height: 80px; object-fit: cover; border-radius: 10px;">`;
    } else {
        document.getElementById('edit_image_preview').innerHTML = '';
    }
    
    // Load brands for this part
    fetch(`get_part_brands.php?part_id=${part.id}`)
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('editBrandsContainer');
            container.innerHTML = '';
            editBrandCounter = 0;
            
            if (data.brands && data.brands.length > 0) {
                data.brands.forEach((brand, index) => {
                    const div = document.createElement('div');
                    div.className = 'row g-2 mb-2';
                    div.innerHTML = `
                        <div class="col-md-6">
                            <input type="text" name="brands[${index}][name]" class="form-control" value="${brand.name.replace(/"/g, '&quot;')}" placeholder="Brand name">
                        </div>
                        <div class="col-md-5">
                            <input type="number" step="0.01" name="brands[${index}][price]" class="form-control" value="${brand.price || ''}" placeholder="Price (RM)">
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.parentElement.remove()">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    `;
                    container.appendChild(div);
                    editBrandCounter = index + 1;
                });
            }
        })
        .catch(error => {
            console.error('Error loading brands:', error);
        });
}

function deletePart(id, name) {
    document.getElementById('delete_part_id').value = id;
    document.getElementById('delete_part_name').textContent = name;
}

$(document).ready(function() {
    $('#partsTable').DataTable({
        pageLength: 10,
        order: [[0, 'desc']],
        searching: false,  // This removes the search bar
        language: {
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            emptyTable: "No components found",
            paginate: {
                first: '<i class="bi bi-chevron-double-left"></i>',
                last: '<i class="bi bi-chevron-double-right"></i>',
                next: '<i class="bi bi-chevron-right"></i>',
                previous: '<i class="bi bi-chevron-left"></i>'
            }
        }
    });
});
</script>

</body>
</html>
