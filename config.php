<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');      // Replace with your database username
define('DB_PASS', '');          // Replace with your database password
define('DB_NAME', 'flashcards');

// Site configuration
define('SITE_NAME', 'FlashLearn');
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];

// FIX: Get the base URL correctly, ensuring we don't duplicate folders
$script_name = $_SERVER['SCRIPT_NAME'];
$app_root = '/flashcards'; // Base application folder 
$base_path = substr($script_name, 0, strpos($script_name, $app_root) + strlen($app_root));
define('SITE_URL', $protocol . $host . $base_path);

// Session configuration
session_start();

// Database connection function
function connectDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    return $conn;
}

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Function to redirect user
function redirect($location) {
    header("Location: " . $location);
    exit();
}

// Generate CSRF token
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verify CSRF token
function verifyCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        return false;
    }
    return true;
}
?>