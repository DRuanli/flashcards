<?php
require_once '../config.php';
// auth/login.php - User login with enhanced UI/UX

$errors = [];

// Check if user is already logged in
if (isLoggedIn()) {
    redirect(SITE_URL);
}

// Check for flash messages
$flash_message = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// Generate CSRF token
$csrf_token = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security verification failed. Please try again.";
    } else {
        // Get and sanitize form data
        $username = trim(htmlspecialchars($_POST['username']));
        $password = $_POST['password'];
        $remember_me = isset($_POST['remember_me']);
        
        // Validate form data
        if (empty($username)) {
            $errors[] = "Username is required";
        }
        
        if (empty($password)) {
            $errors[] = "Password is required";
        }
        
        // If no errors, proceed with login
        if (empty($errors)) {
            $conn = connectDB();
            
            // Prepare and execute query
            $stmt = $conn->prepare("SELECT user_id, username, password FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Verify password
                if (password_verify($password, $user['password'])) {
                    // Login successful
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    
                    // Set remember me cookie if requested
                    if ($remember_me) {
                        $selector = bin2hex(random_bytes(8));
                        $validator = bin2hex(random_bytes(32));
                        
                        // Set cookie expiration to 30 days
                        $expires = time() + 60 * 60 * 24 * 30;
                        
                        // Store in database with hashed validator
                        $stmt = $conn->prepare("INSERT INTO auth_tokens (user_id, selector, token, expires) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("isss", $user['user_id'], $selector, hash('sha256', $validator), date('Y-m-d H:i:s', $expires));
                        $stmt->execute();
                        
                        // Set cookie
                        setcookie(
                            'remember_me',
                            $selector . ':' . $validator,
                            $expires,
                            '/',
                            '',
                            true, // secure
                            true  // httponly
                        );
                    }
                    
                    // Redirect to dashboard
                    redirect(SITE_URL);
                } else {
                    $errors[] = "Invalid username or password";
                }
            } else {
                $errors[] = "Invalid username or password";
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
                    <p class="text-muted">Master knowledge through spaced repetition</p>
                </div>
                
                <!-- Login Card -->
                <div class="card auth-card">
                    <div class="card-body p-4">
                        <h2 class="text-center mb-4">Welcome Back</h2>
                        
                        <?php if (!empty($flash_message)): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="fas fa-check-circle me-2"></i><?php echo $flash_message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <?php
                                    if (count($errors) === 1) {
                                        echo $errors[0];
                                    } else {
                                        echo "There were problems with your login:";
                                        echo "<ul class='mb-0 ps-3 mt-1'>";
                                        foreach ($errors as $error) {
                                            echo "<li>$error</li>";
                                        }
                                        echo "</ul>";
                                    }
                                ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>
                        
                        <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST" class="auth-form" id="loginForm">
                            <!-- CSRF Token -->
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <div class="mb-3">
                                <label for="username" class="form-label">
                                    <i class="fas fa-user me-2"></i>Username
                                </label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>"
                                           placeholder="Enter your username"
                                           autofocus required>
                                    <span class="input-group-text bg-transparent">
                                        <i class="fas fa-user-check text-muted"></i>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between">
                                    <label for="password" class="form-label">
                                        <i class="fas fa-lock me-2"></i>Password
                                    </label>
                                    <a href="<?php echo SITE_URL; ?>/auth/forgot_password.php" class="text-decoration-none small">
                                        Forgot password?
                                    </a>
                                </div>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" 
                                           placeholder="Enter your password" required>
                                    <span class="input-group-text bg-transparent toggle-password" title="Show/Hide Password">
                                        <i class="fas fa-eye text-muted"></i>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="mb-4 form-check">
                                <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me">
                                <label class="form-check-label" for="remember_me">Remember me for 30 days</label>
                            </div>
                            
                            <div class="d-grid mb-4">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-sign-in-alt me-2"></i>Login
                                </button>
                            </div>
                            
                            <div class="text-center mb-3">
                                <span class="text-muted">Don't have an account?</span>
                                <a href="<?php echo SITE_URL; ?>/auth/register.php" class="fw-bold ms-1">Register</a>
                            </div>
                            
                            <div class="social-login">
                                <div class="divider text-center mb-3">
                                    <span class="divider-text">or continue with</span>
                                </div>
                                
                                <div class="d-flex justify-content-center social-buttons">
                                    <button type="button" class="btn btn-outline-secondary social-btn mx-1" disabled>
                                        <i class="fab fa-google"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary social-btn mx-1" disabled>
                                        <i class="fab fa-facebook-f"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary social-btn mx-1" disabled>
                                        <i class="fab fa-apple"></i>
                                    </button>
                                </div>
                                <div class="text-center mt-2">
                                    <small class="text-muted">Social login coming soon</small>
                                </div>
                            </div>
                        </form>
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

<!-- Enhanced CSS for Auth Pages -->
<style>
    body {
        background-image: linear-gradient(135deg, var(--kinari) 0%, #FFFFFF 100%);
        min-height: 100vh;
    }
    
    .auth-container {
        padding-top: 2rem;
        padding-bottom: 2rem;
    }
    
    .logo-container {
        position: relative;
        display: inline-block;
    }
    
    .logo-background {
        width: 80px;
        height: 80px;
        background: linear-gradient(135deg, var(--indigo) 0%, var(--indigo-dark) 100%);
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto;
        box-shadow: 0 10px 20px rgba(62, 74, 137, 0.2);
        position: relative;
        transform: rotate(10deg);
        transition: all 0.3s ease;
    }
    
    .logo-background:hover {
        transform: rotate(0deg) scale(1.05);
    }
    
    .logo-accent {
        color: white;
        font-size: 2.2rem;
        font-weight: 600;
        font-family: 'Shippori Mincho', serif;
    }
    
    .auth-card {
        border: none;
        border-radius: 15px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        transition: all 0.3s ease;
    }
    
    .auth-card:hover {
        box-shadow: 0 20px 35px rgba(0, 0, 0, 0.15);
    }
    
    .auth-form .form-control {
        padding: 0.75rem 1rem;
        font-size: 1rem;
        border-radius: 8px;
        border: 1px solid rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
    }
    
    .auth-form .form-control:focus {
        border-color: var(--indigo);
        box-shadow: 0 0 0 0.2rem rgba(62, 74, 137, 0.25);
    }
    
    .auth-form .input-group-text {
        border-left: none;
        cursor: pointer;
    }
    
    .toggle-password:hover {
        color: var(--indigo);
    }
    
    .auth-form .btn-primary {
        padding: 0.8rem 1rem;
        font-weight: 500;
        transition: all 0.3s ease;
    }
    
    .auth-form .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(62, 74, 137, 0.3);
    }
    
    /* Social Login */
    .social-login .divider {
        position: relative;
        text-align: center;
        margin: 1.5rem 0;
    }
    
    .social-login .divider::before {
        content: '';
        position: absolute;
        top: 50%;
        left: 0;
        width: 100%;
        height: 1px;
        background-color: rgba(0, 0, 0, 0.1);
    }
    
    .social-login .divider-text {
        position: relative;
        background-color: white;
        padding: 0 1rem;
        color: #6c757d;
        font-size: 0.9rem;
    }
    
    .social-buttons .social-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
    }
    
    .social-buttons .social-btn:hover {
        transform: translateY(-2px);
    }
    
    /* Alert styling */
    .alert {
        border-radius: 10px;
        font-size: 0.95rem;
    }
    
    /* Form check styling */
    .form-check-input:checked {
        background-color: var(--indigo);
        border-color: var(--indigo);
    }
    
    /* Animations */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .auth-form-container {
        animation: fadeInUp 0.6s ease forwards;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle password visibility
    const togglePassword = document.querySelector('.toggle-password');
    const passwordInput = document.querySelector('#password');
    
    togglePassword.addEventListener('click', function() {
        // Toggle password visibility
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        
        // Toggle icon
        this.querySelector('i').classList.toggle('fa-eye');
        this.querySelector('i').classList.toggle('fa-eye-slash');
    });
    
    // Form validation
    const loginForm = document.getElementById('loginForm');
    
    loginForm.addEventListener('submit', function(event) {
        let isValid = true;
        const username = document.getElementById('username');
        const password = document.getElementById('password');
        
        // Simple validation - can be enhanced
        if (username.value.trim() === '') {
            isValid = false;
            highlightError(username);
        } else {
            removeError(username);
        }
        
        if (password.value.trim() === '') {
            isValid = false;
            highlightError(password);
        } else {
            removeError(password);
        }
        
        if (!isValid) {
            event.preventDefault();
        }
    });
    
    function highlightError(element) {
        element.classList.add('is-invalid');
        element.focus();
    }
    
    function removeError(element) {
        element.classList.remove('is-invalid');
    }
    
    // Add input event listeners for real-time validation
    const inputs = loginForm.querySelectorAll('input[required]');
    
    inputs.forEach(input => {
        input.addEventListener('input', function() {
            if (this.value.trim() !== '') {
                removeError(this);
            }
        });
    });
});
</script>

<?php include_once dirname(__DIR__) . '/includes/footer.php'; ?>