<?php
require_once '../config.php';
// auth/logout.php - User logout

// Clear all session variables
$_SESSION = [];

// Destroy the session
session_destroy();

// Redirect to login page
redirect(SITE_URL . "/auth/login.php");
?>