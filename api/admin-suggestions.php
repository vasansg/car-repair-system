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

if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}

/* ================= HANDLE FORM SUBMISSIONS ================= */
$message      = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['form_token'] ?? '';
    $is_duplicate    = ($submitted_token === ($_SESSION['last_token'] ?? ''));

    if (!$is_duplicate) {
        $_SESSION['last_token'] = $submitted_token;
        $now = gmdate('Y-m-d\TH:i:s\Z');

        if (isset($_POST['add_suggestions'])) {
            $vehicle_id   = trim($_POST['vehicle_id']);
            $user_id      = trim($_POST['user_id']);
            $booking_id   = trim($_POST['booking_id'] ?? '');
            $service_ids  = $_POST['service_ids'] ?? [];
            $suggested_date = $_POST['suggested_date'];
            $notes        = trim($_POST['notes']);

            if (empty($service_ids)) {
                $message      = "Please select at least one service to suggest.";
                $message_type = 'danger';
            } else {
                $success_count = 0;
                $error_count   = 0;
                foreach ($service_ids as $service_id) {
                    $service_id = trim($service_id);
                    $id = $firebase->addDoc('service_suggestions', [
                        'booking_id'          => $booking_id,
                        'vehicle_id'          => $vehicle_id,
                        'user_id'             => $user_id,
                        'service_category_id' => $service_id,
                        'suggested_date'      => $suggested_date,
                        'notes'               => $notes,
                        'status'              => 'pending',
                        'completed_notes'     => '',
                        'created_at'          => $now,
                        'updated_at'          => $now,
                    ]);
                    $id ? $success_count++ : $error_count++;
                }
                if ($success_count > 0) {
                    $message = "$success_count service suggestion(s) added successfully!";
                    if ($error_count > 0) $message .= " $error_count failed.";
                    $message_type = 'success';
                } else {
                    $message      = "Error adding suggestions.";
                    $message_type = 'danger';
                }
            }
            $_SESSION['form_token'] = bin2hex(random_bytes(32));
        }

        if (isset($_POST['update_suggestion'])) {
            $suggestion_id       = trim($_POST['suggestion_id']);
            $service_category_id = trim($_POST['service_category_id']);
            $suggested_date      = $_POST['suggested_date'];
            $notes               = trim($_POST['notes']);

            $ok = $firebase->updateDoc('service_suggestions', $suggestion_id, [
                'service_category_id' => $service_category_id,
                'suggested_date'      => $suggested_date,
                'notes'               => $notes,
                'updated_at'          => $now,
            ]);
            $message      = $ok ? "Suggestion updated successfully!" : "Error updating suggestion.";
            $message_type = $ok ? 'success' : 'danger';
            $_SESSION['form_token'] = bin2hex(random_bytes(32));
        }

        if (isset($_POST['delete_suggestion'])) {
            $suggestion_id = trim($_POST['suggestion_id']);
            $ok            = $firebase->deleteDoc('service_suggestions', $suggestion_id);
            $message       = $ok ? "Suggestion deleted successfully!" : "Error deleting suggestion.";
            $message_type  = $ok ? 'success' : 'danger';
            $_SESSION['form_token'] = bin2hex(random_bytes(32));
        }

        if (isset($_POST['mark_done'])) {
            $suggestion_id   = trim($_POST['suggestion_id']);
            $completed_notes = trim($_POST['completed_notes']);
            $ok = $firebase->updateDoc('service_suggestions', $suggestion_id, [
                'status'          => 'done',
                'completed_notes' => $completed_notes,
                'updated_at'      => $now,
            ]);
            $message      = $ok ? "Suggestion marked as done!" : "Error updating suggestion.";
            $message_type = $ok ? 'success' : 'danger';
            $_SESSION['form_token'] = bin2hex(random_bytes(32));
        }
    } else {
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
    }
}

// Get selected vehicle from URL
$selected_vehicle_id = trim($_GET['vehicle_id'] ?? '');

/* ================= FETCH CUSTOMERS WITH VEHICLES ================= */
$allCustomers = $firebase->query('users', [['role', '==', 'customer']], 'full_name', 'ASCENDING');
$allVehicles  = $firebase->query('vehicles', [], 'brand_name', 'ASCENDING');

$customers = [];
foreach ($allCustomers as $cu) {
    $customers[$cu['id']]['info'] = [
        'name'  => $cu['full_name'],
        'email' => $cu['email'],
        'phone' => $cu['phone'] ?? '',
    ];
    $customers[$cu['id']]['vehicles'] = [];
}
foreach ($allVehicles as $v) {
    $uid = $v['user_id'] ?? '';
    if (isset($customers[$uid])) {
        $customers[$uid]['vehicles'][] = $v;
    }
}

/* ================= FETCH SERVICE CATEGORIES ================= */
$service_categories = $firebase->query('service_categories', [['is_active', '==', true]], 'category_name', 'ASCENDING');
$categories_by_type = [];
foreach ($service_categories as $cat) {
    $categories_by_type[$cat['type'] ?? 'General'][] = $cat;
}
ksort($categories_by_type);

// Build a lookup for resolving category name by ID
$catById = [];
foreach ($service_categories as $cat) {
    $catById[$cat['id']] = $cat;
}

/* ================= SELECTED VEHICLE ================= */
$selected_vehicle_data = null;
$active_suggestions    = [];
$completed_items       = [];

if (!empty($selected_vehicle_id)) {
    $vehicle = $firebase->getDoc('vehicles', $selected_vehicle_id);
    if ($vehicle) {
        $customer = $firebase->getDoc('users', $vehicle['user_id'] ?? '');
        $selected_vehicle_data = array_merge($vehicle, [
            'customer_name' => $customer['full_name'] ?? '',
            'user_id'       => $vehicle['user_id'] ?? '',
        ]);

        // Fetch all suggestions for this vehicle, split in PHP
        $allSuggestions = $firebase->query(
            'service_suggestions',
            [['vehicle_id', '==', $selected_vehicle_id]],
            'suggested_date', 'ASCENDING'
        );

        $active_statuses = ['pending', 'booked', 'in_progress'];
        foreach ($allSuggestions as $sug) {
            $catId   = $sug['service_category_id'] ?? '';
            $catInfo = $catById[$catId] ?? [];
            $sug['service_name'] = $catInfo['category_name'] ?? 'Unknown Service';
            $sug['service_type'] = $catInfo['type'] ?? '';

            if (in_array($sug['status'] ?? '', $active_statuses)) {
                $active_suggestions[] = $sug;
            } elseif (($sug['status'] ?? '') === 'done') {
                $completed_items[] = $sug;
            }
        }

        // Sort active: pending → booked → in_progress
        usort($active_suggestions, function ($a, $b) {
            $order = ['pending' => 1, 'booked' => 2, 'in_progress' => 3];
            return ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9);
        });

        // Sort completed by updated_at DESC
        usort($completed_items, function ($a, $b) {
            return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
        });
    }
}

$hour = date('G');
if ($hour >= 5 && $hour < 12) {
    $greeting = 'Good Morning';
} elseif ($hour >= 12 && $hour < 18) {
    $greeting = 'Good Afternoon';
} else {
    $greeting = 'Good Evening';
}

$form_token = $_SESSION['form_token'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Suggestions | CS KUMARESAN MOTOR</title>
    
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
        
        /* ================= VEHICLE SELECTOR ================= */
        .vehicle-selector-card {
            background: white;
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
        }
        
        .vehicle-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .vehicle-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
            display: block;
            background: white;
        }
        
        .vehicle-card:hover {
            border-color: #1e40af;
            background: #f8fafc;
        }
        
        .vehicle-icon {
            width: 40px;
            height: 40px;
            background: #eff6ff;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: #1e40af;
            margin-bottom: 0.75rem;
        }
        
        .customer-name {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }
        
        .vehicle-detail {
            font-size: 0.75rem;
            color: #64748b;
        }
        
        .vehicle-plate {
            background: #1e293b;
            color: white;
            padding: 0.15rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            display: inline-block;
            margin-top: 0.5rem;
        }
        
        /* ================= SELECTED VEHICLE HEADER ================= */
        .selected-vehicle-header {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
        }
        
        .vehicle-title {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
        }
        
        .vehicle-subtitle {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-top: 0.25rem;
            flex-wrap: wrap;
        }
        
        .vehicle-plate-badge {
            background: #1e293b;
            color: white;
            padding: 0.2rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }
        
        .vehicle-year-color {
            color: #64748b;
            font-size: 0.75rem;
        }
        
        /* ================= MAIN CARD ================= */
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
        
        /* ================= THREE COLUMN LAYOUT ================= */
        .three-column {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
        }
        
        @media (max-width: 992px) {
            .three-column {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .three-column {
                grid-template-columns: 1fr;
            }
        }
        
        /* ================= STATUS BADGES ================= */
        .status-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-booked {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-in_progress {
            background: #fed7aa;
            color: #9a3412;
        }
        
        .status-done {
            background: #d1fae5;
            color: #065f46;
        }
        
        /* ================= SUGGESTIONS TABLE ================= */
        .suggestions-table {
            width: 100%;
            font-size: 0.75rem;
        }
        
        .suggestions-table td {
            padding: 0.6rem 0.5rem;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        
        .suggestions-table tr:last-child td {
            border-bottom: none;
        }
        
        .service-cell .service-main {
            font-weight: 600;
        }
        
        .service-cell .service-type {
            font-size: 0.65rem;
            color: #64748b;
        }
        
        .notes-text {
            font-size: 0.65rem;
            color: #64748b;
            margin-top: 0.2rem;
        }
        
        /* ================= ACTION BUTTONS ================= */
        .btn-action {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            cursor: pointer;
            transition: all 0.2s ease;
            margin: 0 2px;
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
        
        .btn-done {
            background: #d1fae5;
            color: #059669;
        }
        
        /* ================= HISTORY LIST ================= */
        .history-list {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .history-item {
            padding: 0.75rem;
            background: #f8fafc;
            border-radius: 10px;
            margin-bottom: 0.75rem;
            border-left: 3px solid #1e40af;
        }
        
        .history-item.done-item {
            border-left-color: #059669;
            background: #f0fdf4;
        }
        
        .history-date {
            font-weight: 600;
            font-size: 0.7rem;
            color: #1e40af;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            margin-bottom: 0.25rem;
        }
        
        .history-item.done-item .history-date {
            color: #059669;
        }
        
        .history-service {
            font-weight: 500;
            font-size: 0.75rem;
        }
        
        .history-notes {
            font-size: 0.65rem;
            color: #64748b;
            margin-top: 0.3rem;
            padding-top: 0.3rem;
            border-top: 1px dashed #e2e8f0;
        }
        
        .badge-done {
            background: #d1fae5;
            color: #065f46;
            font-size: 0.6rem;
            padding: 0.1rem 0.4rem;
            border-radius: 20px;
        }
        
        /* ================= SERVICE CHECKBOX GROUP ================= */
        .service-search {
            position: relative;
            margin-bottom: 0.75rem;
        }
        
        .service-search i {
            position: absolute;
            left: 0.8rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.8rem;
        }
        
        .service-search input {
            padding-left: 2rem;
            border: 1px solid #e2e8f0;
            border-radius: 30px;
            font-size: 0.75rem;
            padding: 0.4rem 0.8rem 0.4rem 2rem;
            width: 100%;
        }
        
        .service-checkbox-group {
            max-height: 350px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.5rem;
            background: #f8fafc;
        }
        
        .service-type-section {
            margin-bottom: 0.75rem;
        }
        
        .service-type-title {
            font-weight: 600;
            font-size: 0.7rem;
            color: #1e40af;
            padding: 0.3rem 0;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 0.3rem;
        }
        
        .service-checkbox-item {
            display: flex;
            align-items: center;
            padding: 0.3rem;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 0.3rem;
        }
        
        .service-checkbox-item input[type="checkbox"] {
            width: 14px;
            height: 14px;
            margin-right: 0.5rem;
            cursor: pointer;
        }
        
        .service-checkbox-item label {
            flex: 1;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.7rem;
            margin: 0;
        }
        
        /* ================= FORM ================= */
        .form-label {
            font-weight: 600;
            font-size: 0.75rem;
            margin-bottom: 0.3rem;
            color: #475569;
        }
        
        .form-control, .form-select {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #1e40af;
            outline: none;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
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
        
        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        /* ================= ALERT ================= */
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
        }
        
        .modal-header.bg-primary {
            background: linear-gradient(135deg, #1e40af, #1e3a8a) !important;
        }
        
        .modal-header.bg-success {
            background: linear-gradient(135deg, #059669, #10b981) !important;
        }
        
        .modal-header.bg-danger {
            background: linear-gradient(135deg, #dc2626, #991b1b) !important;
        }
        
        .modal-title {
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .modal-body {
            padding: 1rem;
        }
        
        .modal-footer {
            padding: 0.75rem 1rem;
            border-top: 1px solid #e2e8f0;
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
        
        /* ================= EMPTY STATE ================= */
        .empty-state {
            text-align: center;
            padding: 2rem;
        }
        
        .empty-state i {
            font-size: 2rem;
            color: #cbd5e1;
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            font-size: 0.75rem;
            color: #64748b;
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
            
            .three-column {
                gap: 1rem;
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
                <i class="bi bi bi-car-front-fill"></i>
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
                <i class="bi bi-<?php echo $message_type == 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                
            </div>
        <?php endif; ?>
        
        <!-- Breadcrumb -->
        <div class="breadcrumb-bar">
            <a href="admin-suggestions.php">Service Suggestions</a>
            <?php if ($selected_vehicle_data): ?>
                <span class="separator">›</span>
                <span><?php echo htmlspecialchars($selected_vehicle_data['brand_name'] . ' ' . $selected_vehicle_data['model'] . ' (' . $selected_vehicle_data['number_plate'] . ')'); ?></span>
            <?php endif; ?>
        </div>
        
        <?php if (!$selected_vehicle_data): ?>

    <!-- Search Section -->
    <!-- Search Bar -->
        <div class="search-section" style="margin-bottom: 1rem;">
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="vehicleSearchInput" placeholder="Search by customer name, vehicle model, or plate number...">
            </div>
        </div>
            <div class="vehicle-selector-card">
                <h5 class="mb-2" style="font-size: 0.9rem; font-weight: 600;">
                    <i class="bi bi-car-front-fill me-2"></i>Select a Vehicle to Manage Suggestions
                </h5>
                
                <div class="vehicle-grid">
                    <?php foreach ($customers as $customer_id => $data): 
                        $customer = $data['info'];
                        foreach ($data['vehicles'] as $vehicle):
                    ?>
                        <a href="?vehicle_id=<?php echo $vehicle['id']; ?>" class="vehicle-card">
                            <div class="customer-name"><?php echo htmlspecialchars($customer['name']); ?></div>
                            <div class="vehicle-detail">
                                <?php echo htmlspecialchars($vehicle['brand_name'] . ' ' . $vehicle['model']); ?>
                            </div>
                            <div class="vehicle-detail">
                                <?php echo $vehicle['year']; ?> • <?php echo htmlspecialchars($vehicle['color']); ?>
                            </div>
                            <div class="vehicle-plate"><?php echo htmlspecialchars($vehicle['number_plate']); ?></div>
                        </a>
                    <?php 
                        endforeach;
                    endforeach; 
                    ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($selected_vehicle_data): ?>
            <!-- Selected Vehicle Header -->
            <div class="selected-vehicle-header">
                <div class="vehicle-title"><?php echo strtoupper(htmlspecialchars($selected_vehicle_data['brand_name'] . ' ' . $selected_vehicle_data['model'])); ?></div>
                <div class="vehicle-subtitle">
                    <span class="vehicle-plate-badge"><?php echo htmlspecialchars($selected_vehicle_data['number_plate']); ?></span>
                    <span class="vehicle-year-color"><?php echo $selected_vehicle_data['year']; ?> • <?php echo strtoupper(htmlspecialchars($selected_vehicle_data['color'])); ?></span>
                </div>
            </div>
            
            <!-- Three Column Layout -->
            <div class="three-column">
                <!-- Column 1: Active Suggestions -->
                <div class="main-card">
                    <div class="card-header">
                        <h2>
                            <i class="bi bi-clock-history"></i>
                            Active Suggestions
                            <span class="badge bg-primary ms-2" style="font-size: 0.65rem;"><?php echo count($active_suggestions); ?></span>
                        </h2>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($active_suggestions)): ?>
                            <div class="empty-state">
                                <i class="bi bi-clock-history"></i>
                                <p>No active suggestions.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="suggestions-table">
                                    <tbody>
                                        <?php foreach ($active_suggestions as $suggestion): 
                                            $status_class = 'status-' . $suggestion['status'];
                                            $status_text = $suggestion['status'] == 'in_progress' ? 'In Progress' : ucfirst($suggestion['status']);
                                        ?>
                                            <tr>
                                                <td class="service-cell">
                                                    <div class="service-main"><?php echo htmlspecialchars($suggestion['service_name']); ?></div>
                                                    <div class="service-type"><?php echo htmlspecialchars($suggestion['service_type']); ?></div>
                                                    <?php if (!empty($suggestion['notes'])): ?>
                                                        <div class="notes-text">
                                                            <i class="bi bi-chat-text"></i> <?php echo htmlspecialchars($suggestion['notes']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($suggestion['completed_notes'])): ?>
                                                        <div class="notes-text">
                                                            <i class="bi bi-info-circle"></i> <?php echo htmlspecialchars($suggestion['completed_notes']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                 </div>
                                                <td class="date-cell" style="white-space: nowrap;"><?php echo date('M d, Y', strtotime($suggestion['suggested_date'])); ?></div>
                                                <td class="status-cell" style="white-space: nowrap;">
                                                    <span class="status-badge <?php echo $status_class; ?>">
                                                        <?php echo $status_text; ?>
                                                    </span>
                                                 </div>
                                                <td class="action-cell" style="white-space: nowrap;">
                                                    <?php if ($suggestion['status'] == 'pending'): ?>
                                                        <button class="btn-action btn-done" onclick="markSuggestionDone('<?php echo $suggestion['id']; ?>')"
                                                                data-bs-toggle="modal" data-bs-target="#doneSuggestionModal" title="Mark as Done">
                                                            <i class="bi bi-check-lg"></i>
                                                        </button>
                                                        <button class="btn-action btn-edit" onclick='editSuggestion(<?php echo json_encode($suggestion); ?>)'
                                                                data-bs-toggle="modal" data-bs-target="#editSuggestionModal" title="Edit">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button class="btn-action btn-delete" onclick="deleteSuggestion('<?php echo $suggestion['id']; ?>')"
                                                                data-bs-toggle="modal" data-bs-target="#deleteSuggestionModal" title="Delete">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary" style="font-size: 0.6rem;">Auto-tracked</span>
                                                    <?php endif; ?>
                                                 </div>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Column 2: Completed Services History -->
                <div class="main-card">
                    <div class="card-header">
                        <h2>
                            <i class="bi bi-calendar-check"></i>
                            Completed Services History
                            <span class="badge bg-success ms-2" style="font-size: 0.65rem;"><?php echo count($completed_items); ?></span>
                        </h2>
                    </div>
                    <div class="card-body">
                        <?php if (empty($completed_items)): ?>
                            <div class="empty-state">
                                <i class="bi bi-calendar-check"></i>
                                <p>No completed services.</p>
                            </div>
                        <?php else: ?>
                            <div class="history-list">
                                <?php foreach ($completed_items as $item): 
                                    $is_suggestion = isset($item['status']) && $item['status'] == 'done';
                                    $date = $item['completed_date'] ?? $item['service_date'] ?? $item['updated_at'] ?? '';
                                    $service_name = $item['service_name'];
                                    $service_type = $item['service_type'];
                                    $notes = $is_suggestion ? ($item['completed_notes'] ?? '') : ($item['notes'] ?? '');
                                ?>
                                    <div class="history-item <?php echo $is_suggestion ? 'done-item' : ''; ?>">
                                        <div class="history-date">
                                            <i class="bi bi-calendar-check"></i> 
                                            <?php 
                                            $display_date = !empty($date) ? date('M d, Y', strtotime($date)) : 'Date not available';
                                            echo $display_date; 
                                            ?>
                                            <?php if ($is_suggestion): ?>
                                                <span class="badge-done ms-1">Done</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="history-service">
                                            <?php echo htmlspecialchars($service_name); ?>
                                            <small class="text-muted">(<?php echo htmlspecialchars($service_type); ?>)</small>
                                        </div>
                                        <?php if (!empty($notes)): ?>
                                            <div class="history-notes">
                                                <i class="bi bi-chat-text"></i> <?php echo htmlspecialchars($notes); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Column 3: Add New Suggestions -->
                <div class="main-card">
                    <div class="card-header">
                        <h2>
                            <i class="bi bi-plus-circle"></i>
                            Add New Suggestions
                        </h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="suggestionsForm">
                            <input type="hidden" name="form_token" value="<?php echo $form_token; ?>">
                            <input type="hidden" name="vehicle_id" value="<?php echo $selected_vehicle_data['id']; ?>">
                            <input type="hidden" name="user_id" value="<?php echo $selected_vehicle_data['user_id']; ?>">
                            <input type="hidden" name="booking_id" value="0">
                            
                            <div class="mb-3">
                                <label class="form-label">Select Services to Suggest</label>
                                
                                <div class="service-search">
                                    <i class="bi bi-search"></i>
                                    <input type="text" id="serviceSearch" placeholder="Search services...">
                                </div>
                                
                                <div class="service-checkbox-group" id="serviceCheckboxGroup">
                                    <?php foreach ($categories_by_type as $type => $cats): ?>
                                        <div class="service-type-section" data-type="<?php echo htmlspecialchars($type); ?>">
                                            <div class="service-type-title"><?php echo htmlspecialchars($type); ?></div>
                                            <?php foreach ($cats as $cat): ?>
                                                <div class="service-checkbox-item" data-name="<?php echo strtolower(htmlspecialchars($cat['category_name'])); ?>" data-type="<?php echo strtolower(htmlspecialchars($type)); ?>">
                                                    <input type="checkbox" name="service_ids[]" value="<?php echo $cat['id']; ?>" id="service_<?php echo $cat['id']; ?>">
                                                    <label for="service_<?php echo $cat['id']; ?>">
                                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Suggested Date</label>
                                <input type="date" class="form-control" name="suggested_date" 
                                       min="<?php echo date('Y-m-d'); ?>" 
                                       max="<?php echo date('Y-m-d', strtotime('+1 year')); ?>" 
                                       value="<?php echo date('Y-m-d', strtotime('+3 months')); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Notes (Optional)</label>
                                <textarea class="form-control" name="notes" rows="3" 
                                          placeholder="Any specific reason for these suggestions?"></textarea>
                            </div>
                            
                            <button type="submit" name="add_suggestions" class="btn btn-primary w-100">
                                <i class="bi bi-plus-circle me-2"></i> Add Selected Suggestions
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
    </div>
</div>

<!-- Edit Suggestion Modal -->
<div class="modal fade" id="editSuggestionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square me-2"></i>
                    Edit Suggestion
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="form_token" value="<?php echo $form_token; ?>">
                    <input type="hidden" name="suggestion_id" id="edit_suggestion_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Service</label>
                        <select class="form-select" name="service_category_id" id="edit_service_id" required>
                            <?php foreach ($categories_by_type as $type => $cats): ?>
                                <optgroup label="<?php echo htmlspecialchars($type); ?>">
                                    <?php foreach ($cats as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>">
                                            <?php echo htmlspecialchars($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Suggested Date</label>
                        <input type="date" class="form-control" name="suggested_date" id="edit_suggested_date" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="edit_notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_suggestion" class="btn btn-primary">Update Suggestion</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Done Suggestion Modal -->
<div class="modal fade" id="doneSuggestionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-check-circle me-2"></i>
                    Mark Suggestion as Done
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="form_token" value="<?php echo $form_token; ?>">
                    <input type="hidden" name="suggestion_id" id="done_suggestion_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Notes / Remarks</label>
                        <textarea class="form-control" name="completed_notes" rows="3" 
                                  placeholder="E.g., Customer confirmed done at outside workshop, Customer skipped, etc."></textarea>
                        <div class="form-text small text-muted">These notes will appear in the completed history.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="mark_done" class="btn btn-success">Mark as Done</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Suggestion Modal -->
<div class="modal fade" id="deleteSuggestionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Delete Suggestion
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="form_token" value="<?php echo $form_token; ?>">
                    <input type="hidden" name="suggestion_id" id="delete_suggestion_id">
                    <p>Are you sure you want to delete this suggestion? This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_suggestion" class="btn btn-danger">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Form validation for suggestions
    const suggestionsForm = document.getElementById('suggestionsForm');
    if (suggestionsForm) {
        suggestionsForm.addEventListener('submit', function(e) {
            const checkboxes = document.querySelectorAll('input[name="service_ids[]"]:checked');
            
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one service to suggest.');
                return false;
            }
        });
    }

    // Search functionality for services
    const serviceSearch = document.getElementById('serviceSearch');
    if (serviceSearch) {
        serviceSearch.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const sections = document.querySelectorAll('.service-type-section');
            
            sections.forEach(section => {
                const items = section.querySelectorAll('.service-checkbox-item');
                let visibleCount = 0;
                
                items.forEach(item => {
                    const name = item.getAttribute('data-name') || '';
                    const type = item.getAttribute('data-type') || '';
                    
                    if (name.includes(searchTerm) || type.includes(searchTerm) || searchTerm === '') {
                        item.style.display = 'flex';
                        visibleCount++;
                    } else {
                        item.style.display = 'none';
                    }
                });
                
                if (visibleCount === 0) {
                    section.style.display = 'none';
                } else {
                    section.style.display = 'block';
                }
            });
        });
    }

    function editSuggestion(suggestion) {
        const editSuggestionId = document.getElementById('edit_suggestion_id');
        const editServiceId = document.getElementById('edit_service_id');
        const editSuggestedDate = document.getElementById('edit_suggested_date');
        const editNotes = document.getElementById('edit_notes');
        
        if (editSuggestionId) editSuggestionId.value = suggestion.id;
        if (editServiceId) editServiceId.value = suggestion.service_category_id;
        if (editSuggestedDate) editSuggestedDate.value = suggestion.suggested_date;
        if (editNotes) editNotes.value = suggestion.notes || '';
    }
    
    function markSuggestionDone(suggestionId) {
        const doneSuggestionId = document.getElementById('done_suggestion_id');
        if (doneSuggestionId) doneSuggestionId.value = suggestionId;
    }
    
    function deleteSuggestion(suggestionId) {
        const deleteSuggestionId = document.getElementById('delete_suggestion_id');
        if (deleteSuggestionId) deleteSuggestionId.value = suggestionId;
    }

    // Vehicle search functionality
    const vehicleSearchInput = document.getElementById('vehicleSearchInput');
    if (vehicleSearchInput) {
        vehicleSearchInput.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const vehicleCards = document.querySelectorAll('.vehicle-card');
            const vehicleGrid = document.querySelector('.vehicle-grid');
            let visibleCount = 0;
            
            if (vehicleCards.length > 0) {
                vehicleCards.forEach(card => {
                    const cardText = card.textContent.toLowerCase();
                    if (cardText.includes(searchTerm) || searchTerm === '') {
                        card.style.display = 'block';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });
            }
            
            // Show/hide no results message
            let noResultsMsg = document.getElementById('noVehicleResults');
            
            if (visibleCount === 0 && searchTerm !== '' && vehicleGrid) {
                if (!noResultsMsg) {
                    noResultsMsg = document.createElement('div');
                    noResultsMsg.id = 'noVehicleResults';
                    noResultsMsg.className = 'text-center py-4';
                    noResultsMsg.innerHTML = '<i class="bi bi-search" style="font-size: 2rem; color: #cbd5e1;"></i><p class="mt-2 text-muted">No vehicles match your search.</p>';
                    if (vehicleGrid.parentNode) {
                        vehicleGrid.parentNode.insertBefore(noResultsMsg, vehicleGrid.nextSibling);
                    }
                }
                if (vehicleGrid) vehicleGrid.style.display = 'none';
                if (noResultsMsg) noResultsMsg.style.display = 'block';
            } else {
                if (vehicleGrid) vehicleGrid.style.display = 'grid';
                if (noResultsMsg) {
                    noResultsMsg.style.display = 'none';
                }
            }
        });
    }
</script>

</body>
</html>
