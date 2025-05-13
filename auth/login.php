<?php
require_once '../config.php';
// auth/login.php - User login

$errors = [];

// Check if user is already logged in
if (isLoggedIn()) {
    redirect(SITE_URL);
}

// Check for flash messages
$flash_message = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize form data
    $username = trim(htmlspecialchars($_POST['username']));
    $password = $_POST['password'];
    
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

// Include header
include_once '../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h2 class="text-center">Login</h2>
            </div>
            <div class="card-body">
                <?php if (!empty($flash_message)): ?>
                    <div class="alert alert-success">
                        <?php echo $flash_message; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Login</button>
                    </div>
                </form>
            </div>
            <div class="card-footer text-center">
                Don't have an account? <a href="<?php echo SITE_URL; ?>/auth/register.php">Register</a>
            </div>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>
