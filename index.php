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

$conn->close();

// Include header
include_once 'includes/header.php';
?>

<div class="welcome-banner mb-5">
    <div class="row align-items-center">
        <div class="col-md-8">
            <h1 class="mb-3">
                <i class="fas fa-home me-2"></i>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!
            </h1>
            <p class="text-muted mb-0">
                <?php 
                    $hour = date('H');
                    if ($hour < 12) {
                        echo 'Good morning! Time to start your day with some learning.';
                    } elseif ($hour < 17) {
                        echo 'Good afternoon! Keep up the good work with your flashcards.';
                    } else {
                        echo 'Good evening! Take some time to review what you\'ve learned today.';
                    }
                ?>
            </p>
        </div>
        <div class="col-md-4 text-end">
            <?php if ($due_cards > 0): ?>
                <a href="<?php echo SITE_URL; ?>/study/index.php" class="btn btn-lg btn-success">
                    <i class="fas fa-graduation-cap me-2"></i>Study Due Cards (<?php echo $due_cards; ?>)
                </a>
            <?php else: ?>
                <a href="<?php echo SITE_URL; ?>/study/index.php?mode=all" class="btn btn-lg btn-primary">
                    <i class="fas fa-graduation-cap me-2"></i>Start Studying
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4 mb-4">
        <div class="card stat-card h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-layer-group stat-icon"></i>
                <h5 class="card-title text-muted mb-2">Total Decks</h5>
                <p class="card-text display-4 mb-3"><?php echo $total_decks; ?></p>
                <a href="<?php echo SITE_URL; ?>/decks/list.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-th-list me-1"></i>View All Decks
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card stat-card h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-clone stat-icon"></i>
                <h5 class="card-title text-muted mb-2">Total Flashcards</h5>
                <p class="card-text display-4 mb-3"><?php echo $total_cards; ?></p>
                <a href="<?php echo SITE_URL; ?>/cards/list.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-th-list me-1"></i>View All Cards
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card stat-card h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-exclamation-circle stat-icon"></i>
                <h5 class="card-title text-muted mb-2">Cards Due Today</h5>
                <p class="card-text display-4 mb-3"><?php echo $due_cards; ?></p>
                <a href="<?php echo SITE_URL; ?>/study/index.php" class="btn btn-sm btn-success">
                    <i class="fas fa-graduation-cap me-1"></i>Start Studying
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Study Activity (Last 7 Days)</h5>
                <a href="<?php echo SITE_URL; ?>/stats.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-chart-line me-1"></i>View Stats
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($activity)): ?>
                    <div class="text-center py-5">
                        <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 24 24' fill='none' stroke='%233E4A89' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'%3E%3Cline x1='4' y1='21' x2='4' y2='14'/%3E%3Cline x1='8' y1='21' x2='8' y2='12'/%3E%3Cline x1='12' y1='21' x2='12' y2='8'/%3E%3Cline x1='16' y1='21' x2='16' y2='16'/%3E%3Cline x1='20' y1='21' x2='20' y2='10'/%3E%3C/svg%3E" 
                             alt="No activity" style="width: 80px; height: 80px; opacity: 0.5;" class="mb-3">
                        <p class="text-muted">No study activity in the last 7 days</p>
                        <a href="<?php echo SITE_URL; ?>/study/index.php" class="btn btn-primary">Start Studying Now</a>
                    </div>
                <?php else: ?>
                    <canvas id="activityChart" height="250"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-book me-2"></i>Recent Decks</h5>
                <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-sm btn-primary">
                    <i class="fas fa-plus me-1"></i>New Deck
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recent_decks)): ?>
                    <div class="text-center py-5">
                        <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 24 24' fill='none' stroke='%233E4A89' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20'/%3E%3C/svg%3E" 
                             alt="No decks" style="width: 80px; height: 80px; opacity: 0.5;" class="mb-3">
                        <p class="text-muted mb-3">No decks created yet</p>
                        <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-primary">Create Your First Deck</a>
                    </div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($recent_decks as $deck): ?>
                            <li class="list-group-item deck-list-item p-3">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck['deck_id']; ?>" class="deck-name">
                                            <i class="fas fa-book me-2"></i><?php echo htmlspecialchars($deck['deck_name']); ?>
                                        </a>
                                        <div class="text-muted mt-1">
                                            <small><i class="fas fa-clone me-1"></i><?php echo $deck['card_count']; ?> cards</small>
                                            <?php if ($deck['due_count'] > 0): ?>
                                                <span class="badge bg-danger ms-2">
                                                    <i class="fas fa-exclamation-circle me-1"></i><?php echo $deck['due_count']; ?> due
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <?php if ($deck['card_count'] > 0): ?>
                                            <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-success">
                                                <i class="fas fa-graduation-cap"></i>
                                            </a>
                                        <?php endif; ?>
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
                    borderRadius: 4,
                    barPercentage: 0.5,
                    categoryPercentage: 0.7
                },
                {
                    label: 'Correct Answers',
                    data: correctData,
                    backgroundColor: greenGradient,
                    borderColor: 'rgba(138, 163, 103, 1)',
                    borderWidth: 1,
                    borderRadius: 4,
                    barPercentage: 0.5,
                    categoryPercentage: 0.7
                }
            ]
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 15
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.9)',
                    titleColor: '#333',
                    bodyColor: '#666',
                    borderColor: '#e0e0e0',
                    borderWidth: 1,
                    padding: 10,
                    caretPadding: 5,
                    cornerRadius: 4,
                    titleFont: {
                        family: "'Noto Sans JP', sans-serif",
                        size: 13
                    },
                    bodyFont: {
                        family: "'Noto Sans JP', sans-serif",
                        size: 12
                    },
                    boxWidth: 10
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
                        }
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
                        }
                    }
                }
            }
        }
    });
});
<?php endif; ?>
</script>

<?php include_once 'includes/footer.php'; ?>