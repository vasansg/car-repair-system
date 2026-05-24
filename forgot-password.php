<?php
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

/* ================= DATABASE ================= */
require_once __DIR__ . '/includes/config.php';

/* ================= STEP CONTROL ================= */
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = "";
$success = "";

/* ================= TEMP PASSWORD GENERATOR ================= */
function generateTempPassword($length = 8) {
    return substr(str_shuffle("ABCDEFGHJKLMNPQRSTUVWXYZ23456789"), 0, $length);
}

function sendTempPasswordEmail($toEmail, $toName, $tempPassword) {
    $mail = new PHPMailer(true);

    try {
        // SMTP SETTINGS
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'tarvenmurugarajan@gmail.com';
        $mail->Password   = 'ssch mquw dcjw abmz';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // EMAIL HEADERS
        $mail->setFrom('yourgmail@gmail.com', 'CS Kumaresan Motor');
        $mail->addAddress($toEmail, $toName);

        // EMAIL CONTENT
        $mail->isHTML(true);
        $mail->Subject = 'Temporary Password - CS Kumaresan Motor';
        $mail->Body = "
            <h3>Password Reset Request</h3>
            <p>Hello <strong>$toName</strong>,</p>

            <p>Your temporary password is:</p>
            <h2 style='color:#0d6efd;'>$tempPassword</h2>

            <p>If you did not request this, please ignore this email.</p>

            <br>
            <p>Regards,<br>CS Kumaresan Motor</p>
        ";

        $mail->send();
        return true;

    } catch (Exception $e) {
        return false;
    }
}

/* ================= STEP 1 : REQUEST TEMP PASSWORD ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 1) {
    $email = trim($_POST['email']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Enter a valid email address.";
    } else {
        $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE email=? AND is_active=1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = "Email not found.";
        } else {
            $tempPassword = generateTempPassword();
            $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO password_resets (user_id, temp_password_hash, expires_at)
                VALUES (?, ?, NOW() + INTERVAL '15 minutes')
            ");
            $stmt->execute([$user['id'], $hash]);

            if (sendTempPasswordEmail($email, $user['full_name'], $tempPassword)) {
                $_SESSION['reset_user_id'] = $user['id'];
                header("Location: forgot-password.php?step=2");
                exit();
            } else {
                $error = "Email could not be sent.";
            }
        }
    }
}

/* ================= STEP 2 : VERIFY TEMP PASSWORD ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $inputTemp = $_POST['temp_password'];
    $userId = $_SESSION['reset_user_id'] ?? 0;

    $stmt = $pdo->prepare("
        SELECT * FROM password_resets
        WHERE user_id=? AND used=0 AND expires_at > NOW()
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $error = "Temporary password expired.";
    } else {
        if (password_verify($inputTemp, $row['temp_password_hash'])) {
            $_SESSION['reset_id'] = $row['id'];
            $_SESSION['verified'] = true;
            header("Location: forgot-password.php?step=3");
            exit();
        } else {
            $error = "Invalid temporary password.";
        }
    }
}

/* ================= STEP 3 : CREATE NEW PASSWORD ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    if (!isset($_SESSION['verified'])) {
        header("Location: forgot-password.php");
        exit();
    }

    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    if ($new !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (
        strlen($new) < 10 ||
        !preg_match("/[A-Z]/", $new) ||
        !preg_match("/[a-z]/", $new) ||
        !preg_match("/[0-9]/", $new) ||
        !preg_match("/[^A-Za-z0-9]/", $new)
    ) {
        $error = "Password does not meet requirements.";
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $stmt->execute([$hash, $_SESSION['reset_user_id']]);

        $stmt = $pdo->prepare("UPDATE password_resets SET used=1 WHERE id=?");
        $stmt->execute([$_SESSION['reset_id']]);

        session_destroy();
        header("Location: forgot-password.php?step=4");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Forgot Password | CS KUMARESAN MOTOR</title>
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
        
        .forgot-container {
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
        
        .forgot-card {
            background: white;
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }
        
        .forgot-header {
            background: linear-gradient(135deg, #1e40af, #1e3a8a);
            padding: 1.8rem;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .forgot-header::before {
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
        
        .forgot-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.3rem;
        }
        
        .forgot-header p {
            font-size: 0.8rem;
            opacity: 0.85;
            margin: 0;
        }
        
        .forgot-body {
            padding: 2rem;
        }
        
        /* Progress Steps */
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
        }
        
        .step-item {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 2;
        }
        
        .step-circle {
            width: 45px;
            height: 45px;
            background: #f1f5f9;
            border: 2px solid #e2e8f0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.6rem;
            font-weight: 700;
            font-size: 1rem;
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
            font-size: 0.7rem;
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
            top: 22px;
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
            border-color: #1e40af;
            box-shadow: 0 0 0 4px rgba(30, 64, 175, 0.1);
            outline: none;
            background: white;
        }
        
        .input-group-custom input::placeholder {
            color: #94a3b8;
            font-size: 0.85rem;
        }
        
        /* Password Strength */
        .password-strength {
            margin-top: 0.5rem;
        }
        
        .strength-bar {
            height: 5px;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        
        .strength-requirements {
            background: #f8fafc;
            border-radius: 12px;
            padding: 0.8rem;
            margin-top: 0.8rem;
            font-size: 0.7rem;
        }
        
        .requirement {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.4rem;
            color: #64748b;
        }
        
        .requirement.valid {
            color: #10b981;
        }
        
        .requirement.invalid {
            color: #dc2626;
        }
        
        .requirement i {
            font-size: 0.75rem;
        }
        
        /* Buttons */
        .btn-submit {
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
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(30, 64, 175, 0.4);
        }
        
        .btn-submit:active {
            transform: translateY(0);
        }
        
        .btn-submit.loading {
            pointer-events: none;
            opacity: 0.7;
        }
        
        .btn-submit.loading i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .btn-success-custom {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        
        .btn-success-custom:hover {
            box-shadow: 0 10px 20px -5px rgba(16, 185, 129, 0.4);
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
        
        .alert-success-custom {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        /* Success Box */
        .success-box {
            text-align: center;
            padding: 1rem;
        }
        
        .success-icon {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.2rem;
            font-size: 2.5rem;
            color: white;
            box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.3);
        }
        
        .success-box h4 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        /* Links */
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }
        
        .back-link a {
            color: #64748b;
            text-decoration: none;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: color 0.2s ease;
        }
        
        .back-link a:hover {
            color: #1e40af;
        }
        
        .info-text {
            text-align: center;
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 1.5rem;
        }
        
        /* Responsive */
        @media (max-width: 576px) {
            .forgot-body {
                padding: 1.5rem;
            }
            
            .step-circle {
                width: 35px;
                height: 35px;
                font-size: 0.85rem;
            }
            
            .step-label {
                font-size: 0.6rem;
            }
            
            .progress-line {
                top: 17px;
            }
            
            .logo-icon {
                width: 55px;
                height: 55px;
                font-size: 1.5rem;
            }
            
            .forgot-header h2 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="forgot-container">
        <div class="forgot-card">
            <div class="forgot-header">
                <div class="logo-icon">
                    <i class="bi bi-key"></i>
                </div>
                <h2>Forgot Password</h2>
                <p>Reset your account password</p>
            </div>
            
            <div class="forgot-body">
                <!-- Progress Steps -->
                <div class="progress-steps">
                    <div class="progress-line">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                    <div class="step-item <?php echo $step >= 1 ? ($step > 1 ? 'completed' : 'active') : ''; ?>">
                        <div class="step-circle">1</div>
                        <div class="step-label">Email</div>
                    </div>
                    <div class="step-item <?php echo $step >= 2 ? ($step > 2 ? 'completed' : 'active') : ''; ?>">
                        <div class="step-circle">2</div>
                        <div class="step-label">Verify</div>
                    </div>
                    <div class="step-item <?php echo $step >= 3 ? ($step > 3 ? 'completed' : 'active') : ''; ?>">
                        <div class="step-circle">3</div>
                        <div class="step-label">Reset</div>
                    </div>
                    <div class="step-item <?php echo $step >= 4 ? 'active' : ''; ?>">
                        <div class="step-circle">4</div>
                        <div class="step-label">Done</div>
                    </div>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert-custom alert-danger-custom">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <!-- STEP 1: Enter Email -->
                <?php if ($step == 1): ?>
                    <div class="info-text">
                    </div>
                    
                    <form method="POST" id="step1Form">
                        <div class="input-group-custom">
                            <i class="bi bi-envelope-fill input-icon"></i>
                            <input type="email" name="email" class="form-control" 
                                   placeholder="Enter your email address" required autofocus>
                        </div>
                        
                        <button type="submit" class="btn-submit" id="step1Btn">
                            <i class="bi bi-send"></i>
                            Send Temporary Password
                        </button>
                    </form>
                    
                    <div class="back-link">
                        <a href="login.php">
                            <i class="bi bi-arrow-left"></i> Back to Login
                        </a>
                    </div>
                <?php endif; ?>
                
                <!-- STEP 2: Enter Temporary Password -->
                <?php if ($step == 2): ?>
                    <div class="info-text">
                        <i class="bi bi-envelope-check"></i> Please check your email for the temporary password.
                    </div>
                    
                    <form method="POST" id="step2Form">
                        <div class="input-group-custom">
                            <i class="bi bi-key-fill input-icon"></i>
                            <input type="text" name="temp_password" class="form-control" 
                                   placeholder="Enter temporary password" required autofocus>
                        </div>
                        
                        <button type="submit" class="btn-submit" id="step2Btn">
                            <i class="bi bi-check-circle"></i>
                            Verify & Continue
                        </button>
                    </form>
                    
                    <div class="back-link">
                        <a href="forgot-password.php?step=1">
                            <i class="bi bi-arrow-left"></i> Back to Email
                        </a>
                    </div>
                <?php endif; ?>
                
                <!-- STEP 3: Create New Password -->
                <?php if ($step == 3): ?>
                    <div class="info-text">
                        <i class="bi bi-shield-lock"></i> Create a strong new password for your account.
                    </div>
                    
                    <form method="POST" id="step3Form">
                        <div class="input-group-custom">
                            <i class="bi bi-lock-fill input-icon"></i>
                            <input type="password" name="new_password" id="new_password" 
                                   class="form-control" placeholder="New password" required>
                        </div>
                        
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
                        
                        <div class="input-group-custom mt-3">
                            <i class="bi bi-shield-lock input-icon"></i>
                            <input type="password" name="confirm_password" id="confirm_password" 
                                   class="form-control" placeholder="Confirm password" required>
                        </div>
                        
                        <div class="form-text" id="passwordMatchMsg" style="font-size: 0.7rem; margin-bottom: 1rem;">
                            <i class="bi bi-info-circle"></i> Passwords must match
                        </div>
                        
                        <button type="submit" class="btn-submit btn-success-custom" id="step3Btn">
                            <i class="bi bi-check-lg"></i>
                            Reset Password
                        </button>
                    </form>
                    
                    <div class="back-link">
                        <a href="forgot-password.php?step=2">
                            <i class="bi bi-arrow-left"></i> Back to Verification
                        </a>
                    </div>
                <?php endif; ?>
                
                <!-- STEP 4: Success -->
                <?php if ($step == 4): ?>
                    <div class="success-box">
                        <div class="success-icon">
                            <i class="bi bi-check-lg"></i>
                        </div>
                        <h4>Password Reset Successful!</h4>
                        <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 1.5rem;">
                            Your password has been successfully reset. You can now login with your new password.
                        </p>
                        <a href="login.php" class="btn-submit" style="text-decoration: none; display: inline-flex;">
                            <i class="bi bi-box-arrow-in-right"></i>
                            Go to Login
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update progress bar
        const currentStep = <?php echo $step; ?>;
        const progressFill = document.getElementById('progressFill');
        if (progressFill) {
            const progressPercent = ((currentStep - 1) / 3) * 100;
            progressFill.style.width = progressPercent + '%';
        }
        
        // Password strength checker for Step 3
        function checkNewPasswordStrength(password) {
            let requirements = {
                length: password.length >= 10,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[^A-Za-z0-9]/.test(password)
            };
            
            // Update requirement UI
            updateRequirement('req-length', requirements.length);
            updateRequirement('req-uppercase', requirements.uppercase);
            updateRequirement('req-lowercase', requirements.lowercase);
            updateRequirement('req-number', requirements.number);
            updateRequirement('req-special', requirements.special);
            
            const validCount = Object.values(requirements).filter(Boolean).length;
            const strengthBar = document.getElementById('strengthBar');
            const strength = (validCount / 5) * 100;
            strengthBar.style.width = strength + '%';
            
            if (validCount <= 2) {
                strengthBar.className = 'strength-bar bg-danger';
            } else if (validCount <= 3) {
                strengthBar.className = 'strength-bar bg-warning';
            } else if (validCount <= 4) {
                strengthBar.className = 'strength-bar bg-info';
            } else {
                strengthBar.className = 'strength-bar bg-success';
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
            const password = document.getElementById('new_password')?.value || '';
            const confirm = document.getElementById('confirm_password')?.value || '';
            const matchMsg = document.getElementById('passwordMatchMsg');
            
            if (!matchMsg) return;
            
            if (confirm.length === 0) {
                matchMsg.innerHTML = '<i class="bi bi-info-circle"></i> Passwords must match';
                matchMsg.className = 'form-text';
                return false;
            } else if (password === confirm) {
                matchMsg.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> Passwords match';
                matchMsg.className = 'form-text text-success';
                return true;
            } else {
                matchMsg.innerHTML = '<i class="bi bi-x-circle-fill text-danger"></i> Passwords do not match';
                matchMsg.className = 'form-text text-danger';
                return false;
            }
        }
        
        // Form submission loading states
        document.addEventListener('DOMContentLoaded', function() {
            // Step 1 Form
            const step1Form = document.getElementById('step1Form');
            if (step1Form) {
                step1Form.addEventListener('submit', function() {
                    const btn = document.getElementById('step1Btn');
                    btn.classList.add('loading');
                    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sending...';
                    btn.disabled = true;
                });
            }
            
            // Step 2 Form
            const step2Form = document.getElementById('step2Form');
            if (step2Form) {
                step2Form.addEventListener('submit', function() {
                    const btn = document.getElementById('step2Btn');
                    btn.classList.add('loading');
                    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Verifying...';
                    btn.disabled = true;
                });
            }
            
            // Step 3 Form - with validation
            const step3Form = document.getElementById('step3Form');
            if (step3Form) {
                step3Form.addEventListener('submit', function(e) {
                    const password = document.getElementById('new_password').value;
                    const confirm = document.getElementById('confirm_password').value;
                    
                    // Check password requirements
                    const requirements = checkNewPasswordStrength(password);
                    if (!requirements.length || !requirements.uppercase || !requirements.lowercase || 
                        !requirements.number || !requirements.special) {
                        e.preventDefault();
                        alert('Please meet all password requirements:\n- At least 10 characters\n- Uppercase letter\n- Lowercase letter\n- Number\n- Special character');
                        return false;
                    }
                    
                    if (password !== confirm) {
                        e.preventDefault();
                        alert('Passwords do not match');
                        return false;
                    }
                    
                    const btn = document.getElementById('step3Btn');
                    btn.classList.add('loading');
                    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Resetting...';
                    btn.disabled = true;
                });
            }
            
            // Password strength listener
            const newPasswordInput = document.getElementById('new_password');
            const confirmInput = document.getElementById('confirm_password');
            
            if (newPasswordInput) {
                newPasswordInput.addEventListener('input', function() {
                    checkNewPasswordStrength(this.value);
                    checkPasswordMatch();
                });
            }
            
            if (confirmInput) {
                confirmInput.addEventListener('input', checkPasswordMatch);
            }
        });
    </script>
</body>
</html>