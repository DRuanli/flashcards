<?php
require_once '../config.php';
// auth/forgot_password.php - Password reset functionality

$errors = [];
$success = false;

// Generate CSRF token
$csrf_token = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security verification failed. Please try again.";
    } else {
        // Get and sanitize form data
        $email = trim(filter_var($_POST['email'], FILTER_SANITIZE_EMAIL));
        
        // Validate email
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email is required";
        }
        
        // If no errors, proceed with password reset
        if (empty($errors)) {
            $conn = connectDB();
            
            // Check if email exists
            $stmt = $conn->prepare("SELECT user_id, username FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                // Email not found, but don't reveal this for security
                $success = true;
            } else {
                $user = $result->fetch_assoc();
                
                // Generate unique token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiration
                
                // Store token in database
                $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token, expires) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $user['user_id'], $token, $expires);
                
                if ($stmt->execute()) {
                    // Token created successfully
                    
                    // In a real application, you would send an email with the reset link
                    // For now, we'll just simulate success
                    
                    $success = true;
                } else {
                    $errors[] = "Failed to process your request. Please try again later.";
                }
            }
            
            $stmt->close();
            $conn->close();
        }
    }
}

// Include header
include_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="auth-container">
    <div class="row justify-content-center">
        <div class="col-lg-5 col-md-7">
            <div class="auth-form-container">
                <!-- Branding -->
                <div class="text-center mb-4">
                    <div class="logo-container mb-3">
                        <div class="logo-background">
                            <span class="logo-accent">暗記</span>
                        </div>
                    </div>
                    <h1 class="mb-2">FlashLearn</h1>
                    <p class="text-muted">Reset your password</p>
                </div>
                
                <!-- Password Reset Card -->
                <div class="card auth-card">
                    <div class="card-body p-4">
                        <?php if ($success): ?>
                            <div class="text-center py-4">
                                <div class="mb-4">
                                    <span class="success-icon">
                                        <i class="fas fa-check"></i>
                                    </span>
                                </div>
                                <h2 class="mb-3">Check Your Email</h2>
                                <p class="mb-4">
                                    If an account exists with the email you entered, we've sent instructions to reset your password.
                                </p>
                                <p class="text-muted mb-4">
                                    Please check your inbox and spam folder. The reset link will expire in 1 hour.
                                </p>
                                <a href="<?php echo SITE_URL; ?>/auth/login.php" class="btn btn-primary">
                                    <i class="fas fa-sign-in-alt me-2"></i>Return to Login
                                </a>
                            </div>
                        <?php else: ?>
                            <h2 class="text-center mb-4">Forgot Password</h2>
                            
                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger alert-dismissible fade show">
                                    <i class="fas fa-exclamation-circle me-2"></i>
                                    <?php echo $errors[0]; ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            <?php endif; ?>
                            
                            <p class="text-center mb-4">
                                Enter your email address and we'll send you instructions to reset your password.
                            </p>
                            
                            <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST" class="auth-form" id="resetForm">
                                <!-- CSRF Token -->
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <div class="mb-4">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope me-2"></i>Email
                                    </label>
                                    <div class="input-group">
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>"
                                               placeholder="Enter your email address"
                                               autofocus required>
                                        <span class="input-group-text bg-transparent">
                                            <i class="fas fa-at text-muted"></i>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="d-grid mb-4">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-paper-plane me-2"></i>Send Reset Link
                                    </button>
                                </div>
                                
                                <div class="text-center">
                                    <a href="<?php echo SITE_URL; ?>/auth/login.php" class="text-decoration-none">
                                        <i class="fas fa-arrow-left me-1"></i>Back to Login
                                    </a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Security Notice -->
                <div class="text-center mt-4">
                    <div class="d-flex align-items-center justify-content-center">
                        <i class="fas fa-shield-alt text-muted me-2"></i>
                        <span class="text-muted small">Secure, encrypted connection</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Additional CSS for password reset page -->
<style>
    .success-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 80px;
        height: 80px;
        background-color: rgba(40, 167, 69, 0.1);
        border-radius: 50%;
        color: #28a745;
        font-size: 2rem;
    }
</style>

<?php include_once dirname(__DIR__) . '/includes/footer.php'; ?>