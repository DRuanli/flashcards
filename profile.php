<?php
require_once 'config.php';

// profile.php - User profile and account settings

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

$success_message = '';
$error_message = '';

// Get user information
$conn = connectDB();
$stmt = $conn->prepare("SELECT username, email, created_at FROM users WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Get user statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT deck_id) as total_decks,
        SUM(cards_studied) as total_cards_studied,
        SUM(correct_answers) as total_correct,
        ROUND(SUM(correct_answers) / SUM(cards_studied) * 100) as overall_accuracy,
        MAX(date_studied) as last_study_date
    FROM statistics
    WHERE user_id = ?
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$stats = $result->fetch_assoc();

// Get streak information
$stmt = $conn->prepare("
    SELECT date_studied
    FROM statistics
    WHERE user_id = ?
    GROUP BY date_studied
    ORDER BY date_studied DESC
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

$study_dates = [];
while ($row = $result->fetch_assoc()) {
    $study_dates[] = $row['date_studied'];
}

// Calculate current streak
$current_streak = 0;
$yesterday = date('Y-m-d', strtotime('-1 day'));

// Check if user studied today
$today = date('Y-m-d');
$studied_today = in_array($today, $study_dates);

// Start from yesterday or today
$start_date = $studied_today ? $today : $yesterday;

// Calculate streak
for ($i = 0; $i < count($study_dates); $i++) {
    $date_to_check = date('Y-m-d', strtotime("-{$i} days"));
    if (in_array($date_to_check, $study_dates)) {
        $current_streak++;
    } else {
        break;
    }
}

// Calculate study achievements
$achievements = [
    'first_deck' => [
        'name' => 'First Deck Created',
        'description' => 'Created your first flashcard deck',
        'icon' => 'fa-book',
        'color' => 'bg-primary',
        'unlocked' => false
    ],
    'first_study' => [
        'name' => 'First Study Session',
        'description' => 'Completed your first study session',
        'icon' => 'fa-graduation-cap',
        'color' => 'bg-success',
        'unlocked' => false
    ],
    'streak_3' => [
        'name' => '3-Day Streak',
        'description' => 'Studied for 3 consecutive days',
        'icon' => 'fa-fire',
        'color' => 'bg-warning',
        'unlocked' => false
    ],
    'streak_7' => [
        'name' => '7-Day Streak',
        'description' => 'Studied for 7 consecutive days',
        'icon' => 'fa-fire-alt',
        'color' => 'bg-warning',
        'unlocked' => false
    ],
    'accuracy_80' => [
        'name' => '80% Accuracy',
        'description' => 'Achieved 80% or higher accuracy rate',
        'icon' => 'fa-bullseye',
        'color' => 'bg-info',
        'unlocked' => false
    ],
    'cards_100' => [
        'name' => 'Memory Master',
        'description' => 'Studied more than 100 cards in total',
        'icon' => 'fa-brain',
        'color' => 'bg-danger',
        'unlocked' => false
    ]
];

// Check which achievements are unlocked
$stmt = $conn->prepare("SELECT COUNT(*) as deck_count FROM decks WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$deck_count = $result->fetch_assoc()['deck_count'];

if ($deck_count > 0) {
    $achievements['first_deck']['unlocked'] = true;
}

if ($stats['total_cards_studied'] > 0) {
    $achievements['first_study']['unlocked'] = true;
}

if ($current_streak >= 3) {
    $achievements['streak_3']['unlocked'] = true;
}

if ($current_streak >= 7) {
    $achievements['streak_7']['unlocked'] = true;
}

if ($stats['overall_accuracy'] >= 80) {
    $achievements['accuracy_80']['unlocked'] = true;
}

if ($stats['total_cards_studied'] >= 100) {
    $achievements['cards_100']['unlocked'] = true;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Password change form
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate inputs
        if (empty($current_password)) {
            $error_message = "Current password is required";
        } elseif (empty($new_password)) {
            $error_message = "New password is required";
        } elseif (strlen($new_password) < 6) {
            $error_message = "New password must be at least 6 characters";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "Passwords do not match";
        } else {
            // Verify current password
            $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();
            
            if (password_verify($current_password, $user_data['password'])) {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $stmt->bind_param("si", $hashed_password, $_SESSION['user_id']);
                
                if ($stmt->execute()) {
                    $success_message = "Password updated successfully!";
                } else {
                    $error_message = "Failed to update password: " . $conn->error;
                }
            } else {
                $error_message = "Current password is incorrect";
            }
        }
    }
    
    // Email change form
    if (isset($_POST['change_email'])) {
        $new_email = trim(filter_var($_POST['new_email'], FILTER_SANITIZE_EMAIL));
        
        // Validate input
        if (empty($new_email) || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Valid email is required";
        } else {
            // Check if email already exists for another user
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $stmt->bind_param("si", $new_email, $_SESSION['user_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error_message = "Email already in use by another account";
            } else {
                // Update email
                $stmt = $conn->prepare("UPDATE users SET email = ? WHERE user_id = ?");
                $stmt->bind_param("si", $new_email, $_SESSION['user_id']);
                
                if ($stmt->execute()) {
                    $success_message = "Email updated successfully!";
                    // Update local user data
                    $user['email'] = $new_email;
                } else {
                    $error_message = "Failed to update email: " . $conn->error;
                }
            }
        }
    }
}

$conn->close();

// Include header
include_once 'includes/header.php';
?>

<div class="profile-container">
    <div class="row">
        <div class="col-lg-4 mb-4">
            <!-- User Profile Card -->
            <div class="card profile-card mb-4">
                <div class="profile-card-header">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                    </div>
                </div>
                <div class="card-body text-center">
                    <h3 class="mb-1"><?php echo htmlspecialchars($user['username']); ?></h3>
                    <p class="text-muted mb-3"><?php echo htmlspecialchars($user['email']); ?></p>
                    
                    <div class="profile-stats">
                        <div class="row g-0">
                            <div class="col-4">
                                <div class="profile-stat-item">
                                    <div class="stat-value"><?php echo $stats['total_cards_studied'] ?? 0; ?></div>
                                    <div class="stat-label">Cards Studied</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="profile-stat-item">
                                    <div class="stat-value"><?php echo $current_streak; ?></div>
                                    <div class="stat-label">Day Streak</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="profile-stat-item">
                                    <div class="stat-value"><?php echo $stats['overall_accuracy'] ?? 0; ?>%</div>
                                    <div class="stat-label">Accuracy</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-4">
                    
                    <div class="profile-details text-start">
                        <div class="detail-item">
                            <span class="detail-label">Member Since</span>
                            <span class="detail-value">
                                <?php echo date('F j, Y', strtotime($user['created_at'])); ?>
                            </span>
                        </div>
                        
                        <div class="detail-item">
                            <span class="detail-label">Last Study Session</span>
                            <span class="detail-value">
                                <?php 
                                    if (!empty($stats['last_study_date'])) {
                                        $last_date = new DateTime($stats['last_study_date']);
                                        $now = new DateTime();
                                        $diff = $last_date->diff($now);
                                        
                                        if ($diff->days == 0) {
                                            echo "Today";
                                        } elseif ($diff->days == 1) {
                                            echo "Yesterday";
                                        } else {
                                            echo $diff->days . " days ago";
                                        }
                                    } else {
                                        echo "No sessions yet";
                                    }
                                ?>
                            </span>
                        </div>
                        
                        <div class="detail-item">
                            <span class="detail-label">Total Decks</span>
                            <span class="detail-value"><?php echo $deck_count; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Achievements Card -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-trophy me-2"></i>Achievements</h5>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush achievement-list">
                        <?php foreach ($achievements as $achievement): ?>
                            <li class="list-group-item d-flex align-items-center px-3 py-3 <?php echo $achievement['unlocked'] ? '' : 'achievement-locked'; ?>">
                                <div class="achievement-icon <?php echo $achievement['color']; ?> me-3">
                                    <i class="fas <?php echo $achievement['icon']; ?>"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0"><?php echo $achievement['name']; ?></h6>
                                    <small class="text-muted"><?php echo $achievement['description']; ?></small>
                                </div>
                                <?php if ($achievement['unlocked']): ?>
                                    <div class="ms-auto">
                                        <span class="badge bg-success">
                                            <i class="fas fa-check"></i>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="ms-auto">
                                        <span class="badge bg-secondary">
                                            <i class="fas fa-lock"></i>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="col-lg-8">
            <!-- Account Settings -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-cog me-2"></i>Account Settings</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($success_message)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($error_message)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Settings Tabs -->
                    <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab">
                                <i class="fas fa-lock me-2"></i>Change Password
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="email-tab" data-bs-toggle="tab" data-bs-target="#email" type="button" role="tab">
                                <i class="fas fa-envelope me-2"></i>Change Email
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="preferences-tab" data-bs-toggle="tab" data-bs-target="#preferences" type="button" role="tab">
                                <i class="fas fa-sliders-h me-2"></i>Preferences
                            </button>
                        </li>
                    </ul>
                    
                    <!-- Tab Content -->
                    <div class="tab-content" id="settingsTabContent">
                        <!-- Change Password Tab -->
                        <div class="tab-pane fade show active" id="password" role="tabpanel">
                            <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST">
                                <div class="mb-3">
                                    <label for="current_password" class="form-label">Current Password</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required>
                                    <small class="form-text text-muted">Password must be at least 6 characters long</small>
                                </div>
                                <div class="mb-4">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                </div>
                                <button type="submit" name="change_password" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Update Password
                                </button>
                            </form>
                        </div>
                        
                        <!-- Change Email Tab -->
                        <div class="tab-pane fade" id="email" role="tabpanel">
                            <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST">
                                <div class="mb-3">
                                    <label for="current_email" class="form-label">Current Email</label>
                                    <input type="email" class="form-control" id="current_email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled>
                                </div>
                                <div class="mb-4">
                                    <label for="new_email" class="form-label">New Email</label>
                                    <input type="email" class="form-control" id="new_email" name="new_email" required>
                                </div>
                                <button type="submit" name="change_email" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Update Email
                                </button>
                            </form>
                        </div>
                        
                        <!-- Preferences Tab -->
                        <div class="tab-pane fade" id="preferences" role="tabpanel">
                            <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST">
                                <div class="mb-3">
                                    <label class="form-label d-block">Theme Preference</label>
                                    <div class="theme-selector d-flex flex-wrap gap-3">
                                        <div class="theme-option">
                                            <input type="radio" name="theme" id="theme-default" value="default" class="theme-radio" checked>
                                            <label for="theme-default" class="theme-label">
                                                <div class="theme-preview default-theme"></div>
                                                <span>Default</span>
                                            </label>
                                        </div>
                                        <div class="theme-option">
                                            <input type="radio" name="theme" id="theme-dark" value="dark" class="theme-radio">
                                            <label for="theme-dark" class="theme-label">
                                                <div class="theme-preview dark-theme"></div>
                                                <span>Dark</span>
                                            </label>
                                        </div>
                                        <div class="theme-option">
                                            <input type="radio" name="theme" id="theme-sakura" value="sakura" class="theme-radio">
                                            <label for="theme-sakura" class="theme-label">
                                                <div class="theme-preview sakura-theme"></div>
                                                <span>Sakura</span>
                                            </label>
                                        </div>
                                        <div class="theme-option">
                                            <input type="radio" name="theme" id="theme-matcha" value="matcha" class="theme-radio">
                                            <label for="theme-matcha" class="theme-label">
                                                <div class="theme-preview matcha-theme"></div>
                                                <span>Matcha</span>
                                            </label>
                                        </div>
                                    </div>
                                    <small class="form-text text-muted mt-2">Theme functionality will be available in future updates</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Daily Study Goal</label>
                                    <select class="form-select" name="daily_goal">
                                        <option value="10">10 cards per day</option>
                                        <option value="20" selected>20 cards per day</option>
                                        <option value="30">30 cards per day</option>
                                        <option value="50">50 cards per day</option>
                                        <option value="100">100 cards per day</option>
                                    </select>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="form-label">Email Notifications</label>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="notify_due_cards" name="notify_due_cards" checked>
                                        <label class="form-check-label" for="notify_due_cards">
                                            Send me reminders about due cards
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="notify_streak" name="notify_streak" checked>
                                        <label class="form-check-label" for="notify_streak">
                                            Send me alerts when my streak is at risk
                                        </label>
                                    </div>
                                    <small class="form-text text-muted">Notification functionality will be available in future updates</small>
                                </div>
                                
                                <button type="submit" name="save_preferences" class="btn btn-primary" disabled>
                                    <i class="fas fa-save me-2"></i>Save Preferences
                                </button>
                                <small class="text-muted ms-3">Coming soon in a future update</small>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Activity Overview -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Activity Overview</h5>
                    <a href="<?php echo SITE_URL; ?>/stats.php" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-chart-bar me-1"></i>Detailed Stats
                    </a>
                </div>
                <div class="card-body">
                    <div class="activity-calendar mb-4">
                        <h6 class="mb-3">Study Heatmap</h6>
                        <div id="study-heatmap" class="heatmap-container">
                            <!-- Calendar heatmap will be generated here -->
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="activity-overview-box p-3">
                                <h6 class="mb-3">Top Decks</h6>
                                <?php
                                $conn = connectDB();
                                $stmt = $conn->prepare("
                                    SELECT d.deck_name, COUNT(s.stat_id) as study_count 
                                    FROM statistics s 
                                    JOIN decks d ON s.deck_id = d.deck_id 
                                    WHERE s.user_id = ? 
                                    GROUP BY s.deck_id 
                                    ORDER BY study_count DESC 
                                    LIMIT 3
                                ");
                                $stmt->bind_param("i", $_SESSION['user_id']);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                $top_decks = [];
                                while ($row = $result->fetch_assoc()) {
                                    $top_decks[] = $row;
                                }
                                $conn->close();
                                
                                if (empty($top_decks)) {
                                    echo '<p class="text-muted">No study data available yet</p>';
                                } else {
                                ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($top_decks as $index => $deck): ?>
                                        <li class="list-group-item px-0 py-2 border-0">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <span class="rank-badge me-2"><?php echo $index + 1; ?></span>
                                                    <?php echo htmlspecialchars($deck['deck_name']); ?>
                                                </div>
                                                <span class="badge bg-light text-dark">
                                                    <?php echo $deck['study_count']; ?> sessions
                                                </span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php } ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="activity-overview-box p-3">
                                <h6 class="mb-3">Study Time</h6>
                                <?php
                                // This would normally come from the database
                                // Just showing placeholder data for now
                                $study_times = [
                                    'morning' => 35,
                                    'afternoon' => 45,
                                    'evening' => 20
                                ];
                                ?>
                                <div class="study-time-chart">
                                    <div class="d-flex justify-content-between mb-2">
                                        <small>Morning</small>
                                        <small><?php echo $study_times['morning']; ?>%</small>
                                    </div>
                                    <div class="progress mb-3" style="height: 10px;">
                                        <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $study_times['morning']; ?>%"></div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mb-2">
                                        <small>Afternoon</small>
                                        <small><?php echo $study_times['afternoon']; ?>%</small>
                                    </div>
                                    <div class="progress mb-3" style="height: 10px;">
                                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $study_times['afternoon']; ?>%"></div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mb-2">
                                        <small>Evening</small>
                                        <small><?php echo $study_times['evening']; ?>%</small>
                                    </div>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar bg-indigo" role="progressbar" style="width: <?php echo $study_times['evening']; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CSS Styles -->
<style>
    /* Profile Container */
    .profile-container {
        margin-bottom: 3rem;
    }
    
    /* Profile Card */
    .profile-card {
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        border: none;
    }
    
    .profile-card-header {
        height: 120px;
        background: linear-gradient(135deg, var(--indigo) 0%, var(--indigo-dark) 100%);
        position: relative;
    }
    
    .profile-avatar {
        width: 100px;
        height: 100px;
        background-color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2.5rem;
        font-weight: 600;
        color: var(--indigo);
        position: absolute;
        bottom: -50px;
        left: 50%;
        transform: translateX(-50%);
        border: 4px solid white;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }
    
    /* Profile Stats */
    .profile-stats {
        padding-top: 1rem;
    }
    
    .profile-stat-item {
        padding: 0.5rem;
    }
    
    .stat-value {
        font-size: 1.5rem;
        font-weight: 600;
        color: var(--indigo);
    }
    
    .stat-label {
        font-size: 0.8rem;
        color: #777;
    }
    
    /* Profile Details */
    .profile-details {
        padding-top: 0.5rem;
    }
    
    .detail-item {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.75rem;
    }
    
    .detail-label {
        color: #777;
    }
    
    .detail-value {
        font-weight: 500;
    }
    
    /* Achievement List */
    .achievement-list .achievement-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
    }
    
    .achievement-locked {
        opacity: 0.5;
    }
    
    /* Tab Styling */
    .nav-tabs .nav-link {
        padding: 0.75rem 1.25rem;
        color: #495057;
        font-weight: 500;
    }
    
    .nav-tabs .nav-link.active {
        color: var(--indigo);
        border-color: var(--indigo);
        border-bottom: 2px solid var(--indigo);
    }
    
    /* Activity Overview */
    .activity-overview-box {
        background-color: #f8f9fa;
        border-radius: 8px;
        height: 100%;
    }
    
    .rank-badge {
        display: inline-block;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background-color: var(--indigo);
        color: white;
        text-align: center;
        line-height: 24px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    
    /* Theme Selector */
    .theme-selector {
        display: flex;
    }
    
    .theme-option {
        text-align: center;
    }
    
    .theme-radio {
        display: none;
    }
    
    .theme-label {
        cursor: pointer;
    }
    
    .theme-preview {
        width: 100px;
        height: 60px;
        border-radius: 8px;
        margin-bottom: 0.5rem;
        transition: all 0.2s ease;
        border: 2px solid transparent;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    }
    
    .theme-radio:checked + .theme-label .theme-preview {
        border-color: var(--indigo);
        box-shadow: 0 2px 10px rgba(62, 74, 137, 0.3);
    }
    
    .default-theme {
        background: linear-gradient(135deg, #3E4A89 0%, #FFFFFF 100%);
    }
    
    .dark-theme {
        background: linear-gradient(135deg, #222222 0%, #444444 100%);
    }
    
    .sakura-theme {
        background: linear-gradient(135deg, #FFB7C5 0%, #FFFFFF 100%);
    }
    
    .matcha-theme {
        background: linear-gradient(135deg, #8AA367 0%, #FFFFFF 100%);
    }
    
    /* Heatmap Container */
    .heatmap-container {
        min-height: 200px;
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 1rem;
        display: flex;
        flex-wrap: wrap;
        gap: 3px;
    }
    
    .heatmap-cell {
        width: 15px;
        height: 15px;
        border-radius: 2px;
        background-color: #eee;
    }
    
    .heatmap-cell-level-1 {
        background-color: #d6e9c6;
    }
    
    .heatmap-cell-level-2 {
        background-color: #A4C86A;
    }
    
    .heatmap-cell-level-3 {
        background-color: #8AA367;
    }
    
    .heatmap-cell-level-4 {
        background-color: #537A32;
    }
</style>

<!-- JavaScript for Profile Page -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Generate heatmap
    generateHeatmap();
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
});

function generateHeatmap() {
    // Generate last 90 days for the heatmap
    const heatmapContainer = document.getElementById('study-heatmap');
    if (!heatmapContainer) return;
    
    // Get study dates from PHP
    const studyDates = [
        <?php 
            foreach ($study_dates as $date) {
                echo "'$date',";
            }
        ?>
    ];
    
    // Generate cells for last 90 days
    const days = 90;
    let heatmapHtml = '';
    
    // Add labels for months
    let lastMonth = -1;
    heatmapHtml += '<div class="heatmap-months d-flex w-100 mb-2">';
    for (let i = days; i >= 0; i--) {
        const date = new Date();
        date.setDate(date.getDate() - i);
        
        const month = date.getMonth();
        if (month !== lastMonth) {
            lastMonth = month;
            const monthName = date.toLocaleString('default', { month: 'short' });
            heatmapHtml += `<div class="heatmap-month-label">${monthName}</div>`;
        }
    }
    heatmapHtml += '</div>';
    
    // Add day cells
    for (let i = days; i >= 0; i--) {
        const date = new Date();
        date.setDate(date.getDate() - i);
        
        const dateString = date.toISOString().split('T')[0];
        
        // Check if user studied on this day
        const studyCount = studyDates.includes(dateString) ? Math.floor(Math.random() * 4) + 1 : 0;
        
        let cellClass = 'heatmap-cell';
        if (studyCount > 0) {
            cellClass += ` heatmap-cell-level-${studyCount}`;
        }
        
        const formattedDate = date.toLocaleDateString();
        
        heatmapHtml += `<div class="${cellClass}" title="${formattedDate}" data-bs-toggle="tooltip"></div>`;
    }
    
    heatmapContainer.innerHTML = heatmapHtml;
}
</script>

<?php include_once 'includes/footer.php'; ?>