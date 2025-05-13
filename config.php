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

// Function to check for "Remember Me" cookie and auto-login
function checkRememberMe() {
    if (isset($_COOKIE['remember_me']) && !isLoggedIn()) {
        list($selector, $validator) = explode(':', $_COOKIE['remember_me']);
        
        $conn = connectDB();
        $stmt = $conn->prepare("SELECT t.user_id, t.token, u.username 
                               FROM auth_tokens t 
                               JOIN users u ON t.user_id = u.user_id 
                               WHERE t.selector = ? AND t.expires > NOW()");
        $stmt->bind_param("s", $selector);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            
            if (hash_equals($row['token'], hash('sha256', $validator))) {
                // Valid token, log the user in
                $_SESSION['user_id'] = $row['user_id'];
                $_SESSION['username'] = $row['username'];
                
                // Extend the cookie and database token
                $expires = time() + 60 * 60 * 24 * 30; // 30 days
                $newValidator = bin2hex(random_bytes(32));
                
                $stmt = $conn->prepare("UPDATE auth_tokens SET token = ?, expires = ? WHERE selector = ?");
                $stmt->bind_param("sss", hash('sha256', $newValidator), date('Y-m-d H:i:s', $expires), $selector);
                $stmt->execute();
                
                // Update cookie
                setcookie(
                    'remember_me',
                    $selector . ':' . $newValidator,
                    $expires,
                    '/',
                    '',
                    true, // secure
                    true  // httponly
                );
            }
        }
        
        $stmt->close();
        $conn->close();
    }
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