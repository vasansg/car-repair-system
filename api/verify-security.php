<?php
session_start();

require_once __DIR__ . '/includes/config.php';

// Initialize attempt counter
if (!isset($_SESSION['verification_attempts'])) {
    $_SESSION['verification_attempts'] = 0;
}

// Check if maximum attempts reached
if ($_SESSION['verification_attempts'] >= 5) {
    // Clear session and redirect to login
    session_destroy();
    session_start();
    $_SESSION['verification_blocked'] = true;
    header('Location: login.php?error=Too many failed attempts. Please login again.');
    exit();
}

// Check if user is in login process
if (!isset($_SESSION['login_user'])) {
    header('Location: login.php');
    exit();
}

$user_data = $_SESSION['login_user'];
$error = '';

// Get user's password hash, security image and phrase
$sql = "SELECT password_hash, security_image_path, security_phrase, role FROM users WHERE id = ?";
$stmt = $pdo->prepare($sql);
$stmt->execute([$user_data['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Store actual security image path and phrase from database
$security_image_path = $user['security_image_path'];
$security_phrase = $user['security_phrase'];
$remaining_attempts = 5 - $_SESSION['verification_attempts'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Increment attempt counter
    $_SESSION['verification_attempts']++;
    
    // Verify password
    $entered_password = $_POST['password'];
    
    if (password_verify($entered_password, $user['password_hash'])) {
        // Login successful - reset attempts
        $_SESSION['verification_attempts'] = 0;
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $user_data['user_id'];
        $_SESSION['username'] = $user_data['username'];
        $_SESSION['full_name'] = $user_data['full_name'];
        $_SESSION['email'] = $user_data['email'];
        $_SESSION['role'] = $user['role'];
        
        // Clear temporary session data
        unset($_SESSION['login_user']);
        
        // Redirect to dashboard based on role
        if ($user['role'] == 'admin') {
            header('Location: admin-dashboard.php');
        } elseif ($user['role'] == 'staff') {
            header('Location: staff-dashboard.php');
        } else {
            header('Location: customer-dashboard.php');
        }
        exit();
    } else {
        $error = "Incorrect password. Please try again.";
        
        // Check if this was the last attempt
        if ($_SESSION['verification_attempts'] >= 5) {
            session_destroy();
            session_start();
            $_SESSION['verification_blocked'] = true;
            header('Location: login.php?error=Maximum attempts exceeded. Please login again.');
            exit();
        }
        
        $remaining_attempts = 5 - $_SESSION['verification_attempts'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Security Verification | CS KUMARESAN MOTOR</title>
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
        
        .verification-container {
            width: 100%;
            max-width: 550px;
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
        
        .verification-card {
            background: white;
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }
        
        .verification-header {
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            padding: 1.5rem;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .verification-header::before {
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
        
        .verification-header h2 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }
        
        .verification-header p {
            font-size: 0.75rem;
            opacity: 0.9;
            margin: 0;
        }
        
        .verification-body {
            padding: 1.5rem;
        }
        
        /* Attempt Counter */
        .attempt-counter {
            background: #f8fafc;
            border-radius: 40px;
            padding: 0.3rem 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .attempt-counter i {
            font-size: 0.8rem;
        }
        
        .attempt-counter.warning {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .attempt-counter.danger {
            background: #fecaca;
            color: #991b1b;
            animation: shake 0.5s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        /* User Info Banner */
        .user-banner {
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border-radius: 20px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border: 1px solid rgba(30, 64, 175, 0.2);
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            border-radius: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .user-info h4 {
            font-size: 0.9rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.2rem;
        }
        
        .user-info p {
            font-size: 0.7rem;
            color: #64748b;
            margin: 0;
        }
        
        /* Security Image Box */
        .security-image-box {
            background: #f8fafc;
            border-radius: 20px;
            padding: 1rem;
            text-align: center;
            margin-bottom: 1rem;
            border: 2px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .security-image-box:hover {
            border-color: #1e40af;
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.1);
        }
        
        .security-image-box h5 {
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .security-image {
            max-width: 100%;
            height: auto;
            max-height: 180px;
            border-radius: 16px;
            border: 3px solid white;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }
        
        /* Security Phrase Box */
        .security-phrase-box {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border-radius: 20px;
            padding: 1rem;
            text-align: center;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(5, 150, 105, 0.2);
        }
        
        .security-phrase-box h5 {
            font-size: 0.7rem;
            font-weight: 600;
            color: #059669;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .phrase-text {
            font-size: 1rem;
            font-weight: 600;
            color: #065f46;
            background: white;
            padding: 0.6rem 1rem;
            border-radius: 40px;
            display: inline-block;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        /* Password Input */
        .password-section h4 {
            font-size: 0.9rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .input-group-custom {
            position: relative;
            margin-bottom: 1rem;
        }
        
        .input-group-custom .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1rem;
            z-index: 10;
        }
        
        .input-group-custom input {
            width: 100%;
            padding: 0.9rem 1rem 0.9rem 3rem;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            background: #f8fafc;
        }
        
        .input-group-custom input:focus {
            border-color: #1e40af;
            box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1);
            outline: none;
            background: white;
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
            color: #1e40af;
        }
        
        /* Buttons */
        .btn-login {
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            border: none;
            padding: 0.9rem;
            border-radius: 16px;
            font-weight: 600;
            font-size: 0.9rem;
            width: 100%;
            color: white;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 0.8rem;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(30, 64, 175, 0.4);
        }
        
        .btn-cancel {
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
        
        .btn-cancel:hover {
            background: #f8fafc;
            border-color: #dc2626;
            color: #dc2626;
        }
        
        .forgot-link {
            text-align: center;
            margin-top: 1rem;
            font-size: 0.75rem;
        }
        
        .forgot-link a {
            color: #ff0000;
            text-decoration: none;
        }
        
        .forgot-link a:hover {
            color: #1e40af;
        }
        
        /* Alert Styling */
        .alert-custom {
            border-radius: 16px;
            padding: 0.8rem 1rem;
            font-size: 0.8rem;
            margin-bottom: 1rem;
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
        
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #dc2626;
        }
        
        /* Loading State */
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
        
        /* Responsive */
        @media (max-width: 576px) {
            .verification-body {
                padding: 1.2rem;
            }
            
            .user-banner {
                padding: 0.8rem;
            }
            
            .user-avatar {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }
            
            .security-image {
                max-height: 140px;
            }
            
            .phrase-text {
                font-size: 0.85rem;
                padding: 0.5rem 0.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="verification-container">
        <div class="verification-card">
            <div class="verification-header">
                <div class="logo-icon">
                    <i class="bi bi-shield-lock"></i>
                </div>
                <h2>Security Verification</h2>
                <p>Confirm your identity to continue</p>
            </div>
            
            <div class="verification-body">
                <!-- Attempt Counter -->
                <div style="display: flex; justify-content: flex-end;">
                    <div class="attempt-counter <?php echo $remaining_attempts <= 2 ? 'warning' : ''; ?> <?php echo $remaining_attempts <= 1 ? 'danger' : ''; ?>">
                        <i class="bi bi-shield-exclamation"></i>
                        <span><?php echo $remaining_attempts; ?> attempt(s) remaining</span>
                    </div>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert-custom alert-danger">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <!-- User Info Banner -->
                <div class="user-banner">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($user_data['full_name'], 0, 1)); ?>
                    </div>
                    <div class="user-info">
                        <h4><?php echo htmlspecialchars($user_data['full_name']); ?></h4>
                    </div>
                </div>
                
                <!-- Security Image Display -->
                <div class="security-image-box">
                    <h5><i class="bi bi-image-alt"></i> Your Security Image</h5>
                    <?php if (!empty($security_image_path)): ?>
                        <?php
                        // Check if image path exists
                        $image_full_path = __DIR__ . '/' . $security_image_path;
                        if (file_exists($image_full_path)) {
                            $image_src = $security_image_path;
                        } else {
                            $assets_path = __DIR__ . '/assets/images/' . basename($security_image_path);
                            if (file_exists($assets_path)) {
                                $image_src = 'assets/images/' . basename($security_image_path);
                            } else {
                                $image_src = 'https://via.placeholder.com/400x200/4361ee/ffffff?text=Security+Image';
                            }
                        }
                        ?>
                        <img src="<?php echo htmlspecialchars($image_src); ?>" 
                             class="security-image" 
                             alt="Security Image"
                             onerror="this.src='https://via.placeholder.com/400x200/4361ee/ffffff?text=Image+Not+Found'">
                    <?php else: ?>
                        <div class="alert-custom" style="background: #f1f5f9; color: #64748b;">
                            <i class="bi bi-image"></i>
                            No security image configured
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Security Phrase Display -->
                <?php if (!empty($security_phrase)): ?>
                    <div class="security-phrase-box">
                        <h5><i class="bi bi-chat-quote"></i> Your Security Phrase</h5>
                        <div class="phrase-text">
                            <i class="bi bi-quote"></i>
                            <?php echo htmlspecialchars($security_phrase); ?>
                            <i class="bi bi-quote"></i>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Password Entry Form -->
                <div class="password-section">
                    <h4><i class="bi bi-key"></i> Enter Your Password</h4>
                    
                    <form method="POST" action="" id="passwordForm">
                        <div class="input-group-custom">
                            <i class="bi bi-lock-fill input-icon"></i>
                            <input type="password" name="password" id="password" 
                                   class="form-control" 
                                   placeholder="Enter your password" 
                                   required autofocus>
                            <button type="button" class="toggle-password" id="togglePassword">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        
                        <button type="submit" class="btn-login" id="loginBtn">
                            <i class="bi bi-box-arrow-in-right"></i>
                            Verify & Login
                        </button>
                        
                        <a href="logout.php" class="btn-cancel">
                            <i class="bi bi-x-circle"></i>
                            Cancel & Return to Login
                        </a>
                        
                        <div class="forgot-link">
                            <a href="forgot-password.php">
                                <i class="bi bi-question-circle"></i> Forgot Password?
                            </a>
                        </div>
                    </form>
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
            const passwordForm = document.getElementById('passwordForm');
            if (passwordForm) {
                passwordForm.addEventListener('submit', function(e) {
                    const password = passwordInput.value.trim();
                    
                    if (password === '') {
                        e.preventDefault();
                        showError('Please enter your password!');
                        return false;
                    }
                    
                    // Show loading state
                    loginBtn.classList.add('loading');
                    loginBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Verifying...';
                    loginBtn.disabled = true;
                    
                    // Re-enable after 5 seconds (in case of timeout)
                    setTimeout(() => {
                        if (loginBtn.disabled) {
                            loginBtn.classList.remove('loading');
                            loginBtn.innerHTML = '<i class="bi bi-box-arrow-in-right"></i> Verify & Login';
                            loginBtn.disabled = false;
                        }
                    }, 5000);
                });
            }
            
            function showError(message) {
                // Remove any existing error alerts
                const existingAlerts = document.querySelectorAll('.alert-custom');
                existingAlerts.forEach(alert => {
                    if (alert.classList.contains('alert-danger')) {
                        alert.remove();
                    }
                });
                
                // Create new error alert
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert-custom alert-danger';
                alertDiv.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i> ${message}`;
                
                const container = document.querySelector('.verification-body');
                const attemptCounter = document.querySelector('.attempt-counter');
                container.insertBefore(alertDiv, attemptCounter.nextSibling);
                
                // Auto-remove after 5 seconds
                setTimeout(() => {
                    if (alertDiv.parentNode) {
                        alertDiv.remove();
                    }
                }, 5000);
            }
            
            // Show attempt warning when attempts are low
            <?php if ($remaining_attempts <= 2): ?>
                const counter = document.querySelector('.attempt-counter');
                if (counter) {
                    if (<?php echo $remaining_attempts; ?> === 1) {
                        // Shake animation for last attempt
                        counter.style.animation = 'shake 0.5s ease';
                    }
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>