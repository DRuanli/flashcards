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

// Get user statistics in one efficient query
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT d.deck_id) as total_decks,
        (SELECT COUNT(*) FROM cards c JOIN decks d2 ON c.deck_id = d2.deck_id WHERE d2.user_id = ?) as total_cards,
        COALESCE(SUM(s.cards_studied), 0) as total_cards_studied,
        COALESCE(SUM(s.correct_answers), 0) as total_correct,
        CASE WHEN SUM(s.cards_studied) > 0 
            THEN ROUND(SUM(s.correct_answers) / SUM(s.cards_studied) * 100) 
            ELSE 0 
        END as overall_accuracy,
        MAX(s.date_studied) as last_study_date
    FROM decks d
    LEFT JOIN statistics s ON d.deck_id = s.deck_id AND s.user_id = ?
    WHERE d.user_id = ?
");
$stmt->bind_param("iii", $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$stats = $result->fetch_assoc();

// Get study dates for streak calculation
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
$today = date('Y-m-d');
$studied_today = in_array($today, $study_dates);
$start_date = $studied_today ? $today : date('Y-m-d', strtotime('-1 day'));

// Calculate streak
for ($i = 0; $i < count($study_dates); $i++) {
    $date_to_check = date('Y-m-d', strtotime("-{$i} days"));
    if (in_array($date_to_check, $study_dates)) {
        $current_streak++;
    } else {
        break;
    }
}

// Get study time distribution
$stmt = $conn->prepare("
    SELECT 
        CASE
            WHEN HOUR(last_reviewed) BETWEEN 5 AND 11 THEN 'morning'
            WHEN HOUR(last_reviewed) BETWEEN 12 AND 17 THEN 'afternoon'
            ELSE 'evening'
        END as time_of_day,
        COUNT(*) as review_count
    FROM progress
    WHERE user_id = ? AND last_reviewed IS NOT NULL
    GROUP BY time_of_day
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

$total_reviews = 0;
$study_times = [
    'morning' => 0,
    'afternoon' => 0,
    'evening' => 0
];

while ($row = $result->fetch_assoc()) {
    $study_times[$row['time_of_day']] = $row['review_count'];
    $total_reviews += $row['review_count'];
}

// Convert to percentages
if ($total_reviews > 0) {
    foreach ($study_times as $time => $count) {
        $study_times[$time] = round(($count / $total_reviews) * 100);
    }
}

// Get study count data for heatmap
$stmt = $conn->prepare("
    SELECT date_studied, SUM(cards_studied) as count
    FROM statistics
    WHERE user_id = ?
    GROUP BY date_studied
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$study_counts = [];
while ($row = $result->fetch_assoc()) {
    $study_counts[$row['date_studied']] = $row['count'];
}

// Get top decks
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
                            <span class="detail-value"><?php echo $stats['total_decks']; ?></span>
                        </div>
                        
                        <div class="detail-item">
                            <span class="detail-label">Total Cards</span>
                            <span class="detail-value"><?php echo $stats['total_cards']; ?></span>
                        </div>
                    </div>
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
                    </div>
                </div>
            </div>
            
            <!-- Activity Overview -->
            <div class="row">
                <!-- Study Calendar -->
                <div class="col-md-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Study Calendar</h5>
                        </div>
                        <div class="card-body">
                            <div id="study-calendar"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Study Insights -->
                <div class="col-md-6 mb-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Study Insights</h5>
                        </div>
                        <div class="card-body">
                            <!-- Study Time Distribution -->
                            <h6 class="mb-3">Study Time Distribution</h6>
                            <div class="study-time-chart mb-4">
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

                            <!-- Top Decks -->
                            <h6 class="mb-3 mt-4">Top Decks</h6>
                            <?php if (empty($top_decks)): ?>
                                <p class="text-muted">No study data available yet</p>
                            <?php else: ?>
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
                            <?php endif; ?>
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
    
    /* Calendar Styles */
    .calendar-day {
        display: inline-flex;
        justify-content: center;
        align-items: center;
        width: 2rem;
        height: 2rem;
        border-radius: 50%;
        font-size: 0.9rem;
        margin: 0 auto;
        transition: all 0.2s ease;
    }
    
    .calendar-day.today {
        background-color: var(--indigo);
        color: white;
    }
    
    .calendar-day.studied-1:not(.today) {
        background-color: #d6e9c6;
        color: var(--kuro);
    }
    
    .calendar-day.studied-2:not(.today) {
        background-color: #A4C86A;
        color: var(--kuro);
    }
    
    .calendar-day.studied-3:not(.today) {
        background-color: #8AA367;
        color: white;
    }
    
    .calendar-day.studied-4:not(.today) {
        background-color: #537A32;
        color: white;
    }
    
    .calendar-day.today.studied {
        background: linear-gradient(135deg, var(--indigo) 50%, var(--matcha) 50%);
        color: white;
    }
    
    .heatmap-month-label {
        flex: 1;
        text-align: center;
        font-size: 0.8rem;
        font-weight: 500;
        color: #777;
    }
</style>

<!-- JavaScript for Calendar -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Generate calendar
    renderCalendar();
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
});

function renderCalendar() {
    const calendarEl = document.getElementById('study-calendar');
    if (!calendarEl) return;
    
    const currentDate = new Date();
    const currentMonth = currentDate.getMonth();
    const currentYear = currentDate.getFullYear();
    
    // Get study dates from PHP
    const studyDates = [
        <?php 
            foreach ($study_dates as $date) {
                echo "'$date',";
            }
        ?>
    ];
    
    // Get study counts for intensity
    const studyCounts = {
        <?php 
            foreach ($study_counts as $date => $count) {
                echo "'$date': $count,";
            }
        ?>
    };
    
    // Function to calculate intensity level (1-4) based on count
    function calculateIntensity(count) {
        if (count <= 5) return 1;
        if (count <= 15) return 2;
        if (count <= 30) return 3;
        return 4;
    }
    
    // Create month and year header
    const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    const header = document.createElement('h6');
    header.className = 'text-center mb-3';
    header.textContent = `${monthNames[currentMonth]} ${currentYear}`;
    calendarEl.appendChild(header);
    
    // Create day labels
    const dayLabels = document.createElement('div');
    dayLabels.className = 'row text-center mb-2';
    const days = ['日', '月', '火', '水', '木', '金', '土'];
    
    days.forEach(day => {
        const dayEl = document.createElement('div');
        dayEl.className = 'col px-1';
        dayEl.textContent = day;
        dayEl.style.fontSize = '0.8rem';
        dayEl.style.fontWeight = '500';
        dayLabels.appendChild(dayEl);
    });
    
    calendarEl.appendChild(dayLabels);
    
    // Get first day of month and number of days
    const firstDay = new Date(currentYear, currentMonth, 1).getDay();
    const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
    
    // Create calendar days
    let dayCount = 1;
    let calendarHtml = '';
    
    // Create rows for the month
    for (let i = 0; i < 6; i++) {
        calendarHtml += '<div class="row mb-2">';
        
        // Create 7 columns (days of week)
        for (let j = 0; j < 7; j++) {
            // Add empty cells for days before start of month
            if (i === 0 && j < firstDay) {
                calendarHtml += '<div class="col px-1"></div>';
            } 
            // Add days of month
            else if (dayCount <= daysInMonth) {
                const date = `${currentYear}-${(currentMonth + 1).toString().padStart(2, '0')}-${dayCount.toString().padStart(2, '0')}`;
                const isStudyDay = studyDates.includes(date);
                const isToday = dayCount === currentDate.getDate() && currentMonth === currentDate.getMonth() && currentYear === currentDate.getFullYear();
                
                // Calculate intensity level (1-4) based on actual study count
                const studyIntensity = isStudyDay ? calculateIntensity(studyCounts[date] || 0) : 0;
                
                let dayHtml;
                let intensityClass = studyIntensity > 0 ? ` studied-${studyIntensity}` : '';
                
                if (isStudyDay && isToday) {
                    dayHtml = `<div class="calendar-day today studied${intensityClass}">${dayCount}</div>`;
                } else if (isStudyDay) {
                    dayHtml = `<div class="calendar-day studied${intensityClass}">${dayCount}</div>`;
                } else if (isToday) {
                    dayHtml = `<div class="calendar-day today">${dayCount}</div>`;
                } else {
                    dayHtml = `<div class="calendar-day">${dayCount}</div>`;
                }
                
                const studyCountText = studyCounts[date] ? ` - ${studyCounts[date]} cards` : '';
                calendarHtml += `<div class="col px-1 text-center" title="${date}${studyCountText}" data-bs-toggle="tooltip">${dayHtml}</div>`;
                dayCount++;
            } 
            // Add empty cells for days after end of month
            else {
                calendarHtml += '<div class="col px-1"></div>';
            }
        }
        
        calendarHtml += '</div>';
        
        // Stop if we've reached the end of the month
        if (dayCount > daysInMonth) {
            break;
        }
    }
    
    const calendarDays = document.createElement('div');
    calendarDays.innerHTML = calendarHtml;
    calendarEl.appendChild(calendarDays);
}
</script>

<?php include_once 'includes/footer.php'; ?>