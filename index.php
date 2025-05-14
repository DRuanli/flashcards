<?php
require_once 'config.php';

// index.php - Dashboard (main page)

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

// Get user statistics
$conn = connectDB();

// Get total decks
$stmt = $conn->prepare("SELECT COUNT(*) as total_decks FROM decks WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$total_decks = $result->fetch_assoc()['total_decks'];

// Get total cards
$stmt = $conn->prepare("
    SELECT COUNT(*) as total_cards 
    FROM cards c
    JOIN decks d ON c.deck_id = d.deck_id
    WHERE d.user_id = ?
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$total_cards = $result->fetch_assoc()['total_cards'];

// Get cards due for review
$today = date('Y-m-d');
$stmt = $conn->prepare("
    SELECT COUNT(*) as due_cards 
    FROM progress p
    JOIN cards c ON p.card_id = c.card_id
    JOIN decks d ON c.deck_id = d.deck_id
    WHERE p.user_id = ? AND (p.next_review <= ? OR p.next_review IS NULL)
");
$stmt->bind_param("is", $_SESSION['user_id'], $today);
$stmt->execute();
$result = $stmt->get_result();
$due_cards = $result->fetch_assoc()['due_cards'];

// Get recent study activity (last 7 days)
$stmt = $conn->prepare("
    SELECT DATE_FORMAT(date_studied, '%Y-%m-%d') as study_date, 
           SUM(cards_studied) as cards_studied,
           SUM(correct_answers) as correct_answers
    FROM statistics
    WHERE user_id = ? AND date_studied >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY date_studied
    ORDER BY date_studied
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$activity = [];
while ($row = $result->fetch_assoc()) {
    $activity[] = $row;
}

// Get recent decks
$stmt = $conn->prepare("
    SELECT d.deck_id, d.deck_name, d.description, COUNT(c.card_id) as card_count,
           (SELECT COUNT(*) FROM progress p 
            JOIN cards c2 ON p.card_id = c2.card_id 
            WHERE c2.deck_id = d.deck_id AND p.user_id = ? AND p.next_review <= CURDATE()) as due_count,
           (SELECT MAX(date_studied) FROM statistics WHERE user_id = ? AND deck_id = d.deck_id) as last_studied
    FROM decks d
    LEFT JOIN cards c ON d.deck_id = c.deck_id
    WHERE d.user_id = ?
    GROUP BY d.deck_id
    ORDER BY d.created_at DESC
    LIMIT 6
");
$stmt->bind_param("iii", $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$recent_decks = [];
while ($row = $result->fetch_assoc()) {
    $recent_decks[] = $row;
}

// Get user's study streak
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

// Get overall stats for accuracy
$stmt = $conn->prepare("
    SELECT 
        SUM(cards_studied) as total_cards_studied,
        SUM(correct_answers) as total_correct,
        ROUND(SUM(correct_answers) / SUM(cards_studied) * 100) as overall_accuracy
    FROM statistics
    WHERE user_id = ?
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$overall_stats = $result->fetch_assoc();

// Get mastery progress (cards by learning stage)
$stmt = $conn->prepare("
    SELECT 
        CASE 
            WHEN p.repetitions IS NULL THEN 'new'
            WHEN p.repetitions = 0 THEN 'learning'
            WHEN p.repetitions BETWEEN 1 AND 3 THEN 'reviewing'
            ELSE 'mastered'
        END as card_status,
        COUNT(*) as count
    FROM cards c
    JOIN decks d ON c.deck_id = d.deck_id
    LEFT JOIN progress p ON c.card_id = p.card_id AND p.user_id = ?
    WHERE d.user_id = ?
    GROUP BY card_status
");
$stmt->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$mastery_data = [
    'new' => 0,
    'learning' => 0,
    'reviewing' => 0,
    'mastered' => 0
];
while ($row = $result->fetch_assoc()) {
    $mastery_data[$row['card_status']] = $row['count'];
}

// Get top performing decks
$stmt = $conn->prepare("
    SELECT d.deck_id, d.deck_name, 
           SUM(s.cards_studied) as total_studied,
           SUM(s.correct_answers) as total_correct,
           ROUND(SUM(s.correct_answers) / SUM(s.cards_studied) * 100) as accuracy
    FROM statistics s
    JOIN decks d ON s.deck_id = d.deck_id
    WHERE s.user_id = ? AND s.cards_studied > 0
    GROUP BY s.deck_id
    ORDER BY accuracy DESC, total_studied DESC
    LIMIT 3
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$top_decks = [];
while ($row = $result->fetch_assoc()) {
    $top_decks[] = $row;
}

// Get user's daily goal (default to 20)
$daily_goal = 20; // This could come from user preferences in a real implementation

// Check if we have any study sessions today
$stmt = $conn->prepare("
    SELECT SUM(cards_studied) as today_studied, SUM(correct_answers) as today_correct
    FROM statistics 
    WHERE user_id = ? AND date_studied = CURDATE()
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$today_stats = $result->fetch_assoc();
$today_studied = $today_stats['today_studied'] ?? 0;
$today_correct = $today_stats['today_correct'] ?? 0;

$conn->close();

// Include header
include_once 'includes/header.php';
?>

<!-- New & Enhanced Dashboard UI -->
<div class="dashboard-container">
    <!-- Top Section with User Greeting and Quick Actions -->
    <div class="row mb-4">
        <div class="col-lg-8 mb-4 mb-lg-0">
            <!-- Redesigned Hero Card -->
            <div class="card dashboard-hero-card h-100">
                <div class="card-body p-0">
                    <div class="dashboard-hero-content p-4">
                        <div class="d-flex align-items-center mb-3">
                            <div class="dashboard-avatar me-3">
                                <span><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></span>
                            </div>
                            <div>
                                <h1 class="mb-1">
                                    <?php 
                                        $hour = date('H');
                                        if ($hour < 12) {
                                            echo 'Good morning, ';
                                        } elseif ($hour < 17) {
                                            echo 'Good afternoon, ';
                                        } else {
                                            echo 'Good evening, ';
                                        }
                                        echo htmlspecialchars($_SESSION['username']);
                                    ?>!
                                </h1>
                                <p class="text-muted mb-0">
                                    <?php if ($due_cards > 0): ?>
                                        <span class="text-accent">
                                            <?php echo $due_cards; ?> cards
                                        </span> due for review today
                                    <?php else: ?>
                                        You're all caught up with your reviews! <span class="text-accent">Great job!</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        
                        <!-- Streak Display -->
                        <?php if ($current_streak > 0): ?>
                        <div class="streak-container">
                            <div class="d-flex align-items-center">
                                <div class="streak-badge pulse me-2">
                                    <i class="fas fa-fire-alt"></i>
                                </div>
                                <div>
                                    <h5 class="mb-1"><?php echo $current_streak; ?>-Day Streak</h5>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-accent" role="progressbar" style="width: 100%;" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="streak-container inactive-streak">
                            <div class="d-flex align-items-center">
                                <div class="streak-badge inactive me-2">
                                    <i class="fas fa-fire-alt"></i>
                                </div>
                                <div>
                                    <h5 class="mb-1">Start Your Streak Today!</h5>
                                    <p class="small text-muted mb-0">Study daily to build your learning momentum</p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Hero Card Decoration -->
                    <div class="hero-decoration"></div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <!-- Quick Actions Card -->
            <div class="card quick-actions-card h-100">
                <div class="card-body p-4">
                    <h5 class="card-title mb-3"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                    
                    <div class="action-buttons">
                        <?php if ($due_cards > 0): ?>
                            <a href="<?php echo SITE_URL; ?>/study/index.php" class="btn btn-primary btn-lg w-100 mb-3">
                                <i class="fas fa-graduation-cap me-2"></i>Study Due Cards
                                <span class="badge bg-light text-dark ms-2"><?php echo $due_cards; ?></span>
                            </a>
                        <?php else: ?>
                            <a href="<?php echo SITE_URL; ?>/study/index.php?mode=all" class="btn btn-primary btn-lg w-100 mb-3">
                                <i class="fas fa-sync-alt me-2"></i>Review Cards Anyway
                            </a>
                        <?php endif; ?>
                        
                        <div class="row g-2">
                            <div class="col-6">
                                <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-outline-primary w-100 h-100">
                                    <i class="fas fa-plus me-2"></i>New Deck
                                </a>
                            </div>
                            <div class="col-6">
                                <a href="<?php echo SITE_URL; ?>/decks/list.php" class="btn btn-outline-primary w-100 h-100">
                                    <i class="fas fa-layer-group me-2"></i>My Decks
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Stats Overview Row -->
    <div class="row mb-4">
        <!-- Total Decks -->
        <div class="col-md-3 col-sm-6 mb-4">
            <div class="card stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <h3 class="stat-value"><?php echo $total_decks; ?></h3>
                    <p class="stat-label">Total Decks</p>
                    <a href="<?php echo SITE_URL; ?>/decks/list.php" class="stretched-link" aria-label="View all decks"></a>
                </div>
            </div>
        </div>
        
        <!-- Total Cards -->
        <div class="col-md-3 col-sm-6 mb-4">
            <div class="card stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon">
                        <i class="fas fa-clone"></i>
                    </div>
                    <h3 class="stat-value"><?php echo $total_cards; ?></h3>
                    <p class="stat-label">Total Cards</p>
                </div>
            </div>
        </div>
        
        <!-- Cards Studied -->
        <div class="col-md-3 col-sm-6 mb-4">
            <div class="card stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon">
                        <i class="fas fa-brain"></i>
                    </div>
                    <h3 class="stat-value"><?php echo $overall_stats['total_cards_studied'] ?? 0; ?></h3>
                    <p class="stat-label">Cards Studied</p>
                </div>
            </div>
        </div>
        
        <!-- Overall Accuracy -->
        <div class="col-md-3 col-sm-6 mb-4">
            <div class="card stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <h3 class="stat-value"><?php echo $overall_stats['overall_accuracy'] ?? 0; ?>%</h3>
                    <p class="stat-label">Accuracy</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Main Content Row -->
    <div class="row">
        <!-- Left Column - Study Activity & Mastery -->
        <div class="col-lg-8 mb-4">
            <!-- Study Activity Chart Card -->
            <div class="card mb-4">
                <div class="card-header dashboard-card-header">
                    <div class="d-flex align-items-center justify-content-between">
                        <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Recent Study Activity</h5>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Chart timeframe">
                            <button type="button" class="btn btn-outline-secondary active" id="chart-7days">7 Days</button>
                            <button type="button" class="btn btn-outline-secondary" id="chart-30days">30 Days</button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($activity)): ?>
                        <div class="text-center empty-state py-5">
                            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 24 24' fill='none' stroke='%233E4A89' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'%3E%3Cline x1='4' y1='21' x2='4' y2='14'/%3E%3Cline x1='8' y1='21' x2='8' y2='12'/%3E%3Cline x1='12' y1='21' x2='12' y2='8'/%3E%3Cline x1='16' y1='21' x2='16' y2='16'/%3E%3Cline x1='20' y1='21' x2='20' y2='10'/%3E%3C/svg%3E" 
                                 alt="No activity" class="empty-state-icon mb-3">
                            <h4>No Study Activity Yet</h4>
                            <p class="text-muted mb-4">Start studying your flashcards to see your progress here.</p>
                            <a href="<?php echo SITE_URL; ?>/study/index.php" class="btn btn-primary">
                                <i class="fas fa-graduation-cap me-2"></i>Start Studying Now
                            </a>
                        </div>
                    <?php else: ?>
                        <canvas id="activityChart" height="260"></canvas>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Mastery Progress Card -->
            <div class="card">
                <div class="card-header dashboard-card-header">
                    <h5 class="mb-0"><i class="fas fa-award me-2"></i>Your Mastery Progress</h5>
                </div>
                <div class="card-body pb-0">
                    <?php 
                        $total_mastery_cards = array_sum($mastery_data);
                        $mastery_percent = $total_mastery_cards > 0 ? round(($mastery_data['mastered'] / $total_mastery_cards) * 100) : 0;
                    ?>
                    
                    <div class="mastery-progress-container">
                        <div class="mastery-meter mb-3">
                            <div class="mastery-info">
                                <div class="mastery-percentage">
                                    <h3><?php echo $mastery_percent; ?>%</h3>
                                    <p class="mb-0">Mastered</p>
                                </div>
                            </div>
                            
                            <div class="progress mastery-bar" style="height: 16px;">
                                <?php if ($mastery_data['new'] > 0): ?>
                                <div class="progress-bar bg-indigo" role="progressbar" 
                                    style="width: <?php echo $total_mastery_cards > 0 ? ($mastery_data['new'] / $total_mastery_cards) * 100 : 0; ?>%" 
                                    title="New: <?php echo $mastery_data['new']; ?> cards"></div>
                                <?php endif; ?>
                                
                                <?php if ($mastery_data['learning'] > 0): ?>
                                <div class="progress-bar bg-warning" role="progressbar" 
                                    style="width: <?php echo $total_mastery_cards > 0 ? ($mastery_data['learning'] / $total_mastery_cards) * 100 : 0; ?>%" 
                                    title="Learning: <?php echo $mastery_data['learning']; ?> cards"></div>
                                <?php endif; ?>
                                
                                <?php if ($mastery_data['reviewing'] > 0): ?>
                                <div class="progress-bar bg-info" role="progressbar" 
                                    style="width: <?php echo $total_mastery_cards > 0 ? ($mastery_data['reviewing'] / $total_mastery_cards) * 100 : 0; ?>%" 
                                    title="Reviewing: <?php echo $mastery_data['reviewing']; ?> cards"></div>
                                <?php endif; ?>
                                
                                <?php if ($mastery_data['mastered'] > 0): ?>
                                <div class="progress-bar bg-success" role="progressbar" 
                                    style="width: <?php echo $total_mastery_cards > 0 ? ($mastery_data['mastered'] / $total_mastery_cards) * 100 : 0; ?>%" 
                                    title="Mastered: <?php echo $mastery_data['mastered']; ?> cards"></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="row mastery-legend">
                            <div class="col-md-3 col-6 mb-3">
                                <div class="mastery-item">
                                    <span class="mastery-dot bg-indigo"></span>
                                    <div class="mastery-label">
                                        <div>New</div>
                                        <strong><?php echo $mastery_data['new']; ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 col-6 mb-3">
                                <div class="mastery-item">
                                    <span class="mastery-dot bg-warning"></span>
                                    <div class="mastery-label">
                                        <div>Learning</div>
                                        <strong><?php echo $mastery_data['learning']; ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 col-6 mb-3">
                                <div class="mastery-item">
                                    <span class="mastery-dot bg-info"></span>
                                    <div class="mastery-label">
                                        <div>Reviewing</div>
                                        <strong><?php echo $mastery_data['reviewing']; ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 col-6 mb-3">
                                <div class="mastery-item">
                                    <span class="mastery-dot bg-success"></span>
                                    <div class="mastery-label">
                                        <div>Mastered</div>
                                        <strong><?php echo $mastery_data['mastered']; ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Right Column - Daily Goal, Top Decks, Recent Decks -->
        <div class="col-lg-4 mb-4">
            <!-- Daily Goal Card -->
            <div class="card mb-4">
                <div class="card-header dashboard-card-header">
                    <h5 class="mb-0"><i class="fas fa-bullseye me-2"></i>Today's Progress</h5>
                </div>
                <div class="card-body">
                    <?php 
                        // Calculate progress percentage
                        $progress_percent = $daily_goal > 0 ? min(100, round(($today_studied / $daily_goal) * 100)) : 0;
                        
                        // Set progress classes based on completion
                        $progress_class = $progress_percent >= 100 ? 'completed' : 'in-progress';
                        $progress_text = $progress_percent >= 100 ? 'Goal Complete!' : ($progress_percent > 0 ? 'In Progress' : 'Not Started');
                    ?>
                    <div class="goal-container <?php echo $progress_class; ?>">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="daily-goal-label">Daily Goal (<?php echo $today_studied; ?>/<?php echo $daily_goal; ?> cards)</span>
                            <span class="goal-status"><?php echo $progress_text; ?></span>
                        </div>
                        
                        <div class="progress daily-goal-progress mb-2">
                            <div class="progress-bar progress-bar-striped <?php echo $progress_percent >= 100 ? 'bg-success' : 'bg-accent'; ?>" 
                                role="progressbar" style="width: <?php echo $progress_percent; ?>%" 
                                aria-valuenow="<?php echo $progress_percent; ?>" aria-valuemin="0" aria-valuemax="100">
                            </div>
                        </div>
                        
                        <?php if ($progress_percent >= 100): ?>
                            <div class="text-center goal-complete-message mt-3">
                                <div class="celebration-icon mb-2">
                                    <i class="fas fa-award"></i>
                                </div>
                                <h4 class="mb-2">Great Job!</h4>
                                <p class="text-muted mb-3">You've reached your daily goal. Keep the momentum going!</p>
                                <a href="<?php echo SITE_URL; ?>/study/index.php?mode=all" class="btn btn-sm btn-success">
                                    <i class="fas fa-sync-alt me-1"></i>Study More
                                </a>
                            </div>
                        <?php else: ?>
                            <a href="<?php echo SITE_URL; ?>/study/index.php" class="btn btn-primary w-100 mt-2">
                                <i class="fas fa-graduation-cap me-2"></i>Continue Studying
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Today's Stats Summary -->
                    <?php if ($today_studied > 0): ?>
                    <div class="today-stats mt-3 pt-3 border-top">
                        <h6 class="text-muted mb-3">Today's Stats</h6>
                        <div class="row">
                            <div class="col-6">
                                <div class="today-stat-item">
                                    <div class="today-stat-value"><?php echo $today_studied; ?></div>
                                    <div class="today-stat-label">Cards Studied</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="today-stat-item">
                                    <div class="today-stat-value">
                                        <?php 
                                            echo $today_studied > 0 
                                                ? round(($today_correct / $today_studied) * 100) 
                                                : 0;
                                        ?>%
                                    </div>
                                    <div class="today-stat-label">Accuracy</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Top Performing Decks -->
            <?php if (!empty($top_decks)): ?>
            <div class="card mb-4">
                <div class="card-header dashboard-card-header">
                    <h5 class="mb-0"><i class="fas fa-trophy me-2"></i>Top Decks</h5>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($top_decks as $index => $deck): ?>
                            <li class="list-group-item py-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <div class="top-deck-rank me-3">
                                            <?php echo $index + 1; ?>
                                        </div>
                                        <div>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($deck['deck_name']); ?></h6>
                                            <div class="text-muted small"><?php echo $deck['total_studied']; ?> cards studied</div>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <div class="accuracy-badge 
                                            <?php 
                                                if ($deck['accuracy'] >= 90) echo 'excellent';
                                                elseif ($deck['accuracy'] >= 70) echo 'good';
                                                elseif ($deck['accuracy'] >= 50) echo 'fair';
                                                else echo 'needs-work';
                                            ?>">
                                            <?php echo $deck['accuracy']; ?>%
                                        </div>
                                    </div>
                                </div>
                                <a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck['deck_id']; ?>" class="stretched-link"></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Recent Activity Card -->
            <div class="card dashboard-card">
                <div class="card-header dashboard-card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i>Study Calendar</h5>
                        <button class="btn btn-sm btn-outline-secondary" 
                                type="button"
                                data-bs-toggle="tooltip"
                                title="Coming soon: Detailed study analytics">
                            <i class="fas fa-chart-line"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="study-calendar" class="mb-3"></div>
                    
                    <?php if (!empty($study_dates)): ?>
                    <div class="text-center mt-3">
                        <p class="text-muted mb-0 small">
                            <i class="fas fa-info-circle me-1"></i> You've studied on <?php echo count($study_dates); ?> days in total
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Decks Section -->
    <div class="row">
        <div class="col-12 mb-4">
            <div class="section-header d-flex justify-content-between align-items-center mb-3">
                <h2 class="section-title"><i class="fas fa-book me-2"></i>Recent Decks</h2>
                <a href="<?php echo SITE_URL; ?>/decks/list.php" class="btn btn-sm btn-outline-primary">
                    View All <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
            
            <?php if (empty($recent_decks)): ?>
                <div class="empty-state-container text-center py-5">
                    <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 24 24' fill='none' stroke='%233E4A89' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20'/%3E%3C/svg%3E" 
                        alt="No decks" class="empty-state-icon mb-3">
                    <h4>No Decks Created Yet</h4>
                    <p class="text-muted mb-4">Create your first flashcard deck to start learning.</p>
                    <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Create Your First Deck
                    </a>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($recent_decks as $deck): ?>
                        <div class="col-lg-4 col-md-6 mb-4">
                            <div class="card deck-card h-100">
                                <?php if ($deck['due_count'] > 0): ?>
                                    <div class="due-badge">
                                        <span><?php echo $deck['due_count']; ?> due</span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="card-body">
                                    <h5 class="deck-title"><?php echo htmlspecialchars($deck['deck_name']); ?></h5>
                                    
                                    <p class="deck-description text-muted mb-3">
                                        <?php 
                                            echo !empty($deck['description']) 
                                                ? htmlspecialchars(substr($deck['description'], 0, 80)) . (strlen($deck['description']) > 80 ? '...' : '')
                                                : '<em>No description</em>';
                                        ?>
                                    </p>
                                    
                                    <div class="deck-stats">
                                        <div class="deck-stat">
                                            <i class="fas fa-clone me-1"></i>
                                            <span><?php echo $deck['card_count']; ?> cards</span>
                                        </div>
                                        
                                        <?php if ($deck['last_studied']): ?>
                                            <div class="deck-stat">
                                                <i class="fas fa-history me-1"></i>
                                                <span>
                                                    <?php 
                                                        $last_date = new DateTime($deck['last_studied']);
                                                        $now = new DateTime();
                                                        $diff = $last_date->diff($now);
                                                        
                                                        if ($diff->days == 0) {
                                                            echo "Today";
                                                        } elseif ($diff->days == 1) {
                                                            echo "Yesterday";
                                                        } else {
                                                            echo $diff->days . " days ago";
                                                        }
                                                    ?>
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <div class="deck-stat">
                                                <i class="fas fa-clock me-1"></i>
                                                <span>Never studied</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="card-footer bg-transparent">
                                    <div class="d-grid gap-2">
                                        <?php if ($deck['card_count'] > 0): ?>
                                            <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-primary">
                                                <i class="fas fa-graduation-cap me-1"></i>Study
                                            </a>
                                        <?php else: ?>
                                            <a href="<?php echo SITE_URL; ?>/cards/create.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-primary">
                                                <i class="fas fa-plus me-1"></i>Add Cards
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Invisible stretched link for the entire card -->
                                <a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck['deck_id']; ?>" class="card-link"></a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Study Tips Section (New) -->
<div class="study-tips-section mb-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card study-tips-card">
                    <div class="card-body p-4">
                        <h5 class="mb-3"><i class="fas fa-lightbulb me-2 text-warning"></i>Study Tips</h5>
                        
                        <div id="studyTipsCarousel" class="carousel slide" data-bs-ride="carousel">
                            <div class="carousel-inner">
                                <div class="carousel-item active">
                                    <div class="study-tip">
                                        <p class="tip-text">"Short daily study sessions are more effective than occasional cramming. Try to review your cards for just 5-10 minutes each day."</p>
                                    </div>
                                </div>
                                <div class="carousel-item">
                                    <div class="study-tip">
                                        <p class="tip-text">"Testing yourself is more effective than re-reading. FlashLearn's spaced repetition system optimizes your review schedule."</p>
                                    </div>
                                </div>
                                <div class="carousel-item">
                                    <div class="study-tip">
                                        <p class="tip-text">"Create your own cards! The process of creating flashcards helps with encoding information into memory."</p>
                                    </div>
                                </div>
                                <div class="carousel-item">
                                    <div class="study-tip">
                                        <p class="tip-text">"Maintain your streak! Consistency is key to effective learning. Even 5 minutes a day makes a difference."</p>
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

<!-- Enhanced CSS -->
<style>
    /* Overall Dashboard Enhancements */
    .dashboard-container {
        margin-bottom: 3rem;
    }
    
    .section-title {
        font-size: 1.6rem;
        font-weight: 600;
        margin-bottom: 0;
        color: var(--kuro);
    }
    
    .dashboard-card-header {
        padding: 1rem 1.25rem;
        background-color: rgba(248, 249, 250, 0.5);
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }
    
    /* Custom color variables for dashboard */
    :root {
        --accent-color: #FF9F64;
        --accent-color-dark: #FF8B43;
        --accent-color-light: #FFCDA6;
        --danger-color: #FF6464;
        --success-color: #50C878;
    }
    
    /* Hero Card Styling */
    .dashboard-hero-card {
        position: relative;
        border-radius: 15px;
        overflow: hidden;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        background-image: linear-gradient(135deg, var(--indigo) 0%, var(--indigo-dark) 100%);
    }
    
    .dashboard-hero-content {
        position: relative;
        z-index: 2;
        color: white;
    }
    
    .hero-decoration {
        position: absolute;
        bottom: -50px;
        right: -50px;
        width: 200px;
        height: 200px;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0) 70%);
        border-radius: 100%;
        z-index: 1;
    }
    
    .dashboard-avatar {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        border: 2px solid rgba(255, 255, 255, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        font-weight: 600;
        color: white;
    }
    
    .dashboard-hero-card h1 {
        font-size: 1.8rem;
    }
    
    .text-accent {
        color: var(--accent-color) !important;
        font-weight: 600;
    }
    
    /* Streak styling */
    .streak-container {
        margin-top: 1.5rem;
        padding: 1rem;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
    }
    
    .streak-badge {
        width: 45px;
        height: 45px;
        background: var(--accent-color);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        color: white;
    }
    
    .streak-badge.inactive {
        background: rgba(255, 255, 255, 0.2);
        color: rgba(255, 255, 255, 0.7);
    }
    
    /* Pulse animation for streak badge */
    @keyframes pulse {
        0% {
            box-shadow: 0 0 0 0 rgba(255, 159, 100, 0.7);
        }
        70% {
            box-shadow: 0 0 0 10px rgba(255, 159, 100, 0);
        }
        100% {
            box-shadow: 0 0 0 0 rgba(255, 159, 100, 0);
        }
    }
    
    .pulse {
        animation: pulse 2s infinite;
    }
    
    /* Quick Actions Card */
    .quick-actions-card {
        border-radius: 15px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
    }
    
    .quick-actions-card .card-title {
        color: var(--kuro);
        font-weight: 600;
    }
    
    .action-buttons .btn {
        border-radius: 10px;
    }
    
    /* Stats Cards */
    .stat-card {
        border-radius: 15px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        height: 100%;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        overflow: hidden;
        position: relative;
    }
    
    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }
    
    .stat-card-body {
        padding: 1.5rem;
        text-align: center;
        position: relative;
        z-index: 2;
    }
    
    .stat-icon {
        font-size: 2rem;
        margin-bottom: 0.5rem;
        color: var(--indigo);
        opacity: 0.8;
    }
    
    .stat-value {
        font-size: 2.2rem;
        font-weight: 700;
        margin-bottom: 0.25rem;
        color: var(--kuro);
    }
    
    .stat-label {
        color: #777;
        font-size: 0.9rem;
        margin-bottom: 0;
    }
    
    /* Mastery Progress */
    .mastery-progress-container {
        padding: 0.5rem 0;
    }
    
    .mastery-meter {
        position: relative;
    }
    
    .mastery-info {
        position: absolute;
        top: -60px;
        right: 0;
        background: var(--gofun);
        padding: 0.75rem 1.5rem;
        border-radius: 10px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        z-index: 2;
    }
    
    .mastery-percentage {
        text-align: center;
    }
    
    .mastery-percentage h3 {
        font-size: 1.8rem;
        margin-bottom: 0;
        color: var(--indigo);
    }
    
    .mastery-bar {
        height: 15px !important;
        border-radius: 10px;
    }
    
    .mastery-dot {
        width: 12px;
        height: 12px;
        border-radius: 50%;
        margin-right: 0.75rem;
    }
    
    .mastery-item {
        display: flex;
        align-items: center;
    }
    
    .mastery-label {
        font-size: 0.9rem;
        display: flex;
        flex-direction: column;
    }
    
    .mastery-legend {
        padding: 1rem 0;
    }
    
    /* Daily Goal Styling */
    .goal-container {
        padding: 0.5rem 0;
    }
    
    .daily-goal-label {
        font-weight: 500;
        color: var(--kuro);
    }
    
    .goal-status {
        font-size: 0.85rem;
        padding: 0.25rem 0.6rem;
        background-color: #f8f9fa;
        border-radius: 20px;
        color: #6c757d;
    }
    
    .goal-container.completed .goal-status {
        background-color: #e8f7ee;
        color: var(--success-color);
    }
    
    .daily-goal-progress {
        height: 12px;
        border-radius: 10px;
        overflow: hidden;
    }
    
    .celebration-icon {
        width: 60px;
        height: 60px;
        margin: 0 auto;
        background-color: #e8f7ee;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--success-color);
        font-size: 1.6rem;
    }
    
    /* Today's Stats */
    .today-stat-item {
        text-align: center;
        padding: 0.5rem;
    }
    
    .today-stat-value {
        font-size: 1.5rem;
        font-weight: 600;
        color: var(--indigo);
    }
    
    .today-stat-label {
        font-size: 0.85rem;
        color: #777;
    }
    
    /* Top Decks Styling */
    .top-deck-rank {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background-color: #f8f9fa;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        color: var(--indigo);
    }
    
    li:nth-child(1) .top-deck-rank {
        background-color: rgba(255, 215, 0, 0.2);
        color: #b8860b;
    }
    
    li:nth-child(2) .top-deck-rank {
        background-color: rgba(192, 192, 192, 0.2);
        color: #6c757d;
    }
    
    li:nth-child(3) .top-deck-rank {
        background-color: rgba(205, 127, 50, 0.2);
        color: #8B4513;
    }
    
    .accuracy-badge {
        padding: 0.35rem 0.75rem;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    
    .accuracy-badge.excellent {
        background-color: rgba(80, 200, 120, 0.15);
        color: #2b9348;
    }
    
    .accuracy-badge.good {
        background-color: rgba(125, 185, 222, 0.15);
        color: #1e6091;
    }
    
    .accuracy-badge.fair {
        background-color: rgba(255, 193, 7, 0.15);
        color: #cc8500;
    }
    
    .accuracy-badge.needs-work {
        background-color: rgba(255, 100, 100, 0.15);
        color: #cf1020;
    }
    
    /* Calendar Styling */
    #study-calendar {
        min-height: 250px;
    }
    
    /* Recent Decks */
    .deck-card {
        position: relative;
        border-radius: 12px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        overflow: hidden;
    }
    
    .deck-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }
    
    .due-badge {
        position: absolute;
        top: 1rem;
        right: 1rem;
        background-color: var(--danger-color);
        color: white;
        border-radius: 20px;
        padding: 0.25rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 600;
        z-index: 3;
    }
    
    .deck-title {
        font-size: 1.2rem;
        margin-bottom: 0.75rem;
        font-weight: 600;
        color: var(--kuro);
    }
    
    .deck-description {
        font-size: 0.9rem;
        min-height: 50px;
    }
    
    .deck-stats {
        display: flex;
        justify-content: space-between;
        margin-bottom: 0.75rem;
    }
    
    .deck-stat {
        font-size: 0.85rem;
        color: #6c757d;
    }
    
    .card-link {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 2;
    }
    
    .card-footer .btn {
        position: relative;
        z-index: 3;
    }
    
    /* Empty State Styling */
    .empty-state-container {
        background-color: #f8f9fa;
        border-radius: 15px;
        padding: 3rem 2rem;
    }
    
    .empty-state-icon {
        width: 80px;
        height: 80px;
        opacity: 0.6;
    }
    
    /* Study Tips */
    .study-tips-card {
        border-radius: 15px;
        overflow: hidden;
        background-color: #f8f9fa;
        border: none;
    }
    
    .study-tip {
        padding: 0.5rem 1rem;
        text-align: center;
    }
    
    .tip-text {
        font-size: 1.1rem;
        font-style: italic;
        color: #555;
    }
    
    /* Responsive Styling */
    @media (max-width: 768px) {
        .mastery-info {
            position: relative;
            top: 0;
            right: 0;
            width: 100%;
            display: flex;
            justify-content: center;
            background: none;
            box-shadow: none;
            padding: 0.5rem 0;
        }
        
        .dashboard-avatar {
            width: 50px;
            height: 50px;
            font-size: 1.5rem;
        }
        
        .dashboard-hero-card h1 {
            font-size: 1.5rem;
        }
    }
</style>

<!-- JavaScript for Charts and Calendar -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.0/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    // Initialize study tips carousel
    var studyTipsCarousel = new bootstrap.Carousel(document.getElementById('studyTipsCarousel'), {
        interval: 8000,
        pause: 'hover'
    });
    
    <?php if (!empty($activity)): ?>
    // Render activity chart
    renderActivityChart();
    <?php endif; ?>
    
    // Render calendar
    renderCalendar();
});

<?php if (!empty($activity)): ?>
// Function to render the activity chart
function renderActivityChart() {
    const ctx = document.getElementById('activityChart').getContext('2d');
    if (!ctx) return;
    
    // Create gradient for the chart
    const blueGradient = ctx.createLinearGradient(0, 0, 0, 300);
    blueGradient.addColorStop(0, 'rgba(62, 74, 137, 0.8)');
    blueGradient.addColorStop(1, 'rgba(62, 74, 137, 0.2)');
    
    const orangeGradient = ctx.createLinearGradient(0, 0, 0, 300);
    orangeGradient.addColorStop(0, 'rgba(255, 159, 100, 0.8)');
    orangeGradient.addColorStop(1, 'rgba(255, 159, 100, 0.2)');
    
    // Prepare data for chart
    const labels = [
        <?php 
            foreach ($activity as $day) {
                echo "'" . date('M d', strtotime($day['study_date'])) . "',";
            }
        ?>
    ];
    
    const studiedData = [
        <?php 
            foreach ($activity as $day) {
                echo $day['cards_studied'] . ",";
            }
        ?>
    ];
    
    const correctData = [
        <?php 
            foreach ($activity as $day) {
                echo $day['correct_answers'] . ",";
            }
        ?>
    ];
    
    const chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Cards Studied',
                    data: studiedData,
                    backgroundColor: blueGradient,
                    borderColor: 'rgba(62, 74, 137, 1)',
                    borderWidth: 1,
                    borderRadius: 6,
                    barPercentage: 0.6,
                    categoryPercentage: 0.7
                },
                {
                    label: 'Correct Answers',
                    data: correctData,
                    backgroundColor: orangeGradient,
                    borderColor: 'rgba(255, 159, 100, 1)',
                    borderWidth: 1,
                    borderRadius: 6,
                    barPercentage: 0.6,
                    categoryPercentage: 0.7
                }
            ]
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            animation: {
                duration: 1500,
                easing: 'easeOutQuart'
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        font: {
                            family: "'Noto Sans JP', sans-serif",
                            size: 12
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.9)',
                    titleColor: '#333',
                    bodyColor: '#666',
                    borderColor: '#e0e0e0',
                    borderWidth: 1,
                    padding: 12,
                    cornerRadius: 6,
                    titleFont: {
                        family: "'Noto Sans JP', sans-serif",
                        size: 14,
                        weight: 'bold'
                    },
                    bodyFont: {
                        family: "'Noto Sans JP', sans-serif",
                        size: 13
                    },
                    boxWidth: 10,
                    usePointStyle: true,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            label += context.parsed.y;
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(200, 200, 200, 0.1)',
                        drawBorder: false
                    },
                    ticks: {
                        precision: 0,
                        font: {
                            family: "'Noto Sans JP', sans-serif",
                            size: 11
                        },
                        padding: 10
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            family: "'Noto Sans JP', sans-serif",
                            size: 11
                        },
                        padding: 10
                    }
                }
            }
        }
    });
    
    // Handle chart timeframe buttons
    document.getElementById('chart-7days').addEventListener('click', function() {
        this.classList.add('active');
        document.getElementById('chart-30days').classList.remove('active');
        // Would update chart data for 7 days here
    });
    
    document.getElementById('chart-30days').addEventListener('click', function() {
        this.classList.add('active');
        document.getElementById('chart-7days').classList.remove('active');
        // Would update chart data for 30 days here
    });
}
<?php endif; ?>

// Function to render the study calendar
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
    
    // Create month and year header
    const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    const header = document.createElement('div');
    header.className = 'calendar-header d-flex justify-content-between align-items-center mb-3';
    header.innerHTML = `
        <div class="calendar-nav">
            <button class="btn btn-sm btn-outline-secondary me-1" id="prev-month">
                <i class="fas fa-chevron-left"></i>
            </button>
            <button class="btn btn-sm btn-outline-secondary" id="next-month">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        <h6 class="mb-0">${monthNames[currentMonth]} ${currentYear}</h6>
        <div></div>
    `;
    calendarEl.appendChild(header);
    
    // Create day labels
    const dayLabels = document.createElement('div');
    dayLabels.className = 'row text-center mb-2';
    const days = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
    
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
        calendarHtml += '<div class="row g-1 mb-1">';
        
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
                
                let dayClass = 'calendar-day';
                if (isToday) dayClass += ' today';
                if (isStudyDay) dayClass += ' studied';
                
                calendarHtml += `
                    <div class="col px-1 text-center" data-date="${date}">
                        <div class="${dayClass}">${dayCount}</div>
                    </div>
                `;
                
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
    
    const calendarContainer = document.createElement('div');
    calendarContainer.className = 'calendar-container';
    calendarContainer.innerHTML = calendarHtml;
    calendarEl.appendChild(calendarContainer);
    
    // Add this CSS to the existing styles
    const style = document.createElement('style');
    style.innerHTML = `
        .calendar-container {
            padding: 0.5rem;
            background-color: #f8f9fa;
            border-radius: 10px;
        }
        
        .calendar-day {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            font-size: 0.8rem;
            cursor: default;
            transition: all 0.2s ease;
        }
        
        .calendar-day.today {
            background-color: var(--indigo);
            color: white;
            font-weight: 600;
        }
        
        .calendar-day.studied:not(.today) {
            background-color: rgba(255, 159, 100, 0.2);
            color: #FF8B43;
            font-weight: 600;
        }
        
        .calendar-day.today.studied {
            background: linear-gradient(135deg, var(--indigo) 50%, #FF8B43 50%);
            color: white;
        }
    `;
    document.head.appendChild(style);
    
    // Add navigation functionality (would be implemented in a real app)
    const prevMonthBtn = document.getElementById('prev-month');
    const nextMonthBtn = document.getElementById('next-month');
    
    if (prevMonthBtn) {
        prevMonthBtn.addEventListener('click', function() {
            // Would go to previous month
            // For demo, just show tooltip
            const tooltip = new bootstrap.Tooltip(this, {
                title: 'Previous month view coming soon',
                trigger: 'manual'
            });
            tooltip.show();
            setTimeout(() => tooltip.hide(), 2000);
        });
    }
    
    if (nextMonthBtn) {
        nextMonthBtn.addEventListener('click', function() {
            // Would go to next month
            // For demo, just show tooltip
            const tooltip = new bootstrap.Tooltip(this, {
                title: 'Next month view coming soon',
                trigger: 'manual'
            });
            tooltip.show();
            setTimeout(() => tooltip.hide(), 2000);
        });
    }
}
</script>

<?php include_once 'includes/footer.php'; ?>