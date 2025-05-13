<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');      // Replace with your database username
define('DB_PASS', '');          // Replace with your database password
define('DB_NAME', 'flashcards');

// Site configuration
define('SITE_NAME', 'FlashLearn');
define('SITE_URL', 'http://localhost:81/flashcards'); // Replace with your website URL

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
?>