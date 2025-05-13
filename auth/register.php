<?php
require_once '../config.php';
// auth/register.php - User registration with enhanced UI/UX

$errors = [];

// Generate CSRF token
$csrf_token = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = "Security verification failed. Please try again.";
    } else {
        // Get and sanitize form data
        $username = trim(htmlspecialchars($_POST['username']));
        $email = trim(filter_var($_POST['email'], FILTER_SANITIZE_EMAIL));
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate form data
        if (empty($username)) {
            $errors[] = "Username is required";
        } elseif (strlen($username) < 3) {
            $errors[] = "Username must be at least 3 characters";
        } elseif (strlen($username) > 30) {
            $errors[] = "Username must be less than 30 characters";
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors[] = "Username can only contain letters, numbers, and underscores";
        }
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid email is required";
        }
        
        if (empty($password)) {
            $errors[] = "Password is required";
        } elseif (strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters";
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        } elseif (!preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        } elseif (!preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }
        
        if ($password !== $confirm_password) {
            $errors[] = "Passwords do not match";
        }
        
        // If no errors, proceed with registration
        if (empty($errors)) {
            $conn = connectDB();
            
            // Check if username or email already exists
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
            $stmt->bind_param("ss", $username, $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Check which one exists
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $errors[] = "Username already exists";
                } else {
                    $errors[] = "Email already exists";
                }
            } else {
                // Hash the password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert new user
                $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $username, $email, $hashed_password);
                
                if ($stmt->execute()) {
                    // Registration successful, redirect to login
                    $_SESSION['flash_message'] = "Registration successful! Please log in.";
                    redirect(SITE_URL . "/auth/login.php");
                } else {
                    $errors[] = "Registration failed: " . $conn->error;
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
        <div class="col-lg-6 col-md-8">
            <div class="auth-form-container">
                <!-- Branding -->
                <div class="text-center mb-4">
                    <div class="logo-container mb-3">
                        <div class="logo-background">
                            <span class="logo-accent">暗記</span>
                        </div>
                    </div>
                    <h1 class="mb-2">FlashLearn</h1>
                    <p class="text-muted">Create an account to start your learning journey</p>
                </div>
                
                <!-- Register Card -->
                <div class="card auth-card">
                    <div class="card-body p-4">
                        <h2 class="text-center mb-4">Sign Up</h2>
                        
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <?php
                                    if (count($errors) === 1) {
                                        echo $errors[0];
                                    } else {
                                        echo "Please fix the following issues:";
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
                        
                        <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST" class="auth-form" id="registerForm">
                            <!-- CSRF Token -->
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="username" class="form-label">
                                        <i class="fas fa-user me-2"></i>Username
                                    </label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="username" name="username" 
                                               value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>"
                                               placeholder="Choose a unique username"
                                               autofocus required>
                                        <span class="input-group-text bg-transparent">
                                            <i class="fas fa-user-plus text-muted"></i>
                                        </span>
                                    </div>
                                    <div class="form-text" id="username-feedback">
                                        Username must be 3-30 characters with only letters, numbers, and underscores
                                    </div>
                                </div>
                                
                                <div class="col-md-12 mb-3">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope me-2"></i>Email
                                    </label>
                                    <div class="input-group">
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>"
                                               placeholder="Enter your email address" required>
                                        <span class="input-group-text bg-transparent">
                                            <i class="fas fa-at text-muted"></i>
                                        </span>
                                    </div>
                                    <div class="form-text">We'll never share your email with anyone else</div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="password" class="form-label">
                                        <i class="fas fa-lock me-2"></i>Password
                                    </label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="password" name="password" 
                                               placeholder="Create a strong password" required>
                                        <span class="input-group-text bg-transparent toggle-password" title="Show/Hide Password">
                                            <i class="fas fa-eye text-muted"></i>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="col-md-6 mb-3">
                                    <label for="confirm_password" class="form-label">
                                        <i class="fas fa-lock me-2"></i>Confirm Password
                                    </label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                               placeholder="Confirm your password" required>
                                        <span class="input-group-text bg-transparent toggle-confirm-password" title="Show/Hide Password">
                                            <i class="fas fa-eye text-muted"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Password Strength Meter -->
                            <div class="mb-4">
                                <div class="password-strength">
                                    <div class="progress" style="height: 7px;">
                                        <div id="password-strength-meter" class="progress-bar bg-danger" role="progressbar" style="width: 0%"></div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-1">
                                        <small class="text-muted">Password strength:</small>
                                        <small id="password-strength-text" class="text-muted">Very Weak</small>
                                    </div>
                                    <div id="password-feedback" class="form-text mt-2">
                                        Password must be at least 8 characters with uppercase, lowercase, and numbers
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-4 form-check">
                                <input type="checkbox" class="form-check-input" id="agree_terms" name="agree_terms" required>
                                <label class="form-check-label" for="agree_terms">
                                    I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms of Service</a> and <a href="#" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy Policy</a>
                                </label>
                            </div>
                            
                            <div class="d-grid mb-4">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-user-plus me-2"></i>Create Account
                                </button>
                            </div>
                            
                            <div class="text-center mb-3">
                                <span class="text-muted">Already have an account?</span>
                                <a href="<?php echo SITE_URL; ?>/auth/login.php" class="fw-bold ms-1">Log in</a>
                            </div>
                            
                            <div class="social-login">
                                <div class="divider text-center mb-3">
                                    <span class="divider-text">or sign up with</span>
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
                                    <small class="text-muted">Social signup coming soon</small>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Security Notice -->
                <div class="text-center mt-4">
                    <div class="d-flex align-items-center justify-content-center">
                        <i class="fas fa-shield-alt text-muted me-2"></i>
                        <span class="text-muted small">Your data is securely encrypted</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Terms of Service Modal -->
<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="termsModalLabel">Terms of Service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <h6>1. Acceptance of Terms</h6>
                <p>By accessing and using FlashLearn, you agree to be bound by these Terms of Service. If you do not agree to these terms, please do not use our service.</p>
                
                <h6>2. User Accounts</h6>
                <p>You are responsible for safeguarding your password and for all activities that occur under your account. We cannot and will not be liable for any loss or damage arising from your failure to comply with this security obligation.</p>
                
                <h6>3. User Content</h6>
                <p>You retain ownership of any content you create using our service. However, by posting content, you grant us a license to use, modify, and display that content as needed to provide our services.</p>
                
                <h6>4. Prohibited Activities</h6>
                <p>Users may not engage in any activity that interferes with or disrupts the service, attempts to access data not belonging to the user, or otherwise violates applicable laws.</p>
                
                <h6>5. Changes to Terms</h6>
                <p>We reserve the right to modify these terms at any time. We will provide notice of significant changes by updating the date at the top of the terms and/or by providing additional notice as appropriate.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I Understand</button>
            </div>
        </div>
    </div>
</div>

<!-- Privacy Policy Modal -->
<div class="modal fade" id="privacyModal" tabindex="-1" aria-labelledby="privacyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="privacyModalLabel">Privacy Policy</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <h6>1. Information We Collect</h6>
                <p>We collect information you provide directly to us when you create an account (such as your name, email address) and information about your use of our service.</p>
                
                <h6>2. How We Use Your Information</h6>
                <p>We use the information we collect to provide, maintain, and improve our services, to communicate with you, and to protect our services and users.</p>
                
                <h6>3. Information Sharing</h6>
                <p>We do not share your personal information with third parties except as described in this privacy policy or with your consent.</p>
                
                <h6>4. Data Security</h6>
                <p>We take reasonable measures to help protect your personal information from loss, theft, misuse, unauthorized access, disclosure, alteration, and destruction.</p>
                
                <h6>5. Your Rights</h6>
                <p>You have the right to access, correct, or delete your personal information, and to restrict or object to our processing of your data.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I Understand</button>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced CSS is already included in login.php -->

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
    
    const toggleConfirmPassword = document.querySelector('.toggle-confirm-password');
    const confirmPasswordInput = document.querySelector('#confirm_password');
    
    toggleConfirmPassword.addEventListener('click', function() {
        // Toggle password visibility
        const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        confirmPasswordInput.setAttribute('type', type);
        
        // Toggle icon
        this.querySelector('i').classList.toggle('fa-eye');
        this.querySelector('i').classList.toggle('fa-eye-slash');
    });
    
    // Password strength meter
    const strengthMeter = document.getElementById('password-strength-meter');
    const strengthText = document.getElementById('password-strength-text');
    const passwordFeedback = document.getElementById('password-feedback');
    
    passwordInput.addEventListener('input', updatePasswordStrength);
    
    function updatePasswordStrength() {
        const password = passwordInput.value;
        let strength = 0;
        let feedback = [];
        
        // Length check
        if (password.length >= 8) {
            strength += 1;
        } else {
            feedback.push("At least 8 characters");
        }
        
        // Uppercase check
        if (/[A-Z]/.test(password)) {
            strength += 1;
        } else {
            feedback.push("At least one uppercase letter");
        }
        
        // Lowercase check
        if (/[a-z]/.test(password)) {
            strength += 1;
        } else {
            feedback.push("At least one lowercase letter");
        }
        
        // Number check
        if (/[0-9]/.test(password)) {
            strength += 1;
        } else {
            feedback.push("At least one number");
        }
        
        // Special character check
        if (/[^A-Za-z0-9]/.test(password)) {
            strength += 1;
        } else {
            feedback.push("Special character (recommended)");
        }
        
        // Update strength meter
        const percentage = (strength / 5) * 100;
        strengthMeter.style.width = percentage + '%';
        
        // Update colors and text based on strength
        if (strength === 0) {
            strengthMeter.className = 'progress-bar bg-danger';
            strengthText.textContent = 'Very Weak';
            strengthText.className = 'text-danger';
        } else if (strength === 1) {
            strengthMeter.className = 'progress-bar bg-danger';
            strengthText.textContent = 'Weak';
            strengthText.className = 'text-danger';
        } else if (strength === 2) {
            strengthMeter.className = 'progress-bar bg-warning';
            strengthText.textContent = 'Fair';
            strengthText.className = 'text-warning';
        } else if (strength === 3) {
            strengthMeter.className = 'progress-bar bg-info';
            strengthText.textContent = 'Good';
            strengthText.className = 'text-info';
        } else if (strength === 4) {
            strengthMeter.className = 'progress-bar bg-success';
            strengthText.textContent = 'Strong';
            strengthText.className = 'text-success';
        } else {
            strengthMeter.className = 'progress-bar bg-success';
            strengthText.textContent = 'Very Strong';
            strengthText.className = 'text-success';
        }
        
        // Update feedback text
        if (feedback.length > 0) {
            passwordFeedback.innerHTML = 'Requirements: ' + feedback.join(', ');
        } else {
            passwordFeedback.innerHTML = 'Password meets all requirements!';
        }
    }
    
    // Form validation
    const registerForm = document.getElementById('registerForm');
    const usernameInput = document.getElementById('username');
    const emailInput = document.getElementById('email');
    const agreeTerms = document.getElementById('agree_terms');
    
    registerForm.addEventListener('submit', function(event) {
        let isValid = true;
        
        // Username validation
        if (usernameInput.value.trim() === '') {
            isValid = false;
            highlightError(usernameInput);
        } else if (usernameInput.value.length < 3 || usernameInput.value.length > 30) {
            isValid = false;
            highlightError(usernameInput);
        } else if (!/^[a-zA-Z0-9_]+$/.test(usernameInput.value)) {
            isValid = false;
            highlightError(usernameInput);
        } else {
            removeError(usernameInput);
        }
        
        // Email validation
        if (emailInput.value.trim() === '' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailInput.value)) {
            isValid = false;
            highlightError(emailInput);
        } else {
            removeError(emailInput);
        }
        
        // Password validation
        if (passwordInput.value.trim() === '' || passwordInput.value.length < 8) {
            isValid = false;
            highlightError(passwordInput);
        } else if (!/[A-Z]/.test(passwordInput.value) || !/[a-z]/.test(passwordInput.value) || !/[0-9]/.test(passwordInput.value)) {
            isValid = false;
            highlightError(passwordInput);
        } else {
            removeError(passwordInput);
        }
        
        // Confirm password validation
        if (confirmPasswordInput.value !== passwordInput.value) {
            isValid = false;
            highlightError(confirmPasswordInput);
        } else {
            removeError(confirmPasswordInput);
        }
        
        // Terms agreement
        if (!agreeTerms.checked) {
            isValid = false;
            highlightError(agreeTerms);
        } else {
            removeError(agreeTerms);
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
    
    // Real-time username validation
    usernameInput.addEventListener('input', function() {
        const username = this.value.trim();
        const feedback = document.getElementById('username-feedback');
        
        if (username === '') {
            feedback.textContent = 'Username is required';
            feedback.className = 'form-text text-danger';
        } else if (username.length < 3) {
            feedback.textContent = 'Username must be at least 3 characters';
            feedback.className = 'form-text text-danger';
        } else if (username.length > 30) {
            feedback.textContent = 'Username must be less than 30 characters';
            feedback.className = 'form-text text-danger';
        } else if (!/^[a-zA-Z0-9_]+$/.test(username)) {
            feedback.textContent = 'Username can only contain letters, numbers, and underscores';
            feedback.className = 'form-text text-danger';
        } else {
            feedback.textContent = 'Username looks good!';
            feedback.className = 'form-text text-success';
        }
    });
    
    // Password match check
    confirmPasswordInput.addEventListener('input', function() {
        if (this.value !== passwordInput.value) {
            this.classList.add('is-invalid');
        } else {
            this.classList.remove('is-invalid');
        }
    });
});
</script>

<?php include_once dirname(__DIR__) . '/includes/footer.php'; ?>