<?php
ob_start();

require_once __DIR__ . '/includes/config.php';

// Check for registration success
if (isset($_SESSION['registration_success'])) {
    $registration_success = $_SESSION['registration_success'];
    unset($_SESSION['registration_success']);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);

    // Look up user by username in Firestore
    $users = $firebase->query('users', [
        ['username', '==', $username],
        ['is_active', '==', true],
    ]);
    $user = $users[0] ?? null;

    if ($user) {
        if ($user['role'] === 'admin') {
            $_SESSION['admin_login_user'] = [
                'user_id'   => $user['id'],
                'username'  => $username,
                'email'     => $user['email'],
                'full_name' => $user['full_name'],
                'role'      => 'admin',
            ];
            header('Location: admin-login.php');
            exit();
        } else {
            $_SESSION['login_user'] = [
                'user_id'        => $user['id'],
                'username'       => $username,
                'email'          => $user['email'],
                'full_name'      => $user['full_name'],
                'security_image' => $user['security_image_path'] ?? '',
                'security_phrase'=> $user['security_phrase'] ?? '',
                'role'           => $user['role'],
            ];
            header('Location: verify-security.php');
            exit();
        }
    } else {
        $error = "Username not found or account is inactive!";
    }
}

// If already logged in, redirect based on role
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    if ($_SESSION['role'] == 'admin') {
        header('Location: admin-dashboard.php');
    } elseif ($_SESSION['role'] == 'staff') {
        header('Location: staff-dashboard.php');
    } else {
        header('Location: customer-dashboard.php');
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Login | CS KUMARESAN MOTOR</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 25%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Animated background */
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
        
        .login-container {
            width: 100%;
            max-width: 480px;
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-card {
            background: white;
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .login-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.3);
        }
        
        /* Header Section */
        .login-header {
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            padding: 2rem 1.5rem;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .login-header::before {
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
            width: 70px;
            height: 70px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .login-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
            letter-spacing: -0.5px;
        }
        
        .login-header p {
            font-size: 0.8rem;
            opacity: 0.9;
            margin: 0;
        }
        
        /* Body Section */
        .login-body {
            padding: 2rem 1.5rem;
        }
        
        .welcome-text {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .welcome-text h4 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.3rem;
        }
        
        .welcome-text p {
            font-size: 0.8rem;
            color: #64748b;
            margin: 0;
        }
        
        /* Form Styling */
        .input-group-custom {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .input-group-custom .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1.1rem;
            z-index: 10;
        }
        
        .input-group-custom input {
            width: 100%;
            padding: 0.9rem 1rem 0.9rem 3rem;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: #f8fafc;
        }
        
        .input-group-custom input:focus {
            border-color: #1e40af;
            box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1);
            outline: none;
            background: white;
        }
        
        .input-group-custom input::placeholder {
            color: #94a3b8;
            font-size: 0.85rem;
        }
        
        /* Button Styling */
        .btn-login {
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            border: none;
            padding: 0.9rem;
            border-radius: 16px;
            font-weight: 600;
            font-size: 1rem;
            width: 100%;
            color: white;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            cursor: pointer;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(30, 64, 175, 0.4);
            background: linear-gradient(135deg, #1e3a8a, #1e40af);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 1.5rem 0;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .divider span {
            margin: 0 0.8rem;
            color: #94a3b8;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        /* Register Button */
        .btn-register {
            background: white;
            border: 2px solid #e2e8f0;
            padding: 0.9rem;
            border-radius: 16px;
            font-weight: 600;
            font-size: 0.95rem;
            width: 100%;
            color: #1e293b;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .btn-register:hover {
            background: #f8fafc;
            border-color: #1e40af;
            color: #1e40af;
            transform: translateY(-2px);
        }
        
        /* Alert Styling */
        .alert-custom {
            border-radius: 16px;
            padding: 0.8rem 1rem;
            font-size: 0.8rem;
            margin-bottom: 1.5rem;
            border: none;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            animation: slideDown 0.3s ease-out;
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
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        /* Features Section */
        .features {
            display: flex;
            justify-content: space-between;
            gap: 0.8rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }
        
        .feature-item {
            text-align: center;
            flex: 1;
        }
        
        .feature-item i {
            font-size: 1.2rem;
            color: #1e40af;
            margin-bottom: 0.3rem;
            display: block;
        }
        
        .feature-item span {
            font-size: 0.65rem;
            color: #64748b;
            font-weight: 500;
        }
        
        /* Footer */
        .login-footer {
            text-align: center;
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
        }
        
        .login-footer p {
            font-size: 0.65rem;
            color: #94a3b8;
            margin: 0;
        }
        
        /* Responsive */
        @media (max-width: 480px) {
            .login-card {
                border-radius: 24px;
            }
            
            .login-header {
                padding: 1.5rem 1rem;
            }
            
            .login-body {
                padding: 1.5rem 1rem;
            }
            
            .logo-icon {
                width: 55px;
                height: 55px;
                font-size: 1.5rem;
            }
            
            .login-header h2 {
                font-size: 1.2rem;
            }
            
            .welcome-text h4 {
                font-size: 1.1rem;
            }
            
            .feature-item span {
                font-size: 0.55rem;
            }
        }
        
        /* Loading animation */
        .btn-login.loading {
            pointer-events: none;
            opacity: 0.7;
        }
        
        .btn-login.loading i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <!-- Header -->
            <div class="login-header">
                <div class="logo-icon">
                    <i class="bi bi-gear-wide-connected"></i>
                </div>
                <h2>CS KUMARESAN MOTOR</h2>
                <p>Professional Automotive Service & Repair</p>
            </div>
            
            <!-- Body -->
            <div class="login-body">
                <div class="welcome-text">
                    <h4>Welcome Back!</h4>
                    <p>Sign in to continue to your account</p>
                </div>
                
                <!-- Success Alert -->
                <?php if (isset($registration_success)): ?>
                    <div class="alert-custom alert-success">
                        <i class="bi bi-check-circle-fill fs-6"></i>
                        <?php echo htmlspecialchars($registration_success); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Error Alert -->
                <?php if ($error): ?>
                    <div class="alert-custom alert-danger">
                        <i class="bi bi-exclamation-triangle-fill fs-6"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                    
                    <?php if (strpos($error, 'Database') !== false): ?>
                        <div class="alert-custom alert-danger">
                            <i class="bi bi-database-fill-exclamation fs-6"></i>
                            <div>
                                <strong>Database Setup Required</strong><br>
                                <small>Please run the database setup script first.</small>
                                <div class="mt-2">
                                    <a href="setup.php" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-gear me-1"></i>Run Setup
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <!-- Login Form -->
                <form method="POST" action="" id="loginForm">
                    <div class="input-group-custom">
                        <i class="bi bi-person input-icon"></i>
                        <input type="text" name="username" class="form-control" 
                               placeholder="Enter your username" required autofocus>
                    </div>
                    
                    <button type="submit" class="btn-login" id="loginBtn">
                        <i class="bi bi-arrow-right-circle"></i>
                        Continue to Login
                    </button>
                </form>
                
                <div class="divider">
                    <span>OR</span>
                </div>
                
                <!-- Register Button -->
                <a href="register.php" class="btn-register">
                    <i class="bi bi-person-plus"></i>
                    Create New Account
                </a>
                
                <!-- Features -->
                <div class="features">
                    <div class="feature-item">
                        <i class="bi bi-shield-check"></i>
                        <span>Secure Login</span>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="login-footer">
                <p>© <?php echo date('Y'); ?> CS KUMARESAN MOTOR. All rights reserved.</p>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-focus on username field
            const usernameInput = document.querySelector('input[name="username"]');
            if (usernameInput) {
                usernameInput.focus();
            }
            
            // Form submission loading animation
            const loginForm = document.getElementById('loginForm');
            const loginBtn = document.getElementById('loginBtn');
            
            if (loginForm) {
                loginForm.addEventListener('submit', function() {
                    loginBtn.classList.add('loading');
                    loginBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
                    loginBtn.disabled = true;
                });
            }
            
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert-custom');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => {
                        if (alert.parentNode) alert.remove();
                    }, 500);
                }, 5000);
            });
        });
    </script>
</body>
</html>