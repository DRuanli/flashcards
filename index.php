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
    SELECT d.deck_id, d.deck_name, COUNT(c.card_id) as card_count,
           (SELECT COUNT(*) FROM progress p 
            JOIN cards c2 ON p.card_id = c2.card_id 
            WHERE c2.deck_id = d.deck_id AND p.user_id = ? AND p.next_review <= CURDATE()) as due_count
    FROM decks d
    LEFT JOIN cards c ON d.deck_id = c.deck_id
    WHERE d.user_id = ?
    GROUP BY d.deck_id
    ORDER BY d.created_at DESC
    LIMIT 5
");
$stmt->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
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

$conn->close();

// Include header
include_once 'includes/header.php';
?>

<!-- Dashboard Hero Section -->
<div class="dashboard-hero mb-4">
    <div class="card border-0 bg-gradient-primary text-white">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <div class="d-flex align-items-center mb-3">
                        <div class="avatar-container me-3">
                            <div class="avatar-placeholder">
                                <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                            </div>
                        </div>
                        <div>
                            <h1 class="mb-1 display-5">
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
                            <p class="lead mb-0 text-white-50">
                                <?php 
                                    if ($due_cards > 0) {
                                        echo "You have <strong>{$due_cards}</strong> cards waiting for review today.";
                                    } else {
                                        echo "You're all caught up with your reviews. Great work!";
                                    }
                                ?>
                            </p>
                        </div>
                    </div>
                    <?php if ($current_streak > 0): ?>
                    <div class="streak-container mt-3">
                        <div class="streak-badge">
                            <i class="fas fa-fire-alt"></i> <?php echo $current_streak; ?>-day streak
                        </div>
                        <div class="progress bg-white bg-opacity-25 mt-2" style="height: 6px;">
                            <div class="progress-bar bg-warning" role="progressbar" style="width: 100%;" aria-valuenow="100" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <small class="text-white-50 mt-1 d-block">Keep going to maintain your streak!</small>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-lg-4 text-center text-lg-end mt-4 mt-lg-0">
                    <?php if ($due_cards > 0): ?>
                        <div class="action-button-container d-flex flex-column align-items-center align-items-lg-end">
                            <a href="<?php echo SITE_URL; ?>/study/index.php" class="btn btn-light btn-lg px-4 mb-3">
                                <i class="fas fa-graduation-cap me-2"></i>Start Today's Review
                            </a>
                            <span class="text-white-50">It'll take about <?php echo ceil($due_cards * 0.5); ?> minutes</span>
                        </div>
                    <?php else: ?>
                        <div class="action-button-container d-flex flex-column align-items-center align-items-lg-end">
                            <a href="<?php echo SITE_URL; ?>/study/index.php?mode=all" class="btn btn-light btn-lg px-4 mb-3">
                                <i class="fas fa-sync-alt me-2"></i>Review Extra Cards
                            </a>
                            <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-outline-light">
                                <i class="fas fa-plus me-2"></i>Create New Deck
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats Overview -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-4">
        <div class="card h-100 stat-card-enhanced">
            <div class="stat-icon-bg bg-indigo">
                <i class="fas fa-layer-group"></i>
            </div>
            <div class="card-body text-center p-4">
                <h5 class="card-title text-muted mb-2">Total Decks</h5>
                <p class="card-text display-4 mb-0"><?php echo $total_decks; ?></p>
                <div class="mt-3">
                    <a href="<?php echo SITE_URL; ?>/decks/list.php" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-th-list me-1"></i>View All
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-4">
        <div class="card h-100 stat-card-enhanced">
            <div class="stat-icon-bg bg-sakura">
                <i class="fas fa-clone"></i>
            </div>
            <div class="card-body text-center p-4">
                <h5 class="card-title text-muted mb-2">Total Cards</h5>
                <p class="card-text display-4 mb-0"><?php echo $total_cards; ?></p>
                <div class="mt-3">
                    <a href="<?php echo SITE_URL; ?>/cards/list.php" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-th-list me-1"></i>View All
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-4">
        <div class="card h-100 stat-card-enhanced">
            <div class="stat-icon-bg bg-matcha">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <div class="card-body text-center p-4">
                <h5 class="card-title text-muted mb-2">Due Today</h5>
                <p class="card-text display-4 mb-0"><?php echo $due_cards; ?></p>
                <div class="mt-3">
                    <a href="<?php echo SITE_URL; ?>/study/index.php" class="btn btn-sm btn-success">
                        <i class="fas fa-graduation-cap me-1"></i>Study Now
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-4">
        <div class="card h-100 stat-card-enhanced">
            <div class="stat-icon-bg bg-asagi">
                <i class="fas fa-chart-pie"></i>
            </div>
            <div class="card-body text-center p-4">
                <h5 class="card-title text-muted mb-2">Accuracy</h5>
                <p class="card-text display-4 mb-0"><?php echo $overall_stats['overall_accuracy'] ?? 0; ?>%</p>
                <div class="mt-3">
                    <a href="<?php echo SITE_URL; ?>/stats.php" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-chart-line me-1"></i>View Stats
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Dashboard Content -->
<div class="row">
    <!-- Activity Chart -->
    <div class="col-lg-8 mb-4">
        <div class="card h-100 dashboard-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Recent Study Activity</h5>
                <a href="<?php echo SITE_URL; ?>/stats.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-chart-line me-1"></i>View All Stats
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($activity)): ?>
                    <div class="text-center py-5 empty-state">
                        <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 24 24' fill='none' stroke='%233E4A89' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'%3E%3Cline x1='4' y1='21' x2='4' y2='14'/%3E%3Cline x1='8' y1='21' x2='8' y2='12'/%3E%3Cline x1='12' y1='21' x2='12' y2='8'/%3E%3Cline x1='16' y1='21' x2='16' y2='16'/%3E%3Cline x1='20' y1='21' x2='20' y2='10'/%3E%3C/svg%3E" 
                             alt="No activity" style="width: 80px; height: 80px; opacity: 0.5;" class="mb-3">
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
    </div>

    <!-- Right Side Content -->
    <div class="col-lg-4 mb-4">
        <div class="row">
            <!-- Daily Goal Progress -->
            <div class="col-12 mb-4">
                <div class="card dashboard-card daily-goal-card">
                    <div class="card-body p-4">
                        <h5 class="mb-3"><i class="fas fa-bullseye me-2"></i>Daily Goal</h5>
                        <?php 
                            // Simple daily goal calculation
                            $today_studied = 0;
                            $today_goal = max(10, ceil($total_cards * 0.1)); // 10% of total cards or at least 10
                            
                            foreach ($activity as $day) {
                                if ($day['study_date'] == date('Y-m-d')) {
                                    $today_studied = $day['cards_studied'];
                                    break;
                                }
                            }
                            
                            $progress_percent = min(100, round(($today_studied / $today_goal) * 100));
                        ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted">Progress</span>
                            <span class="text-muted"><?php echo $today_studied; ?> / <?php echo $today_goal; ?> cards</span>
                        </div>
                        <div class="progress mb-3" style="height: 10px;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $progress_percent; ?>%;" aria-valuenow="<?php echo $progress_percent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <?php if ($progress_percent < 100): ?>
                            <a href="<?php echo SITE_URL; ?>/study/index.php" class="btn btn-sm btn-success w-100">
                                <i class="fas fa-play me-2"></i>Continue Studying
                            </a>
                        <?php else: ?>
                            <div class="alert alert-success mb-0 d-flex align-items-center">
                                <i class="fas fa-check-circle me-2 fs-5"></i>
                                <div>Great job! You've reached your daily goal.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Decks -->
            <div class="col-12">
                <div class="card dashboard-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-book me-2"></i>Recent Decks</h5>
                        <div>
                            <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-sm btn-primary">
                                <i class="fas fa-plus"></i>
                            </a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recent_decks)): ?>
                            <div class="text-center py-5 empty-state">
                                <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 24 24' fill='none' stroke='%233E4A89' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20'/%3E%3C/svg%3E" 
                                     alt="No decks" style="width: 80px; height: 80px; opacity: 0.5;" class="mb-3">
                                <h4>No Decks Created Yet</h4>
                                <p class="text-muted mb-3">Create your first flashcard deck to start learning.</p>
                                <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i>Create Your First Deck
                                </a>
                            </div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush deck-list">
                                <?php foreach ($recent_decks as $deck): ?>
                                    <li class="list-group-item p-0">
                                        <div class="deck-list-item p-3">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck['deck_id']; ?>" class="deck-name">
                                                        <i class="fas fa-book me-2"></i><?php echo htmlspecialchars($deck['deck_name']); ?>
                                                    </a>
                                                    <div class="d-flex align-items-center mt-1">
                                                        <span class="badge bg-primary rounded-pill me-2">
                                                            <i class="fas fa-clone me-1"></i><?php echo $deck['card_count']; ?>
                                                        </span>
                                                        <?php if ($deck['due_count'] > 0): ?>
                                                            <span class="badge bg-danger rounded-pill">
                                                                <i class="fas fa-exclamation-circle me-1"></i><?php echo $deck['due_count']; ?> due
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div>
                                                    <?php if ($deck['card_count'] > 0): ?>
                                                        <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-success" data-bs-toggle="tooltip" title="Study this deck">
                                                            <i class="fas fa-graduation-cap"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="card-footer text-center">
                                <a href="<?php echo SITE_URL; ?>/decks/list.php" class="text-decoration-none">
                                    <i class="fas fa-th-list me-1"></i>View All Decks
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Additional CSS -->
<style>
    /* Dashboard Hero Styles */
    .dashboard-hero .card {
        background: linear-gradient(135deg, var(--indigo) 0%, var(--indigo-dark) 100%);
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(62, 74, 137, 0.2);
    }
    
    .avatar-container {
        width: 60px;
        height: 60px;
    }
    
    .avatar-placeholder {
        width: 100%;
        height: 100%;
        background-color: rgba(255, 255, 255, 0.2);
        color: white;
        font-size: 1.8rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }
    
    .streak-badge {
        display: inline-block;
        background-color: rgba(255, 180, 0, 0.3);
        color: rgba(255, 255, 255, 0.9);
        border-radius: 30px;
        padding: 5px 15px;
        font-weight: 500;
    }
    
    .streak-badge i {
        color: #FFB400;
    }
    
    /* Enhanced Stat Cards */
    .stat-card-enhanced {
        border-radius: 12px;
        overflow: hidden;
        position: relative;
        border: none;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    
    .stat-card-enhanced:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
    }
    
    .stat-icon-bg {
        position: absolute;
        top: -20px;
        right: -20px;
        width: 90px;
        height: 90px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0.8;
    }
    
    .stat-icon-bg i {
        font-size: 2.5rem;
        color: rgba(255, 255, 255, 0.7);
    }
    
    .bg-indigo {
        background-color: var(--indigo);
    }
    
    .bg-sakura {
        background-color: var(--sakura);
    }
    
    .bg-matcha {
        background-color: var(--matcha);
    }
    
    .bg-asagi {
        background-color: var(--asagi);
    }
    
    /* Dashboard Card Styles */
    .dashboard-card {
        border-radius: 12px;
        overflow: hidden;
        border: none;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }
    
    .dashboard-card .card-header {
        background-color: #f8f9fa;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        padding: 1rem 1.25rem;
    }
    
    /* Daily Goal Card */
    .daily-goal-card {
        background-color: #f8f9fa;
    }
    
    /* Deck List Styling */
    .deck-list .deck-list-item {
        transition: background-color 0.2s ease;
        border-left: 3px solid transparent;
    }
    
    .deck-list .deck-list-item:hover {
        background-color: rgba(62, 74, 137, 0.05);
        border-left-color: var(--indigo);
    }
    
    .deck-name {
        color: var(--kuro);
        font-weight: 500;
        text-decoration: none;
    }
    
    /* Empty State Styling */
    .empty-state {
        padding: 2rem;
    }
    
    .empty-state h4 {
        color: var(--indigo);
        margin-bottom: 0.5rem;
    }
</style>

<!-- JavaScript for charts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.0/chart.min.js"></script>
<script>
<?php if (!empty($activity)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('activityChart').getContext('2d');
    
    // Create gradient for the bar chart
    const blueGradient = ctx.createLinearGradient(0, 0, 0, 300);
    blueGradient.addColorStop(0, 'rgba(62, 74, 137, 0.8)');
    blueGradient.addColorStop(1, 'rgba(62, 74, 137, 0.2)');
    
    const greenGradient = ctx.createLinearGradient(0, 0, 0, 300);
    greenGradient.addColorStop(0, 'rgba(138, 163, 103, 0.8)');
    greenGradient.addColorStop(1, 'rgba(138, 163, 103, 0.2)');
    
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
                    backgroundColor: greenGradient,
                    borderColor: 'rgba(138, 163, 103, 1)',
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
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    // Add animation to stat cards
    const statCards = document.querySelectorAll('.stat-card-enhanced');
    statCards.forEach((card, index) => {
        setTimeout(() => {
            card.classList.add('animate__animated', 'animate__fadeInUp');
        }, index * 100);
    });
});
<?php endif; ?>
</script>

<?php include_once 'includes/footer.php'; ?>