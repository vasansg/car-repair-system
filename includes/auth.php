<?php
/**
 * Authentication Functions for Car Repair System
 */

// Check if connected to database
if (!isset($pdo)) {
    die("Database connection not established");
}

/**
 * Verify username and get user data for login
 */
function verifyUsername($username) {
    global $pdo;

    $sql = "SELECT id, email, full_name, security_image_path, password_hash
            FROM users
            WHERE username = ? AND is_active = 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        return [
            'success' => true,
            'user_id' => $user['id'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'security_image' => $user['security_image_path'],
            'password_hash' => $user['password_hash']
        ];
    }

    return ['success' => false, 'message' => 'Username not found'];
}

/**
 * Verify password
 */
function verifyPassword($password, $stored_hash) {
    return password_verify($password, $stored_hash);
}

/**
 * Complete login - set session variables
 */
function completeLogin($user_data) {
    $_SESSION['user_id'] = $user_data['user_id'];
    $_SESSION['username'] = $user_data['username'];
    $_SESSION['email'] = $user_data['email'];
    $_SESSION['full_name'] = $user_data['full_name'];
    $_SESSION['role'] = $user_data['role'] ?? 'customer';
    $_SESSION['logged_in'] = true;
    
    return true;
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Require login - redirect if not logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

/**
 * Logout user
 */
function logout() {
    session_destroy();
    header('Location: login.php');
    exit();
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user role
 */
function getCurrentUserRole() {
    return $_SESSION['role'] ?? 'customer';
}

/**
 * Check if user has specific role
 */
function hasRole($role) {
    return getCurrentUserRole() === $role;
}
?>