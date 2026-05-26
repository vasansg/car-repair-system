<?php
ob_start();

require_once __DIR__ . '/includes/config.php';

// Check if admin is in login process
if (!isset($_SESSION['admin_login_user'])) {
    // If directly accessed, redirect to main login
    header('Location: login.php');
    exit();
}

$admin_data = $_SESSION['admin_login_user'];
$error = '';

// Verify this is actually an admin in Firestore
$admin = $firebase->getDoc('users', $admin_data['user_id']);

if (!$admin || ($admin['role'] ?? '') !== 'admin') {
    unset($_SESSION['admin_login_user']);
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Verify password
    $entered_password = $_POST['password'];
    
    if (password_verify($entered_password, $admin['password_hash'])) {
        // Admin login successful
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $admin_data['user_id'];
        $_SESSION['username'] = $admin_data['username'];
        $_SESSION['full_name'] = $admin_data['full_name'];
        $_SESSION['email'] = $admin_data['email'];
        $_SESSION['role'] = 'admin';
        
        // Clear temporary session data
        unset($_SESSION['admin_login_user']);
        
        // Redirect to admin dashboard
        header('Location: admin-dashboard.php');
        exit();
    } else {
        $error = "Incorrect password. Please try again.";
    }
}

// If already logged in as admin, redirect to dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] && $_SESSION['role'] == 'admin') {
    header('Location: admin-dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Admin Login | CS KUMARESAN MOTOR</title>
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
        
        .admin-container {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
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
        
        .admin-card {
            background: white;
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }
        
        .admin-header {
            background: linear-gradient(135deg, #dc2626, #991b1b);
            padding: 1.8rem;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .admin-header::before {
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
        
        .admin-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            padding: 0.3rem 0.8rem;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 1px;
            backdrop-filter: blur(10px);
        }
        
        .logo-icon {
            width: 70px;
            height: 70px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .admin-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }
        
        .admin-header p {
            font-size: 0.8rem;
            opacity: 0.85;
            margin: 0;
        }
        
        .admin-body {
            padding: 2rem;
        }
        
        /* User Info Card */
        .user-info-card {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border-radius: 20px;
            padding: 1.2rem;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(220, 38, 38, 0.2);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-avatar {
            width: 55px;
            height: 55px;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
            font-weight: 700;
        }
        
        .user-details h4 {
            font-size: 1rem;
            font-weight: 700;
            color: #991b1b;
            margin-bottom: 0.2rem;
        }
        
        .user-details p {
            font-size: 0.7rem;
            color: #64748b;
            margin: 0;
        }
        
        .user-details p i {
            margin-right: 0.3rem;
        }
        
        /* Security Notice */
        .security-notice {
            background: #f8fafc;
            border-radius: 16px;
            padding: 0.8rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            font-size: 0.7rem;
            color: #64748b;
            border-left: 3px solid #dc2626;
        }
        
        .security-notice i {
            font-size: 1rem;
            color: #dc2626;
        }
        
        /* Form Elements */
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
            border-color: #dc2626;
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.1);
            outline: none;
            background: white;
        }
        
        .input-group-custom input::placeholder {
            color: #94a3b8;
            font-size: 0.85rem;
        }
        
        .toggle-password {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            z-index: 10;
        }
        
        .toggle-password:hover {
            color: #dc2626;
        }
        
        /* Buttons */
        .btn-login {
            background: linear-gradient(135deg, #dc2626, #991b1b);
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
            margin-bottom: 0.8rem;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(220, 38, 38, 0.4);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
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
        
        .btn-back {
            background: white;
            border: 2px solid #e2e8f0;
            padding: 0.8rem;
            border-radius: 16px;
            font-weight: 600;
            font-size: 0.85rem;
            width: 100%;
            color: #64748b;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .btn-back:hover {
            background: #f8fafc;
            border-color: #dc2626;
            color: #dc2626;
        }
        
        /* Alert Styling */
        .alert-custom {
            border-radius: 16px;
            padding: 0.8rem 1rem;
            font-size: 0.8rem;
            margin-bottom: 1.5rem;
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
        
        .alert-danger-custom {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #dc2626;
        }
        
        /* Footer */
        .admin-footer {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }
        
        .admin-footer p {
            font-size: 0.65rem;
            color: #94a3b8;
            margin: 0;
        }
        
        .admin-footer i {
            margin-right: 0.3rem;
        }
        
        /* Responsive */
        @media (max-width: 576px) {
            .admin-body {
                padding: 1.5rem;
            }
            
            .logo-icon {
                width: 55px;
                height: 55px;
                font-size: 1.5rem;
            }
            
            .admin-header h2 {
                font-size: 1.2rem;
            }
            
            .user-avatar {
                width: 45px;
                height: 45px;
                font-size: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-card">
            <div class="admin-header">

                <div class="logo-icon">
                    <i class="bi bi-shield-lock"></i>
                </div>
                <h2>Admin Portal</h2>
                <p>CS KUMARESAN MOTOR</p>
            </div>
            
            <div class="admin-body">
                <?php if ($error): ?>
                    <div class="alert-custom alert-danger-custom">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <!-- User Information -->
                <div class="user-info-card">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($admin_data['full_name'], 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <h4><?php echo htmlspecialchars($admin_data['full_name']); ?></h4>
                        <p>
                            <i class="bi bi-person-badge"></i> Administrator
                        </p>
                    </div>
                </div>
            
                
                <!-- Password Form -->
                <form method="POST" action="" id="adminLoginForm">
                    <div class="input-group-custom">
                        <i class="bi bi-key-fill input-icon"></i>
                        <input type="password" name="password" id="password" 
                               class="form-control" 
                               placeholder="Enter administrator password" 
                               required autofocus>
                        <button type="button" class="toggle-password" id="togglePassword">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    
                    <button type="submit" class="btn-login" id="loginBtn">
                        <i class="bi bi-shield-check"></i>
                        Login as Administrator
                    </button>
                    
                    <a href="logout.php?cancel_admin=1" class="btn-back">
                        <i class="bi bi-arrow-left"></i>
                        Back to Main Login
                    </a>
                </form>
                
                <!-- Footer -->
                <div class="admin-footer">
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('password');
            const loginBtn = document.getElementById('loginBtn');
            const togglePassword = document.getElementById('togglePassword');
            
            // Auto-focus on password input
            if (passwordInput) {
                passwordInput.focus();
            }
            
            // Toggle password visibility
            if (togglePassword) {
                togglePassword.addEventListener('click', function() {
                    const icon = this.querySelector('i');
                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        icon.classList.remove('bi-eye');
                        icon.classList.add('bi-eye-slash');
                    } else {
                        passwordInput.type = 'password';
                        icon.classList.remove('bi-eye-slash');
                        icon.classList.add('bi-eye');
                    }
                });
            }
            
            // Form submission with loading state
            const adminLoginForm = document.getElementById('adminLoginForm');
            if (adminLoginForm) {
                adminLoginForm.addEventListener('submit', function(e) {
                    const password = passwordInput.value.trim();
                    
                    if (password === '') {
                        e.preventDefault();
                        showError('Please enter your administrator password!');
                        return false;
                    }
                    
                    // Show loading state
                    loginBtn.classList.add('loading');
                    loginBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Authenticating...';
                    loginBtn.disabled = true;
                    
                    // Re-enable after 5 seconds (in case of timeout)
                    setTimeout(() => {
                        if (loginBtn.disabled) {
                            loginBtn.classList.remove('loading');
                            loginBtn.innerHTML = '<i class="bi bi-shield-check"></i> Login as Administrator';
                            loginBtn.disabled = false;
                        }
                    }, 5000);
                });
            }
            
            function showError(message) {
                // Remove any existing error alerts
                const existingAlerts = document.querySelectorAll('.alert-custom');
                existingAlerts.forEach(alert => {
                    if (alert.classList.contains('alert-danger-custom')) {
                        alert.remove();
                    }
                });
                
                // Create new error alert
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert-custom alert-danger-custom';
                alertDiv.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> ${message}`;
                
                const adminBody = document.querySelector('.admin-body');
                const userInfoCard = document.querySelector('.user-info-card');
                adminBody.insertBefore(alertDiv, userInfoCard);
                
                // Auto-remove after 5 seconds
                setTimeout(() => {
                    if (alertDiv.parentNode) {
                        alertDiv.remove();
                    }
                }, 5000);
            }
        });
    </script>
</body>
</html>