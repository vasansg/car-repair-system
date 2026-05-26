<?php
ob_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/includes/config.php';

// Get security images from Firestore
$security_images = $firebase->query('security_images', [['is_active', '==', true]]);

$error = '';
$success = '';

// ================= SEND OTP FUNCTION =================
function sendOTPEmail($toEmail, $toName, $otp) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'tarvenmurugarajan@gmail.com';
        $mail->Password   = 'ssch mquw dcjw abmz';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('yourgmail@gmail.com', 'CS Kumaresan Motor');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = 'Email Verification - CS Kumaresan Motor';
        $mail->Body = "
        <html>
        <head>
            <title>Email Verification</title>
        </head>
        <body>
            <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;'>
                <div style='text-align: center; margin-bottom: 20px;'>
                    <h2 style='color: #1e40af;'>CS KUMARESAN MOTOR</h2>
                    <p style='color: #666;'>Professional Automotive Service</p>
                </div>
                <div style='background: #f0fdf4; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 20px;'>
                    <p style='margin: 0; color: #166534;'>Your verification code is:</p>
                    <h1 style='margin: 10px 0; color: #1e40af; letter-spacing: 5px;'>$otp</h1>
                </div>
                <p style='color: #666; font-size: 12px; text-align: center;'>If you didn't request this, please ignore this email.</p>
                <hr>
                <p style='color: #999; font-size: 10px; text-align: center;'>CS Kumaresan Motor - Professional Automotive Service</p>
            </div>
        </body>
        </html>
        ";

        $mail->send();
        return true;

    } catch (Exception $e) {
        return false;
    }
}

// ================= HANDLE AJAX REQUESTS =================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');

    // Send OTP
    if (isset($_POST['send_otp'])) {
        $email     = trim($_POST['email']);
        $full_name = trim($_POST['full_name']);
        $phone     = trim($_POST['phone']);

        // Check if email already exists in Firestore
        if ($firebase->exists('users', [['email', '==', $email]])) {
            echo json_encode(['success' => false, 'message' => 'Email already registered!']);
            exit();
        }
        
        // Generate OTP
        $otp = sprintf("%06d", mt_rand(1, 999999));
        
        // Store in session
        $_SESSION['temp_email'] = $email;
        $_SESSION['temp_fullname'] = $full_name;
        $_SESSION['temp_phone'] = $phone;
        $_SESSION['email_otp'] = $otp;
        $_SESSION['otp_expiry'] = time() + 300;
        $_SESSION['registration_step'] = 2;
        
        if (sendOTPEmail($email, $full_name, $otp)) {
            echo json_encode(['success' => true, 'message' => 'OTP sent successfully! Check your email.', 'step' => 2]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send OTP. Please try again.']);
        }
        exit();
    }
    
    // Verify OTP
    if (isset($_POST['verify_otp'])) {
        $entered_otp = trim($_POST['otp_code']);
        
        if (!isset($_SESSION['email_otp'])) {
            echo json_encode(['success' => false, 'message' => 'Please request OTP first.']);
            exit();
        }
        
        if (isset($_SESSION['otp_expiry']) && time() > $_SESSION['otp_expiry']) {
            echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
            exit();
        }
        
        if ($entered_otp == $_SESSION['email_otp']) {
            $_SESSION['email_verified'] = true;
            $_SESSION['registration_step'] = 3;
            echo json_encode(['success' => true, 'message' => 'Email verified successfully!', 'step' => 3]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid OTP. Please try again.']);
        }
        exit();
    }
    
    // Resend OTP
    if (isset($_POST['resend_otp'])) {
        if (isset($_SESSION['temp_email']) && isset($_SESSION['temp_fullname'])) {
            $email = $_SESSION['temp_email'];
            $full_name = $_SESSION['temp_fullname'];
            $otp = sprintf("%06d", mt_rand(1, 999999));
            
            $_SESSION['email_otp'] = $otp;
            $_SESSION['otp_expiry'] = time() + 300;
            
            if (sendOTPEmail($email, $full_name, $otp)) {
                echo json_encode(['success' => true, 'message' => 'New OTP sent successfully! Check your email.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to send OTP.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Session expired. Please start over.']);
        }
        exit();
    }
    
    // Complete Registration
if (isset($_POST['complete_registration'])) {
    // Check if email is verified
    if (!isset($_SESSION['email_verified']) || $_SESSION['email_verified'] !== true) {
        echo json_encode(['success' => false, 'message' => 'Please verify your email first!']);
        exit();
    }
    
    $email = $_SESSION['temp_email'];
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $full_name = $_SESSION['temp_fullname'];
    $phone = $_SESSION['temp_phone'];
    $security_phrase = trim($_POST['security_phrase']);
    $security_image_id = isset($_POST['security_image']) ? intval($_POST['security_image']) : 0;
    
    $errors = [];
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match!";
    } elseif (strlen($password) < 10) {
        $errors[] = "Password must be at least 10 characters!";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must include at least one uppercase letter!";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must include at least one lowercase letter!";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must include at least one number!";
    } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must include at least one special character!";
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $errors[] = "Username must be 3-20 characters (letters, numbers, underscore only)!";
    } elseif (empty($security_phrase)) {
        $errors[] = "Security phrase is required!";
    } elseif (strlen($security_phrase) < 5) {
        $errors[] = "Security phrase must be at least 5 characters!";
    } elseif ($security_image_id == 0) {
        $errors[] = "Please select a security image!";
    }
    
    // Check if username already exists in Firestore
    if (empty($errors)) {
        if ($firebase->exists('users', [['username', '==', $username]])) {
            $errors[] = "Username already taken! Please choose another username.";
        }
    }

    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit();
    }

    // Get image path from Firestore
    $image_data = $firebase->getDoc('security_images', (string)$security_image_id);

    if ($image_data) {
        $image_path    = $image_data['image_path'] ?? '';
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $newId = $firebase->addDoc('users', [
            'email'               => $email,
            'username'            => $username,
            'password_hash'       => $password_hash,
            'full_name'           => $full_name,
            'phone'               => $phone,
            'security_image_path' => $image_path,
            'security_phrase'     => $security_phrase,
            'role'                => 'customer',
            'is_active'           => true,
            'created_at'          => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        if ($newId) {
            unset($_SESSION['temp_email'], $_SESSION['temp_fullname'], $_SESSION['temp_phone'],
                  $_SESSION['email_otp'], $_SESSION['otp_expiry'], $_SESSION['email_verified'],
                  $_SESSION['registration_step']);

            echo json_encode(['success' => true, 'message' => 'Registration successful! Redirecting to login...', 'redirect' => 'login.php']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid security image selected!']);
    }
    exit();
}
    
    // Set step
    if (isset($_POST['set_step'])) {
        $_SESSION['registration_step'] = intval($_POST['set_step']);
        echo json_encode(['success' => true]);
        exit();
    }
}

// Determine which step to show
$current_display_step = 1;
if (isset($_SESSION['email_verified']) && $_SESSION['email_verified'] === true) {
    $current_display_step = 3;
} elseif (isset($_SESSION['temp_email']) && isset($_SESSION['email_otp'])) {
    $current_display_step = 2;
}
if (isset($_SESSION['registration_step']) && $_SESSION['registration_step'] > $current_display_step) {
    $current_display_step = $_SESSION['registration_step'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Create Account | CS KUMARESAN MOTOR</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 25%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            position: relative;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.03)" fill-opacity="1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,154.7C960,171,1056,181,1152,165.3C1248,149,1344,107,1392,85.3L1440,64L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
            background-size: cover;
            opacity: 0.3;
            pointer-events: none;
        }
        
        .register-container {
            width: 100%;
            max-width: 750px;
            margin: 0 auto;
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .register-card {
            background: white;
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }
        
        .register-header {
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            padding: 1.5rem;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .register-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.3; }
        }
        
        .logo-icon {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.8rem;
            font-size: 1.8rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .register-header h2 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }
        
        .register-header p {
            font-size: 0.75rem;
            opacity: 0.9;
            margin: 0;
        }
        
        .register-body {
            padding: 1.5rem;
        }
        
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            position: relative;
            flex-wrap: wrap;
        }
        
        .step-item {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 2;
            min-width: 0;
        }
        
        .step-circle {
            width: 38px;
            height: 38px;
            background: #f1f5f9;
            border: 2px solid #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: 700;
            font-size: 0.85rem;
            color: #64748b;
            transition: all 0.3s ease;
        }
        
        .step-item.active .step-circle {
            background: #1e40af;
            border-color: #1e40af;
            color: white;
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
        }
        
        .step-item.completed .step-circle {
            background: #10b981;
            border-color: #10b981;
            color: white;
        }
        
        .step-label {
            font-size: 0.65rem;
            font-weight: 600;
            color: #64748b;
        }
        
        .step-item.active .step-label {
            color: #1e40af;
        }
        
        .step-item.completed .step-label {
            color: #10b981;
        }
        
        .progress-line {
            position: absolute;
            top: 19px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e2e8f0;
            z-index: 1;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #1e40af, #10b981);
            width: 0%;
            transition: width 0.4s ease;
        }
        
        .form-section {
            animation: fadeIn 0.4s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateX(10px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .section-title {
            font-size: 1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .section-title i {
            color: #1e40af;
            font-size: 1.1rem;
        }
        
        .form-control, .form-select {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.6rem 0.8rem;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
            outline: none;
        }
        
        .form-label {
            font-weight: 600;
            font-size: 0.75rem;
            color: #475569;
            margin-bottom: 0.3rem;
        }
        
        .otp-section {
            background: #f8fafc;
            border-radius: 16px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .otp-input {
            font-size: 1.2rem;
            letter-spacing: 5px;
            text-align: center;
            font-weight: bold;
        }
        
        .password-strength {
            margin-top: 0.5rem;
        }
        
        .strength-bar {
            height: 4px;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        
        .strength-requirements {
            background: #f8fafc;
            border-radius: 10px;
            padding: 0.6rem;
            margin-top: 0.5rem;
            font-size: 0.7rem;
        }
        
        .requirement {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            margin-bottom: 0.3rem;
            color: #64748b;
        }
        
        .requirement.valid { color: #10b981; }
        .requirement.invalid { color: #dc2626; }
        
        .security-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(90px, 1fr));
            gap: 0.6rem;
            max-height: 250px;
            overflow-y: auto;
            padding: 0.2rem;
        }
        
        .security-card {
            cursor: pointer;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 0.5rem;
            text-align: center;
            transition: all 0.2s ease;
            background: white;
        }
        
        .security-card:hover {
            border-color: #1e40af;
            transform: translateY(-2px);
        }
        
        .security-card.selected {
            border-color: #10b981;
            background: #f0fdf4;
        }
        
        .security-card img {
            width: 100%;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 0.3rem;
        }
        
        .security-name {
            font-size: 0.6rem;
            font-weight: 600;
            color: #475569;
        }
        
        .selected-info {
            background: #f0fdf4;
            border-left: 3px solid #10b981;
            padding: 0.6rem;
            border-radius: 8px;
            margin-top: 0.8rem;
            font-size: 0.75rem;
        }
        
        .nav-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 1.5rem;
            gap: 0.8rem;
        }
        
        .btn-nav {
            padding: 0.6rem 1.2rem;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.8rem;
            border: none;
            transition: all 0.2s ease;
        }
        
        .btn-prev {
            background: #e2e8f0;
            color: #475569;
        }
        
        .btn-prev:hover {
            background: #cbd5e1;
            transform: translateX(-2px);
        }
        
        .btn-next, .btn-register {
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            color: white;
        }
        
        .btn-next:hover, .btn-register:hover {
            transform: translateX(2px);
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
        }
        
        .btn-register {
            background: linear-gradient(135deg, #10b981, #059669);
            flex: 2;
        }
        
        .btn-otp {
            background: #1e40af;
            color: white;
            border: none;
            padding: 0.6rem 1rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .btn-otp:hover {
            background: #1e3a8a;
        }
        
        .review-card {
            background: #f8fafc;
            border-radius: 16px;
            padding: 1rem;
        }
        
        .review-item {
            margin-bottom: 0.8rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .review-label {
            font-size: 0.7rem;
            color: #64748b;
            margin-bottom: 0.2rem;
        }
        
        .review-value {
            font-weight: 600;
            font-size: 0.85rem;
            color: #1e293b;
        }
        
        .alert-custom {
            border-radius: 12px;
            padding: 0.7rem 1rem;
            font-size: 0.8rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .login-link {
            text-align: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
            font-size: 0.75rem;
        }
        
        .login-link a {
            color: #1e40af;
            text-decoration: none;
            font-weight: 600;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .loading-spinner {
            background: white;
            padding: 20px;
            border-radius: 16px;
            text-align: center;
        }
        
        .loading-spinner i {
            font-size: 2rem;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 576px) {
            .step-label { font-size: 0.55rem; }
            .step-circle { width: 30px; height: 30px; font-size: 0.7rem; }
            .progress-line { top: 15px; }
            .security-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <i class="bi bi-hourglass-split"></i>
            <p class="mt-2 mb-0">Processing...</p>
        </div>
    </div>
    
    <div class="register-container">
        <div class="register-card">
            <div class="register-header">
                <div class="logo-icon">
                    <i class="bi bi-person-plus"></i>
                </div>
                <h2>Create Account</h2>
                <p>Join CS KUMARESAN MOTOR today</p>
            </div>
            
            <div class="register-body">
                <!-- Progress Steps -->
                <div class="progress-steps">
                    <div class="progress-line">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                    <div class="step-item <?php echo $current_display_step >= 1 ? 'active' : ''; ?>" id="step1-indicator">
                        <div class="step-circle">1</div>
                        <div class="step-label">Info</div>
                    </div>
                    <div class="step-item <?php echo $current_display_step >= 2 ? ($current_display_step > 2 ? 'completed' : 'active') : ''; ?>" id="step2-indicator">
                        <div class="step-circle">2</div>
                        <div class="step-label">Verify</div>
                    </div>
                    <div class="step-item <?php echo $current_display_step >= 3 ? ($current_display_step > 3 ? 'completed' : 'active') : ''; ?>" id="step3-indicator">
                        <div class="step-circle">3</div>
                        <div class="step-label">Credentials</div>
                    </div>
                    <div class="step-item <?php echo $current_display_step >= 4 ? ($current_display_step > 4 ? 'completed' : 'active') : ''; ?>" id="step4-indicator">
                        <div class="step-circle">4</div>
                        <div class="step-label">Security</div>
                    </div>
                    <div class="step-item <?php echo $current_display_step >= 5 ? 'active' : ''; ?>" id="step5-indicator">
                        <div class="step-circle">5</div>
                        <div class="step-label">Review</div>
                    </div>
                </div>
                
                <div id="alertContainer"></div>
                
                <form id="registrationForm">
                    <!-- Step 1: Name, Email, Phone -->
                    <div class="form-section" id="step1" style="display: <?php echo $current_display_step == 1 ? 'block' : 'none'; ?>">
                        <div class="section-title">
                            <i class="bi bi-person-circle"></i>
                            Personal Information
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="full_name" id="full_name" class="form-control" 
                                       value="<?php echo isset($_SESSION['temp_fullname']) ? htmlspecialchars($_SESSION['temp_fullname']) : ''; ?>"
                                       placeholder="Enter your full name" required>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Email Address *</label>
                                <input type="email" name="email" id="email" class="form-control" 
                                       value="<?php echo isset($_SESSION['temp_email']) ? htmlspecialchars($_SESSION['temp_email']) : ''; ?>"
                                       placeholder="your@email.com" required>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Phone Number *</label>
                                <input type="tel" name="phone" id="phone" class="form-control" 
                                       value="<?php echo isset($_SESSION['temp_phone']) ? htmlspecialchars($_SESSION['temp_phone']) : ''; ?>"
                                       placeholder="012-3456789" required>
                            </div>
                        </div>
                        
                        <div class="nav-buttons">
                            <div></div>
                            <button type="button" class="btn-nav btn-next" onclick="sendOTPAndNext()">
                                Next <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 2: Email OTP Verification -->
                    <div class="form-section" id="step2" style="display: <?php echo $current_display_step == 2 ? 'block' : 'none'; ?>">
                        <div class="section-title">
                            <i class="bi bi-envelope-check"></i>
                            Email Verification
                        </div>
                        
                        <div class="otp-section">
                            <p class="mb-3 text-center">We've sent a verification code to:</p>
                            <h5 class="text-center text-primary mb-3" id="displayEmail">
                                <?php echo isset($_SESSION['temp_email']) ? htmlspecialchars($_SESSION['temp_email']) : ''; ?>
                            </h5>
                            
                            <div id="otpSection" style="margin-top: 1rem;">
                                <label class="form-label">Enter OTP Code</label>
                                <div class="input-group">
                                    <input type="text" id="otp_code" class="form-control otp-input" 
                                           placeholder="000000" maxlength="6" pattern="[0-9]{6}">
                                    <button type="button" class="btn-otp" onclick="verifyOTP()">
                                        Verify
                                    </button>
                                </div>
                                <small class="text-muted mt-2 d-block">
                                    <a href="#" onclick="resendOTP(); return false;">Resend OTP</a>
                                </small>
                            </div>
                        </div>
                        
                        <div class="nav-buttons">
                            <button type="button" class="btn-nav btn-prev" onclick="goToStep(1)">
                                <i class="bi bi-arrow-left"></i> Back
                            </button>
                            <button type="button" class="btn-nav btn-next" onclick="goToStep(3)" 
                                    id="nextToStep3" style="display: <?php echo (isset($_SESSION['email_verified']) && $_SESSION['email_verified'] === true) ? 'flex' : 'none'; ?>">
                                Next <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 3: Username & Password -->
                    <div class="form-section" id="step3" style="display: <?php echo $current_display_step == 3 ? 'block' : 'none'; ?>">
                        <div class="section-title">
                            <i class="bi bi-key"></i>
                            Account Credentials
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Username *</label>
                                <input type="text" name="username" id="username" class="form-control" 
                                       placeholder="3-20 characters (letters, numbers, underscore)" required>
                                <small class="text-muted" style="font-size: 0.65rem;">Only letters, numbers, and underscore</small>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Password *</label>
                                <input type="password" name="password" id="password" class="form-control" 
                                       placeholder="At least 10 characters" required>
                                <div class="password-strength">
                                    <div class="strength-bar bg-secondary" id="strengthBar"></div>
                                </div>
                                <div class="strength-requirements" id="strengthRequirements">
                                    <div class="requirement invalid" id="req-length">
                                        <i class="bi bi-x-circle"></i> 10+ characters
                                    </div>
                                    <div class="requirement invalid" id="req-uppercase">
                                        <i class="bi bi-x-circle"></i> Uppercase letter
                                    </div>
                                    <div class="requirement invalid" id="req-lowercase">
                                        <i class="bi bi-x-circle"></i> Lowercase letter
                                    </div>
                                    <div class="requirement invalid" id="req-number">
                                        <i class="bi bi-x-circle"></i> Number
                                    </div>
                                    <div class="requirement invalid" id="req-special">
                                        <i class="bi bi-x-circle"></i> Special character
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Confirm Password *</label>
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control" 
                                       placeholder="Re-enter password" required>
                                <small class="text-muted" id="passwordMatchMsg" style="font-size: 0.65rem;">
                                    <i class="bi bi-info-circle"></i> Passwords must match
                                </small>
                            </div>
                        </div>
                        
                        <div class="nav-buttons">
                            <button type="button" class="btn-nav btn-prev" onclick="goToStep(2)">
                                <i class="bi bi-arrow-left"></i> Back
                            </button>
                            <button type="button" class="btn-nav btn-next" onclick="goToStep(4)">
                                Next <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 4: Security Image & Phrase -->
                    <div class="form-section" id="step4" style="display: <?php echo $current_display_step == 4 ? 'block' : 'none'; ?>">
                        <div class="section-title">
                            <i class="bi bi-shield-lock"></i>
                            Security Setup
                        </div>
                        
                        <?php if (count($security_images) > 0): ?>
                            <label class="form-label">Select Security Image *</label>
                            <div class="security-grid">
                                <?php foreach($security_images as $image): ?>
                                <div class="security-card" onclick="selectSecurityImage(<?php echo $image['id']; ?>, '<?php echo htmlspecialchars($image['image_name']); ?>')">
                                    <input type="radio" name="security_image" id="img_<?php echo $image['id']; ?>"
                                           value="<?php echo $image['id']; ?>" class="d-none">
                                    <img src="assets/images/<?php echo htmlspecialchars($image['image_path']); ?>"
                                         alt="<?php echo htmlspecialchars($image['image_name']); ?>"
                                         onerror="this.src='https://via.placeholder.com/100/cccccc/666666?text=Image'">
                                    <div class="security-name"><?php echo htmlspecialchars($image['image_name']); ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div id="selectedImageInfo" class="selected-info" style="display: none;">
                                <i class="bi bi-check-circle-fill text-success"></i>
                                Selected: <strong id="selectedImageName"></strong>
                            </div>
                        <?php else: ?>
                            <div class="alert-custom alert-danger">
                                <i class="bi bi-exclamation-triangle"></i>
                                No security images available.
                            </div>
                        <?php endif; ?>
                        
                        <div class="mt-3">
                            <label class="form-label">Security Phrase *</label>
                            <input type="text" name="security_phrase" id="security_phrase" class="form-control" 
                                   placeholder="e.g., My blue Proton 2023" required>
                            <small class="text-muted" style="font-size: 0.65rem;">Minimum 5 characters. Make it unique and memorable.</small>
                        </div>
                        
                        <div class="nav-buttons">
                            <button type="button" class="btn-nav btn-prev" onclick="goToStep(3)">
                                <i class="bi bi-arrow-left"></i> Back
                            </button>
                            <button type="button" class="btn-nav btn-next" onclick="goToStep(5)">
                                Next <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 5: Review & Complete -->
                    <div class="form-section" id="step5" style="display: <?php echo $current_display_step == 5 ? 'block' : 'none'; ?>">
                        <div class="section-title">
                            <i class="bi bi-check-circle"></i>
                            Review Information
                        </div>
                        
                        <div class="review-card">
                            <div class="review-item">
                                <div class="review-label">Full Name</div>
                                <div class="review-value" id="reviewName"></div>
                            </div>
                            <div class="review-item">
                                <div class="review-label">Email Address</div>
                                <div class="review-value" id="reviewEmail"></div>
                            </div>
                            <div class="review-item">
                                <div class="review-label">Phone Number</div>
                                <div class="review-value" id="reviewPhone"></div>
                            </div>
                            <div class="review-item">
                                <div class="review-label">Username</div>
                                <div class="review-value" id="reviewUsername"></div>
                            </div>
                            <div class="review-item">
                                <div class="review-label">Security Image</div>
                                <div class="review-value" id="reviewImage"></div>
                            </div>
                            <div class="review-item">
                                <div class="review-label">Security Phrase</div>
                                <div class="review-value" id="reviewPhrase"></div>
                            </div>
                        </div>
                        
                        <div class="nav-buttons">
                            <button type="button" class="btn-nav btn-prev" onclick="goToStep(4)">
                                <i class="bi bi-arrow-left"></i> Back
                            </button>
                            <button type="button" class="btn-nav btn-register" onclick="completeRegistration()">
                                <i class="bi bi-person-plus"></i> Complete Registration
                            </button>
                        </div>
                    </div>
                </form>
                
                <div class="login-link">
                    Already have an account? <a href="login.php">Sign In</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentStep = <?php echo $current_display_step; ?>;
        let selectedImageId = null;
        let selectedImageName = '';
        let isSendingOTP = false;
        
        document.addEventListener('DOMContentLoaded', function() {
            setupEventListeners();
            updateProgressFill();
            updateReviewInfo();
            
            // If email is already verified, show next button
            <?php if (isset($_SESSION['email_verified']) && $_SESSION['email_verified'] === true): ?>
                document.getElementById('nextToStep3').style.display = 'flex';
                // Auto-fill username if exists
                <?php if (isset($_SESSION['temp_email'])): ?>
                    const username = '<?php echo explode('@', $_SESSION['temp_email'])[0]; ?>';
                    if (document.getElementById('username')) {
                        document.getElementById('username').value = username;
                    }
                <?php endif; ?>
            <?php endif; ?>
        });
        
        function setupEventListeners() {
            const passwordInput = document.getElementById('password');
            if (passwordInput) {
                passwordInput.addEventListener('input', function() {
                    checkPasswordStrength(this.value);
                    checkPasswordMatch();
                });
            }
            
            const confirmPasswordInput = document.getElementById('confirm_password');
            if (confirmPasswordInput) {
                confirmPasswordInput.addEventListener('input', checkPasswordMatch);
            }
        }
        
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
        
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }
        
        function showAlert(message, type) {
            const alertContainer = document.getElementById('alertContainer');
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert-custom alert-${type}`;
            alertDiv.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'}"></i> ${message}`;
            alertContainer.innerHTML = '';
            alertContainer.appendChild(alertDiv);
            
            setTimeout(() => {
                if (alertDiv.parentNode) alertDiv.remove();
            }, 5000);
        }
        
        function sendOTPAndNext() {
            const email = document.getElementById('email').value;
            const fullName = document.getElementById('full_name').value;
            const phone = document.getElementById('phone').value;
            
            if (!email || !fullName || !phone) {
                showAlert('Please fill all fields first', 'danger');
                return;
            }
            
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showAlert('Please enter a valid email address', 'danger');
                return;
            }
            
            if (phone.length < 10) {
                showAlert('Please enter a valid phone number', 'danger');
                return;
            }
            
            if (isSendingOTP) return;
            isSendingOTP = true;
            
            showLoading();
            
            const formData = new FormData();
            formData.append('send_otp', '1');
            formData.append('email', email);
            formData.append('full_name', fullName);
            formData.append('phone', phone);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                isSendingOTP = false;
                
                if (data.success) {
                    showAlert(data.message, 'success');
                    goToStep(2);
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(error => {
                hideLoading();
                isSendingOTP = false;
                showAlert('Failed to send OTP. Please try again.', 'danger');
            });
        }
        
        function verifyOTP() {
            const otp = document.getElementById('otp_code').value;
            if (!otp || otp.length !== 6) {
                showAlert('Please enter a valid 6-digit OTP', 'danger');
                return;
            }
            
            showLoading();
            
            const formData = new FormData();
            formData.append('verify_otp', '1');
            formData.append('otp_code', otp);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showAlert(data.message, 'success');
                    document.getElementById('nextToStep3').style.display = 'flex';
                    goToStep(3);
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(error => {
                hideLoading();
                showAlert('Failed to verify OTP. Please try again.', 'danger');
            });
        }
        
        function resendOTP() {
            showLoading();
            
            const formData = new FormData();
            formData.append('resend_otp', '1');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showAlert(data.message, 'success');
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(error => {
                hideLoading();
                showAlert('Failed to resend OTP. Please try again.', 'danger');
            });
        }
        
        function selectSecurityImage(imageId, imageName) {
            document.querySelectorAll('.security-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            const selectedCard = event.currentTarget;
            selectedCard.classList.add('selected');
            selectedImageId = imageId;
            selectedImageName = imageName;
            
            document.getElementById(`img_${imageId}`).checked = true;
            document.getElementById('selectedImageName').textContent = imageName;
            document.getElementById('selectedImageInfo').style.display = 'block';
        }
        
        function goToStep(step) {
            if (step < currentStep) {
                // Going back
                for (let i = currentStep; i > step; i--) {
                    document.getElementById(`step${i}`).style.display = 'none';
                }
                document.getElementById(`step${step}`).style.display = 'block';
                currentStep = step;
                updateProgressIndicators();
                updateProgressFill();
                return;
            }
            
            // Going forward - validate
            if (!validateStep(currentStep)) {
                return;
            }
            
            // Save step via AJAX
            const formData = new FormData();
            formData.append('set_step', step);
            fetch(window.location.href, { method: 'POST', body: formData });
            
            document.getElementById(`step${currentStep}`).style.display = 'none';
            document.getElementById(`step${step}`).style.display = 'block';
            
            currentStep = step;
            updateProgressIndicators();
            updateProgressFill();
            
            if (currentStep === 5) {
                updateReviewInfo();
            }
        }
        
        function updateProgressIndicators() {
            for (let i = 1; i <= 5; i++) {
                const indicator = document.getElementById(`step${i}-indicator`);
                indicator.classList.remove('active', 'completed');
                
                if (i < currentStep) {
                    indicator.classList.add('completed');
                } else if (i === currentStep) {
                    indicator.classList.add('active');
                }
            }
        }
        
        function validateStep(step) {
            switch(step) {
                case 1:
                    const fullName = document.getElementById('full_name').value.trim();
                    const email = document.getElementById('email').value.trim();
                    const phone = document.getElementById('phone').value.trim();
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    
                    if (!fullName) {
                        showAlert('Please enter your full name', 'danger');
                        return false;
                    }
                    if (!email || !emailRegex.test(email)) {
                        showAlert('Please enter a valid email address', 'danger');
                        return false;
                    }
                    if (!phone || phone.length < 10) {
                        showAlert('Please enter a valid phone number', 'danger');
                        return false;
                    }
                    // Don't validate OTP here - it will be sent separately
                    return true;
                    
                case 2:
                    // Check if email is verified
                    const nextBtn = document.getElementById('nextToStep3');
                    if (!nextBtn || nextBtn.style.display !== 'flex') {
                        showAlert('Please verify your email first', 'danger');
                        return false;
                    }
                    return true;
                    
                case 3:
                    const username = document.getElementById('username').value.trim();
                    const password = document.getElementById('password').value;
                    const confirm = document.getElementById('confirm_password').value;
                    
                    if (!username || !/^[a-zA-Z0-9_]{3,20}$/.test(username)) {
                        showAlert('Username must be 3-20 characters (letters, numbers, underscore only)', 'danger');
                        return false;
                    }
                    
                    const req = checkPasswordStrength(password);
                    if (!req.uppercase || !req.lowercase || !req.number || !req.special) {
                        showAlert('Please meet all password requirements', 'danger');
                        return false;
                    }
                    if (password !== confirm) {
                        showAlert('Passwords do not match', 'danger');
                        return false;
                    }
                    return true;
                    
                case 4:
                    if (!selectedImageId) {
                        showAlert('Please select a security image', 'danger');
                        return false;
                    }
                    const phrase = document.getElementById('security_phrase').value.trim();
                    if (phrase.length < 5) {
                        showAlert('Security phrase must be at least 5 characters', 'danger');
                        return false;
                    }
                    return true;
                    
                default:
                    return true;
            }
        }
        
        function completeRegistration() {
    if (!selectedImageId) {
        showAlert('Please select a security image', 'danger');
        goToStep(4);
        return;
    }
    
    const phrase = document.getElementById('security_phrase').value.trim();
    if (phrase.length < 5) {
        showAlert('Security phrase must be at least 5 characters', 'danger');
        goToStep(4);
        return;
    }
    
    // Validate password again before submitting
    const password = document.getElementById('password').value;
    const confirm = document.getElementById('confirm_password').value;
    
    if (password !== confirm) {
        showAlert('Passwords do not match', 'danger');
        goToStep(3);
        return;
    }
    
    // Check password requirements
    const hasUppercase = /[A-Z]/.test(password);
    const hasLowercase = /[a-z]/.test(password);
    const hasNumber = /[0-9]/.test(password);
    const hasSpecial = /[^A-Za-z0-9]/.test(password);
    
    if (password.length < 10 || !hasUppercase || !hasLowercase || !hasNumber || !hasSpecial) {
        showAlert('Please meet all password requirements', 'danger');
        goToStep(3);
        return;
    }
    
    showLoading();
    
    const formData = new FormData();
    formData.append('complete_registration', '1');
    formData.append('username', document.getElementById('username').value);
    formData.append('password', password);
    formData.append('confirm_password', confirm);
    formData.append('security_phrase', phrase);
    formData.append('security_image', selectedImageId);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showAlert(data.message, 'success');
            setTimeout(() => {
                window.location.href = data.redirect;
            }, 2000);
        } else {
            showAlert(data.message, 'danger');
            console.error('Registration error:', data.message);
        }
    })
    .catch(error => {
        hideLoading();
        console.error('Fetch error:', error);
        showAlert('Registration failed. Please check console for details.', 'danger');
    });
}
        
        function updateProgressFill() {
            const progress = ((currentStep - 1) / 4) * 100;
            document.getElementById('progressFill').style.width = progress + '%';
        }
        
        function updateReviewInfo() {
            document.getElementById('reviewName').textContent = document.getElementById('full_name')?.value || '-';
            document.getElementById('reviewEmail').textContent = document.getElementById('email')?.value || '-';
            document.getElementById('reviewPhone').textContent = document.getElementById('phone')?.value || '-';
            document.getElementById('reviewUsername').textContent = document.getElementById('username')?.value || '-';
            document.getElementById('reviewImage').innerHTML = selectedImageName ? 
                `<i class="bi bi-check-circle-fill text-success"></i> ${selectedImageName}` : '-';
            document.getElementById('reviewPhrase').innerHTML = document.getElementById('security_phrase')?.value ? 
                `<i class="bi bi-shield-lock"></i> ${document.getElementById('security_phrase').value}` : '-';
        }
        
        function checkPasswordStrength(password) {
            let requirements = {
                length: password.length >= 10,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[^A-Za-z0-9]/.test(password)
            };
            
            updateRequirement('req-length', requirements.length);
            updateRequirement('req-uppercase', requirements.uppercase);
            updateRequirement('req-lowercase', requirements.lowercase);
            updateRequirement('req-number', requirements.number);
            updateRequirement('req-special', requirements.special);
            
            const validCount = Object.values(requirements).filter(Boolean).length;
            const strengthBar = document.getElementById('strengthBar');
            
            if (validCount <= 2) {
                strengthBar.className = 'strength-bar bg-danger';
                strengthBar.style.width = '20%';
            } else if (validCount <= 3) {
                strengthBar.className = 'strength-bar bg-warning';
                strengthBar.style.width = '40%';
            } else if (validCount <= 4) {
                strengthBar.className = 'strength-bar bg-info';
                strengthBar.style.width = '70%';
            } else {
                strengthBar.className = 'strength-bar bg-success';
                strengthBar.style.width = '100%';
            }
            
            return requirements;
        }
        
        function updateRequirement(elementId, isValid) {
            const element = document.getElementById(elementId);
            if (element) {
                element.className = isValid ? 'requirement valid' : 'requirement invalid';
                element.innerHTML = isValid ? 
                    `<i class="bi bi-check-circle-fill"></i> ${elementId.replace('req-', '').replace('-', ' ')}` : 
                    `<i class="bi bi-x-circle"></i> ${elementId.replace('req-', '').replace('-', ' ')}`;
            }
        }
        
        function checkPasswordMatch() {
            const password = document.getElementById('password')?.value || '';
            const confirm = document.getElementById('confirm_password')?.value || '';
            const msg = document.getElementById('passwordMatchMsg');
            
            if (!msg) return;
            
            if (confirm.length === 0) {
                msg.innerHTML = '<i class="bi bi-info-circle"></i> Passwords must match';
                return false;
            } else if (password === confirm) {
                msg.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> Passwords match';
                return true;
            } else {
                msg.innerHTML = '<i class="bi bi-x-circle-fill text-danger"></i> Passwords do not match';
                return false;
            }
        }
    </script>
</body>
</html>